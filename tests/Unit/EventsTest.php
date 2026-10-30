<?php
/**
 * Tests for link activity.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Tests\Unit;

use AryanX\Axle\Review\Events;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Covers Events.
 */
final class EventsTest extends ReviewTestCase {

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] );
		parent::tearDown();
	}

	public function test_browser_families(): void {
		$this->assertSame( 'Chrome', Events::browser( 'Mozilla/5.0 (Macintosh) AppleWebKit/537.36 Chrome/130.0 Safari/537.36' ) );
		$this->assertSame( 'Edge', Events::browser( 'Mozilla/5.0 Chrome/130.0 Safari/537.36 Edg/130.0' ) );
		$this->assertSame( 'Firefox', Events::browser( 'Mozilla/5.0 (X11; Linux) Gecko/20100101 Firefox/131.0' ) );
		$this->assertSame( 'Safari', Events::browser( 'Mozilla/5.0 (iPhone) AppleWebKit/605.1.15 Version/18.0 Mobile Safari/604.1' ) );
		$this->assertSame( 'bot', Events::browser( 'Slackbot-LinkExpanding 1.0' ) );
		$this->assertSame( 'Other', Events::browser( 'curl/8.0' ) );
		$this->assertSame( '', Events::browser( '' ) );
	}

	public function test_ip_addresses_are_anonymised_unless_filtered(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.45';
		Functions\when( 'wp_privacy_anonymize_ip' )->justReturn( '203.0.113.0' );
		$this->assertSame( '203.0.113.0', Events::ip() );

		Filters\expectApplied( 'axle_review_anonymize_ip' )->once()->andReturn( false );
		$this->assertSame( '203.0.113.45', Events::ip() );

		$_SERVER['REMOTE_ADDR'] = 'not an ip';
		$this->assertSame( '', Events::ip() );
	}

	public function test_add_stores_known_types_only(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'Firefox/131.0';
		$events                     = new Events();
		$this->assertSame( 0, $events->add( 7, 'nonsense' ) );
		$this->assertSame( 1, $events->add( 7, 'open' ) );
		$row = $this->wpdb->inserted['wp_axle_review_events'][0];
		$this->assertSame( 'open', $row['type'] );
		$this->assertSame( 'Firefox', $row['ua_family'] );
	}

	public function test_prune_uses_the_retention_filter(): void {
		Filters\expectApplied( 'axle_review_event_retention_days' )->once()->with( 90 )->andReturn( 0 );
		( new Events() )->prune();
		$this->assertStringContainsString( gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), end( $this->wpdb->queries ) );
	}

	public function test_note_counts_default_to_zero(): void {
		$this->wpdb->lists[] = array( array( 'link_id' => '3', 'notes' => '2' ) );
		$this->assertSame( array( 3 => 2, 4 => 0 ), ( new Events() )->note_counts( array( 3, 4 ) ) );
		$this->assertSame( array(), ( new Events() )->note_counts( array() ) );
	}
}
