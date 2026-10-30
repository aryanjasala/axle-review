<?php
/**
 * The resolve-before-publish gate.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review;

defined( 'ABSPATH' ) || exit;

/**
 * When the gate is on, a post with open top-level notes cannot move to
 * publish or future. REST saves (the block editor) get the error
 * axle_review_unresolved_notes; other saves (classic editor, code) keep their
 * old status and the user sees a notice.
 *
 * Someone with the override capability can publish anyway after giving a
 * reason, which is stored as a reply on every open note. Scheduled posts are
 * checked when they are scheduled, not again when cron publishes them.
 */
final class Gate implements Service {

	public const ERROR_CODE = 'axle_review_unresolved_notes';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Notes.
	 *
	 * @var Notes
	 */
	private Notes $notes;

	/**
	 * Sets up the gate.
	 *
	 * @param Settings $settings Settings.
	 * @param Notes    $notes    Notes.
	 */
	public function __construct( Settings $settings, Notes $notes ) {
		$this->settings = $settings;
		$this->notes    = $notes;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_filter( 'wp_insert_post_data', array( $this, 'hold_status' ), 20, 2 );
		add_action( 'init', array( $this, 'rest_hooks' ), 99 );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'transition_post_status', array( $this, 'clear_override' ), 10, 3 );
	}

	/**
	 * Hooks the REST check for every post type the gate can cover.
	 */
	public function rest_hooks(): void {
		foreach ( Settings::post_types() as $type ) {
			add_filter( 'rest_pre_insert_' . $type, array( $this, 'rest_check' ), 10, 2 );
		}
	}

	/**
	 * Whether the gate applies to a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public function enabled_for( \WP_Post $post ): bool {
		$settings = $this->settings->all();
		$types    = $settings['gate_post_types'] ? $settings['gate_post_types'] : Settings::post_types();
		$enabled  = $settings['gate_enabled'] && in_array( $post->post_type, $types, true ) && in_array( $post->post_type, Settings::post_types(), true );

		/**
		 * Filters whether the publish gate applies to a post.
		 *
		 * @param bool     $enabled From the settings.
		 * @param \WP_Post $post    The post.
		 */
		return (bool) apply_filters( 'axle_review_gate_enabled', $enabled, $post );
	}

	/**
	 * The open notes that block a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return \WP_Comment[]
	 */
	public function blocking( \WP_Post $post ): array {
		$settings = $this->settings->all();
		$open     = $this->notes->unresolved( (int) $post->ID );
		if ( ! $open ) {
			return array();
		}

		$linked = Anchor::all_note_ids( parse_blocks( (string) $post->post_content ) );
		$found  = array();
		foreach ( $open as $note ) {
			$counts = true;
			if ( ! $settings['gate_guest_notes'] && Notes::is_guest( $note ) ) {
				$counts = false;
			}
			if ( ! $settings['gate_orphans'] && ! in_array( (int) $note->comment_ID, $linked, true ) ) {
				$counts = false;
			}

			/**
			 * Filters whether one open note holds back publishing.
			 *
			 * @param bool        $counts From the settings (guest notes, notes whose block is gone).
			 * @param \WP_Comment $note   The note.
			 * @param \WP_Post    $post   The post.
			 */
			if ( apply_filters( 'axle_review_gate_counts_note', $counts, $note, $post ) ) {
				$found[] = $note;
			}
		}

		return $found;
	}

	/**
	 * Whether publishing a post is held back, and why.
	 *
	 * @param \WP_Post $post Post.
	 * @return array{enabled: bool, blocks: bool, notes: \WP_Comment[], open: int}
	 */
	public function readiness( \WP_Post $post ): array {
		$enabled = $this->enabled_for( $post );
		$notes   = $this->blocking( $post );

		return array(
			'enabled' => $enabled,
			'blocks'  => $enabled && count( $notes ) > 0,
			'notes'   => $notes,
			'open'    => $this->notes->unresolved_count( (int) $post->ID ),
		);
	}

	/**
	 * The message shown when publishing is held back.
	 *
	 * @param int      $count Open notes.
	 * @param \WP_Post $post  Post.
	 * @return string
	 */
	public static function message( int $count, \WP_Post $post ): string {
		$message = sprintf(
			/* translators: %d: number of open notes. */
			_n( 'This post has %d open note. Resolve it before publishing.', 'This post has %d open notes. Resolve them before publishing.', $count, 'axle-review' ),
			$count
		);

		/**
		 * Filters the message shown when open notes hold back publishing.
		 *
		 * @param string   $message Message.
		 * @param int      $count   Open notes.
		 * @param \WP_Post $post    The post.
		 */
		$filtered = (string) apply_filters( 'axle_review_gate_message', $message, $count, $post );

		return '' !== trim( $filtered ) ? $filtered : $message;
	}

	/**
	 * Whether a status change publishes.
	 *
	 * @param string $from Old status.
	 * @param string $to   New status.
	 * @return bool
	 */
	public static function publishes( string $from, string $to ): bool {
		$live = array( 'publish', 'future' );
		return in_array( $to, $live, true ) && ! in_array( $from, $live, true );
	}

	/**
	 * The REST check: refuses a save that would publish with open notes.
	 *
	 * @param \stdClass|\WP_Error $prepared Prepared post.
	 * @param \WP_REST_Request    $request  Request.
	 * @return \stdClass|\WP_Error
	 */
	public function rest_check( $prepared, $request ) {
		unset( $request );
		if ( is_wp_error( $prepared ) || empty( $prepared->ID ) || empty( $prepared->post_status ) ) {
			return $prepared;
		}

		$post = get_post( (int) $prepared->ID );
		if ( ! $post ) {
			return $prepared;
		}

		$error = $this->check( $post, (string) $prepared->post_status );

		return $error ? $error : $prepared;
	}

	/**
	 * The check itself.
	 *
	 * @param \WP_Post $post Post as stored.
	 * @param string   $to   Status it moves to.
	 * @return \WP_Error|null An error when held back.
	 */
	public function check( \WP_Post $post, string $to ): ?\WP_Error {
		if ( ! self::publishes( (string) $post->post_status, $to ) || ! $this->enabled_for( $post ) ) {
			return null;
		}

		$blocking = $this->blocking( $post );
		if ( ! $blocking ) {
			return null;
		}

		if ( $this->has_override( (int) $post->ID ) ) {
			return null;
		}

		/**
		 * Fires when open notes held back publishing a post.
		 *
		 * @param \WP_Post      $post  The post.
		 * @param \WP_Comment[] $notes The open notes.
		 * @param string        $to    The status it was moving to.
		 */
		do_action( 'axle_review_gate_blocked', $post, $blocking, $to );

		return new \WP_Error(
			self::ERROR_CODE,
			self::message( count( $blocking ), $post ),
			array(
				'status' => 409,
				'notes'  => array_map( static fn( $note ) => (int) $note->comment_ID, $blocking ),
			)
		);
	}

	/**
	 * Non-REST saves: keeps the old status when the gate holds the post back.
	 *
	 * @param array $data    Slashed post data.
	 * @param array $postarr Raw post array.
	 * @return array
	 */
	public function hold_status( $data, $postarr ): array {
		$data = (array) $data;
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $data;
		}

		$post_id = (int) ( $postarr['ID'] ?? 0 );
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return $data;
		}

		$error = $this->check( $post, (string) ( $data['post_status'] ?? '' ) );
		if ( ! $error ) {
			return $data;
		}

		$data['post_status'] = in_array( $post->post_status, array( 'auto-draft', 'inherit' ), true ) ? 'draft' : $post->post_status;
		if ( get_current_user_id() ) {
			set_transient( 'axle_review_gate_notice_' . get_current_user_id(), $error->get_error_message(), MINUTE_IN_SECONDS );
		}

		return $data;
	}

	/**
	 * Tells a classic-editor user why the post was not published.
	 */
	public function notice(): void {
		$key     = 'axle_review_gate_notice_' . get_current_user_id();
		$message = get_transient( $key );
		if ( ! is_string( $message ) || '' === $message ) {
			return;
		}

		delete_transient( $key );
		printf( '<div class="notice notice-error axr-gate-notice"><p>%s</p></div>', esc_html( $message ) );
	}

	/**
	 * Lets a user publish past the gate for 10 minutes, storing the reason as a
	 * reply on every open note.
	 *
	 * @param \WP_Post $post    Post.
	 * @param int      $user_id User.
	 * @param string   $reason  Why.
	 * @return true|\WP_Error
	 */
	public function override( \WP_Post $post, int $user_id, string $reason ) {
		if ( ! user_can( $user_id, Settings::gate_override_capability() ) || ! user_can( $user_id, 'edit_post', (int) $post->ID ) ) {
			return new \WP_Error( 'axle_review_cannot_override', __( 'You cannot publish past open notes.', 'axle-review' ), array( 'status' => 403 ) );
		}

		$reason = trim( sanitize_textarea_field( $reason ) );
		if ( '' === $reason ) {
			return new \WP_Error( 'axle_review_reason_required', __( 'Give a reason for publishing with open notes.', 'axle-review' ), array( 'status' => 400 ) );
		}

		$notes = $this->blocking( $post );
		foreach ( $notes as $note ) {
			$this->notes->add_user_note(
				(int) $post->ID,
				$user_id,
				esc_html(
					sprintf(
						/* translators: %s: reason. */
						__( 'Published with this note open. Reason: %s', 'axle-review' ),
						$reason
					)
				),
				(int) $note->comment_ID
			);
		}

		set_transient( 'axle_review_override_' . (int) $post->ID, $user_id, 10 * MINUTE_IN_SECONDS );

		/**
		 * Fires after someone chose to publish past the gate.
		 *
		 * @param \WP_Post      $post    The post.
		 * @param int           $user_id Who.
		 * @param string        $reason  Why.
		 * @param \WP_Comment[] $notes   The open notes the reason went on.
		 */
		do_action( 'axle_review_gate_overridden', $post, $user_id, $reason, $notes );

		return true;
	}

	/**
	 * Whether an override is active for the post and the current user.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function has_override( int $post_id ): bool {
		$user = (int) get_transient( 'axle_review_override_' . $post_id );
		return $user > 0 && get_current_user_id() === $user;
	}

	/**
	 * Ends the override once the post is published.
	 *
	 * @param string $to   New status.
	 * @param string $from Old status.
	 * @param mixed  $post Post.
	 */
	public function clear_override( $to, $from, $post ): void {
		if ( $post instanceof \WP_Post && in_array( $to, array( 'publish', 'future' ), true ) ) {
			delete_transient( 'axle_review_override_' . (int) $post->ID );
		}
	}
}
