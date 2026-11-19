<?php
/**
 * The changes command: working copies of live posts.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Cli;

use AryanX\Axle\Review\Changes;

defined( 'ABSPATH' ) || exit;

/**
 * Ask for review of a change to a live post, publish it, discard it, or undo
 * the last published change. WP-CLI is a trusted context: capabilities are not
 * checked, and the publish gate is.
 *
 * ## EXAMPLES
 *
 *     wp axle-review change request 42
 *     wp axle-review change list
 *     wp axle-review change publish 57 --user=maria
 *     wp axle-review change discard 57
 *     wp axle-review change undo 42
 */
final class ChangeCommand {

	/**
	 * Working copies.
	 *
	 * @var Changes
	 */
	private Changes $changes;

	/**
	 * Sets up the command.
	 *
	 * @param Changes $changes Working copies.
	 */
	public function __construct( Changes $changes ) {
		$this->changes = $changes;
	}

	/**
	 * Makes a working copy of a published post.
	 *
	 * ## OPTIONS
	 *
	 * <post>
	 * : ID of the live post.
	 *
	 * [--porcelain]
	 * : Print only the copy's ID.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function request( $args, $assoc_args ): void {
		$result = $this->changes->request( (int) ( $args[0] ?? 0 ), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		if ( ! empty( $assoc_args['porcelain'] ) ) {
			\WP_CLI::line( (string) $result['copy'] );
			return;
		}

		\WP_CLI::success( sprintf( 'Working copy %d made for post %d. Create review links on it, then publish the change.', $result['copy'], $result['original'] ) );
	}

	/**
	 * Lists the working copies.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - ids
	 *   - count
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function list( $args, $assoc_args ): void {
		unset( $args );
		$copies = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'meta_key'       => Changes::META_OF, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A rare meta key, on demand.
				'posts_per_page' => 100,
			)
		);

		$rows = array();
		foreach ( $copies as $copy ) {
			$original = Changes::original_of( (int) $copy->ID );
			$rows[]   = array(
				'copy'     => (int) $copy->ID,
				'original' => $original,
				'title'    => get_the_title( $copy ),
				'status'   => $copy->post_status,
				'live'     => $original ? get_post_status( $original ) : 'gone',
			);
		}

		$format = (string) ( $assoc_args['format'] ?? 'table' );
		if ( 'ids' === $format ) {
			\WP_CLI::line( implode( ' ', array_column( $rows, 'copy' ) ) );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $rows, array( 'copy', 'original', 'title', 'status', 'live' ) );
	}

	/**
	 * Publishes the change: merges a working copy into its live post.
	 *
	 * ## OPTIONS
	 *
	 * <copy>
	 * : ID of the working copy.
	 *
	 * [--overwrite]
	 * : Publish even though the live post was edited after the copy was made.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function publish( $args, $assoc_args ): void {
		$result = $this->changes->publish( (int) ( $args[0] ?? 0 ), get_current_user_id(), ! empty( $assoc_args['overwrite'] ) );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( sprintf( 'Published the change to post %d. %d notes moved. Undo it with: wp axle-review change undo %d', $result['original'], $result['notes'], $result['original'] ) );
	}

	/**
	 * Deletes a working copy and its links. The live post is not touched.
	 *
	 * ## OPTIONS
	 *
	 * <copy>
	 * : ID of the working copy.
	 *
	 * @param array $args Positional arguments.
	 */
	public function discard( $args ): void {
		$result = $this->changes->discard( (int) ( $args[0] ?? 0 ), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( 'Working copy deleted.' );
	}

	/**
	 * Puts a live post back to how it was before its last published change.
	 *
	 * ## OPTIONS
	 *
	 * <post>
	 * : ID of the live post.
	 *
	 * [--overwrite]
	 * : Undo even though the post was edited after the change went live.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function undo( $args, $assoc_args ): void {
		$result = $this->changes->undo( (int) ( $args[0] ?? 0 ), get_current_user_id(), ! empty( $assoc_args['overwrite'] ) );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( 'The change was undone.' );
	}
}
