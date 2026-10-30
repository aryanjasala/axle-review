<?php
/**
 * Network Admin > Settings > Review Links.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Admin;

use AryanX\Axle\Review\Service;
use AryanX\Axle\Review\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * On multisite, a network admin can pause review links on every site at once.
 */
final class NetworkPause implements Service {

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		if ( ! is_multisite() ) {
			return;
		}

		add_action( 'network_admin_menu', array( $this, 'menu' ) );
		add_action( 'network_admin_edit_axle_review_network_pause', array( $this, 'save' ) );
	}

	/**
	 * Adds the page.
	 */
	public function menu(): void {
		add_submenu_page( 'settings.php', __( 'Review Links', 'axle-review' ), __( 'Review Links', 'axle-review' ), 'manage_network_options', 'axle-review-network', array( $this, 'render' ) );
	}

	/**
	 * Prints the page.
	 */
	public function render(): void {
		$paused = (bool) get_site_option( Settings::NETWORK_PAUSE );
		echo '<div class="wrap"><h1>' . esc_html__( 'Review Links', 'axle-review' ) . '</h1>';
		echo '<p>' . esc_html( $paused ? __( 'Review links are paused on every site of the network.', 'axle-review' ) : __( 'Pause review links on every site of the network at once, for example after a leak.', 'axle-review' ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( network_admin_url( 'edit.php?action=axle_review_network_pause' ) ) . '">';
		wp_nonce_field( 'axle_review_network_pause' );
		echo '<input type="hidden" name="pause" value="' . ( $paused ? '0' : '1' ) . '">';
		submit_button( $paused ? __( 'Resume links on every site', 'axle-review' ) : __( 'Pause links on every site', 'axle-review' ) );
		echo '</form></div>';
	}

	/**
	 * Saves the network pause.
	 */
	public function save(): void {
		check_admin_referer( 'axle_review_network_pause' );
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You cannot pause review links on the network.', 'axle-review' ), 403 );
		}

		$pause = isset( $_POST['pause'] ) && '1' === sanitize_key( wp_unslash( $_POST['pause'] ) );
		if ( $pause ) {
			update_site_option( Settings::NETWORK_PAUSE, time() );
			/** This action is documented in src/Settings.php */
			do_action( 'axle_review_links_paused', get_current_user_id() );
		} else {
			delete_site_option( Settings::NETWORK_PAUSE );
			/** This action is documented in src/Settings.php */
			do_action( 'axle_review_links_resumed', get_current_user_id() );
		}

		wp_safe_redirect( network_admin_url( 'settings.php?page=axle-review-network' ) );
		exit;
	}
}
