<?php
/**
 * REST routes for reviewers (no WordPress login).
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Rest;

use AryanX\Axle\Review\Access;
use AryanX\Axle\Review\Attacher;
use AryanX\Axle\Review\Events;
use AryanX\Axle\Review\Links;
use AryanX\Axle\Review\Notes;
use AryanX\Axle\Review\Service;
use AryanX\Axle\Review\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The guest routes under axle-review/v1/guest/: leave a note, read your own notes, reply to
 * them, and the toolbar ping that spots page caches.
 *
 * Each call needs the link's signed session cookie and the nonce bound to
 * that session. It never sets a current user.
 */
final class GuestRoutes implements Service {

	public const NS                = 'axle-review/v1';
	public const CACHE_WARN_OPTION = 'axle_review_cache_warning';

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
	 * Notes.
	 *
	 * @var Notes
	 */
	private Notes $notes;

	/**
	 * Attacher.
	 *
	 * @var Attacher
	 */
	private Attacher $attacher;

	/**
	 * The link and session of the current request, set by the permission check.
	 *
	 * @var array{link: array, session: array}|null
	 */
	private ?array $current = null;

	/**
	 * Sets up the routes.
	 *
	 * @param Links    $links    Links.
	 * @param Events   $events   Events.
	 * @param Access   $access   Access.
	 * @param Notes    $notes    Notes.
	 * @param Attacher $attacher Attacher.
	 */
	public function __construct( Links $links, Events $events, Access $access, Notes $notes, Attacher $attacher ) {
		$this->links    = $links;
		$this->events   = $events;
		$this->access   = $access;
		$this->notes    = $notes;
		$this->attacher = $attacher;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Registers the routes.
	 */
	public function routes(): void {
		$link = array(
			'type'     => 'integer',
			'required' => true,
			'minimum'  => 1,
		);

		register_rest_route(
			self::NS,
			'/guest/notes',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_notes' ),
					'permission_callback' => array( $this, 'check' ),
					'args'                => array( 'link' => $link ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_note' ),
					'permission_callback' => array( $this, 'check' ),
					'args'                => array(
						'link'    => $link,
						'text'    => array(
							'type'     => 'string',
							'required' => true,
						),
						'name'    => array(
							'type'    => 'string',
							'default' => '',
						),
						'email'   => array(
							'type'    => 'string',
							'default' => '',
						),
						'path'    => array(
							'type'    => 'string',
							'default' => '',
							'pattern' => '^[0-9.]*$',
						),
						'hash'    => array(
							'type'    => 'string',
							'default' => '',
							'pattern' => '^[a-f0-9]*$',
						),
						'block'   => array(
							'type'    => 'string',
							'default' => '',
						),
						'quote'   => array(
							'type'    => 'string',
							'default' => '',
						),
						'parent'  => array(
							'type'    => 'integer',
							'default' => 0,
						),
						'render'  => array(
							'type'    => 'string',
							'default' => '',
						),
						'website' => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/guest/ping',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'ping' ),
				'permission_callback' => array( $this, 'check' ),
				'args'                => array(
					'link'   => $link,
					'render' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Permission: a live link, its signed session and the session nonce.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function check( $request ) {
		$denied = new \WP_Error( 'axle_review_forbidden', __( 'This review session is not valid. Open the review link again.', 'axle-review' ), array( 'status' => 403 ) );

		if ( Settings::paused() ) {
			return $denied;
		}

		$link = $this->links->get( (int) $request->get_param( 'link' ) );
		if ( ! $link || in_array( $link['status'], array( 'expired', 'revoked' ), true ) ) {
			return $denied;
		}

		$post = get_post( (int) $link['post_id'] );
		if ( ! $post || in_array( $post->post_status, Links::dead_statuses(), true ) ) {
			return $denied;
		}

		$session = $this->access->session( $link );
		if ( ! $session || ( '' !== $link['email'] && ! $session['verified'] ) ) {
			return $denied;
		}

		$nonce = (string) $request->get_header( 'X-Axle-Review-Nonce' );
		if ( '' === $nonce || ! hash_equals( Access::nonce( (int) $link['id'], (string) $session['id'] ), $nonce ) ) {
			return $denied;
		}

		$this->current = array(
			'link'    => $link,
			'session' => $session,
		);

		return true;
	}

	/**
	 * How many notes one link may leave in an hour.
	 *
	 * @return int
	 */
	public static function hourly_limit(): int {
		/**
		 * Filters how many notes and replies one review link may send in an hour.
		 *
		 * @param int $limit Default 30, between 1 and 1000.
		 */
		return max( 1, min( 1000, (int) apply_filters( 'axle_review_notes_per_link_per_hour', 30 ) ) );
	}

	/**
	 * The fewest seconds between the page loading and a note, against bots.
	 *
	 * @return int
	 */
	public static function min_seconds(): int {
		/**
		 * Filters the fewest seconds between a review page loading and a note being sent.
		 *
		 * @param int $seconds Default 3, between 0 and 60.
		 */
		return max( 0, min( 60, (int) apply_filters( 'axle_review_min_seconds', 3 ) ) );
	}

	/**
	 * Creates a note or a reply.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_note( $request ) {
		$link    = $this->current['link'] ?? array();
		$session = $this->current['session'] ?? array();

		if ( empty( $link['allow_notes'] ) ) {
			return new \WP_Error( 'axle_review_notes_off', __( 'Notes are turned off for this link.', 'axle-review' ), array( 'status' => 403 ) );
		}

		// The honeypot: people never see this field.
		if ( '' !== trim( (string) $request->get_param( 'website' ) ) ) {
			return new \WP_Error( 'axle_review_spam', __( 'The note could not be sent.', 'axle-review' ), array( 'status' => 400 ) );
		}

		$render  = preg_replace( '/[^a-f0-9]/', '', (string) $request->get_param( 'render' ) );
		$started = $render ? (int) get_transient( 'axle_review_render_' . $render ) : 0;
		if ( ! $started || time() - $started < self::min_seconds() ) {
			return new \WP_Error( 'axle_review_too_fast', __( 'That was quick. Wait a moment and send it again.', 'axle-review' ), array( 'status' => 429 ) );
		}

		if ( $this->events->count_since( (int) $link['id'], 'note', time() - HOUR_IN_SECONDS ) >= self::hourly_limit() ) {
			return new \WP_Error( 'axle_review_rate_limited', __( 'Too many notes from this link in the last hour. Try again later.', 'axle-review' ), array( 'status' => 429 ) );
		}

		$parent = (int) $request->get_param( 'parent' );
		if ( $parent && ! $this->owns_thread( $parent, $link, $session ) ) {
			return new \WP_Error( 'axle_review_not_yours', __( 'You can only reply to your own notes.', 'axle-review' ), array( 'status' => 403 ) );
		}

		$verified = '' !== $link['email'] && ! empty( $session['verified'] );
		$email    = $verified ? (string) $link['email'] : sanitize_email( (string) $request->get_param( 'email' ) );

		$id = $this->notes->create_guest_note(
			array(
				'post_id'  => (int) $link['post_id'],
				'link_id'  => (int) $link['id'],
				'session'  => (string) $session['id'],
				'text'     => (string) $request->get_param( 'text' ),
				'name'     => (string) $request->get_param( 'name' ),
				'email'    => $email,
				'verified' => $verified,
				'parent'   => $parent,
				'anchor'   => array(
					'path'      => (string) $request->get_param( 'path' ),
					'hash'      => (string) $request->get_param( 'hash' ),
					'blockName' => sanitize_text_field( (string) $request->get_param( 'block' ) ),
					'quote'     => (string) $request->get_param( 'quote' ),
				),
			)
		);

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$this->events->add( (int) $link['id'], 'note', $id );
		$note = Notes::get( $id );

		/**
		 * Fires after a reviewer left a note or a reply through a link. It is a
		 * core Note now, not yet linked to its block.
		 *
		 * @param \WP_Comment $note The note.
		 * @param array       $link The link.
		 */
		do_action( 'axle_review_guest_note_created', $note, $link );

		$attached = null;
		if ( ! $parent ) {
			$attached = $this->attacher->attach( $id );
			if ( ! is_wp_error( $attached ) ) {
				/**
				 * Fires after a reviewer's note was linked to its block in post_content.
				 *
				 * @param \WP_Comment $note     The note.
				 * @param array       $attached path, orphan.
				 */
				do_action( 'axle_review_guest_note_attached', Notes::get( $id ), $attached );
			}
		}

		return rest_ensure_response(
			array(
				'id'       => $id,
				'attached' => is_array( $attached ) ? $attached : null,
				'note'     => $note ? $this->guest_view( $note ) : null,
			)
		);
	}

	/**
	 * Whether a thread was started in this session, or by this verified email.
	 *
	 * @param int   $note_id Thread ID.
	 * @param array $link    Link.
	 * @param array $session Session.
	 * @return bool
	 */
	private function owns_thread( int $note_id, array $link, array $session ): bool {
		$note = Notes::get( $note_id );
		if ( ! $note || 0 !== (int) $note->comment_parent || (int) $note->comment_post_ID !== (int) $link['post_id'] ) {
			return false;
		}

		if ( hash_equals( (string) get_comment_meta( $note_id, '_axr_session', true ), (string) $session['id'] ) ) {
			return true;
		}

		return '' !== $link['email'] && ! empty( $session['verified'] )
			&& strtolower( (string) $note->comment_author_email ) === $link['email']
			&& (bool) get_comment_meta( $note_id, '_axr_email_verified', true );
	}

	/**
	 * The reviewer's own threads with their replies; with sharing on, other
	 * reviewers' threads too. Editors' own threads never.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_notes( $request ) {
		unset( $request );
		$link    = $this->current['link'] ?? array();
		$session = $this->current['session'] ?? array();
		$shared  = Settings::guest_sees_others( (bool) ( $link['share_notes'] ?? false ), (int) ( $link['id'] ?? 0 ) );

		$threads = get_comments(
			array(
				'type'       => 'note',
				'post_id'    => (int) $link['post_id'],
				'parent'     => 0,
				'status'     => array( 'hold', 'approve' ),
				'number'     => 200,
				'orderby'    => 'comment_date_gmt',
				'order'      => 'ASC',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Few notes per post.
					array(
						'key'     => '_axr_link_id',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$items = array();
		$seen  = false;
		foreach ( (array) $threads as $thread ) {
			if ( ! $thread instanceof \WP_Comment ) {
				continue;
			}

			$mine = $this->owns_thread( (int) $thread->comment_ID, $link, $session );
			if ( ! $mine && ! $shared ) {
				continue;
			}

			$view            = $this->guest_view( $thread );
			$view['mine']    = $mine;
			$view['replies'] = array();
			foreach ( $this->notes->replies( (int) $thread->comment_ID ) as $reply ) {
				if ( Notes::is_activity( $reply ) ) {
					continue;
				}
				$view['replies'][] = $this->guest_view( $reply );
				if ( $mine && (int) $reply->user_id ) {
					$seen = true;
				}
			}
			$items[] = $view;
		}

		if ( $seen ) {
			$this->events->add( (int) $link['id'], 'reply_seen' );
		}

		return rest_ensure_response( $items );
	}

	/**
	 * What a reviewer may see of a note: no emails, no user IDs.
	 *
	 * @param \WP_Comment $note Note.
	 * @return array
	 */
	private function guest_view( \WP_Comment $note ): array {
		return array(
			'id'     => (int) $note->comment_ID,
			'author' => (string) $note->comment_author,
			'editor' => (int) $note->user_id > 0,
			'text'   => trim( html_entity_decode( wp_strip_all_tags( (string) $note->comment_content ), ENT_QUOTES, 'UTF-8' ) ),
			'status' => '1' === (string) $note->comment_approved ? 'resolved' : 'open',
			'date'   => mysql_to_rfc3339( (string) $note->comment_date_gmt ),
			'path'   => (string) ( Notes::anchor( (int) $note->comment_ID )['path'] ?? '' ),
		);
	}

	/**
	 * The toolbar's ping. Each page render gets its own ID; seeing one twice,
	 * or one never issued, means a page cache served the review page.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ping( $request ) {
		$render = preg_replace( '/[^a-f0-9]/', '', (string) $request->get_param( 'render' ) );
		$key    = 'axle_review_render_' . $render;
		$seen   = 'axle_review_pinged_' . $render;
		$cached = '' === $render || ! get_transient( $key ) || get_transient( $seen );

		if ( $cached ) {
			update_option( self::CACHE_WARN_OPTION, time(), false );
		} else {
			set_transient( $seen, 1, Access::session_ttl() );
		}

		$response = rest_ensure_response( array( 'cached' => $cached ) );
		$response->header( 'Cache-Control', 'private, no-store' );

		return $response;
	}
}
