<?php
/**
 * Tests for the post fingerprint and the block comparison.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Tests\Unit;

use AryanX\Axle\Review\Fingerprint;
use Brain\Monkey\Functions;

/**
 * Covers Fingerprint.
 */
final class FingerprintTest extends ReviewTestCase {

	/**
	 * The blocks parse_blocks() returns.
	 *
	 * @var array
	 */
	private array $blocks = array();

	/**
	 * Featured image ID.
	 *
	 * @var int
	 */
	private int $image = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->blocks = $this->paragraphs( array( 'One', 'Two', 'Three' ) );
		Functions\when( 'parse_blocks' )->alias( fn() => $this->blocks );
		Functions\when( 'get_post_meta' )->alias( fn() => $this->image );
	}

	private function post( array $fields = array() ): \WP_Post {
		return new \WP_Post( array_merge( array( 'ID' => 42, 'post_title' => 'Spring menu', 'post_excerpt' => 'A short one', 'post_content' => 'x' ), $fields ) );
	}

	public function test_the_same_post_gives_the_same_hash_and_paths_follow_anchor(): void {
		$a = Fingerprint::of( $this->post() );
		$b = Fingerprint::of( $this->post() );

		$this->assertSame( $a['hash'], $b['hash'] );
		$this->assertSame( array( '0', '1', '2' ), array_map( 'strval', array_keys( $a['blocks'] ) ) );
		$this->assertStringStartsWith( 'core/paragraph|', $a['blocks'][0] );
	}

	public function test_a_note_attached_to_a_block_is_not_an_edit(): void {
		$before                               = Fingerprint::of( $this->post() )['hash'];
		$this->blocks[0]['attrs']['metadata'] = array( 'noteId' => 9 );
		$this->assertSame( $before, Fingerprint::of( $this->post() )['hash'] );

		$this->blocks[0]['attrs']['metadata'] = array( 'noteId' => array( 9, 10 ), 'name' => 'Intro' );
		$this->assertNotSame( $before, Fingerprint::of( $this->post() )['hash'], 'A renamed block is an edit.' );
	}

	public function test_text_attributes_title_excerpt_and_image_each_change_the_hash(): void {
		$base = Fingerprint::of( $this->post() )['hash'];

		$this->assertNotSame( $base, Fingerprint::of( $this->post( array( 'post_title' => 'Summer menu' ) ) )['hash'] );
		$this->assertNotSame( $base, Fingerprint::of( $this->post( array( 'post_excerpt' => 'Another' ) ) )['hash'] );

		$this->image = 12;
		$this->assertNotSame( $base, Fingerprint::of( $this->post() )['hash'] );
		$this->image = 0;

		$this->blocks[2]['attrs']['align'] = 'wide';
		$this->assertNotSame( $base, Fingerprint::of( $this->post() )['hash'] );
	}

	public function test_classic_content_is_fingerprinted_too(): void {
		$this->blocks                 = array(
			array( 'blockName' => null, 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p>Old classic text</p>', 'innerContent' => array() ),
		);
		$old                          = Fingerprint::of( $this->post() );
		$this->blocks[0]['innerHTML'] = '<p>New classic text</p>';
		$latest                       = Fingerprint::of( $this->post() );

		$this->assertArrayHasKey( 'f0', $old['blocks'] );
		$this->assertNotSame( $old['hash'], $latest['hash'] );
	}

	public function test_comparing_finds_changed_added_and_removed_blocks(): void {
		$old          = Fingerprint::of( $this->post() );
		$this->blocks = $this->paragraphs( array( 'One', 'Two, edited', 'Three', 'Four' ) );
		$latest       = Fingerprint::of( $this->post() );
		$diff         = Fingerprint::compare( $old, $latest );

		$this->assertFalse( $diff['same'] );
		$this->assertSame( array( '1' ), array_column( $diff['changed'], 'path' ) );
		$this->assertSame( array( '3' ), array_column( $diff['added'], 'path' ) );
		$this->assertSame( array(), $diff['removed'] );
		$this->assertSame( 2, $diff['count'] );

		$gone = Fingerprint::compare( $latest, $old );
		$this->assertSame( array( '1' ), array_column( $gone['changed'], 'path' ) );
		$this->assertSame( array( '3' ), array_column( $gone['removed'], 'path' ) );
	}

	public function test_inserting_a_block_does_not_mark_everything_below_it(): void {
		$old          = Fingerprint::of( $this->post() );
		$this->blocks = $this->paragraphs( array( 'Brand new', 'One', 'Two', 'Three' ) );
		$diff         = Fingerprint::compare( $old, Fingerprint::of( $this->post() ) );

		$this->assertSame( 1, $diff['count'] );
		$this->assertCount( 1, $diff['added'] );
		$this->assertCount( 0, $diff['removed'] );
	}

	public function test_moving_a_block_is_not_a_change_and_repeats_are_counted(): void {
		$old          = Fingerprint::of( $this->post() );
		$this->blocks = $this->paragraphs( array( 'Three', 'One', 'Two' ) );
		$this->assertSame( 0, Fingerprint::compare( $old, Fingerprint::of( $this->post() ) )['count'] );

		$this->blocks = $this->paragraphs( array( 'One', 'One' ) );
		$twice        = Fingerprint::of( $this->post() );
		$this->blocks = $this->paragraphs( array( 'One' ) );
		$once         = Fingerprint::of( $this->post() );
		$this->assertCount( 1, Fingerprint::compare( $twice, $once )['removed'] );
	}

	public function test_title_excerpt_and_image_changes_are_listed_by_name(): void {
		$old         = Fingerprint::of( $this->post() );
		$this->image = 5;
		$latest      = Fingerprint::of( $this->post( array( 'post_title' => 'New title' ) ) );
		$diff        = Fingerprint::compare( $old, $latest );

		$this->assertSame( array( 'title', 'image' ), $diff['meta'] );
		$this->assertSame( array( 'Title', 'Featured image' ), Fingerprint::meta_labels( $diff['meta'] ) );
	}

	public function test_a_stored_snapshot_is_read_back_or_refused(): void {
		$snapshot = Fingerprint::of( $this->post() );

		$this->assertSame( $snapshot, Fingerprint::decode( Fingerprint::encode( $snapshot ) ) );
		$this->assertNull( Fingerprint::decode( '' ) );
		$this->assertNull( Fingerprint::decode( '{"v":99,"hash":"x","blocks":[]}' ) );
		$this->assertNull( Fingerprint::decode( 'not json' ) );
	}

	public function test_the_matching_revision_skips_autosaves_and_needs_revisions_on(): void {
		$post = $this->post( array( 'post_content' => 'same' ) );
		Functions\when( 'post_type_supports' )->justReturn( true );
		Functions\when( 'wp_revisions_to_keep' )->justReturn( -1 );
		Functions\when( 'wp_get_post_revisions' )->justReturn(
			array(
				new \WP_Post( array( 'ID' => 90, 'post_name' => '42-autosave-v1', 'post_content' => 'same' ) ),
				new \WP_Post( array( 'ID' => 80, 'post_name' => '42-revision-v1', 'post_content' => 'older' ) ),
				new \WP_Post( array( 'ID' => 70, 'post_name' => '42-revision-v1', 'post_content' => 'same' ) ),
			)
		);

		$this->assertSame( 70, Fingerprint::matching_revision( $post ) );
		$this->assertSame( 70, Fingerprint::with_revision( $post )['revision'] );
	}

	public function test_with_revisions_off_there_is_no_revision(): void {
		Functions\when( 'post_type_supports' )->justReturn( true );
		Functions\when( 'wp_revisions_to_keep' )->justReturn( 0 );

		$this->assertFalse( Fingerprint::revisions_on( $this->post() ) );
		$this->assertSame( 0, Fingerprint::matching_revision( $this->post() ) );
	}

	public function test_the_old_text_comes_from_the_recorded_revision(): void {
		$revision     = new \WP_Post( array( 'ID' => 70, 'post_parent' => 42, 'post_content' => 'rev' ) );
		$this->blocks = $this->paragraphs( array( 'Old one', 'Old two' ) );
		Functions\when( 'get_post' )->justReturn( $revision );

		$text = Fingerprint::previous_text( 42, array( 'revision' => 70 ), array( '1', '9' ) );
		$this->assertSame( array( '1' => 'Old two' ), $text );
		$this->assertSame( array(), Fingerprint::previous_text( 42, array( 'revision' => 0 ), array( '1' ) ) );

		Functions\when( 'get_post' )->justReturn( new \WP_Post( array( 'ID' => 70, 'post_parent' => 99 ) ) );
		$this->assertSame( array(), Fingerprint::previous_text( 42, array( 'revision' => 70 ), array( '1' ) ), 'A revision of another post is never read.' );
	}
}
