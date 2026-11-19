<?php
/**
 * Review sessions, email codes, passwords and brute-force limits.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review;

defined( 'ABSPATH' ) || exit;

/**
 * A review session is a signed cookie per link, never a WordPress login. It
 * grants reading that one post and leaving notes on it, nothing else.
 */
final class Access {

	/**
	 * Links.
	 *
	 * @var Links
	 */
	private Links $links;

	/**
	 * Events.
	 *
	 * @var Events
	 */
	private Events $events;

	/**
	 * Sets up access checks.
	 *
	 * @param Links  $links  Links.
	 * @param Events $events Events.
	 */
	public function __construct( Links $links, Events $events ) {
		$this->links  = $links;
		$this->events = $events;
	}

	/**
	 * The cookie name of a link's session.
	 *
	 * @param int $link_id Link ID.
	 * @return string
	 */
	public static function cookie_name( int $link_id ): string {
		return 'axr_' . $link_id;
	}

	/**
	 * Signs session fields.
	 *
	 * @param string $payload Fields joined with |.
	 * @return string
	 */
	private static function sign( string $payload ): string {
		return hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
	}

	/**
	 * How long a session lasts.
	 *
	 * @return int Seconds.
	 */
	public static function session_ttl(): int {
		/**
		 * Filters how long a review session lasts. The link's expiry still ends it.
		 *
		 * @param int $seconds Default 12 hours, between 5 minutes and 30 days.
		 */
		return max( 5 * MINUTE_IN_SECONDS, min( 30 * DAY_IN_SECONDS, (int) apply_filters( 'axle_review_session_ttl', 12 * HOUR_IN_SECONDS ) ) );
	}

	/**
	 * The cookie value for a session.
	 *
	 * @param int    $link_id  Link ID.
	 * @param string $session  Session ID.
	 * @param int    $expires  Unix time.
	 * @param bool   $verified Whether the email code was checked.
	 * @return string
	 */
	public static function cookie_value( int $link_id, string $session, int $expires, bool $verified ): string {
		$payload = $link_id . '|' . $session . '|' . $expires . '|' . (int) $verified;
		return $session . '|' . $expires . '|' . (int) $verified . '|' . self::sign( $payload );
	}

	/**
	 * Reads and checks a session cookie value.
	 *
	 * @param int    $link_id Link ID.
	 * @param string $value   Cookie value.
	 * @param int    $now     Unix time.
	 * @return array{id: string, expires: int, verified: bool}|null
	 */
	public static function parse_cookie( int $link_id, string $value, int $now ): ?array {
		$parts = explode( '|', $value );
		if ( 4 !== count( $parts ) ) {
			return null;
		}

		list( $session, $expires, $verified, $signature ) = $parts;
		if ( ! preg_match( '/^[a-f0-9]{24}$/', $session ) || (int) $expires < $now ) {
			return null;
		}

		$expected = self::sign( $link_id . '|' . $session . '|' . (int) $expires . '|' . (int) $verified );
		if ( ! hash_equals( $expected, $signature ) ) {
			return null;
		}

		return array(
			'id'       => $session,
			'expires'  => (int) $expires,
			'verified' => '1' === $verified,
		);
	}

	/**
	 * The session of the current visitor for a link.
	 *
	 * @param array $link Link.
	 * @return array{id: string, expires: int, verified: bool}|null
	 */
	public function session( array $link ): ?array {
		$name = self::cookie_name( (int) $link['id'] );
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Review responses are never cached (no-store headers).
		$value = isset( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) : '';

		return '' === $value ? null : self::parse_cookie( (int) $link['id'], $value, time() );
	}

