<?php
/**
 * Tests for settings and the capability filters.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Tests\Unit;

use AryanX\Axle\Review\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Covers Settings.
 */
final class SettingsTest extends ReviewTestCase {

	public function test_defaults_and_sanitising(): void {
		$settings = ( new Settings() )->all();
		$this->assertFalse( $settings['gate_enabled'] );
		$this->assertSame( 7 * DAY_IN_SECONDS, $settings['default_expiry'] );

		$clean = Settings::sanitize(
			array(
				'gate_enabled'      => '1',
				'gate_post_types'   => array( 'post', 'Bad Type!', '' ),
				'default_expiry'    => 999 * DAY_IN_SECONDS,
				'default_max_views' => -4,
			)
		);
		$this->assertTrue( $clean['gate_enabled'] );
		$this->assertSame( array( 'post', 'badtype' ), $clean['gate_post_types'] );
		$this->assertSame( 30 * DAY_IN_SECONDS, $clean['default_expiry'] );
		$this->assertSame( 0, $clean['default_max_views'] );
		$this->assertSame( Settings::sanitize( 'junk' )['gate_guest_notes'], true );
	}

	public function test_update_merges_and_stores(): void {
		( new Settings() )->update( array( 'gate_enabled' => true ) );
		$this->assertTrue( $this->options['axle_review_settings']['gate_enabled'] );
		$this->assertTrue( $this->options['axle_review_settings']['gate_orphans'] );
	}

	public function test_capability_filters_fall_back_on_empty_values(): void {
		Filters\expectApplied( 'axle_review_inbox_capability' )->once()->with( 'edit_posts' )->andReturn( '' );
		$this->assertSame( 'edit_posts', Settings::inbox_capability() );

		Filters\expectApplied( 'axle_review_settings_capability' )->once()->with( 'manage_options' )->andReturn( 'manage_review' );
		$this->assertSame( 'manage_review', Settings::settings_capability() );

		Filters\expectApplied( 'axle_review_gate_override_capability' )->once()->with( 'edit_others_posts' )->andReturn( 'publish_now' );
		$this->assertSame( 'publish_now', Settings::gate_override_capability() );
	}

	public function test_can_share_checks_the_filtered_capability_on_the_post(): void {
		Functions\when( 'get_post' )->justReturn( new \WP_Post( array( 'ID' => 42 ) ) );
		Filters\expectApplied( 'axle_review_capability' )->once()->with( 'edit_post', 42 )->andReturn( 'share_drafts' );
		Functions\expect( 'current_user_can' )->once()->with( 'share_drafts', 42 )->andReturn( true );

		$this->assertTrue( Settings::can_share( 42 ) );
		$this->assertFalse( Settings::can_share( 0 ) );
	}

	public function test_post_types_are_the_ones_with_notes_support_and_filterable(): void {
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page', 'product', 'book' ) );
		Functions\when( 'post_type_supports' )->justReturn( true );
		Functions\when( 'post_type_exists' )->justReturn( true );
		Functions\when( 'get_all_post_type_supports' )->alias(
			static fn( $type ) => 'book' === $type ? array( 'editor' => array( array( 'notes' => true ) ) ) : array( 'editor' => true )
		);

		Filters\expectApplied( 'axle_review_post_types' )->once()->with( array( 'post', 'page', 'book' ) )->andReturnUsing( static fn( $types ) => array_merge( $types, array( 42 ) ) );
		$this->assertSame( array( 'post', 'page', 'book', '42' ), Settings::post_types() );
	}

	public function test_expiry_choices_are_filtered_and_clamped(): void {
		Filters\expectApplied( 'axle_review_max_expiry' )->andReturn( 10 * DAY_IN_SECONDS );
		Filters\expectApplied( 'axle_review_expiry_choices' )->once()->andReturnUsing(
			static function ( $choices ) {
				$choices[30] = 'Too short';
				$choices[ 2 * HOUR_IN_SECONDS ] = 'Two hours';
				return $choices;
			}
		);

		$choices = Settings::expiry_choices();
		$this->assertArrayHasKey( 2 * HOUR_IN_SECONDS, $choices );
		$this->assertArrayNotHasKey( 30, $choices );
		$this->assertArrayNotHasKey( 14 * DAY_IN_SECONDS, $choices );
	}

	public function test_link_defaults_are_filtered_and_clamped(): void {
		$settings = new Settings();

		Filters\expectApplied( 'axle_review_default_expiry' )->once()->andReturn( 999 * DAY_IN_SECONDS );
		$this->assertSame( 30 * DAY_IN_SECONDS, $settings->default_expiry() );

		Filters\expectApplied( 'axle_review_default_max_views' )->once()->with( 0 )->andReturn( -3 );
		$this->assertSame( 0, $settings->default_max_views() );

		Filters\expectApplied( 'axle_review_max_expiry' )->once()->andReturn( 5 );
		$this->assertSame( HOUR_IN_SECONDS, Settings::max_expiry() );
	}

	public function test_guest_visibility_is_filterable(): void {
		Filters\expectApplied( 'axle_review_guest_sees_others' )->once()->with( false, 7 )->andReturn( true );
		$this->assertTrue( Settings::guest_sees_others( false, 7 ) );
	}

	public function test_pausing_and_resuming_fire_actions(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Actions\expectDone( 'axle_review_links_paused' )->once()->with( 1 );
		Settings::set_paused( true );
		$this->assertTrue( Settings::paused() );

		Actions\expectDone( 'axle_review_links_resumed' )->once()->with( 1 );
		Settings::set_paused( false );
		$this->assertFalse( Settings::paused() );
	}

	public function test_the_webhook_is_masked(): void {
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		$this->assertSame( 'https://hooks.slack.com/••••••••wxyz', Settings::mask( 'https://hooks.slack.com/services/T1/B2/abcdwxyz' ) );
		$this->assertSame( '', Settings::mask( '' ) );
	}
}
