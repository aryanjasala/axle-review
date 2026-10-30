<?php
/**
 * The link commands.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Cli;

use AryanX\Axle\Review\Events;
use AryanX\Axle\Review\Links;
use AryanX\Axle\Review\Rest\EditorRoutes;
use AryanX\Axle\Review\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Create, list and revoke review links, see their activity, and pause or
 * resume every link.
 *
 * ## EXAMPLES
 *
 *     wp axle-review link create 42 --label="Legal" --expires=3d --views=1
 *     wp axle-review link list --post=42 --status=active
 *     wp axle-review link revoke 7
 *     wp axle-review link pause
 */
final class LinkCommand {

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
	 * Sets up the command.
	 *
	 * @param Settings $settings Settings.
	 * @param Links    $links    Links.
	 * @param Events   $events   Events.
	 */
	public function __construct( Settings $settings, Links $links, Events $events ) {
		$this->settings = $settings;
		$this->links    = $links;
		$this->events   = $events;
	}

	/**
	 * Parses a duration such as 90m, 12h, 7d or a number of seconds.
	 *
	 * @param string $value Duration.
	 * @return int Seconds, 0 when unreadable.
	 */
	public static function duration( string $value ): int {
		if ( ! preg_match( '/^(\d+)\s*([smhdw]?)$/i', trim( $value ), $match ) ) {
			return 0;
		}

		$units = array(
			''  => 1,
			's' => 1,
			'm' => MINUTE_IN_SECONDS,
			'h' => HOUR_IN_SECONDS,
			'd' => DAY_IN_SECONDS,
			'w' => WEEK_IN_SECONDS,
		);

		return (int) $match[1] * $units[ strtolower( $match[2] ) ];
	}

