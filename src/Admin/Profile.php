<?php
/**
 * The notification preference on the profile screen.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Admin;

use AryanX\Axle\Review\Alerts;
use AryanX\Axle\Review\Service;

defined( 'ABSPATH' ) || exit;

/**
 * "Note emails" on Users > Profile: instantly, hourly digest, daily digest or off.
 */
final class Profile implements Service {

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'field' ) );
		add_action( 'edit_user_profile', array( $this, 'field' ) );
		add_action( 'personal_options_update', array( $this, 'save' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save' ) );
	}

	/**
	 * Prints the field.
	 *
	 * @param mixed $user User being edited.
	 */
	public function field( $user ): void {
		if ( ! $user instanceof \WP_User || ! user_can( $user, 'edit_posts' ) ) {
			return;
		}

		$current = Alerts::preference( (int) $user->ID );
		$choices = array(
			'instant' => __( 'Right away', 'axle-review' ),
			'hourly'  => __( 'Hourly digest', 'axle-review' ),
			'daily'   => __( 'Daily digest', 'axle-review' ),
			'off'     => __( 'Off', 'axle-review' ),
		);

		echo '<div class="axr-profile" id="axle-review"><h2>' . esc_html__( 'Notes', 'axle-review' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tr><th scope="row"><label for="axr-notify">' . esc_html__( 'Note emails', 'axle-review' ) . '</label></th><td>';
		echo '<select id="axr-notify" name="axle_review_notify" aria-describedby="axr-notify-help">';
		foreach ( $choices as $value => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
		echo '<p class="description" id="axr-notify-help">' . esc_html__( 'Emails about notes reviewers leave on your posts and replies to notes you started. Digests also list notes that mention you. WordPress itself still emails you about mentions and notes on your posts as they happen.', 'axle-review' ) . '</p>';
		echo '</td></tr></table></div>';
	}

	/**
	 * Saves the field. Core checked the profile nonce before this runs.
	 *
	 * @param int $user_id User being saved.
	 */
	public function save( $user_id ): void {
		$user_id = (int) $user_id;
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		check_admin_referer( 'update-user_' . $user_id );

		$value = isset( $_POST['axle_review_notify'] ) ? sanitize_key( wp_unslash( $_POST['axle_review_notify'] ) ) : '';
		if ( in_array( $value, Alerts::PREFERENCES, true ) ) {
			update_user_meta( $user_id, Alerts::PREF_KEY, $value );
		}
	}
}
