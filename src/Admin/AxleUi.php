<?php
/**
 * The parts every Axle plugin screen shares.
 *
 * Generated from _dev/repo-template by apply-template.py. Do not edit it in a
 * plugin; change the template and re-apply. See _dev/DESIGN.md.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Admin;

use const AryanX\Axle\Review\PLUGIN_FILE;
use const AryanX\Axle\Review\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Loads axle-ui.css and prints the family footer.
 */
final class AxleUi {

	public const HANDLE = 'axle-review-axle-ui';

	/**
	 * Registers and enqueues the shared stylesheet. Call it from the
	 * plugin's own admin_enqueue_scripts callback, after checking the screen.
	 */
	public static function enqueue(): void {
		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/axle-ui.css', PLUGIN_FILE ), array(), VERSION );
	}

	/**
	 * Prints the one-line footer at the bottom of the plugin's own screens.
	 */
	public static function footer(): void {
		printf(
			'<p class="axle-footer">%1$s &middot; <a href="%2$s">%3$s</a> &middot; <a href="%4$s">%5$s</a></p>',
			esc_html__( 'Part of Axle by AryanX', 'axle-review' ),
			esc_url( 'https://axlewp.com/plugins/axle-review/' ),
			esc_html__( 'Documentation', 'axle-review' ),
			esc_url( 'https://wordpress.org/support/plugin/axle-review/' ),
			esc_html__( 'Support', 'axle-review' )
		);
	}
}