	/**
	 * Creates a review link and prints its URL. The URL is shown only once.
	 *
	 * ## OPTIONS
	 *
	 * <post>
	 * : Post ID.
	 *
	 * [--label=<label>]
	 * : Label, such as who it is for.
	 *
	 * [--expires=<duration>]
	 * : How long it works: 90m, 12h, 7d. Default from the settings (7d), at most 30d.
	 *
	 * [--views=<n>]
	 * : View limit. 0 is unlimited, 1 opens once.
	 *
	 * [--email=<email>]
	 * : Lock the link to this address with a one-time code.
	 *
	 * [--password[=<password>]]
	 * : Ask for a password. Without a value it is read from STDIN.
	 *
	 * [--no-notes]
	 * : The reviewer can read, not leave notes.
	 *
	 * [--send-to=<email>]
	 * : Email the link to this address.
	 *
	 * [--porcelain]
	 * : Print only the URL.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function create( $args, $assoc_args ): void {
		$post_id  = (int) ( $args[0] ?? 0 );
		$expires  = isset( $assoc_args['expires'] ) ? self::duration( (string) $assoc_args['expires'] ) : $this->settings->default_expiry();
		$password = '';
		if ( isset( $assoc_args['password'] ) ) {
			// --password=<value>, or a bare --password reads one line from STDIN.
			$password = true === $assoc_args['password'] ? trim( (string) fgets( STDIN ) ) : (string) $assoc_args['password'];
			if ( '' === $password ) {
				\WP_CLI::error( 'The password is empty.' );
			}
		}

		if ( ! $expires ) {
			\WP_CLI::error( 'Could not read --expires. Use a number with m, h, d or w, such as 7d.' );
		}

		$created = $this->links->create(
			array(
				'post_id'     => $post_id,
				'label'       => (string) ( $assoc_args['label'] ?? '' ),
				'expires'     => $expires,
				'max_views'   => isset( $assoc_args['views'] ) ? (int) $assoc_args['views'] : $this->settings->default_max_views(),
				'email'       => (string) ( $assoc_args['email'] ?? '' ),
				'password'    => $password,
				'allow_notes' => \WP_CLI\Utils\get_flag_value( $assoc_args, 'notes', true ),
				'share_notes' => (bool) $this->settings->get( 'guest_sees_others' ),
				'created_by'  => get_current_user_id(),
			)
		);

		if ( is_wp_error( $created ) ) {
			\WP_CLI::error( $created->get_error_message() );
		}

		if ( ! empty( $assoc_args['send-to'] ) && is_email( (string) $assoc_args['send-to'] ) ) {
			EditorRoutes::email_link( (string) $assoc_args['send-to'], $created['url'], $created['link'] );
		}

		if ( ! empty( $assoc_args['porcelain'] ) ) {
			\WP_CLI::line( $created['url'] );
			return;
		}

		\WP_CLI::success( sprintf( 'Created link %d. Copy the URL now, it is not shown again:', $created['id'] ) );
		\WP_CLI::line( $created['url'] );
	}

	/**
	 * Lists review links, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--post=<id>]
	 * : Only links to this post.
	 *
	 * [--status=<status>]
	 * : Only links with this status.
	 * ---
	 * options:
	 *   - active
	 *   - used
	 *   - expired
	 *   - revoked
	 * ---
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
		$found  = $this->links->query(
			array(
				'post_id' => (int) ( $assoc_args['post'] ?? 0 ),
				'status'  => (string) ( $assoc_args['status'] ?? '' ),
				'limit'   => 500,
			)
		);
		$counts = $this->events->note_counts( array_column( $found['items'], 'id' ) );
		$rows   = array_map( static fn( $link ) => Links::to_public( $link, $counts[ $link['id'] ] ?? 0 ), $found['items'] );
		$format = (string) ( $assoc_args['format'] ?? 'table' );

		if ( 'ids' === $format ) {
			\WP_CLI::line( implode( ' ', array_column( $rows, 'id' ) ) );
			return;
		}

		$fields = isset( $assoc_args['fields'] ) ? explode( ',', (string) $assoc_args['fields'] ) : array( 'id', 'post_id', 'label', 'status', 'views', 'max_views', 'notes', 'email', 'expires_at' );
		\WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * Revokes links.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Link IDs.
	 *
	 * [--post=<id>]
	 * : Revoke every link to this post.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function revoke( $args, $assoc_args ): void {
		$by = get_current_user_id();
		if ( ! empty( $assoc_args['post'] ) ) {
			$count = $this->links->revoke_for_post( (int) $assoc_args['post'], $by );
			\WP_CLI::success( sprintf( 'Revoked %d link(s).', $count ) );
			return;
		}

		if ( ! $args ) {
			\WP_CLI::error( 'Give link IDs or --post.' );
		}

		foreach ( $args as $id ) {
			if ( ! $this->links->revoke( (int) $id, $by ) ) {
				\WP_CLI::warning( sprintf( 'Link %d does not exist.', (int) $id ) );
				continue;
			}
			\WP_CLI::success( sprintf( 'Revoked link %d.', (int) $id ) );
		}
	}

	/**
	 * Shows a link's activity, newest first.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Link ID.
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
	public function activity( $args, $assoc_args ): void {
		$link = $this->links->get( (int) ( $args[0] ?? 0 ) );
		if ( ! $link ) {
			\WP_CLI::error( 'That link does not exist.' );
		}

		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $this->events->for_link( (int) $link['id'], 1000 ), array( 'created_at', 'type', 'browser', 'ip', 'comment_id' ) );
	}

	/**
	 * Pauses every review link on the site.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function pause( $args, $assoc_args ): void {
		unset( $args, $assoc_args );
		Settings::set_paused( true );
		\WP_CLI::success( 'Every review link is paused.' );
	}

	/**
	 * Resumes review links.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function resume( $args, $assoc_args ): void {
		unset( $args, $assoc_args );
		Settings::set_paused( false );
		\WP_CLI::success( 'Review links work again.' );
	}
}
