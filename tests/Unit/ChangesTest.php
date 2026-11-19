<?php
/**
 * Tests for working copies of live posts.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Tests\Unit;

use AryanX\Axle\Review\Changes;
use AryanX\Axle\Review\Gate;
use AryanX\Axle\Review\Notes;
use AryanX\Axle\Review\Settings;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Covers Changes. The block parser is replaced by JSON, so the tests cover
 * the logic; the end-to-end suite covers real markup.
 */
final class ChangesTest extends ReviewTestCase {

	/**
	 * Post meta by post ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $meta = array();

	/**
	 * Posts by ID.
	 *
	 * @var array<int, \WP_Post>
	 */
	private array $posts = array();

	protected function setUp(): void {
		parent::setUp();
		$this->meta  = array();
		$this->posts = array();

		Functions\when( 'parse_blocks' )->alias( static fn( $content ) => (array) json_decode( (string) $content, true ) );
		Functions\when( 'serialize_blocks' )->alias( static fn( $blocks ) => (string) wp_json_encode( $blocks ) );
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key = '' ) => $this->meta[ (int) $id ][ $key ] ?? '' );
		Functions\when( 'get_post' )->alias( fn( $id ) => $this->posts[ (int) $id ] ?? null );
		Functions\when( 'get_post_types' )->justReturn( array( 'post' ) );
		Functions\when( 'post_type_supports' )->justReturn( true );
		Functions\when( 'post_type_exists' )->justReturn( true );
		Functions\when( 'get_all_post_type_supports' )->justReturn( array() );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ) => $thing instanceof \WP_Error );
	}

	/**
	 * A post in the fake store.
	 *
	 * @param int    $id     ID.
	 * @param string $status Status.
	 * @return \WP_Post
	 */
	private function post( int $id, string $status = 'publish' ): \WP_Post {
		$this->posts[ $id ] = new \WP_Post(
			array(
				'ID'          => $id,
				'post_type'   => 'post',
				'post_status' => $status,
				'post_title'  => 'Pricing',
			)
		);

		return $this->posts[ $id ];
	}

	/**
	 * The service.
	 *
	 * @return Changes
	 */
	private function changes(): Changes {
		return new Changes( new Gate( new Settings(), new Notes() ) );
	}

	/**
	 * A paragraph block with an optional note.
	 *
	 * @param string $text Text.
	 * @param mixed  $note Note ID or array of IDs; null for none.
	 * @return array
	 */
	private function block( string $text, $note = null ): array {
		return array(
			'blockName'   => 'core/paragraph',
			'attrs'       => null === $note ? array() : array( 'metadata' => array( 'noteId' => $note ) ),
			'innerBlocks' => array(),
			'innerHTML'   => '<p>' . $text . '</p>',
		);
	}

	public function test_a_copy_starts_without_notes(): void {
		$content = (string) wp_json_encode( array( $this->block( 'One', array( 3, 4 ) ), $this->block( 'Two' ) ) );
		$clean   = json_decode( Changes::strip_note_ids( $content ), true );

		$this->assertSame( array(), $clean[0]['attrs'] );
		$this->assertSame( 'core/paragraph', $clean[0]['blockName'] );
		$this->assertSame( '<p>One</p>', $clean[0]['innerHTML'] );
	}

	public function test_stripping_keeps_other_block_metadata_and_inner_blocks(): void {
		$inner         = $this->block( 'Inner', 9 );
		$outer         = $this->block( 'Outer' );
		$outer['attrs'] = array( 'metadata' => array( 'noteId' => 8, 'name' => 'Intro' ) );
		$outer['innerBlocks'] = array( $inner );

		$clean = json_decode( Changes::strip_note_ids( (string) wp_json_encode( array( $outer ) ) ), true );

		$this->assertSame( array( 'metadata' => array( 'name' => 'Intro' ) ), $clean[0]['attrs'] );
		$this->assertSame( array(), $clean[0]['innerBlocks'][0]['attrs'] );
	}

	public function test_content_without_notes_is_left_alone(): void {
		$content = '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->';
		$this->assertSame( $content, Changes::strip_note_ids( $content ) );
	}

	public function test_notes_stay_on_blocks_that_did_not_change(): void {
		$live     = (string) wp_json_encode( array( $this->block( 'Keep me', 5 ), $this->block( 'Old price', 6 ) ) );
		$incoming = (string) wp_json_encode( array( $this->block( 'Keep me' ), $this->block( 'New price' ) ) );

		$merged = json_decode( Changes::carry_notes( $live, $incoming ), true );

		$this->assertSame( array( 5 ), $merged[0]['attrs']['metadata']['noteId'], 'WordPress 7.1 writes an array.' );
		$this->assertArrayNotHasKey( 'metadata', $merged[1]['attrs'], 'The text changed, so its note is not guessed onto the new text.' );
	}

	public function test_two_identical_blocks_each_keep_their_own_notes(): void {
		$live     = (string) wp_json_encode( array( $this->block( 'Same', 5 ), $this->block( 'Same', 6 ) ) );
		$incoming = (string) wp_json_encode( array( $this->block( 'Same' ), $this->block( 'Same' ) ) );

		$merged = json_decode( Changes::carry_notes( $live, $incoming ), true );

		$this->assertSame( array( 5 ), $merged[0]['attrs']['metadata']['noteId'], 'WordPress 7.1 writes an array.' );
		$this->assertSame( array( 6 ), $merged[1]['attrs']['metadata']['noteId'] );
	}

	public function test_content_with_no_old_notes_is_published_as_is(): void {
		$incoming = (string) wp_json_encode( array( $this->block( 'New', 12 ) ) );
		$this->assertSame( $incoming, Changes::carry_notes( (string) wp_json_encode( array( $this->block( 'Old' ) ) ), $incoming ) );
	}

	public function test_internal_meta_stays_out_of_the_copy(): void {
		foreach ( array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_trash_meta_status', '_oembed_abc', '_axr_change_of', '_axr_change_undo' ) as $key ) {
			$this->assertTrue( Changes::skips_meta( $key ), $key );
		}
		foreach ( array( '_thumbnail_id', '_wp_page_template', '_yoast_wpseo_title', 'rank_math_description', 'price' ) as $key ) {
			$this->assertFalse( Changes::skips_meta( $key ), $key );
		}
	}

	public function test_the_skip_list_is_filterable(): void {
		Filters\expectApplied( 'axle_review_change_skip_meta' )->andReturn( array( 'price', 'seo_*' ) );
		$this->assertTrue( Changes::skips_meta( 'price' ) );
		$this->assertTrue( Changes::skips_meta( 'seo_title' ) );
		$this->assertFalse( Changes::skips_meta( '_thumbnail_id' ) );
	}

	public function test_only_a_published_post_can_have_a_copy_made(): void {
		$this->post( 42, 'draft' );
		$error = $this->changes()->request( 42, 1 );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'axle_review_change_status', $error->get_error_code() );
		$this->assertSame( 'axle_review_no_post', $this->changes()->request( 999, 1 )->get_error_code() );
	}

	public function test_a_copy_cannot_have_a_copy(): void {
		$this->post( 42 );
		$this->meta[42][ Changes::META_OF ] = 7;

		$this->assertSame( 'axle_review_change_type', $this->changes()->request( 42, 1 )->get_error_code() );
	}

	public function test_a_second_copy_is_refused_and_names_the_first_only_to_who_can_edit_it(): void {
		$this->post( 42 );
		$this->post( 50, 'draft' );
		$this->meta[42][ Changes::META_COPY ] = 50;
		$this->meta[50][ Changes::META_OF ]   = 42;

		$error = $this->changes()->request( 42, 1 );
		$this->assertSame( 'axle_review_change_exists', $error->get_error_code() );
		$this->assertSame( 50, $error->get_error_data()['copy'] );

		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertSame( 0, $this->changes()->request( 42, 1 )->get_error_data()['copy'] );
	}

	public function test_a_copy_is_not_published_directly(): void {
		$this->post( 50, 'draft' );
		$this->meta[50][ Changes::META_OF ] = 42;
		$this->post( 42 );

		$data = $this->changes()->hold_copy( array( 'post_status' => 'publish' ), array( 'ID' => 50 ) );
		$this->assertSame( 'draft', $data['post_status'] );
		$this->assertArrayHasKey( 'axle_review_change_notice_1', $this->transients );

		// A draft save, and a post that is not a copy, pass through.
		$this->assertSame( 'draft', $this->changes()->hold_copy( array( 'post_status' => 'draft' ), array( 'ID' => 50 ) )['post_status'] );
		$this->assertSame( 'publish', $this->changes()->hold_copy( array( 'post_status' => 'publish' ), array( 'ID' => 42 ) )['post_status'] );
	}

	public function test_a_rest_save_that_publishes_a_copy_is_refused(): void {
		$this->post( 50, 'draft' );
		$this->meta[50][ Changes::META_OF ] = 42;

		$refused = $this->changes()->rest_check( (object) array( 'ID' => 50, 'post_status' => 'publish' ), null );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( Changes::ERROR_USE_MERGE, $refused->get_error_code() );
		$this->assertSame( 409, $refused->get_error_data()['status'] );

		$ok = (object) array( 'ID' => 50, 'post_status' => 'draft' );
		$this->assertSame( $ok, $this->changes()->rest_check( $ok, null ) );
	}

	public function test_get_post_with_zero_is_never_asked_for_the_live_post(): void {
		$this->post( 50, 'draft' );
		$this->assertSame( 0, Changes::original_of( 50 ) );
		$this->assertNull( Changes::live_baseline( $this->posts[50] ) );
		$this->assertFalse( Changes::can_undo( 0 ) );
	}

	public function test_the_undo_state_is_empty_without_a_record(): void {
		$this->post( 42 );
		$this->assertSame(
			array(
				'available' => false,
				'time'      => '',
				'edited'    => false,
			),
			Changes::undo_state( 42 )
		);
		$this->assertSame( 'axle_review_no_undo', $this->changes()->undo( 42, 1 )->get_error_code() );
	}

	public function test_publishing_something_that_is_not_a_copy_is_refused(): void {
		$this->post( 42 );
		$this->assertSame( 'axle_review_change_missing', $this->changes()->publish( 42, 1 )->get_error_code() );
		$this->assertSame( 'axle_review_change_missing', $this->changes()->discard( 42, 1 )->get_error_code() );
	}

	public function test_a_copy_whose_live_post_is_not_published_cannot_go_live(): void {
		$this->post( 50, 'draft' );
		$this->post( 42, 'draft' );
		$this->meta[50][ Changes::META_OF ] = 42;

		$this->assertSame( 'axle_review_change_not_live', $this->changes()->publish( 50, 1 )->get_error_code() );
	}
}
