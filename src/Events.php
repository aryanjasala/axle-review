<?php
/**
 * The link activity table.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review;

defined( 'ABSPATH' ) || exit;

/**
 * Records what happened on each link: opens, codes, failed passwords, notes
 * and refusals, with an anonymised IP address and the browser family.
 */
final class Events {

	public const TYPES = array( 'open', 'code_sent', 'code_ok', 'code_fail', 'password_fail', 'note', 'reply_seen', 'denied' );

	/**
	 * Adds an event.
	 *
	 * @param int    $link_id    Link ID.
	 * @param string $type       One of TYPES.
	 * @param int    $comment_id Note ID, for note events.
	 * @return int Event ID, 0 when not saved.
	 */
	public function add( int $link_id, string $type, int $comment_id = 0 ): int {
		global $wpdb;

		if ( ! in_array( $type, self::TYPES, true ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table.
		$wpdb->insert(
			Schema::events_table(),
			array(
				'link_id'    => $link_id,
				'type'       => $type,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'ip'         => self::ip(),
				'ua_family'  => self::browser( self::user_agent() ),
				'comment_id' => $comment_id,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * The visitor's IP address, anonymised unless a filter keeps it.
	 *
	 * @return string
	 */
	public static function ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- Stored anonymised for the activity log and rate limits.
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		/**
		 * Filters whether IP addresses in link activity are anonymised.
		 *
		 * @param bool $anonymize Default true.
		 */
		if ( apply_filters( 'axle_review_anonymize_ip', true ) ) {
			return (string) wp_privacy_anonymize_ip( $ip );
		}

		return $ip;
	}

	/**
	 * The full IP address, for rate limits only (never stored as is).
	 *
	 * @return string
	 */
	public static function raw_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- Hashed into rate-limit keys.
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * The user agent string.
	 *
	 * @return string
	 */
	private static function user_agent(): string {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : ''; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__ -- Only the browser family is kept.
	}

	/**
	 * The browser family of a user agent, never the full string.
	 *
	 * @param string $agent User agent.
	 * @return string
	 */
	public static function browser( string $agent ): string {
		$families = array(
			'bot'     => '/bot|crawl|spider|slurp|preview|facebookexternalhit|slack|whatsapp|telegram/i',
			'Edge'    => '/Edg\//',
			'Opera'   => '/OPR\//',
			'Samsung' => '/SamsungBrowser/',
			'Firefox' => '/Firefox|FxiOS/',
			'Chrome'  => '/Chrome|CriOS|HeadlessChrome/',
			'Safari'  => '/Safari/',
		);

		foreach ( $families as $family => $pattern ) {
			if ( preg_match( $pattern, $agent ) ) {
				return $family;
			}
		}

		return '' === $agent ? '' : 'Other';
	}

	/**
	 * Events of one link, newest first.
	 *
	 * @param int $link_id Link ID.
	 * @param int $limit   Most rows.
	 * @return array[]
	 */
	public function for_link( int $link_id, int $limit = 100 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE link_id = %d ORDER BY id DESC LIMIT %d', Schema::events_table(), $link_id, max( 1, min( 1000, $limit ) ) ), ARRAY_A );

		return array_map(
			static function ( $row ) {
				return array(
					'id'         => (int) $row['id'],
					'link_id'    => (int) $row['link_id'],
					'type'       => (string) $row['type'],
					'created_at' => Links::iso( (string) $row['created_at'] ),
					'ip'         => (string) $row['ip'],
					'browser'    => (string) $row['ua_family'],
					'comment_id' => (int) $row['comment_id'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * How many events of a type a link had since a time.
	 *
	 * @param int    $link_id Link ID.
	 * @param string $type    Event type.
	 * @param int    $since   Unix time.
	 * @return int
	 */
	public function count_since( int $link_id, string $type, int $since ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rate limit: always the live count.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE link_id = %d AND type = %s AND created_at >= %s', Schema::events_table(), $link_id, $type, gmdate( 'Y-m-d H:i:s', $since ) ) );
	}

	/**
	 * Notes left per link, for a set of links.
	 *
	 * @param int[] $link_ids Link IDs.
	 * @return array<int, int> Link ID => notes.
	 */
	public function note_counts( array $link_ids ): array {
		global $wpdb;

		$link_ids = array_values( array_filter( array_map( 'intval', $link_ids ) ) );
		if ( ! $link_ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $link_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The plugin's own table; $placeholders only holds %d.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT link_id, COUNT(*) AS notes FROM %i WHERE type = 'note' AND link_id IN ({$placeholders}) GROUP BY link_id", array_merge( array( Schema::events_table() ), $link_ids ) ), ARRAY_A );
		// phpcs:enable

		$counts = array_fill_keys( $link_ids, 0 );
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row['link_id'] ] = (int) $row['notes'];
		}

		return $counts;
	}

	/**
	 * Deletes events older than the retention period, 1000 at a time.
	 *
	 * @return int Rows deleted.
	 */
	public function prune(): int {
		global $wpdb;

		/**
		 * Filters how many days link activity is kept.
		 *
		 * @param int $days Default 90, at least 1.
		 */
		$days = max( 1, (int) apply_filters( 'axle_review_event_retention_days', 90 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Clean-up of the plugin's own table.
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s LIMIT 1000', Schema::events_table(), gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Deletes every event of a link, for the privacy eraser.
	 *
	 * @param int $link_id Link ID.
	 * @return int Rows deleted.
	 */
	public function delete_for_link( int $link_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		return (int) $wpdb->delete( Schema::events_table(), array( 'link_id' => $link_id ) );
	}
}
