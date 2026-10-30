<?php
/**
 * Tests for email alerts, digests and Slack.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Tests\Unit;

use AryanX\Axle\Review\Alerts;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers Alerts.
 */
final class AlertsTest extends ReviewTestCase {

	/**
	 * Emails sent.
	 *
	 * @var array[]
	 */
	private array $mail = array();

	/**
	 * User meta, keyed by user then key.
	 *
	 * @var array
	 */
	private array $user_meta = array();

	protected function setUp(): void {
		parent::setUp();
		$this->mail      = array();
		$this->user_meta = array();
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $message ) {
				$this->mail[] = compact( 'to', 'subject', 'message' );
				return true;
			}
		);
		Functions\when( 'get_user_meta' )->alias( fn( $id, $key ) => $this->user_meta[ $id ][ $key ] ?? '' );
		Functions\when( 'update_user_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->user_meta[ $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_userdata' )->alias( static fn( $id ) => new \WP_User( array( 'ID' => (int) $id, 'display_name' => 'User ' . $id, 'user_email' => "user{$id}@example.com" ) ) );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'switch_to_user_locale' )->justReturn( false );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'get_the_title' )->justReturn( 'Spring menu' );
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'wp_trim_words' )->returnArg();
		Functions\when( 'get_post' )->justReturn( new \WP_Post( array( 'ID' => 42, 'post_author' => 3 ) ) );
	}

	/**
	 * A guest note.
	 *
	 * @return \WP_Comment
	 */
	private function note(): \WP_Comment {
		return new \WP_Comment( array( 'comment_ID' => '9', 'comment_post_ID' => '42', 'comment_author' => 'Priya', 'comment_content' => 'Is 42 right?' ) );
	}

	public function test_preferences_default_to_instant(): void {
		$this->assertSame( 'instant', Alerts::preference( 3 ) );
		$this->user_meta[3]['axle_review_notify'] = 'daily';
		$this->assertSame( 'daily', Alerts::preference( 3 ) );
		$this->user_meta[3]['axle_review_notify'] = 'weekly';
		$this->assertSame( 'instant', Alerts::preference( 3 ) );
	}

	public function test_subject_and_message_filters_fall_back_on_empty(): void {
		Filters\expectApplied( 'axle_review_email_subject' )->once()->with( 'Subject', 'digest', array() )->andReturn( '' );
		Filters\expectApplied( 'axle_review_email_message' )->once()->with( 'Body', 'digest', array() )->andReturn( 'Custom body' );

		$this->assertSame( 'Subject', Alerts::subject( 'Subject', 'digest', array() ) );
		$this->assertSame( 'Custom body', Alerts::message( 'Body', 'digest', array() ) );
	}

	public function test_a_guest_note_emails_the_post_author_right_away(): void {
		Filters\expectApplied( 'axle_review_notify_recipients' )->once()->with( array( 3 ), 'guest_note', Mockery::type( 'array' ) )->andReturnUsing( static fn( $ids ) => array_merge( $ids, array( 5, 'x' ) ) );
		$this->user_meta[5]['axle_review_notify'] = 'daily';

		( new Alerts() )->on_guest_note( $this->note(), array( 'id' => 7 ) );

		$this->assertCount( 1, $this->mail );
		$this->assertSame( 'user3@example.com', $this->mail[0]['to'] );
		$this->assertStringContainsString( 'A reviewer left a note on "Spring menu"', $this->mail[0]['subject'] );
		$this->assertStringContainsString( 'Is 42 right?', $this->mail[0]['message'] );
	}

	public function test_a_reply_emails_the_thread_starter(): void {
		Functions\when( 'get_comment' )->justReturn( new \WP_Comment( array( 'comment_ID' => '5', 'user_id' => '4', 'comment_type' => 'note' ) ) );
		$reply = new \WP_Comment( array( 'comment_ID' => '6', 'comment_post_ID' => '42', 'comment_parent' => '5', 'user_id' => '1', 'comment_author' => 'Admin', 'comment_content' => 'Done.' ) );

		( new Alerts() )->on_reply( 6, $reply );

		$this->assertSame( 'user4@example.com', $this->mail[0]['to'] );
		$this->assertStringContainsString( 'New reply to your note', $this->mail[0]['subject'] );
	}

	public function test_activity_replies_send_nothing(): void {
		$resolved = new \WP_Comment( array( 'comment_ID' => '6', 'comment_parent' => '5', 'comment_content' => '' ) );
		( new Alerts() )->on_reply( 6, $resolved );
		$this->assertSame( array(), $this->mail );
	}

	public function test_a_digest_lists_new_items_and_fires_its_action(): void {
		Functions\when( 'get_comments' )->alias(
			fn( $args ) => isset( $args['meta_query'] ) ? array( $this->note() ) : array()
		);
		Functions\when( '_n' )->alias( static fn( $single, $plural, $count ) => 1 === $count ? $single : $plural );
		Actions\expectDone( 'axle_review_digest_sent' )->once()->with( 3, Mockery::type( 'array' ) );

		$this->assertTrue( ( new Alerts() )->send_digest( 3, DAY_IN_SECONDS ) );
		$this->assertStringContainsString( '1 new note', $this->mail[0]['subject'] );
		$this->assertStringContainsString( 'Reviewer note, Priya', $this->mail[0]['message'] );
		$this->assertEqualsWithDelta( time(), $this->user_meta[3]['axle_review_digest_sent'], 2 );

		// Nothing new: no email.
		Functions\when( 'get_comments' )->justReturn( array() );
		$this->assertFalse( ( new Alerts() )->send_digest( 3, DAY_IN_SECONDS ) );
	}

	public function test_slack_uses_the_payload_filter_and_reports_failures(): void {
		$this->options['axle_review_slack'] = 'https://hooks.slack.com/services/x';
		Filters\expectApplied( 'axle_review_slack_payload' )->once()->with( array( 'text' => 'Hello' ), 'test', array() )->andReturn( array( 'text' => 'Changed' ) );
		Functions\expect( 'wp_remote_post' )->once()->with( 'https://hooks.slack.com/services/x', Mockery::on( static fn( $args ) => '{"text":"Changed"}' === $args['body'] ) )->andReturn( array() );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
		Actions\expectDone( 'axle_review_slack_failed' )->once();

		$this->assertFalse( ( new Alerts() )->slack( 'test', 'Hello' ) );
		$this->assertFalse( $this->options['axle_review_slack_status']['ok'] );
	}

	public function test_no_webhook_no_request(): void {
		Functions\expect( 'wp_remote_post' )->never();
		$this->assertFalse( ( new Alerts() )->slack( 'test', 'Hello' ) );
	}
}
