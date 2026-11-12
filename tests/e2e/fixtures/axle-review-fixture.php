<?php
/**
 * Plugin Name: Axle Review e2e fixture
 * Description: Application passwords over plain HTTP, an email catcher and faster timings for the end-to-end tests. Copied into mu-plugins by the tests.
 *
 * @package AxleReview
 */

// phpcs:ignoreFile

// Application passwords need HTTPS unless the site is local; the test site is plain HTTP.
add_filter( 'wp_is_application_passwords_available', '__return_true' );

// Catch email instead of sending it. The tests read the option.
add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) {
		$sent   = get_option( 'axr_fixture_mail', array() );
		$sent[] = array(
			'to'      => implode( ',', (array) $atts['to'] ),
			'subject' => (string) $atts['subject'],
			'message' => (string) $atts['message'],
		);
		update_option( 'axr_fixture_mail', $sent, false );
		return true;
	},
	10,
	2
);

// No waiting on page load before a note, and a quick editor watcher.
add_filter( 'axle_review_min_seconds', '__return_zero' );
add_filter(
	'axle_review_editor_poll_seconds',
	static function () {
		return 5;
	}
);

// Revisions can be turned off from a test: wp option update axr_fixture_no_revisions 1
add_filter(
	'wp_revisions_to_keep',
	static function ( $num ) {
		return get_option( 'axr_fixture_no_revisions' ) ? 0 : $num;
	},
	20
);

// A shortcode that lists posts of every status the way careless theme code might: the review page must not show other drafts.
add_shortcode(
	'axr_drafts',
	static function () {
		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'draft', 'publish' ),
				'posts_per_page' => 20,
			)
		);
		$items = '';
		foreach ( $query->posts as $item ) {
			$items .= '<li>' . esc_html( $item->post_title ) . '</li>';
		}
		return '<ul class="axr-leak-test">' . $items . '</ul>';
	}
);
