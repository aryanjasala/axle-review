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
	 * Transient keys of the failure counters: per link and per IP address.
	 *
	 * @param int $link_id Link ID.
	 * @return string[]
	 */
	private static function fail_keys( int $link_id ): array {
		return array(
			'axle_review_fails_link_' . $link_id,
			'axle_review_fails_ip_' . substr( md5( Events::raw_ip() . wp_salt( 'nonce' ) ), 0, 16 ),
		);
	}

	/**
	 * Whether the link or the visitor is locked out after too many failures.
	 *
	 * @param int $link_id Link ID.
	 * @return bool
	 */
	public function locked( int $link_id ): bool {
		$tries = self::code_rules()['tries'];
		foreach ( self::fail_keys( $link_id ) as $key ) {
			if ( (int) get_transient( $key ) >= $tries ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Counts a failed code or password.
	 *
	 * @param int $link_id Link ID.
	 */
	private function fail( int $link_id ): void {
		foreach ( self::fail_keys( $link_id ) as $key ) {
			set_transient( $key, (int) get_transient( $key ) + 1, 15 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Clears the failure counters after a success.
	 *
	 * @param int $link_id Link ID.
	 */
	private function clear_fails( int $link_id ): void {
		foreach ( self::fail_keys( $link_id ) as $key ) {
			delete_transient( $key );
		}
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

		set_transient(
			'axle_review_code_' . (int) $link['id'],
			array(
				'hash'  => self::sign( 'code|' . (int) $link['id'] . '|' . $code ),
				'tries' => 0,
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
	 * Checks an email code.
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

		$stored = get_transient( 'axle_review_code_' . $link_id );
		$code   = (string) preg_replace( '/\D/', '', $code );

		if ( is_array( $stored ) && '' !== $code && hash_equals( (string) $stored['hash'], self::sign( 'code|' . $link_id . '|' . $code ) ) ) {
			delete_transient( 'axle_review_code_' . $link_id );
			$this->clear_fails( $link_id );
			$this->events->add( $link_id, 'code_ok' );
			return true;
		}

		$this->fail( $link_id );
		$this->events->add( $link_id, 'code_fail' );
		if ( is_array( $stored ) ) {
			$stored['tries'] = (int) $stored['tries'] + 1;
			if ( $stored['tries'] >= self::code_rules()['tries'] ) {
				delete_transient( 'axle_review_code_' . $link_id );
			} else {
				set_transient( 'axle_review_code_' . $link_id, $stored, self::code_rules()['ttl'] );
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
			$this->clear_fails( $link_id );
			return true;
		}

		$this->fail( $link_id );
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
