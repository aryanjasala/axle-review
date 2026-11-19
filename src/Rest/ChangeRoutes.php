<?php
/**
 * Editor routes for changes to live pages.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Rest;

use AryanX\Axle\Review\Changes;
use AryanX\Axle\Review\Service;

defined( 'ABSPATH' ) || exit;

/**
 * The routes axle-review/v1/changes (state, ask for review of a change),
 * /changes/publish, /changes/discard and /changes/undo, for signed-in users.
 * Each route checks the capability for the post it touches.
 */
final class ChangeRoutes implements Service {

	/**
	 * Working copies.
	 *
	 * @var Changes
	 */
	private Changes $changes;

	/**
	 * Sets up the routes.
	 *
	 * @param Changes $changes Working copies.
	 */
	public function __construct( Changes $changes ) {
		$this->changes = $changes;
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
		$post      = array(
			'type'     => 'integer',
			'required' => true,
			'minimum'  => 1,
		);
		$overwrite = array(
			'type'    => 'boolean',
			'default' => false,
		);

		register_rest_route(
			GuestRoutes::NS,
			'/changes',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'state' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => array( 'post' => $post ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'request' ),
					'permission_callback' => array( $this, 'can_request' ),
					'args'                => array( 'post' => $post ),
				),
			)
		);

		register_rest_route(
			GuestRoutes::NS,
			'/changes/publish',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'publish' ),
				'permission_callback' => array( $this, 'can_publish' ),
				'args'                => array(
					'post'      => $post,
					'overwrite' => $overwrite,
				),
			)
		);

		register_rest_route(
			GuestRoutes::NS,
			'/changes/discard',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'discard' ),
				'permission_callback' => array( $this, 'can_discard' ),
				'args'                => array( 'post' => $post ),
			)
		);

		register_rest_route(
			GuestRoutes::NS,
			'/changes/undo',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'undo' ),
				'permission_callback' => array( $this, 'can_undo' ),
				'args'                => array(
					'post'      => $post,
					'overwrite' => $overwrite,
				),
			)
		);
	}

	/**
	 * Permission: may edit the post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_edit( $request ): bool {
		return current_user_can( 'edit_post', (int) $request->get_param( 'post' ) );
	}

	/**
	 * Permission: may ask for review of a change to the post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_request( $request ): bool {
		return Changes::can_request( (int) $request->get_param( 'post' ) );
	}

	/**
	 * Permission: may publish the change in the copy.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_publish( $request ): bool {
		return Changes::can_publish( (int) $request->get_param( 'post' ) );
	}

	/**
	 * Permission: may discard the copy.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_discard( $request ): bool {
		$id = (int) $request->get_param( 'post' );
		return Changes::is_copy( $id ) && current_user_can( 'delete_post', $id );
	}

	/**
	 * Permission: may undo the last change of the post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_undo( $request ): bool {
		return Changes::can_undo( (int) $request->get_param( 'post' ) );
	}

	/**
	 * What the editor panel needs to know about the post's changes.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function state( $request ) {
		$post = get_post( (int) $request->get_param( 'post' ) );
		if ( ! $post ) {
			return new \WP_Error( 'axle_review_no_post', __( 'That post does not exist.', 'axle-review' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $this->changes->state( $post ) );
	}

	/**
	 * Makes a working copy.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function request( $request ) {
		$result = $this->changes->request( (int) $request->get_param( 'post' ), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = rest_ensure_response(
			array(
				'copy'     => $result['copy'],
				'original' => $result['original'],
				'editUrl'  => (string) get_edit_post_link( $result['copy'], 'raw' ),
			)
		);
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Publishes the change.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function publish( $request ) {
		$result = $this->changes->publish( (int) $request->get_param( 'post' ), get_current_user_id(), (bool) $request->get_param( 'overwrite' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['editUrl'] = (string) get_edit_post_link( $result['original'], 'raw' );
		$result['url']     = (string) get_permalink( $result['original'] );

		return rest_ensure_response( $result );
	}

	/**
	 * Discards a copy.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function discard( $request ) {
		$copy     = (int) $request->get_param( 'post' );
		$original = Changes::original_of( $copy );
		$result   = $this->changes->discard( $copy, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'discarded' => true,
				'editUrl'   => $original && current_user_can( 'edit_post', $original ) ? (string) get_edit_post_link( $original, 'raw' ) : '',
			)
		);
	}

	/**
	 * Undoes the last published change.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function undo( $request ) {
		$result = $this->changes->undo( (int) $request->get_param( 'post' ), get_current_user_id(), (bool) $request->get_param( 'overwrite' ) );

		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'undone' => true ) );
	}
}