	/**
	 * Starts a session: sets the cookie. The caller has counted the view.
	 *
	 * @param array $link     Link.
	 * @param bool  $verified Whether the email code was checked.
	 * @return array{id: string, expires: int, verified: bool}
	 */
	public function start( array $link, bool $verified ): array {
		$session   = bin2hex( random_bytes( 12 ) );
		$link_ends = strtotime( (string) $link['expires_at'] . ' UTC' );
		$expires   = min( time() + self::session_ttl(), false === $link_ends ? time() : $link_ends );
		$value     = self::cookie_value( (int) $link['id'], $session, $expires, $verified );

		if ( ! headers_sent() ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie -- The review session; responses carry no-store headers.
			setcookie(
				self::cookie_name( (int) $link['id'] ),
				$value,
				array(
					'expires'  => $expires,
					'path'     => '/',
					'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? (string) COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
		$_COOKIE[ self::cookie_name( (int) $link['id'] ) ] = $value; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- The session just started, for the rest of this request.

		return array(
			'id'       => $session,
			'expires'  => $expires,
			'verified' => $verified,
		);
	}

	/**
	 * The REST nonce for a session: a signature bound to the link and session.
	 *
	 * @param int    $link_id Link ID.
	 * @param string $session Session ID.
	 * @return string
	 */
	public static function nonce( int $link_id, string $session ): string {
		return substr( self::sign( 'rest|' . $link_id . '|' . $session ), 0, 32 );
	}

	/**
	 * A token for the logged-out forms (code and password), bound to the link.
	 *
	 * Logged-out visitors have no WordPress nonce, so this signed token is the
	 * CSRF protection for those forms: it proves the form came from this site's
	 * gate page for this link, and it expires after a day or two.
	 *
	 * @param int $link_id Link ID.
	 * @return string
	 */
	public static function form_token( int $link_id ): string {
		return substr( self::sign( 'form|' . $link_id . '|' . gmdate( 'Y-m-d' ) ), 0, 20 );
	}

	/**
	 * Checks a form token (today's or yesterday's).
	 *
	 * @param int    $link_id Link ID.
	 * @param string $token   Token.
	 * @return bool
	 */
	public static function check_form_token( int $link_id, string $token ): bool {
		foreach ( array( gmdate( 'Y-m-d' ), gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ) ) as $day ) {
			if ( hash_equals( substr( self::sign( 'form|' . $link_id . '|' . $day ), 0, 20 ), $token ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The code length, ttl and tries.
	 *
	 * @return array{length: int, ttl: int, tries: int}
	 */
	public static function code_rules(): array {
		return array(
			/**
			 * Filters the number of digits in an email code.
			 *
			 * @param int $length Default 6, between 4 and 10.
			 */
			'length' => max( 4, min( 10, (int) apply_filters( 'axle_review_code_length', 6 ) ) ),
			/**
			 * Filters how long an email code works.
			 *
			 * @param int $seconds Default 10 minutes, between 1 minute and 1 hour.
			 */
			'ttl'    => max( MINUTE_IN_SECONDS, min( HOUR_IN_SECONDS, (int) apply_filters( 'axle_review_code_ttl', 10 * MINUTE_IN_SECONDS ) ) ),
			/**
			 * Filters how many wrong codes or passwords lock a link out for 15 minutes.
			 *
			 * @param int $tries Default 5, between 1 and 20.
			 */
			'tries'  => max( 1, min( 20, (int) apply_filters( 'axle_review_code_max_tries', 5 ) ) ),
		);
	}

	/**
	 * Transient key of the failure counter of one visitor (by IP address).
	 *
	 * @return string
	 */
	private static function ip_fail_key(): string {
		return 'axle_review_fails_ip_' . substr( md5( Events::raw_ip() . wp_salt( 'nonce' ) ), 0, 16 );
	}

	/**
	 * Transient key of the backstop counter of one link.
	 *
	 * @param int $link_id Link ID.
	 * @return string
	 */
	private static function link_fail_key( int $link_id ): string {
		return 'axle_review_fails_link_' . $link_id;
	}

	/**
	 * How many failures from all visitors together lock a link, however many
	 * addresses they come from. Far above the per-visitor limit, so one person
	 * holding a forwarded link cannot lock the real reviewer out.
	 *
	 * @return int
	 */
	public static function link_ceiling(): int {
		$default = self::code_rules()['tries'] * 10;

		/**
		 * Filters how many wrong codes or passwords, from all visitors together,
		 * lock a link for 15 minutes. A single visitor is stopped much sooner, by
		 * the per-visitor limit (axle_review_code_max_tries).
		 *
		 * @param int $ceiling Default ten times the per-visitor limit, between 5 and 500.
		 */
		return max( 5, min( 500, (int) apply_filters( 'axle_review_link_fail_ceiling', $default ) ) );
	}

	/**
	 * Whether the visitor is locked out after too many failures, or the link
	 * after a flood of them from many visitors.
	 *
	 * @param int $link_id Link ID.
	 * @return bool
	 */
	public function locked( int $link_id ): bool {
		return (int) get_transient( self::ip_fail_key() ) >= self::code_rules()['tries'] || (int) get_transient( self::link_fail_key( $link_id ) ) >= self::link_ceiling();
	}

	/**
	 * Counts a failed code or password: always against the visitor's address,
	 * and against the link's backstop. When the visitor reaches the limit the
	 * link's creator is told.
	 *
	 * @param array $link Link.
	 */
	private function fail( array $link ): void {
		$link_id = (int) $link['id'];
		$ip      = (int) get_transient( self::ip_fail_key() ) + 1;
		set_transient( self::ip_fail_key(), $ip, 15 * MINUTE_IN_SECONDS );
		set_transient( self::link_fail_key( $link_id ), (int) get_transient( self::link_fail_key( $link_id ) ) + 1, 15 * MINUTE_IN_SECONDS );

		$limit = self::code_rules()['tries'];
		if ( $limit === $ip ) {
			$this->tell_creator( $link );
		}
	}

	/**
	 * Clears the visitor's failure counter after a success. The link's backstop
	 * is left to run out, so a guesser cannot reset it by passing once.
	 */
	private function clear_fails(): void {
		delete_transient( self::ip_fail_key() );
	}

	/**
	 * Emails the person who made a link when someone failed its code or
	 * password too often, with a pointer to Revoke and Ask again. At most one
	 * email an hour for each link.
	 *
	 * @param array $link Link.
	 */
	private function tell_creator( array $link ): void {
		$link_id = (int) $link['id'];
		$guard   = 'axle_review_guess_mail_' . $link_id;
		if ( get_transient( $guard ) ) {
			return;
		}
		set_transient( $guard, 1, HOUR_IN_SECONDS );

		$this->events->add( $link_id, 'guess_alert' );

		/**
		 * Fires when someone failed a link's code or password as often as the
		 * limit allows (default 5 times) and the link's creator was told.
		 *
		 * @param array $link The link.
		 */
		do_action( 'axle_review_link_guessing', $link );

		$user = (int) $link['created_by'] ? get_userdata( (int) $link['created_by'] ) : false;
		if ( ! $user || ! $user->user_email ) {
			return;
		}

		$label = '' !== (string) $link['label'] ? (string) $link['label'] : sprintf(
			/* translators: %d: link ID. */
			__( 'Link %d', 'axle-review' ),
			$link_id
		);
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		/* translators: 1: site name, 2: link label. */
		$subject = sprintf( __( '[%1$s] Someone keeps failing the code on "%2$s"', 'axle-review' ), $site, $label );
		$message = sprintf(
			/* translators: 1: link label, 2: number of wrong tries, 3: address of the Review Links screen. */
			__( "Someone failed the code or password of the review link \"%1\$s\" %2\$d times in a row. They are locked out for 15 minutes.\n\nIf this is not the person you shared it with, revoke the link. If it is, ask them again and they get a new link.\n\nRevoke or ask again here:\n%3\$s", 'axle-review' ),
			$label,
			self::code_rules()['tries'],
			Admin\LinksPage::url( array( 'post_id' => (int) $link['post_id'] ) )
		);

		$subject = Alerts::subject( $subject, 'guessing', array( 'link' => $link ) );
		$message = Alerts::message( $message, 'guessing', array( 'link' => $link ) );

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- One security notice to the link's creator, at most once an hour.
		wp_mail( $user->user_email, $subject, $message );
	}

	/**
	 * Emails a one-time code to the link's address.
	 *
	 * @param array $link Link.
	 * @return true|\WP_Error
	 */
	public function send_code( array $link ) {
		if ( '' === $link['email'] ) {
			return new \WP_Error( 'axle_review_no_email', __( 'This link is not locked to an email address.', 'axle-review' ) );
		}

		if ( $this->locked( (int) $link['id'] ) ) {
			return new \WP_Error( 'axle_review_locked', __( 'Too many tries. Wait 15 minutes and try again.', 'axle-review' ) );
		}

		$sent_key = 'axle_review_codes_sent_' . (int) $link['id'];
		$sent     = (int) get_transient( $sent_key );
		if ( $sent >= 5 ) {
			return new \WP_Error( 'axle_review_too_many_codes', __( 'Several codes were sent already. Wait 15 minutes and try again.', 'axle-review' ) );
		}
		set_transient( $sent_key, $sent + 1, 15 * MINUTE_IN_SECONDS );

		$rules = self::code_rules();
		$code  = '';
		for ( $i = 0; $i < $rules['length']; $i++ ) {
			$code .= (string) random_int( 0, 9 );
		}

		// A second click must not kill the first email: the last two codes both work.
		$now   = time();
		$state = self::code_state( (int) $link['id'], $now );
		$keep  = $state ? array_slice( $state['codes'], 0, self::codes_kept() - 1 ) : array();
		$codes = array_merge(
			array(
				array(
					'hash'  => self::sign( 'code|' . (int) $link['id'] . '|' . $code ),
					'until' => $now + $rules['ttl'],
				),
			),
			$keep
		);

		set_transient(
			'axle_review_code_' . (int) $link['id'],
			array(
				'codes' => $codes,
				'tries' => $state ? $state['tries'] : 0,
			),
			$rules['ttl']
		);

		$post  = get_post( (int) $link['post_id'] );
		$title = $post ? wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ) : '';
		$site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		/* translators: 1: site name. */
		$subject = sprintf( __( '[%1$s] Your review code', 'axle-review' ), $site );
		$message = sprintf(
			/* translators: 1: code, 2: post title, 3: minutes. */
			__( "Your code to open \"%2\$s\" for review is:\n\n%1\$s\n\nIt works for %3\$d minutes. If you did not ask for it, ignore this email.", 'axle-review' ),
			$code,
			$title,
			(int) ( $rules['ttl'] / MINUTE_IN_SECONDS )
		);

		$subject = Alerts::subject( $subject, 'code', array( 'link' => $link ) );
		$message = Alerts::message( $message, 'code', array( 'link' => $link ) );

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- One code, to one reviewer, on request.
		wp_mail( (string) $link['email'], $subject, $message );
		$this->events->add( (int) $link['id'], 'code_sent' );

		/**
		 * Fires after an email code was sent for a link.
		 *
		 * @param array $link The link.
		 */
		do_action( 'axle_review_code_sent', $link );

		return true;
	}

	/**
	 * How many of the latest codes stay valid.
	 *
	 * @return int
	 */
	public static function codes_kept(): int {
		/**
		 * Filters how many of the latest email codes work at the same time, so a
		 * second "Email me a code" does not make the first email useless.
		 *
		 * @param int $count Default 2, between 1 and 5.
		 */
		return max( 1, min( 5, (int) apply_filters( 'axle_review_codes_kept', 2 ) ) );
	}

	/**
	 * The stored codes of a link that have not run out.
	 *
	 * @param int $link_id Link ID.
	 * @param int $now     Unix time.
	 * @return array{codes: array<int, array{hash: string, until: int}>, tries: int}|null Null when none is valid.
	 */
	public static function code_state( int $link_id, int $now ): ?array {
		$stored = get_transient( 'axle_review_code_' . $link_id );
		if ( ! is_array( $stored ) ) {
			return null;
		}

		$codes = array();
		if ( isset( $stored['codes'] ) && is_array( $stored['codes'] ) ) {
			foreach ( $stored['codes'] as $entry ) {
				if ( is_array( $entry ) && isset( $entry['hash'], $entry['until'] ) && (int) $entry['until'] > $now ) {
					$codes[] = array(
						'hash'  => (string) $entry['hash'],
						'until' => (int) $entry['until'],
					);
				}
			}
		} elseif ( isset( $stored['hash'] ) ) {
			// A code stored by an older version: its lifetime is not known, so it keeps the default.
			$codes[] = array(
				'hash'  => (string) $stored['hash'],
				'until' => $now + self::code_rules()['ttl'],
			);
		}

		return $codes ? array(
			'codes' => $codes,
			'tries' => (int) ( $stored['tries'] ?? 0 ),
		) : null;
	}

	/**
	 * Whether a code was sent for the link and still works.
	 *
	 * @param int $link_id Link ID.
	 * @return bool
	 */
	public static function has_live_code( int $link_id ): bool {
		return null !== self::code_state( $link_id, time() );
	}

	/**
	 * Checks an email code.
	 *
	 * A wrong try never lengthens a code's life. After the allowed number of
	 * wrong tries the codes are dropped and the reviewer asks for a new one.
	 *
	 * @param array  $link Link.
	 * @param string $code Code typed.
	 * @return true|\WP_Error
	 */
	public function check_code( array $link, string $code ) {
		$link_id = (int) $link['id'];
		if ( $this->locked( $link_id ) ) {
			return new \WP_Error( 'axle_review_locked', __( 'Too many tries. Wait 15 minutes and try again.', 'axle-review' ) );
		}

		$now    = time();
		$state  = self::code_state( $link_id, $now );
		$code   = (string) preg_replace( '/\D/', '', $code );
		$digest = self::sign( 'code|' . $link_id . '|' . $code );

		if ( $state && '' !== $code ) {
			foreach ( $state['codes'] as $entry ) {
				if ( hash_equals( $entry['hash'], $digest ) ) {
					delete_transient( 'axle_review_code_' . $link_id );
					$this->clear_fails();
					$this->events->add( $link_id, 'code_ok' );
					return true;
				}
			}
		}

		$this->fail( $link );
		$this->events->add( $link_id, 'code_fail' );
		if ( $state ) {
			++$state['tries'];
			if ( $state['tries'] >= self::code_rules()['tries'] ) {
				delete_transient( 'axle_review_code_' . $link_id );
				$this->tell_creator( $link );
			} else {
				// Same end time as before: a wrong try does not give the code more time.
				$left = max( 1, (int) max( array_column( $state['codes'], 'until' ) ) - $now );
				set_transient( 'axle_review_code_' . $link_id, $state, $left );
			}
		}

		/**
		 * Fires after a wrong or expired email code.
		 *
		 * @param array $link The link.
		 */
		do_action( 'axle_review_code_failed', $link );

		return new \WP_Error( 'axle_review_bad_code', __( 'That code is wrong or has expired.', 'axle-review' ) );
	}

	/**
	 * Checks a link password.
	 *
	 * @param array  $link     Link.
	 * @param string $password Password typed.
	 * @return true|\WP_Error
	 */
	public function check_password( array $link, string $password ) {
		$link_id = (int) $link['id'];
		if ( $this->locked( $link_id ) ) {
			return new \WP_Error( 'axle_review_locked', __( 'Too many tries. Wait 15 minutes and try again.', 'axle-review' ) );
		}

		if ( '' !== $password && wp_check_password( $password, (string) $link['password_hash'] ) ) {
			$this->clear_fails();
			return true;
		}

		$this->fail( $link );
		$this->events->add( $link_id, 'password_fail' );

		return new \WP_Error( 'axle_review_bad_password', __( 'That password is wrong.', 'axle-review' ) );
	}

	/**
	 * Proof that the visitor passed the email code for this link, kept in a
	 * short cookie until the password form is passed too.
	 *
	 * @param int $link_id Link ID.
	 * @return string
	 */
	public static function step_proof( int $link_id ): string {
		return substr( self::sign( 'step|' . $link_id . '|' . (int) floor( time() / 900 ) ), 0, 24 );
	}

	/**
	 * Checks a step proof (this or the previous 15-minute window).
	 *
	 * @param int    $link_id Link ID.
	 * @param string $proof   Proof.
	 * @return bool
	 */
	public static function check_step_proof( int $link_id, string $proof ): bool {
		$window = (int) floor( time() / 900 );
		foreach ( array( $window, $window - 1 ) as $slot ) {
			if ( hash_equals( substr( self::sign( 'step|' . $link_id . '|' . $slot ), 0, 24 ), $proof ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Links service, for callers that only have Access.
	 *
	 * @return Links
	 */
	public function links(): Links {
		return $this->links;
	}
}
