<?php
/**
 * The block editor panels.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Admin;

use AryanX\Axle\Review\Service;
use AryanX\Axle\Review\Settings;
use const AryanX\Axle\Review\PLUGIN_FILE;
use const AryanX\Axle\Review\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Loads assets/editor.js: the "Share for review" panel, the pre-publish
 * panel with open notes, and the watcher that puts reviewers' notes on their
 * blocks in an open editor.
 */
final class EditorAssets implements Service {

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the editor script on supported post types.
	 */
	public function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || ! in_array( (string) $screen->post_type, Settings::post_types(), true ) ) {
			return;
		}

		$post_id = get_the_ID();
		$post_id = $post_id ? (int) $post_id : 0;

		wp_enqueue_style( 'axle-review-editor', plugins_url( 'assets/editor.css', PLUGIN_FILE ), array(), VERSION );
		wp_enqueue_script(
			'axle-review-editor',
			plugins_url( 'assets/editor.js', PLUGIN_FILE ),
			array( 'wp-api-fetch', 'wp-block-editor', 'wp-components', 'wp-core-data', 'wp-data', 'wp-editor', 'wp-element', 'wp-hooks', 'wp-i18n', 'wp-notices', 'wp-plugins', 'wp-url' ),
			VERSION,
			true
		);
		wp_set_script_translations( 'axle-review-editor', 'axle-review' );
		wp_localize_script(
			'axle-review-editor',
			'axleReviewEditor',
			array(
				'canShare'    => $post_id ? Settings::can_share( $post_id ) : current_user_can( 'edit_posts' ),
				'canOverride' => current_user_can( Settings::gate_override_capability() ),
				'inboxUrl'    => Inbox::url(),
				'linksUrl'    => LinksPage::url(),
				'poll'        => self::poll_seconds(),
			)
		);
	}

	/**
	 * How often the open editor checks for new reviewer notes.
	 *
	 * @return int Seconds.
	 */
	public static function poll_seconds(): int {
		/**
		 * Filters how often an open editor checks for new reviewer notes, in seconds.
		 *
		 * @param int $seconds Default 20, between 5 and 600.
		 */
		return max( 5, min( 600, (int) apply_filters( 'axle_review_editor_poll_seconds', 20 ) ) );
	}
}
