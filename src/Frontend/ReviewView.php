<?php
/**
 * The review link on the front end.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Frontend;

use AryanX\Axle\Review\Access;
use AryanX\Axle\Review\Anchor;
use AryanX\Axle\Review\Events;
use AryanX\Axle\Review\Links;
use AryanX\Axle\Review\Notes;
use AryanX\Axle\Review\Service;
use AryanX\Axle\Review\Settings;
use const AryanX\Axle\Review\PLUGIN_FILE;
use const AryanX\Axle\Review\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Turns /?axle_review=<token> into the draft, rendered by the theme like a
 * published post, for whoever holds the link.
 *
 * The main query asks for the post with its own status, which WP_Query lets
 * through for a status it was asked for, so nothing pretends the post is
 * published. Gates (email code, password) and refusals are small standalone
 * pages. Every response carries no-store and noindex headers.
 */
final class ReviewView implements Service {

	public const QUERY_VAR = 'axle_review';

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
	 * Access.
	 *
	 * @var Access
	 */
	private Access $access;

	/**
	 * What this request is: render, code, password, denied, or empty when it is not a review request.
	 *
	 * @var string
	 */
	private string $state = '';

	/**
	 * Why access was refused: unknown, expired, used, revoked or paused.
	 *
	 * @var string
	 */
	private string $reason = '';

	/**
	 * The link of this request.
	 *
	 * @var array|null
	 */
	private ?array $link = null;

	/**
	 * The session of this request.
	 *
	 * @var array|null
	 */
	private ?array $session = null;

	/**
	 * The token from the URL.
	 *
	 * @var string
	 */
	private string $token = '';

