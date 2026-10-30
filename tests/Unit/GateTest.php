<?php
/**
 * Tests for the publish gate.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Tests\Unit;

use AryanX\Axle\Review\Gate;
use AryanX\Axle\Review\Notes;
use AryanX\Axle\Review\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers Gate.
 */
final class GateTest extends ReviewTestCase {

	/**
	 * Open notes get_comments() returns.
	 *
	 * @var \WP_Comment[]
	 */
	private array $open = array();

	protected function setUp(): void {
		parent::setUp();
		$this->options['axle_review_settings'] = array( 'gate_enabled' => true );
		$this->open                            = array(
			new \WP_Comment( array( 'comment_ID' => '5', 'comment_post_ID' => '42', 'comment_author' => 'Priya' ) ),
		);
		$this->comment_meta[5]['_axr_link_id'] = 7;
		Functions\when( 'get_post_types' )->justReturn( array( 'post' ) );
		Functions\when( 'post_type_supports' )->justReturn( true );
		Functions\when( 'post_type_exists' )->justReturn( true );
		Functions\when( 'get_all_post_type_supports' )->justReturn( array() );
		Functions\when( 'get_comments' )->alias( fn( $args ) => ! empty( $args['count'] ) ? count( $this->open ) : $this->open );
		Functions\when( 'parse_blocks' )->justReturn( array() );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
	}

	/**
	 * The gate.
	 *
	 * @return Gate
	 */
	private function gate(): Gate {
		return new Gate( new Settings(), new Notes() );
	}

	/**
	 * A draft.
	 *
	 * @return \WP_Post
	 */
	private function draft(): \WP_Post {
		return new \WP_Post( array( 'ID' => 42, 'post_type' => 'post', 'post_status' => 'draft' ) );
	}

	public function test_only_moves_into_publish_or_future_are_checked(): void {
		$this->assertTrue( Gate::publishes( 'draft', 'publish' ) );
		$this->assertTrue( Gate::publishes( 'pending', 'future' ) );
		$this->assertFalse( Gate::publishes( 'publish', 'publish' ) );
		$this->assertFalse( Gate::publishes( 'draft', 'pending' ) );
	}

	public function test_open_notes_block_publishing_with_an_error_and_an_action(): void {
		Actions\expectDone( 'axle_review_gate_blocked' )->once()->with( Mockery::type( \WP_Post::class ), $this->open, 'publish' );
		Filters\expectApplied( 'axle_review_gate_message' )->once()->andReturn( 'Resolve the notes first.' );

		$error = $this->gate()->check( $this->draft(), 'publish' );

		$this->assertSame( Gate::ERROR_CODE, $error->get_error_code() );
		$this->assertSame( 'Resolve the notes first.', $error->get_error_message() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
		$this->assertSame( array( 5 ), $error->get_error_data()['notes'] );
	}

	public function test_the_gate_can_be_turned_off_per_post(): void {
		Filters\expectApplied( 'axle_review_gate_enabled' )->once()->with( true, Mockery::type( \WP_Post::class ) )->andReturn( false );
		$this->assertNull( $this->gate()->check( $this->draft(), 'publish' ) );
	}

	public function test_guest_notes_and_single_notes_can_be_left_out(): void {
		$this->options['axle_review_settings']['gate_guest_notes'] = false;
		$this->assertNull( $this->gate()->check( $this->draft(), 'publish' ) );

		$this->options['axle_review_settings']['gate_guest_notes'] = true;
		Filters\expectApplied( 'axle_review_gate_counts_note' )->once()->with( true, $this->open[0], Mockery::type( \WP_Post::class ) )->andReturn( false );
		$this->assertNull( $this->gate()->check( $this->draft(), 'publish' ) );
	}

	public function test_the_rest_check_returns_the_error_or_the_post(): void {
		Functions\when( 'get_post' )->justReturn( $this->draft() );
		$prepared = (object) array( 'ID' => 42, 'post_status' => 'publish' );
		$this->assertInstanceOf( \WP_Error::class, $this->gate()->rest_check( $prepared, null ) );

		$this->open = array();
		$this->assertSame( $prepared, $this->gate()->rest_check( $prepared, null ) );
	}

	public function test_classic_saves_keep_the_old_status(): void {
		Functions\when( 'get_post' )->justReturn( $this->draft() );
		$data = $this->gate()->hold_status( array( 'post_status' => 'publish' ), array( 'ID' => 42 ) );
		$this->assertSame( 'draft', $data['post_status'] );
		$this->assertArrayHasKey( 'axle_review_gate_notice_1', $this->transients );
	}

	public function test_an_override_needs_the_capability_and_a_reason(): void {
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'get_userdata' )->justReturn( new \WP_User( array( 'ID' => 1, 'display_name' => 'Admin', 'user_email' => 'a@example.com' ) ) );
		Functions\when( 'wp_kses' )->returnArg();
		Functions\expect( 'wp_insert_comment' )->once()->andReturn( 77 );
		Functions\when( 'get_comment' )->justReturn( null );

		$this->assertSame( 'axle_review_reason_required', $this->gate()->override( $this->draft(), 1, '  ' )->get_error_code() );

		Actions\expectDone( 'axle_review_gate_overridden' )->once()->with( Mockery::type( \WP_Post::class ), 1, 'Legal signed off by phone.', $this->open );
		$this->assertTrue( $this->gate()->override( $this->draft(), 1, 'Legal signed off by phone.' ) );
		$this->assertTrue( $this->gate()->has_override( 42 ) );
		$this->assertNull( $this->gate()->check( $this->draft(), 'publish' ) );
	}

	public function test_without_the_capability_there_is_no_override(): void {
		Functions\when( 'user_can' )->justReturn( false );
		$this->assertSame( 'axle_review_cannot_override', $this->gate()->override( $this->draft(), 2, 'Because.' )->get_error_code() );
	}
}
