<?php
/**
 * The notes commands.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Cli;

use AryanX\Axle\Review\Abilities;
use AryanX\Axle\Review\Attacher;
use AryanX\Axle\Review\Notes;

defined( 'ABSPATH' ) || exit;

/**
 * List, read, add, resolve and reopen notes, and retry attaching reviewers'
 * notes to their blocks.
 *
 * ## EXAMPLES
 *
 *     wp axle-review notes list --status=unresolved --guests
 *     wp axle-review notes show 12
 *     wp axle-review notes add 42 --text="Check this number." --block=2
 *     wp axle-review notes resolve 12 13 --user=admin
 */
final class NotesCommand {

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
	 * Abilities, which hold the add-note logic.
	 *
	 * @var Abilities
	 */
	private Abilities $abilities;

	/**
	 * Sets up the command.
	 *
	 * @param Notes     $notes     Notes.
	 * @param Attacher  $attacher  Attacher.
	 * @param Abilities $abilities Abilities.
	 */
	public function __construct( Notes $notes, Attacher $attacher, Abilities $abilities ) {
		$this->notes     = $notes;
		$this->attacher  = $attacher;
		$this->abilities = $abilities;
	}

	/**
	 * Lists notes, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Which notes.
	 * ---
	 * default: unresolved
	 * options:
	 *   - unresolved
	 *   - resolved
	 *   - all
	 * ---
	 *
	 * [--post=<id>]
	 * : Only notes on this post.
	 *
	 * [--mentions=<user>]
	 * : Only notes that mention this user (ID, login or email). WordPress 7.1+.
	 *
	 * [--guests]
	 * : Only notes from reviewers.
	 *
	 * [--limit=<n>]
	 * : Most rows. Default 100.
	 *
	 * [--fields=<fields>]
	 * : Fields to show.
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
	 *   - ids
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function list_( $args, $assoc_args ): void {
		$mentions = 0;
		if ( ! empty( $assoc_args['mentions'] ) ) {
			if ( ! Notes::has_mentions() ) {
				\WP_CLI::error( 'Mentions in notes need WordPress 7.1 or later.' );
			}
			$user = self::user( (string) $assoc_args['mentions'] );
			if ( ! $user ) {
				\WP_CLI::error( 'Unknown user for --mentions.' );
			}
			$mentions = (int) $user->ID;
		}

		$found = $this->notes->query(
			array(
				'status'   => (string) ( $assoc_args['status'] ?? 'unresolved' ),
				'post_id'  => (int) ( $assoc_args['post'] ?? 0 ),
				'mentions' => $mentions,
				'guests'   => ! empty( $assoc_args['guests'] ),
				'per_page' => max( 1, min( 200, (int) ( $assoc_args['limit'] ?? 100 ) ) ),
			)
		);

		$rows   = array_map( array( Notes::class, 'to_array' ), $found['items'] );
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		if ( 'ids' === $format ) {
			\WP_CLI::line( implode( ' ', array_column( $rows, 'id' ) ) );
			return;
		}

		foreach ( $rows as &$row ) {
			$row['text']     = mb_substr( (string) preg_replace( '/\s+/', ' ', $row['text'] ), 0, 80 );
			$row['mentions'] = implode( ',', $row['mentions'] );
		}
		unset( $row );

		$fields = isset( $assoc_args['fields'] ) ? explode( ',', (string) $assoc_args['fields'] ) : array( 'id', 'post_id', 'author', 'guest', 'status', 'block', 'date', 'text' );
		\WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * Shows a note thread: the note, its block, replies and history.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Note ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: yaml
	 * options:
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function show( $args, $assoc_args ): void {
		$thread = $this->abilities->get_thread( array( 'id' => (int) ( $args[0] ?? 0 ) ) );
		if ( is_wp_error( $thread ) ) {
			\WP_CLI::error( $thread->get_error_message() );
		}

		\WP_CLI::print_value( $thread, array( 'format' => (string) ( $assoc_args['format'] ?? 'yaml' ) ) );
	}

	/**
	 * Adds a note on a block, or a reply, as the --user.
	 *
	 * ## OPTIONS
	 *
	 * <post>
	 * : Post ID.
	 *
	 * --text=<text>
	 * : Note text.
	 *
	 * [--block=<path>]
	 * : Block path: 2 is the third top-level block, 2.0 its first inner block. Default: the first block with text.
	 *
	 * [--match=<text>]
	 * : The first block whose text contains this.
	 *
	 * [--reply-to=<id>]
	 * : Reply to this thread instead.
	 *
	 * [--porcelain]
	 * : Print only the note ID.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function add( $args, $assoc_args ): void {
		if ( ! get_current_user_id() ) {
			\WP_CLI::error( 'Run it with --user=<login>: notes have an author.' );
		}

		$input = array(
			'post' => (int) ( $args[0] ?? 0 ),
			'text' => (string) ( $assoc_args['text'] ?? '' ),
		);
		if ( isset( $assoc_args['block'] ) ) {
			$input['block'] = (string) $assoc_args['block'];
		}
		if ( isset( $assoc_args['match'] ) ) {
			$input['match'] = (string) $assoc_args['match'];
		}
		if ( isset( $assoc_args['reply-to'] ) ) {
			$input['reply_to'] = (int) $assoc_args['reply-to'];
		}

		$result = $this->abilities->add_note( $input );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		if ( ! empty( $assoc_args['porcelain'] ) ) {
			\WP_CLI::line( (string) $result['note']['id'] );
			return;
		}

		\WP_CLI::success( sprintf( 'Added note %d%s.', (int) $result['note']['id'], null === $result['block'] ? '' : ' on block ' . $result['block'] ) );
	}

	/**
	 * Resolves notes.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Note IDs.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function resolve( $args, $assoc_args ): void {
		unset( $assoc_args );
		$this->set_status( $args, 'resolved' );
	}

	/**
	 * Reopens notes.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Note IDs.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function reopen( $args, $assoc_args ): void {
		unset( $assoc_args );
		$this->set_status( $args, 'open' );
	}

	/**
	 * Resolves or reopens.
	 *
	 * @param array  $ids IDs.
	 * @param string $to  resolved or open.
	 */
	private function set_status( array $ids, string $to ): void {
		foreach ( $ids as $id ) {
			$result = $this->notes->set_status( (int) $id, $to, get_current_user_id() );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::warning( sprintf( 'Note %d: %s', (int) $id, $result->get_error_message() ) );
				continue;
			}
			\WP_CLI::success( sprintf( 'Note %d is %s.', (int) $id, $to ) );
		}
	}

	/**
	 * Retries attaching reviewers' notes that are still pending on a post.
	 *
	 * ## OPTIONS
	 *
	 * <post>
	 * : Post ID.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function reattach( $args, $assoc_args ): void {
		unset( $assoc_args );
		$results = $this->attacher->reattach( (int) ( $args[0] ?? 0 ) );
		if ( ! $results ) {
			\WP_CLI::success( 'No notes are waiting to be attached.' );
			return;
		}

		foreach ( $results as $id => $result ) {
			if ( is_wp_error( $result ) ) {
				\WP_CLI::warning( sprintf( 'Note %d: %s', $id, $result->get_error_message() ) );
				continue;
			}
			\WP_CLI::success( sprintf( 'Note %d is on block %s%s.', $id, $result['path'], $result['orphan'] ? ' (its own block was gone)' : '' ) );
		}
	}

	/**
	 * A user by ID, login or email.
	 *
	 * @param string $value Value.
	 * @return \WP_User|null
	 */
	public static function user( string $value ): ?\WP_User {
		$user = is_numeric( $value ) ? get_user_by( 'id', (int) $value ) : ( is_email( $value ) ? get_user_by( 'email', $value ) : get_user_by( 'login', $value ) );
		return $user instanceof \WP_User ? $user : null;
	}
}