	/**
	 * Sets up the view.
	 *
	 * @param Links  $links  Links.
	 * @param Events $events Events.
	 * @param Access $access Access.
	 */
	public function __construct( Links $links, Events $events, Access $access ) {
		$this->links  = $links;
		$this->events = $events;
		$this->access = $access;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_filter( 'request', array( $this, 'request' ), 1 );
		add_filter( 'wp_headers', array( $this, 'headers' ) );
		add_filter( 'redirect_canonical', array( $this, 'no_canonical' ) );
		add_action( 'template_redirect', array( $this, 'template_redirect' ), 0 );
		add_filter( 'the_content', array( $this, 'mark_blocks' ), 8 );
		add_filter( 'render_block', array( $this, 'annotate_block' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'comments_open', array( $this, 'comments_closed' ), 99, 2 );
		add_filter( 'wp_robots', array( $this, 'robots' ), 99 );
	}

	/**
	 * Adds the query var.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function query_vars( $vars ): array {
		$vars   = (array) $vars;
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Whether this request is a review request.
	 *
	 * @return bool
	 */
	public function active(): bool {
		return '' !== $this->state;
	}

	/**
	 * The current state, for tests and other code.
	 *
	 * @return string
	 */
	public function state(): string {
		return $this->state;
	}

	/**
	 * The link of this request, when it renders.
	 *
	 * @return array|null
	 */
	public function link(): ?array {
		return 'render' === $this->state ? $this->link : null;
	}

	/**
	 * Decides what the request shows, and points the main query at the post.
	 *
	 * @param array $query_vars Query vars.
	 * @return array
	 */
	public function request( $query_vars ): array {
		$query_vars = (array) $query_vars;
		if ( empty( $query_vars[ self::QUERY_VAR ] ) || ! is_string( $query_vars[ self::QUERY_VAR ] ) ) {
			return $query_vars;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The page cache convention.
		}

		$this->token = (string) $query_vars[ self::QUERY_VAR ];
		$this->link  = $this->links->find_by_token( $this->token );
		$this->decide();

		if ( 'render' !== $this->state || ! $this->link ) {
			return array( self::QUERY_VAR => $this->token );
		}

		$post = get_post( (int) $this->link['post_id'] );
		if ( ! $post ) {
			$this->deny( 'revoked' );
			return array( self::QUERY_VAR => $this->token );
		}

		$vars = array(
			self::QUERY_VAR => $this->token,
			'post_type'     => $post->post_type,
			'post_status'   => $post->post_status,
		);
		if ( 'page' === $post->post_type ) {
			$vars['page_id'] = (int) $post->ID;
		} else {
			$vars['p'] = (int) $post->ID;
		}

		return $vars;
	}

	/**
	 * Works out the state from the link and the session.
	 */
	private function decide(): void {
		$link = $this->link;
		if ( ! $link ) {
			$this->deny( 'unknown' );
			return;
		}

		if ( Settings::paused() ) {
			$this->deny( 'paused' );
			return;
		}

		$post = get_post( (int) $link['post_id'] );
		if ( ! $post || in_array( $post->post_status, Links::dead_statuses(), true ) ) {
			$this->deny( 'revoked' );
			return;
		}

		if ( in_array( $link['status'], array( 'expired', 'revoked' ), true ) ) {
			$this->deny( $link['status'] );
			return;
		}

		$session = $this->access->session( $link );
		if ( $session && ( '' === $link['email'] || $session['verified'] ) ) {
			$this->session = $session;
			$this->state   = 'render';
			$this->links->touch( (int) $link['id'] );
			return;
		}

		if ( 'used' === $link['status'] ) {
			$this->deny( 'used' );
			return;
		}

		if ( '' !== $link['email'] && ! $this->passed_code() ) {
			$this->state = 'code';
			return;
		}

		if ( $link['has_password'] ) {
			$this->state = 'password';
			return;
		}

		$this->open( false );
	}

	/**
	 * Counts the view and starts the session.
	 *
	 * @param bool $verified Whether the email code was checked.
	 */
	private function open( bool $verified ): void {
		$link = (array) $this->link;
		if ( ! $this->links->count_view( $link ) ) {
			$this->deny( 'used' );
			return;
		}

		$this->session = $this->access->start( $link, $verified || '' !== $link['email'] );
		$this->state   = 'render';
		$this->events->add( (int) $link['id'], 'open' );

		/**
		 * Fires when someone opened a review link and a session started.
		 *
		 * @param array $link    The link.
		 * @param array $session The session: id, expires, verified.
		 */
		do_action( 'axle_review_link_opened', $this->links->get( (int) $link['id'] ), $this->session );
	}

	/**
	 * Refuses access.
	 *
	 * @param string $reason unknown, expired, used, revoked or paused.
	 */
	private function deny( string $reason ): void {
		$this->state  = 'denied';
		$this->reason = $reason;

		if ( $this->link ) {
			$this->events->add( (int) $this->link['id'], 'denied' );
		}

		/**
		 * Fires when a review link was refused.
		 *
		 * @param string     $reason unknown, expired, used, revoked or paused.
		 * @param array|null $link   The link, null for an unknown token.
		 */
		do_action( 'axle_review_access_denied', $reason, $this->link );
	}

	/**
	 * Whether this visitor passed the email code (proof cookie from the code form).
	 *
	 * @return bool
	 */
	private function passed_code(): bool {
		if ( ! $this->link ) {
			return false;
		}

		$name = 'axr_step_' . (int) $this->link['id'];
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Review responses are never cached.
		$proof = isset( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) : '';

		return '' !== $proof && Access::check_step_proof( (int) $this->link['id'], $proof );
	}

	/**
	 * The no-store and noindex headers on every review response.
	 *
	 * @param array $headers Headers.
	 * @return array
	 */
	public function headers( $headers ): array {
		$headers = (array) $headers;
		if ( ! $this->active() ) {
			return $headers;
		}

		$review = array_merge(
			wp_get_nocache_headers(),
			array(
				'Cache-Control'   => 'private, no-store, no-cache, must-revalidate, max-age=0',
				'X-Robots-Tag'    => 'noindex, nofollow',
				'Referrer-Policy' => 'no-referrer',
			)
		);

		/**
		 * Filters the headers sent with review pages. Cache-Control and
		 * X-Robots-Tag are always kept.
		 *
		 * @param array $review Headers.
		 */
		$filtered = (array) apply_filters( 'axle_review_review_headers', $review );

		$filtered['Cache-Control'] = $review['Cache-Control'];
		$filtered['X-Robots-Tag']  = $review['X-Robots-Tag'];

		return array_merge( $headers, $filtered );
	}

	/**
	 * Keeps WordPress from redirecting the review URL to the post's own URL.
	 *
	 * @param string|false $url Redirect.
	 * @return string|false
	 */
	public function no_canonical( $url ) {
		return $this->active() ? false : $url;
	}

	/**
	 * Noindex in the robots meta tag too.
	 *
	 * @param array $robots Robots directives.
	 * @return array
	 */
	public function robots( $robots ): array {
		$robots = (array) $robots;
		if ( $this->active() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
			unset( $robots['index'], $robots['follow'], $robots['max-image-preview'] );
		}

		return $robots;
	}

	/**
	 * No comment form on a review page.
	 *
	 * @param bool $open    Open.
	 * @param int  $post_id Post ID.
	 * @return bool
	 */
	public function comments_closed( $open, $post_id ): bool {
		if ( $this->link() && (int) $post_id === (int) $this->link['post_id'] ) {
			return false;
		}

		return (bool) $open;
	}

	/**
	 * Gates, refusals and form posts. Rendering goes on to the theme.
	 */
	public function template_redirect(): void {
		if ( ! $this->active() ) {
			return;
		}

		nocache_headers();

		if ( 'render' === $this->state ) {
			if ( ! have_posts() ) {
				$this->deny( 'revoked' );
			} else {
				return;
			}
		}

		$error = '';
		$note  = '';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Logged-out form; checked with its own signed token below.
		$action = isset( $_POST['axr_action'] ) ? sanitize_key( wp_unslash( $_POST['axr_action'] ) ) : '';
		if ( $this->link && '' !== $action && in_array( $this->state, array( 'code', 'password' ), true ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Same.
			$token = isset( $_POST['axr_form'] ) ? sanitize_text_field( wp_unslash( $_POST['axr_form'] ) ) : '';
			if ( ! Access::check_form_token( (int) $this->link['id'], $token ) ) {
				$error = __( 'The form expired. Try again.', 'axle-review' );
			} else {
				$result = $this->handle_form( $action );
				if ( true === $result ) {
					wp_safe_redirect( Links::url( $this->token ) );
					exit;
				}
				if ( is_wp_error( $result ) ) {
					$error = $result->get_error_message();
				} else {
					$note = (string) $result;
				}
			}
		}

		$this->render_gate( $error, $note );
		exit;
	}

	/**
	 * Handles a gate form.
	 *
	 * @param string $action send_code, verify_code or password.
	 * @return true|string|\WP_Error True to redirect, a message to show, or an error.
	 */
	private function handle_form( string $action ) {
		$link = (array) $this->link;

		if ( 'send_code' === $action && 'code' === $this->state ) {
			$sent = $this->access->send_code( $link );
			return is_wp_error( $sent ) ? $sent : __( 'We sent a code to the email address this link is for. It works for a few minutes.', 'axle-review' );
		}

		if ( 'verify_code' === $action && 'code' === $this->state ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in template_redirect().
			$code   = isset( $_POST['axr_code'] ) ? sanitize_text_field( wp_unslash( $_POST['axr_code'] ) ) : '';
			$result = $this->access->check_code( $link, $code );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( $link['has_password'] ) {
				$this->set_step_cookie( (int) $link['id'] );
				return true;
			}

			$this->open( true );
			return 'render' === $this->state ? true : new \WP_Error( 'axle_review_used', self::denied_message( 'used' ) );
		}

		if ( 'password' === $action && 'password' === $this->state ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked in template_redirect(); a password is compared, never stored or shown.
			$password = isset( $_POST['axr_password'] ) ? (string) wp_unslash( $_POST['axr_password'] ) : '';
			$result   = $this->access->check_password( $link, $password );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$this->open( '' !== $link['email'] );
			return 'render' === $this->state ? true : new \WP_Error( 'axle_review_used', self::denied_message( 'used' ) );
		}

		return new \WP_Error( 'axle_review_bad_action', __( 'Something went wrong. Try again.', 'axle-review' ) );
	}

	/**
	 * Remembers that the email code was right, until the password is given.
	 *
	 * @param int $link_id Link ID.
	 */
	private function set_step_cookie( int $link_id ): void {
		if ( headers_sent() ) {
			return;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie -- Short-lived proof between the two gate forms.
		setcookie(
			'axr_step_' . $link_id,
			Access::step_proof( $link_id ),
			array(
				'expires'  => time() + 15 * MINUTE_IN_SECONDS,
				'path'     => '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? (string) COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * The message shown when a link does not open.
	 *
	 * @param string $reason unknown, expired, used, revoked or paused.
	 * @return string
	 */
	public static function denied_message( string $reason ): string {
		$messages = array(
			'unknown' => __( 'This review link does not work. Ask the person who sent it for a new one.', 'axle-review' ),
			'expired' => __( 'This review link has expired. Ask the person who sent it for a new one.', 'axle-review' ),
			'used'    => __( 'This review link was already used. It only opens a limited number of times. Ask the person who sent it for a new one.', 'axle-review' ),
			'revoked' => __( 'This review link was turned off. Ask the person who sent it for a new one.', 'axle-review' ),
			'paused'  => __( 'Review links on this site are paused for now. Try again later.', 'axle-review' ),
		);
		$message  = $messages[ $reason ] ?? $messages['unknown'];

		/**
		 * Filters the message on the page a refused review link shows.
		 *
		 * @param string $message Message.
		 * @param string $reason  unknown, expired, used, revoked or paused.
		 */
		$filtered = (string) apply_filters( 'axle_review_denied_message', $message, $reason );

		return '' !== trim( $filtered ) ? $filtered : $message;
	}

	/**
	 * Prints a gate or refusal page.
	 *
	 * @param string $error Error to show.
	 * @param string $note  Notice to show.
	 */
	private function render_gate( string $error, string $note ): void {
		$state = $this->state;
		status_header( 'denied' === $state ? 404 : 200 );
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );

		wp_register_style( 'axle-review-gate', plugins_url( 'assets/gate.css', PLUGIN_FILE ), array(), VERSION );

		$site    = (string) get_bloginfo( 'name' );
		$link_id = $this->link ? (int) $this->link['id'] : 0;
		$action  = Links::url( $this->token );
		$title   = __( 'Review link', 'axle-review' );
		if ( 'code' === $state ) {
			$title = __( 'Check your email', 'axle-review' );
		} elseif ( 'password' === $state ) {
			$title = __( 'Enter the password', 'axle-review' );
		} elseif ( 'denied' === $state ) {
			$title = __( 'This link does not open', 'axle-review' );
		}

		$this->print_gate_page( $state, $error, $note, $site, $link_id, $action, $title );
	}

	/**
	 * The page for the email code, the password, and refused links.
	 *
	 * @param string $state   code, password or denied.
	 * @param string $error   Error to show.
	 * @param string $note    Notice to show.
	 * @param string $site    Site name.
	 * @param int    $link_id Link ID, 0 when unknown.
	 * @param string $action  Form action URL.
	 * @param string $title   Page title.
	 */
	private function print_gate_page( string $state, string $error, string $note, string $site, int $link_id, string $action, string $title ): void {
		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title . ' | ' . $site ); ?></title>
		<?php wp_print_styles( array( 'axle-review-gate' ) ); ?>
</head>
<body class="axr-gate-body">
<main class="axr-gate" id="axr-gate">
	<p class="axr-gate__site"><?php echo esc_html( $site ); ?></p>
	<h1 class="axr-gate__title"><?php echo esc_html( $title ); ?></h1>

		<?php if ( '' !== $error ) : ?>
		<p class="axr-gate__error" role="alert"><?php echo esc_html( $error ); ?></p>
	<?php endif; ?>
		<?php if ( '' !== $note ) : ?>
		<p class="axr-gate__note" role="status"><?php echo esc_html( $note ); ?></p>
	<?php endif; ?>

		<?php if ( 'denied' === $state ) : ?>
		<p class="axr-gate__text"><?php echo esc_html( self::denied_message( $this->reason ) ); ?></p>
	<?php elseif ( 'code' === $state ) : ?>
		<p class="axr-gate__text"><?php esc_html_e( 'This link is for one email address. Get a one-time code sent there, then enter it here.', 'axle-review' ); ?></p>
		<form method="post" action="<?php echo esc_url( $action ); ?>" class="axr-gate__form">
			<input type="hidden" name="axr_action" value="send_code">
			<input type="hidden" name="axr_form" value="<?php echo esc_attr( Access::form_token( $link_id ) ); ?>">
			<button type="submit" class="axr-gate__button axr-gate__button--secondary"><?php esc_html_e( 'Email me a code', 'axle-review' ); ?></button>
		</form>
		<form method="post" action="<?php echo esc_url( $action ); ?>" class="axr-gate__form">
			<input type="hidden" name="axr_action" value="verify_code">
			<input type="hidden" name="axr_form" value="<?php echo esc_attr( Access::form_token( $link_id ) ); ?>">
			<label for="axr-code" class="axr-gate__label"><?php esc_html_e( 'Code', 'axle-review' ); ?></label>
			<input type="text" id="axr-code" name="axr_code" inputmode="numeric" autocomplete="one-time-code" maxlength="10" class="axr-gate__input" required>
			<button type="submit" class="axr-gate__button"><?php esc_html_e( 'Open the page', 'axle-review' ); ?></button>
		</form>
	<?php elseif ( 'password' === $state ) : ?>
		<p class="axr-gate__text"><?php esc_html_e( 'This link has a password. The person who sent it can tell you what it is.', 'axle-review' ); ?></p>
		<form method="post" action="<?php echo esc_url( $action ); ?>" class="axr-gate__form">
			<input type="hidden" name="axr_action" value="password">
			<input type="hidden" name="axr_form" value="<?php echo esc_attr( Access::form_token( $link_id ) ); ?>">
			<label for="axr-password" class="axr-gate__label"><?php esc_html_e( 'Password', 'axle-review' ); ?></label>
			<input type="password" id="axr-password" name="axr_password" autocomplete="current-password" class="axr-gate__input" required>
			<button type="submit" class="axr-gate__button"><?php esc_html_e( 'Open the page', 'axle-review' ); ?></button>
		</form>
	<?php endif; ?>
</main>
</body>
</html>
		<?php
	}

	/**
	 * Adds the block path and hash to each block of the reviewed post, for
	 * note mode.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public function mark_blocks( $content ): string {
		$content = (string) $content;
		$link    = $this->link();
		if ( ! $link || ! $link['allow_notes'] || (int) get_the_ID() !== (int) $link['post_id'] || ! has_blocks( $content ) ) {
			return $content;
		}

		return serialize_blocks( Anchor::mark( parse_blocks( $content ) ) );
	}

	/**
	 * Writes the path and hash onto the block's first tag.
	 *
	 * @param string $html  Block HTML.
	 * @param mixed  $block Parsed block.
	 * @return string
	 */
	public function annotate_block( $html, $block ): string {
		$html = (string) $html;
		if ( ! $this->link() || ! is_array( $block ) || ! isset( $block['attrs']['axrPath'] ) || '' === trim( $html ) ) {
			return $html;
		}

		$tags = new \WP_HTML_Tag_Processor( $html );
		if ( $tags->next_tag() ) {
			$tags->set_attribute( 'data-axr-block', (string) $block['attrs']['axrPath'] );
			$tags->set_attribute( 'data-axr-hash', (string) ( $block['attrs']['axrHash'] ?? '' ) );
			$tags->set_attribute( 'data-axr-name', (string) ( $block['blockName'] ?? '' ) );
			return $tags->get_updated_html();
		}

		return $html;
	}

	/**
	 * The strings the toolbar shows.
	 *
	 * @return array<string, string>
	 */
	public static function toolbar_strings(): array {
		$strings = array(
			'title'        => __( 'Review', 'axle-review' ),
			'leaveNote'    => __( 'Leave a note', 'axle-review' ),
			'stopNotes'    => __( 'Stop leaving notes', 'axle-review' ),
			'myNotes'      => __( 'View notes I left', 'axle-review' ),
			'noteMode'     => __( 'Click a block, or Tab to it and press Enter, to leave a note on it. Escape stops.', 'axle-review' ),
			'name'         => __( 'Your name', 'axle-review' ),
			'note'         => __( 'Your note', 'axle-review' ),
			'email'        => __( 'Your email (optional)', 'axle-review' ),
			'website'      => __( 'Leave this empty', 'axle-review' ),
			'missing'      => __( 'Write your name and a note first.', 'axle-review' ),
			'submit'       => __( 'Send note', 'axle-review' ),
			'cancel'       => __( 'Cancel', 'axle-review' ),
			'sent'         => __( 'Note sent. Thank you.', 'axle-review' ),
			'failed'       => __( 'The note could not be sent.', 'axle-review' ),
			'noNotes'      => __( 'You have not left any notes yet.', 'axle-review' ),
			'resolved'     => __( 'Resolved', 'axle-review' ),
			'open'         => __( 'Open', 'axle-review' ),
			'reply'        => __( 'Reply', 'axle-review' ),
			'close'        => __( 'Close', 'axle-review' ),
			'noBlocks'     => __( 'This page is not built with blocks, so notes on blocks are not available. You can still read it.', 'axle-review' ),
			'notesOff'     => __( 'Notes are turned off for this link. You can read the page.', 'axle-review' ),
			'quoted'       => __( 'About the text:', 'axle-review' ),
			/* translators: %s: reviewer name. */
			'reviewingAs'  => __( 'Reviewing as %s', 'axle-review' ),
			'notesHeading' => __( 'Your notes', 'axle-review' ),
		);

		/**
		 * Filters the strings on the review toolbar and note popover.
		 *
		 * @param array $strings Strings by key.
		 */
		$filtered = (array) apply_filters( 'axle_review_toolbar_strings', $strings );

		foreach ( $strings as $key => $default ) {
			$strings[ $key ] = isset( $filtered[ $key ] ) && is_string( $filtered[ $key ] ) && '' !== $filtered[ $key ] ? $filtered[ $key ] : $default;
		}

		return $strings;
	}

	/**
	 * The toolbar script and styles on a review page.
	 */
	public function enqueue(): void {
		$link = $this->link();
		if ( ! $link || ! $this->session ) {
			return;
		}

		$post   = get_post( (int) $link['post_id'] );
		$render = bin2hex( random_bytes( 8 ) );
		set_transient( 'axle_review_render_' . $render, time(), Access::session_ttl() );

		wp_enqueue_style( 'axle-review-guest', plugins_url( 'assets/review.css', PLUGIN_FILE ), array(), VERSION );
		wp_enqueue_script( 'axle-review-guest', plugins_url( 'assets/review.js', PLUGIN_FILE ), array( 'wp-hooks' ), VERSION, true );
		wp_localize_script(
			'axle-review-guest',
			'axleReviewGuest',
			array(
				'root'       => esc_url_raw( rest_url( 'axle-review/v1/guest/' ) ),
				'link'       => (int) $link['id'],
				'post'       => (int) $link['post_id'],
				'nonce'      => Access::nonce( (int) $link['id'], (string) $this->session['id'] ),
				'render'     => $render,
				'allowNotes' => (bool) $link['allow_notes'],
				'hasBlocks'  => $post ? has_blocks( (string) $post->post_content ) : false,
				'locked'     => '' !== $link['email'],
				'maxLength'  => Notes::max_length(),
				'strings'    => self::toolbar_strings(),
			)
		);
	}
}
