<?php
/**
 * The review state command.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Cli;

use AryanX\Axle\Review\Approvals;

defined( 'ABSPATH' ) || exit;

/**
 * Shows who approved a draft, who asked for changes, and whether an approval
 * is out of date because the post was edited afterwards. Reviewers decide
 * from their own link: there is no command that approves for them.
 *
 * ## EXAMPLES
 *
 *     wp axle-review review status 42
 *     wp axle-review review status 42 --format=json
 */
final class ReviewCommand {

	/**
	 * Approvals.
	 *
	 * @var Approvals
	 */
	private Approvals $approvals;

	/**
	 * Sets up the command.
	 *
	 * @param Approvals $approvals Approvals.
	 */
	public function __construct( Approvals $approvals ) {
		$this->approvals = $approvals;
	}

	/**
	 * Shows the review state of a post, one row per reviewer.
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
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function status( $args, $assoc_args ): void {
		$post = get_post( (int) ( $args[0] ?? 0 ) );
		if ( ! $post ) {
			\WP_CLI::error( 'That post does not exist.' );
		}

		$state  = $this->approvals->post_state( $post );
		$format = (string) ( $assoc_args['format'] ?? 'table' );

		if ( 'json' === $format ) {
			\WP_CLI::line( (string) wp_json_encode( $state ) );
			return;
		}

		if ( 'table' === $format ) {
			\WP_CLI::line( '' === $state['text'] ? 'Nobody was asked to review this post.' : $state['text'] );
			if ( ! $state['revisions'] ) {
				\WP_CLI::line( 'Revisions are off for this post, so reviewers see which blocks changed but not the earlier text.' );
			}
		}

		$rows = array_map(
			static fn( $item ) => array(
				'link'     => $item['link'],
				'label'    => $item['label'],
				'reviewer' => $item['reviewer'],
				'decision' => '' === $item['decision'] ? 'none' : $item['decision'],
				'decided'  => $item['decided'],
				'edited'   => $item['edited'] ? 'yes' : 'no',
				'comment'  => $item['comment'],
			),
			$state['decisions']
		);

		\WP_CLI\Utils\format_items( $format, $rows, array( 'link', 'label', 'reviewer', 'decision', 'decided', 'edited', 'comment' ) );
	}
}
