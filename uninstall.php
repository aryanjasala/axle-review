<?php
/**
 * Removes Axle Review's tables, settings, user meta, comment meta and
 * schedules, on every site. Reviewers' notes stay: they are core Notes now.
 *
 * @package AxleReview
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes Axle Review data from the current site.
 */
function axle_review_uninstall_site(): void {
	global $wpdb;

	foreach ( array( 'axle_review_prune', 'axle_review_digest_hourly', 'axle_review_digest_daily' ) as $axle_review_hook ) {
		wp_clear_scheduled_hook( $axle_review_hook );
	}

	foreach ( array( 'axle_review_settings', 'axle_review_paused', 'axle_review_slack', 'axle_review_slack_status', 'axle_review_cache_warning', 'axle_review_db_version' ) as $axle_review_option ) {
		delete_option( $axle_review_option );
	}

	/**
	 * Filters whether uninstalling deletes the notes reviewers left. They are
	 * core Notes, so they stay by default.
	 *
	 * @param bool $delete Default false.
	 */
	if ( apply_filters( 'axle_review_delete_guest_notes_on_uninstall', false ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall.
		$axle_review_notes = $wpdb->get_col( $wpdb->prepare( 'SELECT comment_id FROM %i WHERE meta_key = %s', $wpdb->commentmeta, '_axr_link_id' ) );
		foreach ( (array) $axle_review_notes as $axle_review_note ) {
			wp_delete_comment( (int) $axle_review_note, true );
		}
	}

	foreach ( array( '_axr_link_id', '_axr_anchor', '_axr_pending_attach', '_axr_email_verified', '_axr_session', '_axr_orphaned' ) as $axle_review_key ) {
		delete_metadata( 'comment', 0, $axle_review_key, '', true );
	}

	foreach ( array( 'axle_review_links', 'axle_review_events' ) as $axle_review_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own table on uninstall.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $axle_review_table ) );
	}

	// Transients: codes, rate limits, render IDs, overrides.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_axle_review_' ) . '%', $wpdb->esc_like( '_transient_timeout_axle_review_' ) . '%', $wpdb->esc_like( 'axle_review_lock_' ) . '%' ) );
}

if ( is_multisite() ) {
	$axle_review_offset = 0;
	do {
		$axle_review_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $axle_review_offset,
			)
		);

		foreach ( $axle_review_sites as $axle_review_site ) {
			switch_to_blog( $axle_review_site );
			axle_review_uninstall_site();
			restore_current_blog();
		}

		$axle_review_offset += 100;
	} while ( $axle_review_sites );

	delete_site_option( 'axle_review_network_paused' );
} else {
	axle_review_uninstall_site();
}

// User meta is shared across a network.
foreach ( array( 'axle_review_notify', 'axle_review_inbox_seen', 'axle_review_digest_sent' ) as $axle_review_meta ) {
	delete_metadata( 'user', 0, $axle_review_meta, '', true );
}
