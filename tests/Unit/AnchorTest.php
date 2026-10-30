<?php
/**
 * Tests for finding blocks and linking notes to them.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Tests\Unit;

use AryanX\Axle\Review\Anchor;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Covers Anchor.
 */
final class AnchorTest extends ReviewTestCase {

	public function test_paths_skip_whitespace_blocks_and_count_inner_blocks(): void {
		$blocks                             = $this->paragraphs( array( 'One', 'Two' ) );
		$blocks[2]['innerBlocks']           = $this->paragraphs( array( 'Inner' ) );
		$paths                              = array();
		Anchor::walk(
			$blocks,
			static function ( $block, $path ) use ( &$paths ) {
				$paths[ $path ] = Anchor::text( $block );
			}
		);

		$this->assertSame(
			array(
				'0'   => 'One',
				'1'   => 'Two',
				'1.0' => 'Inner',
			),
			$paths
		);
		$this->assertSame( 'Inner', Anchor::text( (array) Anchor::at( $blocks, '1.0' ) ) );
		$this->assertNull( Anchor::at( $blocks, '5' ) );
	}

	public function test_the_hash_follows_the_text_not_the_attributes(): void {
		$block = $this->paragraphs( array( 'Hello <strong>world</strong>' ) )[0];
		$same  = $block;
		$same['attrs']['metadata'] = array( 'noteId' => 3 );
		$other = $this->paragraphs( array( 'Hello there' ) )[0];

		$this->assertSame( 12, strlen( Anchor::hash( $block ) ) );
		$this->assertSame( Anchor::hash( $block ), Anchor::hash( $same ) );
		$this->assertNotSame( Anchor::hash( $block ), Anchor::hash( $other ) );
	}

	public function test_mark_adds_path_and_hash_attributes(): void {
		$marked = Anchor::mark( $this->paragraphs( array( 'One', 'Two' ) ) );

		$this->assertSame( '1', $marked[2]['attrs']['axrPath'] );
		$this->assertSame( Anchor::hash( $marked[2] ), $marked[2]['attrs']['axrHash'] );
		$this->assertArrayNotHasKey( 'axrPath', $marked[1]['attrs'] );
	}

	public function test_shape_is_an_array_from_71_and_a_scalar_before(): void {
		$this->assertSame( 'array', Anchor::shape() );

		Functions\when( 'get_bloginfo' )->justReturn( '6.9.10' );
		$this->assertSame( 'scalar', Anchor::shape() );
	}

	public function test_the_shape_filter_is_applied_and_cleaned(): void {
		Filters\expectApplied( 'axle_review_note_id_shape' )->once()->with( 'array' )->andReturn( 'nonsense' );
		$this->assertSame( 'scalar', Anchor::shape() );
	}

	public function test_adding_a_note_id_in_each_shape(): void {
		$blocks = $this->paragraphs( array( 'One', 'Two' ) );

		$scalar = Anchor::add_note_id( $blocks, '1', 9, 'scalar' );
		$this->assertSame( 9, $scalar[2]['attrs']['metadata']['noteId'] );
		$this->assertSame( array( 9 ), Anchor::note_ids( $scalar[2] ) );

		$array = Anchor::add_note_id( $blocks, '1', 9, 'array' );
		$this->assertSame( array( 9 ), $array[2]['attrs']['metadata']['noteId'] );

		// A block with a scalar takes a second note only as an array.
		$this->assertNull( Anchor::add_note_id( $scalar, '1', 10, 'scalar' ) );
		$both = Anchor::add_note_id( $scalar, '1', 10, 'array' );
		$this->assertSame( array( 9, 10 ), $both[2]['attrs']['metadata']['noteId'] );

		// Adding the same ID changes nothing; a missing path gives null.
		$this->assertSame( $both, Anchor::add_note_id( $both, '1', 10, 'array' ) );
		$this->assertNull( Anchor::add_note_id( $blocks, '4', 9, 'array' ) );
	}

	public function test_resolve_uses_the_path_then_the_hash_then_the_first_block(): void {
		$blocks = $this->paragraphs( array( 'One', 'Two', 'Three' ) );
		$hash   = Anchor::hash( $blocks[4] );

		$this->assertSame( array( 'path' => '2', 'orphan' => false ), Anchor::resolve( $blocks, array( 'path' => '2', 'hash' => $hash ), 'array' ) );

		// The block moved: found by its hash.
		$moved = array_merge( $this->paragraphs( array( 'New first' ) ), $blocks );
		$this->assertSame( array( 'path' => '3', 'orphan' => false ), Anchor::resolve( $moved, array( 'path' => '2', 'hash' => $hash ), 'array' ) );

		// The block is gone: the first block, as an orphan.
		$gone = $this->paragraphs( array( 'One', 'Two' ) );
		$this->assertSame( array( 'path' => '0', 'orphan' => true ), Anchor::resolve( $gone, array( 'path' => '2', 'hash' => $hash ), 'array' ) );
	}

	public function test_resolve_skips_blocks_that_cannot_take_another_scalar_note(): void {
		$blocks = Anchor::add_note_id( $this->paragraphs( array( 'One', 'Two' ) ), '0', 4, 'scalar' );
		$hash   = Anchor::hash( $blocks[0] );

		$this->assertSame( array( 'path' => '1', 'orphan' => true ), Anchor::resolve( $blocks, array( 'path' => '0', 'hash' => $hash ), 'scalar' ) );

		$full = Anchor::add_note_id( $blocks, '1', 5, 'scalar' );
		$this->assertNull( Anchor::resolve( $full, array( 'path' => '0', 'hash' => $hash ), 'scalar' ) );
	}

	public function test_finding_notes(): void {
		$blocks = Anchor::add_note_id( $this->paragraphs( array( 'One', 'Two' ) ), '1', 12, 'array' );
		$blocks = Anchor::add_note_id( $blocks, '1', 13, 'array' );

		$this->assertSame( '1', Anchor::find_note( $blocks, 13 ) );
		$this->assertNull( Anchor::find_note( $blocks, 99 ) );
		$this->assertSame( array( 12, 13 ), Anchor::all_note_ids( $blocks ) );
	}
}
