<?php
/**
 * Tests for linking guest notes to their block.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Tests\Unit;

use AryanX\Axle\Review\Anchor;
use AryanX\Axle\Review\Attacher;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers Attacher.
 */
final class AttacherTest extends ReviewTestCase {

	/**
	 * A guest note on post 42.
	 *
	 * @param int $id Note ID.
	 * @return \WP_Comment
	 */
	private function note( int $id = 9 ): \WP_Comment {
		return new \WP_Comment(
			array(
				'comment_ID'      => (string) $id,
				'comment_post_ID' => '42',
				'comment_content' => 'Check this.',
			)
		);
	}

	public function test_place_puts_the_note_on_its_block(): void {
		$blocks                       = $this->paragraphs( array( 'One', 'Two', 'Three' ) );
		$this->comment_meta[9]['_axr_anchor'] = json_encode( array( 'path' => '2', 'hash' => Anchor::hash( $blocks[4] ) ) );
		Filters\expectApplied( 'axle_review_anchor' )->once()->with( array( 'path' => '2', 'orphan' => false ), Mockery::type( \WP_Comment::class ), Mockery::type( 'array' ), $blocks );

		$placed = ( new Attacher() )->place( $blocks, $this->note() );

		$this->assertSame( '2', $placed['path'] );
		$this->assertFalse( $placed['orphan'] );
		$this->assertSame( array( 9 ), Anchor::note_ids( $placed['blocks'][4] ) );
	}

	public function test_a_note_whose_block_is_gone_goes_first_with_its_quote(): void {
		$blocks                               = $this->paragraphs( array( 'One' ) );
		$this->comment_meta[9]['_axr_anchor'] = json_encode( array( 'path' => '5', 'hash' => 'ffffffffffff', 'quote' => 'old words' ) );
		Functions\expect( 'wp_update_comment' )->once()->with( Mockery::on( static fn( $data ) => 0 === strpos( $data['comment_content'], 'About "old words":' ) ) );
		Actions\expectDone( 'axle_review_guest_note_orphaned' )->once();

		$placed = ( new Attacher() )->place( $blocks, $this->note() );

		$this->assertSame( '0', $placed['path'] );
		$this->assertTrue( $placed['orphan'] );
		$this->assertSame( 1, $this->comment_meta[9]['_axr_orphaned'] );
	}

	public function test_the_anchor_filter_can_leave_a_note_unattached(): void {
		$this->comment_meta[9]['_axr_anchor'] = json_encode( array( 'path' => '0' ) );
		Filters\expectApplied( 'axle_review_anchor' )->once()->andReturn( null );

		$this->assertSame( 'axle_review_no_block', ( new Attacher() )->place( $this->paragraphs( array( 'One' ) ), $this->note() )->get_error_code() );
	}

	public function test_a_save_without_a_pending_note_gets_it_back(): void {
		$stale  = $this->paragraphs( array( 'One', 'Two' ) );
		$stored = Anchor::add_note_id( $stale, '1', 9, 'array' );
		$this->comment_meta[9]['_axr_anchor']         = json_encode( array( 'path' => '1', 'hash' => Anchor::hash( $stale[2] ) ) );
		$this->comment_meta[9]['_axr_pending_attach'] = time();

		Functions\when( 'get_comments' )->justReturn( array( $this->note() ) );
		Functions\when( 'parse_blocks' )->alias( static fn( $content ) => 'stale' === $content ? $stale : $stored );
		Functions\when( 'serialize_blocks' )->alias( static fn( $blocks ) => 9 === ( Anchor::note_ids( $blocks[2] )[0] ?? 0 ) ? 'with-note' : 'without' );

		$data = ( new Attacher() )->keep_pending( array( 'post_content' => 'stale', 'post_type' => 'post' ), array( 'ID' => 42 ) );

		$this->assertSame( 'with-note', $data['post_content'] );
		$this->assertArrayHasKey( '_axr_pending_attach', $this->comment_meta[9] );
	}

	public function test_a_save_that_has_the_note_clears_the_flag(): void {
		$stored = Anchor::add_note_id( $this->paragraphs( array( 'One', 'Two' ) ), '1', 9, 'array' );
		$this->comment_meta[9]['_axr_pending_attach'] = time();

		Functions\when( 'get_comments' )->justReturn( array( $this->note() ) );
		Functions\when( 'parse_blocks' )->justReturn( $stored );
		Functions\when( 'get_post' )->justReturn( new \WP_Post( array( 'ID' => 42, 'post_content' => 'stored' ) ) );

		$data = ( new Attacher() )->keep_pending( array( 'post_content' => 'fresh', 'post_type' => 'post' ), array( 'ID' => 42 ) );

		$this->assertSame( 'fresh', $data['post_content'] );
		$this->assertArrayNotHasKey( '_axr_pending_attach', $this->comment_meta[9] );
	}

	public function test_old_pending_flags_expire(): void {
		$this->comment_meta[9]['_axr_pending_attach'] = time() - 2 * DAY_IN_SECONDS;
		Functions\when( 'get_comments' )->justReturn( array( $this->note() ) );

		$this->assertSame( array(), ( new Attacher() )->pending( 42 ) );
		$this->assertArrayNotHasKey( '_axr_pending_attach', $this->comment_meta[9] );
	}

	public function test_only_the_attach_write_skips_revisions(): void {
		$attacher = new Attacher();
		$this->assertSame( 25, $attacher->no_revision( 25, new \WP_Post( array( 'ID' => 42 ) ) ) );
	}
}
