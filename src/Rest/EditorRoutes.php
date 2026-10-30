<?php
/**
 * REST routes for the editor panels.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Rest;

use AryanX\Axle\Review\Alerts;
use AryanX\Axle\Review\Anchor;
use AryanX\Axle\Review\Attacher;
use AryanX\Axle\Review\Events;
use AryanX\Axle\Review\Gate;
use AryanX\Axle\Review\Links;
use AryanX\Axle\Review\Notes;
use AryanX\Axle\Review\Service;
use AryanX\Axle\Review\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The editor routes axle-review/v1/links, /notes/pending and /gate, for signed-in users with
 * the usual REST nonce.
 */
final class EditorRoutes implements Service {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

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
	 * Attacher.
	 *
	 * @var Attacher
	 */
	private Attacher $attacher;

	/**
	 * Gate.
	 *
	 * @var Gate
	 */
	private Gate $gate;

	/**
	 * Sets up the routes.
	 *
	 * @param Settings $settings Settings.
	 * @param Links    $links    Links.
	 * @param Events   $events   Events.
	 * @param Attacher $attacher Attacher.
	 * @param Gate     $gate     Gate.
	 */
	public function __construct( Settings $settings, Links $links, Events $events, Attacher $attacher, Gate $gate ) {
		$this->settings = $settings;
		$this->links    = $links;
		$this->events   = $events;
		$this->attacher = $attacher;
		$this->gate     = $gate;
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
		$post = array(
			'type'     => 'integer',
			'required' => true,
			'minimum'  => 1,
		);
		$ns   = GuestRoutes::NS;

		register_rest_route(
			$ns,
			'/links',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_links' ),
					'permission_callback' => array( $this, 'can_share_post' ),
					'args'                => array( 'post' => $post ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_link' ),
					'permission_callback' => array( $this, 'can_share_post' ),
					'args'                => array(
						'post'        => $post,
						'label'       => array(
							'type'    => 'string',
							'default' => '',
						),
						'expires'     => array(
							'type'    => 'integer',
							'default' => 0,
						),
						'max_views'   => array(
							'type'    => 'integer',
							'default' => 0,
							'minimum' => 0,
						),
						'email'       => array(
							'type'    => 'string',
							'default' => '',
						),
						'password'    => array(
							'type'    => 'string',
							'default' => '',
						),
						'allow_notes' => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'share_notes' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'send_to'     => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/links/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'revoke_link' ),
				'permission_callback' => array( $this, 'can_manage_link' ),
			)
		);

		register_rest_route(
			$ns,
			'/links/(?P<id>\d+)/activity',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'activity' ),
				'permission_callback' => array( $this, 'can_manage_link' ),
			)
		);

		register_rest_route(
			$ns,
			'/links/revoke-all',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'revoke_all' ),
				'permission_callback' => array( $this, 'can_share_post' ),
				'args'                => array( 'post' => $post ),
			)
		);

		register_rest_route(
			$ns,
			'/notes/pending',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'pending' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array( 'post' => $post ),
			)
		);

		register_rest_route(
			$ns,
			'/gate',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'readiness' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array( 'post' => $post ),
			)
		);

		register_rest_route(
			$ns,
			'/gate/override',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'override' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array(
					'post'   => $post,
					'reason' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Permission: may share this post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_share_post( $request ): bool {
		return Settings::can_share( (int) $request->get_param( 'post' ) );
	}

	/**
	 * Permission: may edit this post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_edit_post( $request ): bool {
		return current_user_can( 'edit_post', (int) $request->get_param( 'post' ) );
	}

	/**
	 * Permission: may share the post of this link.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_manage_link( $request ): bool {
		$link = $this->links->get( (int) $request->get_param( 'id' ) );
		return $link && Settings::can_share( (int) $link['post_id'] );
	}

	/**
	 * Links of a post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_links( $request ) {
		$found  = $this->links->query(
			array(
				'post_id' => (int) $request->get_param( 'post' ),
				'limit'   => 100,
			)
		);
		$counts = $this->events->note_counts( array_column( $found['items'], 'id' ) );

		return rest_ensure_response(
			array(
				'links'   => array_map(
					static fn( $link ) => Links::to_public( $link, $counts[ $link['id'] ] ?? 0 ),
					$found['items']
				),
				'paused'  => Settings::paused(),
				'expiry'  => Settings::expiry_choices(),
				'default' => array(
					'expires'   => $this->settings->default_expiry(),
					'max_views' => $this->settings->default_max_views(),
					'share'     => (bool) $this->settings->get( 'guest_sees_others' ),
				),
			)
		);
	}

	/**
	 * Creates a link and returns its URL, once.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_link( $request ) {
		$created = $this->links->create(
			array(
				'post_id'     => (int) $request->get_param( 'post' ),
				'label'       => (string) $request->get_param( 'label' ),
				'expires'     => (int) $request->get_param( 'expires' ) ? (int) $request->get_param( 'expires' ) : $this->settings->default_expiry(),
				'max_views'   => (int) $request->get_param( 'max_views' ),
				'email'       => (string) $request->get_param( 'email' ),
				'password'    => (string) $request->get_param( 'password' ),
				'allow_notes' => (bool) $request->get_param( 'allow_notes' ),
				'share_notes' => (bool) $request->get_param( 'share_notes' ),
			)
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$emailed = false;
		$send_to = sanitize_email( (string) $request->get_param( 'send_to' ) );
		if ( '' !== $send_to && is_email( $send_to ) ) {
			$emailed = self::email_link( $send_to, $created['url'], $created['link'] );
		}

		$response = rest_ensure_response(
			array(
				'url'     => $created['url'],
				'link'    => Links::to_public( $created['link'] ),
				'emailed' => $emailed,
			)
		);
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Emails a new link to someone.
	 *
	 * @param string $to   Address.
	 * @param string $url  Link URL.
	 * @param array  $link Link.
	 * @return bool
	 */
	public static function email_link( string $to, string $url, array $link ): bool {
		$post  = get_post( (int) $link['post_id'] );
		$user  = wp_get_current_user();
		$site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$title = $post ? wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ) : '';

		/* translators: 1: site name, 2: post title. */
		$subject = sprintf( __( '[%1$s] Please review "%2$s"', 'axle-review' ), $site, $title );
		$message = sprintf(
			/* translators: 1: sender name, 2: post title, 3: link, 4: expiry date. */
			__( "%1\$s asked you to review \"%2\$s\" before it goes live.\n\nOpen it here:\n%3\$s\n\nThe link works until %4\$s. You can leave notes on any part of the page.", 'axle-review' ),
			$user->exists() ? $user->display_name : $site,
			$title,
			$url,
			wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), (int) strtotime( (string) $link['expires_at'] . ' UTC' ) )
		);

		$context = array(
			'link' => $link,
			'url'  => $url,
		);

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- One email, sent by an editor to a reviewer.
		return (bool) wp_mail( $to, Alerts::subject( $subject, 'link', $context ), Alerts::message( $message, 'link', $context ) );
	}

	/**
	 * Revokes a link.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function revoke_link( $request ) {
		$id = (int) $request->get_param( 'id' );
		$this->links->revoke( $id, get_current_user_id() );
		$link = $this->links->get( $id );

		return rest_ensure_response( array( 'link' => $link ? Links::to_public( $link ) : null ) );
	}

	/**
	 * Revokes every link of a post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function revoke_all( $request ) {
		return rest_ensure_response( array( 'revoked' => $this->links->revoke_for_post( (int) $request->get_param( 'post' ), get_current_user_id() ) ) );
	}

	/**
	 * A link's activity.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function activity( $request ) {
		return rest_ensure_response( $this->events->for_link( (int) $request->get_param( 'id' ), 100 ) );
	}

	/**
	 * Guest notes the open editor should put on their blocks.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function pending( $request ) {
		$post_id = (int) $request->get_param( 'post' );
		$post    = get_post( $post_id );
		$blocks  = $post ? parse_blocks( (string) $post->post_content ) : array();
		$items   = array();

		foreach ( $this->attacher->pending( $post_id ) as $note ) {
			$id      = (int) $note->comment_ID;
			$items[] = array(
				'id'    => $id,
				'path'  => (string) Anchor::find_note( $blocks, $id ),
				'shape' => Anchor::shape(),
			);
		}

		return rest_ensure_response( $items );
	}

	/**
	 * Whether the gate would hold the post back, with the open notes.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function readiness( $request ) {
		$post = get_post( (int) $request->get_param( 'post' ) );
		if ( ! $post ) {
			return new \WP_Error( 'axle_review_no_post', __( 'That post does not exist.', 'axle-review' ), array( 'status' => 404 ) );
		}

		$ready  = $this->gate->readiness( $post );
		$blocks = parse_blocks( (string) $post->post_content );

		return rest_ensure_response(
			array(
				'enabled'     => $ready['enabled'],
				'blocks'      => $ready['blocks'],
				'open'        => $ready['open'],
				'message'     => $ready['blocks'] ? Gate::message( count( $ready['notes'] ), $post ) : '',
				'canOverride' => current_user_can( Settings::gate_override_capability() ),
				'override'    => $this->gate->has_override( (int) $post->ID ),
				'notes'       => array_map(
					static function ( $note ) use ( $blocks ) {
						$item         = Notes::to_array( $note );
						$item['path'] = (string) Anchor::find_note( $blocks, (int) $note->comment_ID );
						return $item;
					},
					$ready['notes']
				),
			)
		);
	}

	/**
	 * Publishes past the gate with a reason.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function override( $request ) {
		$post = get_post( (int) $request->get_param( 'post' ) );
		if ( ! $post ) {
			return new \WP_Error( 'axle_review_no_post', __( 'That post does not exist.', 'axle-review' ), array( 'status' => 404 ) );
		}

		$result = $this->gate->override( $post, get_current_user_id(), (string) $request->get_param( 'reason' ) );

		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'override' => true ) );
	}
}
