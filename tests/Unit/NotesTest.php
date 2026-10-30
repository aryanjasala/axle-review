<?php
/**
 * Tests for core Notes: guest notes, resolve, reopen and queries.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Tests\Unit;

use AryanX\Axle\Review\Notes;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers Notes.
 */
final class NotesTest extends ReviewTestCase {

	/**
	 * Comments by ID.
	 *
	 * @var array<int, \WP_Comment>
	 */
	private array $comments = array();

	/**
	 * Inserted comments.
	 *
	 * @var array[]
	 */
	private array $inserted = array();

	protected function setUp(): void {
		parent::setUp();
		$this->comments = array();
		$this->inserted = array();
		Functions\when( 'wp_insert_comment' )->alias(
			function ( $data ) {
				$id                    = 100 + count( $this->inserted );
				$this->inserted[ $id ] = $data;
				$this->comments[ $id ] = new \WP_Comment(
					array(
						'comment_ID'       => (string) $id,
						'comment_post_ID'  => (string) $data['comment_post_ID'],
						'comment_parent'   => (string) $data['comment_parent'],
						'comment_approved' => (string) $data['comment_approved'],
						'comment_content'  => $data['comment_content'],
						'user_id'          => (string) $data['user_id'],
					)
				);
				return $id;
			}
		);
		Functions\when( 'get_comment' )->alias( fn( $id ) => $this->comments[ (int) $id ] ?? null );
	}

	public function test_guest_text_is_plain_and_capped(): void {
		Filters\expectApplied( 'axle_review_note_max_length' )->andReturn( 10 );
		$this->assertSame( 'Hello worl', Notes::clean_text( "Hello <b>world</b>\r\nmore" ) );
	}

	public function test_a_guest_note_is_an_open_core_note_with_its_anchor(): void {
		Filters\expectApplied( 'axle_review_note_content' )->once()->with( "About \"42 rupees\":\n\nIs this right?", Mockery::type( 'array' ) )->andReturnUsing( static fn( $text ) => $text . ' <b>!</b>' );

		$id = ( new Notes() )->create_guest_note(
			array(
				'post_id' => 42,
				'link_id' => 7,
				'session' => 'abc',
				'text'    => 'Is this <em>right</em>?',
				'name'    => 'Priya',
				'email'   => 'priya@example.com',
				'anchor'  => array(
					'path'      => '3',
					'hash'      => 'abc123',
					'blockName' => 'core/paragraph',
					'quote'     => '42 rupees',
				),
			)
		);

		$row = $this->inserted[ $id ];
		$this->assertSame( 'note', $row['comment_type'] );
		$this->assertSame( 0, $row['comment_approved'] );
		$this->assertSame( 0, $row['user_id'] );
		$this->assertSame( 'Priya', $row['comment_author'] );
		$this->assertSame( "About \"42 rupees\":\n\nIs this right? !", $row['comment_content'] );
		$this->assertSame( 7, $this->comment_meta[ $id ]['_axr_link_id'] );
		$this->assertSame( '3', json_decode( $this->comment_meta[ $id ]['_axr_anchor'], true )['path'] );
		$this->assertArrayHasKey( '_axr_pending_attach', $this->comment_meta[ $id ] );
	}

	public function test_an_empty_guest_note_is_refused(): void {
		$this->assertSame( 'axle_review_empty', ( new Notes() )->create_guest_note( array( 'text' => '<b></b>' ) )->get_error_code() );
	}

	public function test_resolving_writes_the_activity_reply_and_fires_once(): void {
		$this->comments[5] = new \WP_Comment( array( 'comment_ID' => '5', 'comment_post_ID' => '42' ) );
		Functions\when( 'get_userdata' )->justReturn( new \WP_User( array( 'ID' => 1, 'display_name' => 'Admin', 'user_email' => 'a@example.com' ) ) );
		Functions\expect( 'wp_set_comment_status' )->once()->with( 5, 'approve' )->andReturnUsing(
			function () {
				$this->comments[5]->comment_approved = '1';
				return true;
			}
		);
		Actions\expectDone( 'axle_review_note_resolved' )->once()->with( Mockery::type( \WP_Comment::class ), 1 );

		$notes = new Notes();
		$this->assertTrue( $notes->set_status( 5, 'resolved', 1 ) );
		$reply = array_key_last( $this->inserted );
		$this->assertSame( 5, $this->inserted[ $reply ]['comment_parent'] );
		$this->assertSame( '', $this->inserted[ $reply ]['comment_content'] );
		$this->assertSame( 'resolved', $this->comment_meta[ $reply ]['_wp_note_status'] );

		// Again: nothing changes.
		$this->assertTrue( $notes->set_status( 5, 'resolved', 1 ) );
		$this->assertCount( 1, $this->inserted );
	}

	public function test_reopening_fires_its_action(): void {
		$this->comments[5] = new \WP_Comment( array( 'comment_ID' => '5', 'comment_approved' => '1' ) );
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'wp_set_comment_status' )->justReturn( true );
		Actions\expectDone( 'axle_review_note_reopened' )->once();

		$this->assertTrue( ( new Notes() )->set_status( 5, 'open', 1 ) );
		$this->assertSame( 'reopen', $this->comment_meta[ array_key_last( $this->inserted ) ]['_wp_note_status'] );
	}

	public function test_replies_cannot_be_resolved(): void {
		$this->comments[6] = new \WP_Comment( array( 'comment_ID' => '6', 'comment_parent' => '5' ) );
		$this->assertSame( 'axle_review_no_note', ( new Notes() )->set_status( 6, 'resolved', 1 )->get_error_code() );
	}

	public function test_query_arguments_for_the_inbox_views(): void {
		$this->assertSame( 'hold', Notes::query_args( array() )['status'] );
		$this->assertSame( 'approve', Notes::query_args( array( 'status' => 'resolved' ) )['status'] );
		$this->assertSame( array( 3 ), Notes::query_args( array( 'on_posts_by' => 3 ) )['post_author__in'] );
		$this->assertSame( '_axr_link_id', Notes::query_args( array( 'guests' => true ) )['meta_query'][0]['key'] );
		$this->assertSame( 0, Notes::query_args( array() )['parent'] );
	}

	public function test_mentions_are_written_as_core_chips(): void {
		$user = new \WP_User( array( 'ID' => 4, 'display_name' => 'Maria' ) );
		$this->assertSame( '<span class="wp-note-mention user-4">@Maria</span>', Notes::mention( $user ) );
	}

	public function test_counts_are_cached(): void {
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\expect( 'wp_cache_set' )->atLeast()->once();
		Functions\expect( 'get_comments' )->once()->andReturn( 3 );

		$this->assertSame( 3, ( new Notes() )->unresolved_count( 42 ) );
	}
}
