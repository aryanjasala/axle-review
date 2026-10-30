<?php
/**
 * Tests for the inbox, schedules, abilities, lifecycle, privacy and uninstall.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Tests\Unit;

use AryanX\Axle\Review\Abilities;
use AryanX\Axle\Review\Admin\EditorAssets;
use AryanX\Axle\Review\Admin\Inbox;
use AryanX\Axle\Review\Cli\LinkCommand;
use AryanX\Axle\Review\Events;
use AryanX\Axle\Review\Gate;
use AryanX\Axle\Review\Lifecycle;
use AryanX\Axle\Review\Links;
use AryanX\Axle\Review\Notes;
use AryanX\Axle\Review\Privacy;
use AryanX\Axle\Review\Schema;
use AryanX\Axle\Review\Settings;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers the smaller services.
 */
final class MiscTest extends ReviewTestCase {

	public function test_inbox_views_drop_mentions_before_71_and_are_filterable(): void {
		$this->assertArrayHasKey( 'unresolved', Inbox::views() );
		$this->assertArrayNotHasKey( 'mentions', Inbox::views() );

		Filters\expectApplied( 'axle_review_inbox_views' )->once()->andReturn( array( 'resolved' => 'Done', 'made_up' => 'No' ) );
		$this->assertSame( array( 'resolved' => 'Done' ), Inbox::views() );
	}

	public function test_inbox_page_size_is_filtered_and_clamped(): void {
		Filters\expectApplied( 'axle_review_inbox_per_page' )->once()->with( 20 )->andReturn( 500 );
		$this->assertSame( 100, Inbox::per_page() );
	}

	public function test_the_daily_digest_hour_is_filterable(): void {
		Functions\when( 'current_time' )->justReturn( time() );
		Filters\expectApplied( 'axle_review_digest_schedule' )->once()->with( 8 )->andReturn( 99 );

		$next = Schema::digest_start( 'daily' );
		$this->assertGreaterThan( time(), $next );
		$this->assertLessThanOrEqual( time() + DAY_IN_SECONDS, $next );
		$this->assertSame( 0, Schema::digest_start( 'hourly' ) % HOUR_IN_SECONDS );
	}

	public function test_the_editor_poll_interval_is_filtered_and_clamped(): void {
		Filters\expectApplied( 'axle_review_editor_poll_seconds' )->once()->with( 20 )->andReturn( 1 );
		$this->assertSame( 5, EditorAssets::poll_seconds() );
	}

	public function test_cli_durations(): void {
		$this->assertSame( 7 * DAY_IN_SECONDS, LinkCommand::duration( '7d' ) );
		$this->assertSame( 90 * MINUTE_IN_SECONDS, LinkCommand::duration( '90m' ) );
		$this->assertSame( 2 * WEEK_IN_SECONDS, LinkCommand::duration( '2w' ) );
		$this->assertSame( 3600, LinkCommand::duration( '3600' ) );
		$this->assertSame( 0, LinkCommand::duration( 'soon' ) );
	}

	public function test_abilities_are_registered_with_their_annotations(): void {
		$registered = array();
		Functions\when( 'wp_register_ability' )->alias(
			static function ( $name, $args ) use ( &$registered ) {
				$registered[ $name ] = $args['meta'];
			}
		);
		Functions\when( 'current_user_can' )->justReturn( true );

		$abilities = new Abilities( new Settings(), new Links(), new Events(), new Notes(), new Gate( new Settings(), new Notes() ) );
		$abilities->register_abilities();

		$this->assertSame(
			array( 'list-notes', 'get-note-thread', 'add-note', 'resolve-note', 'reopen-note', 'get-publish-readiness', 'list-review-links', 'create-review-link', 'revoke-review-link' ),
			array_map( static fn( $name ) => substr( $name, strlen( 'axle-review/' ) ), array_keys( $registered ) )
		);
		$this->assertTrue( $registered['axle-review/list-notes']['annotations']['readonly'] );
		$this->assertTrue( $registered['axle-review/resolve-note']['annotations']['idempotent'] );
		$this->assertTrue( $registered['axle-review/revoke-review-link']['annotations']['destructive'] );
		$this->assertFalse( $registered['axle-review/add-note']['annotations']['destructive'] );
		$this->assertTrue( $registered['axle-review/add-note']['mcp']['public'] );
	}

	public function test_publishing_or_trashing_revokes_links(): void {
		$this->wpdb->lists[] = array( '7' );
		$this->wpdb->rows[]  = $this->link_row();
		$this->wpdb->rows[]  = $this->link_row( array( 'revoked_at' => '2026-10-30 10:00:00' ) );

		$lifecycle = new Lifecycle( new Links(), new Events() );
		$lifecycle->on_status( 'publish', 'draft', new \WP_Post( array( 'ID' => 42 ) ) );
		$this->assertSame( 'wp_axle_review_links', $this->wpdb->updated[0][0] );

		// A status that keeps links does nothing.
		$lifecycle->on_status( 'pending', 'draft', new \WP_Post( array( 'ID' => 42 ) ) );
		$this->assertCount( 1, $this->wpdb->updated );
	}

	public function test_the_eraser_keeps_note_text_unless_filtered(): void {
		$note = new \WP_Comment( array( 'comment_ID' => '9' ) );
		Functions\when( 'get_comments' )->justReturn( array( $note ) );
		Functions\expect( 'wp_update_comment' )->once()->with( Mockery::on( static fn( $data ) => 'Reviewer' === $data['comment_author'] && '' === $data['comment_author_email'] ) );
		$this->wpdb->vars[]  = 0;
		$this->wpdb->lists[] = array();

		$result = ( new Privacy( new Links(), new Events() ) )->erase( 'legal@example.com' );
		$this->assertTrue( $result['items_removed'] );

		Filters\expectApplied( 'axle_review_erase_deletes_notes' )->once()->andReturn( true );
		Functions\expect( 'wp_delete_comment' )->once()->with( 9, true );
		$this->wpdb->vars[]  = 0;
		$this->wpdb->lists[] = array();
		( new Privacy( new Links(), new Events() ) )->erase( 'legal@example.com' );
	}

	public function test_uninstall_keeps_guest_notes_unless_filtered(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );
		Functions\when( 'delete_metadata' )->justReturn( true );
		$this->wpdb->lists[] = array( '9', '10' );
		Filters\expectApplied( 'axle_review_delete_guest_notes_on_uninstall' )->once()->with( false )->andReturn( true );
		Functions\expect( 'wp_delete_comment' )->twice();

		defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', 'axle-review/axle-review.php' );
		$this->options['axle_review_settings'] = array( 'gate_enabled' => true );
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertArrayNotHasKey( 'axle_review_settings', $this->options );
		$this->assertStringContainsString( 'DROP TABLE IF EXISTS', implode( "\n", $this->wpdb->queries ) );
	}
}
