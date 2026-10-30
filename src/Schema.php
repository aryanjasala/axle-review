<?php
/**
 * Database tables and schedules.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the links and events tables, and schedules the daily
 * clean-up and the digests.
 *
 * The version check runs on every load, so sites added to a network after the
 * plugin was network activated get their tables on their first request.
 */
final class Schema {

	public const DB_VERSION        = '1';
	public const DB_VERSION_OPTION = 'axle_review_db_version';
	public const PRUNE_HOOK        = 'axle_review_prune';
	public const DIGEST_HOOKS      = array(
		'hourly' => 'axle_review_digest_hourly',
		'daily'  => 'axle_review_digest_daily',
	);

	/**
	 * The links table name.
	 *
	 * @return string
	 */
	public static function links_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'axle_review_links';
	}

	/**
	 * The events table name.
	 *
	 * @return string
	 */
	public static function events_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'axle_review_events';
	}

	/**
	 * Activation: tables and schedules.
	 */
	public static function activate(): void {
		if ( ! Requirements::met() ) {
			return;
		}

		self::install();
	}

	/**
	 * Deactivation: stops the schedules on this site, or on every site when it
	 * was network deactivated. Tables, links and settings stay until uninstall.
	 *
	 * @param bool $network_wide Whether it was network deactivated.
	 */
	public static function deactivate( $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			$offset = 0;
			do {
				$sites = get_sites(
					array(
						'fields' => 'ids',
						'number' => 100,
						'offset' => $offset,
					)
				);
				foreach ( $sites as $site ) {
					switch_to_blog( (int) $site );
					self::clear_schedules();
					restore_current_blog();
				}
				$offset += 100;
			} while ( $sites );
			return;
		}

		self::clear_schedules();
	}

	/**
	 * Removes every scheduled event of the plugin on the current site.
	 */
	public static function clear_schedules(): void {
		wp_clear_scheduled_hook( self::PRUNE_HOOK );
		foreach ( self::DIGEST_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Installs or upgrades when the stored version differs, and makes sure the
	 * schedules exist.
	 */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== get_option( self::DB_VERSION_OPTION ) ) {
			self::install();
			return;
		}

		self::schedule();
	}

	/**
	 * Creates the tables and the schedules.
	 */
	public static function install(): void {
		self::create_tables();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		self::schedule();
	}

	/**
	 * Schedules the clean-up and the digests when they are missing.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK );
		}

		foreach ( self::DIGEST_HOOKS as $frequency => $hook ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( self::digest_start( $frequency ), $frequency, $hook );
			}
		}
	}

	/**
	 * When the first digest of a frequency goes out.
	 *
	 * @param string $frequency hourly or daily.
	 * @return int Unix time.
	 */
	public static function digest_start( string $frequency ): int {
		$now = time();

		if ( 'hourly' === $frequency ) {
			return $now - ( $now % HOUR_IN_SECONDS ) + HOUR_IN_SECONDS;
		}

		/**
		 * Filters the hour of the day (site time, 0 to 23) the daily digest goes out.
		 *
		 * @param int $hour Default 8.
		 */
		$hour = (int) apply_filters( 'axle_review_digest_schedule', 8 );
		$hour = max( 0, min( 23, $hour ) );

		$next = (int) strtotime( sprintf( 'today %02d:00', $hour ), (int) current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Site-local hour.
		$next = $next - (int) ( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
		while ( $next <= $now ) {
			$next += DAY_IN_SECONDS;
		}

		return $next;
	}

	/**
	 * Creates or updates both tables with dbDelta.
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$links   = self::links_table();
		$events  = self::events_table();

		dbDelta(
			"CREATE TABLE {$links} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_id bigint(20) unsigned NOT NULL default 0,
				token_hash char(64) NOT NULL default '',
				label varchar(191) NOT NULL default '',
				created_by bigint(20) unsigned NOT NULL default 0,
				created_at datetime NOT NULL default '0000-00-00 00:00:00',
				expires_at datetime NULL,
				max_views int(10) unsigned NOT NULL default 0,
				views int(10) unsigned NOT NULL default 0,
				email varchar(100) NULL,
				password_hash varchar(255) NULL,
				allow_notes tinyint(1) unsigned NOT NULL default 1,
				share_notes tinyint(1) unsigned NOT NULL default 0,
				revoked_at datetime NULL,
				revoked_by bigint(20) unsigned NOT NULL default 0,
				last_opened_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY post_id (post_id),
				KEY created_by (created_by),
				KEY email (email)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				link_id bigint(20) unsigned NOT NULL default 0,
				type varchar(20) NOT NULL default '',
				created_at datetime NOT NULL default '0000-00-00 00:00:00',
				ip varchar(45) NOT NULL default '',
				ua_family varchar(40) NOT NULL default '',
				comment_id bigint(20) unsigned NOT NULL default 0,
				PRIMARY KEY  (id),
				KEY link_type (link_id,type,created_at),
				KEY created_at (created_at)
			) {$charset};"
		);
	}

	/**
	 * Whether both tables exist, for Site Health.
	 *
	 * @return bool
	 */
	public static function tables_exist(): bool {
		global $wpdb;

		foreach ( array( self::links_table(), self::events_table() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checks the plugin's own table, for Site Health.
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				return false;
			}
		}

		return true;
	}
}
