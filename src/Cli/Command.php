<?php
/**
 * The gate, digest and prune commands.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Cli;

use AryanX\Axle\Review\Alerts;
use AryanX\Axle\Review\Events;
use AryanX\Axle\Review\Gate;
use AryanX\Axle\Review\Notes;

defined( 'ABSPATH' ) || exit;

/**
 * Check the publish gate, send digests now and prune old link activity.
 *
 * ## EXAMPLES
 *
 *     wp axle-review gate check 42
 *     wp axle-review digest send --user=maria
 *     wp axle-review prune
 */
final class Command {

	/**
	 * Gate.
	 *
	 * @var Gate
	 */
	private Gate $gate;

	/**
	 * Alerts.
	 *
	 * @var Alerts
	 */
	private Alerts $alerts;

	/**
	 * Events.
	 *
	 * @var Events
	 */
	private Events $events;

	/**
	 * Sets up the command.
	 *
	 * @param Gate   $gate   Gate.
	 * @param Alerts $alerts Alerts.
	 * @param Events $events Events.
	 */
	public function __construct( Gate $gate, Alerts $alerts, Events $events ) {
		$this->gate   = $gate;
		$this->alerts = $alerts;
		$this->events = $events;
	}

	/**
	 * Whether the publish gate would hold a post back. Exits 1 when it would.
	 *
	 * ## OPTIONS
	 *
	 * <post>
	 * : Post ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function gate_check( $args, $assoc_args ): void {
		$post = get_post( (int) ( $args[0] ?? 0 ) );
		if ( ! $post ) {
			\WP_CLI::error( 'That post does not exist.' );
		}

		$ready  = $this->gate->readiness( $post );
		$format = (string) ( $assoc_args['format'] ?? 'table' );

		if ( 'table' !== $format ) {
			\WP_CLI::print_value(
				array(
					'post'         => (int) $post->ID,
					'gate_enabled' => $ready['enabled'],
					'would_block'  => $ready['blocks'],
					'open_notes'   => $ready['open'],
					'blocking'     => array_map( static fn( $note ) => (int) $note->comment_ID, $ready['notes'] ),
				),
				array( 'format' => $format )
			);
		} elseif ( $ready['blocks'] ) {
			\WP_CLI::warning( Gate::message( count( $ready['notes'] ), $post ) );
			\WP_CLI\Utils\format_items( 'table', array_map( array( Notes::class, 'to_array' ), $ready['notes'] ), array( 'id', 'author', 'block', 'text' ) );
		} elseif ( $ready['enabled'] ) {
			\WP_CLI::success( 'No open notes hold this post back.' );
		} else {
			\WP_CLI::success( sprintf( 'The gate is off for this post. It has %d open note(s).', $ready['open'] ) );
		}

		if ( $ready['blocks'] ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Sends digests now.
	 *
	 * ## OPTIONS
	 *
	 * [--user=<user>]
	 * : WP-CLI's global user: send only this user's digest, covering the last day.
	 *
	 * [--frequency=<frequency>]
	 * : Without --user, which digests.
	 * ---
	 * default: daily
	 * options:
	 *   - hourly
	 *   - daily
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function digest_send( $args, $assoc_args ): void {
		unset( $args );
		$user_id = get_current_user_id();
		if ( $user_id ) {
			$sent = $this->alerts->send_digest( $user_id, DAY_IN_SECONDS );
			\WP_CLI::success( $sent ? 'Digest sent.' : 'Nothing new since the last digest, so nothing was sent.' );
			return;
		}

		$count = $this->alerts->send_digests( 'hourly' === ( $assoc_args['frequency'] ?? '' ) ? 'hourly' : 'daily' );
		\WP_CLI::success( sprintf( 'Sent %d digest(s).', $count ) );
	}

	/**
	 * Deletes link activity older than the retention period (90 days).
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function prune( $args, $assoc_args ): void {
		unset( $args, $assoc_args );
		$total = 0;
		do {
			$deleted = $this->events->prune();
			$total  += $deleted;
		} while ( $deleted > 0 );

		\WP_CLI::success( sprintf( 'Deleted %d old activity row(s).', $total ) );
	}
}
