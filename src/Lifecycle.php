<?php
/**
 * What ends links, and the daily clean-up.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review;

defined( 'ABSPATH' ) || exit;

/**
 * Links die when their post is published, made private or trashed, when the
 * post is deleted and when their creator is deleted. Old activity is pruned
 * daily. Cached note counts are dropped whenever a note changes.
 */
final class Lifecycle implements Service {

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
	 * Sets up the lifecycle hooks.
	 *
	 * @param Links  $links  Links.
	 * @param Events $events Events.
	 */
	public function __construct( Links $links, Events $events ) {
		$this->links  = $links;
		$this->events = $events;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'transition_post_status', array( $this, 'on_status' ), 10, 3 );
		add_action( 'deleted_post', array( $this, 'on_deleted_post' ) );
		add_action( 'delete_user', array( $this, 'on_delete_user' ) );
		add_action( Schema::PRUNE_HOOK, array( $this, 'prune' ) );

		foreach ( array( 'wp_insert_comment', 'edit_comment', 'trashed_comment', 'untrashed_comment', 'deleted_comment' ) as $hook ) {
			add_action( $hook, array( $this, 'flush' ) );
		}
		add_action( 'wp_set_comment_status', array( $this, 'flush' ) );
		add_action( 'save_post', array( $this, 'flush' ) );
	}

	/**
	 * Revokes a post's links when it reaches a dead status.
	 *
	 * @param string $to   New status.
	 * @param string $from Old status.
	 * @param mixed  $post Post.
	 */
	public function on_status( $to, $from, $post ): void {
		if ( $post instanceof \WP_Post && $to !== $from && in_array( (string) $to, Links::dead_statuses(), true ) ) {
			$this->links->revoke_for_post( (int) $post->ID, 0 );
		}
	}

	/**
	 * Deletes a deleted post's links.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_deleted_post( $post_id ): void {
		$this->links->delete_for_post( (int) $post_id );
	}

	/**
	 * Revokes the links a deleted user created.
	 *
	 * @param int $user_id User ID.
	 */
	public function on_delete_user( $user_id ): void {
		$this->links->revoke_by_creator( (int) $user_id, 0 );
	}

	/**
	 * The daily clean-up.
	 */
	public function prune(): void {
		$this->events->prune();
	}

	/**
	 * Drops cached note counts.
	 */
	public function flush(): void {
		Notes::flush();
	}
}
