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
		$hook = add_submenu_page( 'settings.php', __( 'Review Links', 'axle-review' ), __( 'Review Links', 'axle-review' ), 'manage_network_options', 'axle-review-network', array( $this, 'render' ) );

		if ( $hook ) {
			add_action( 'admin_print_styles-' . $hook, array( AxleUi::class, 'enqueue' ) );
			add_action( 'admin_print_scripts-' . $hook, array( LinksPage::class, 'enqueue_script' ) );
			add_action( 'load-' . $hook, array( $this, 'help' ) );
		}
	}

	/**
	 * The Help tabs of this screen.
	 */
	public function help(): void {
		AxleUi::help_tabs(
			array(
				array(
					'id'      => 'overview',
					'title'   => __( 'Overview', 'axle-review' ),
					'content' => '<p>' . esc_html__( 'Review links let someone without an account read a draft and leave notes on it. This screen turns every review link off across the network at once.', 'axle-review' ) . '</p>',
				),
				array(
					'id'      => 'pause',
					'title'   => __( 'Pausing and resuming', 'axle-review' ),
					'content' => '<p>' . esc_html__( 'While links are paused nobody can open one, on any site. Nothing is deleted. Resuming brings back the links that were still valid, so you can undo a pause at any time.', 'axle-review' ) . '</p>',
				),
			)
		);
	}

	/**
	 * Prints the page.
	 */
	public function render(): void {
		$paused = (bool) get_site_option( Settings::NETWORK_PAUSE );
		echo '<div class="wrap axle-ui axr-network">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Review Links', 'axle-review' ) . '</h1>';
		if ( $paused ) {
			echo '<span class="axle-status"><span class="axle-badge axle-badge--warning">' . esc_html__( 'Paused', 'axle-review' ) . '</span></span>';
		}
		echo '<hr class="wp-header-end">';
		echo '<p class="axle-intro">' . esc_html__( 'Review links let someone without an account read a draft and leave notes on it. Here you can turn them all off across the network, for example after a leak.', 'axle-review' ) . '</p>';

		echo '<section class="axle-card' . ( $paused ? '' : ' axle-card--danger' ) . '">';
		echo '<h2 class="axle-card__title">' . esc_html( $paused ? __( 'Links are paused on every site', 'axle-review' ) : __( 'Pause links on every site', 'axle-review' ) ) . '</h2>';
		echo '<p>' . esc_html( $paused ? __( 'Review links are paused on every site of the network. Resuming brings back the links that were still valid.', 'axle-review' ) : __( 'Nobody can open a review link on any site of the network until you resume them. Resuming brings back the links that were still valid.', 'axle-review' ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( network_admin_url( 'edit.php?action=axle_review_network_pause' ) ) . '">';
		wp_nonce_field( 'axle_review_network_pause' );
		echo '<input type="hidden" name="pause" value="' . ( $paused ? '0' : '1' ) . '">';
		if ( $paused ) {
			submit_button( __( 'Resume links on every site', 'axle-review' ), 'secondary', 'submit', false );
		} else {
			echo '<p class="axr-confirm-after">';
			submit_button(
				__( 'Pause links on every site', 'axle-review' ),
				'secondary axle-button--danger',
				'submit',
				false,
				array(
					'data-axr-confirm'       => __( 'Pause review links on every site of the network? Nobody can open one until you resume them. You can undo this with "Resume links on every site".', 'axle-review' ),
					'data-axr-confirm-label' => __( 'Pause links on every site', 'axle-review' ),
				)
			);
			echo '</p>';
		}
		echo '</form></section>';
		AxleUi::footer();
		echo '</div>';
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
