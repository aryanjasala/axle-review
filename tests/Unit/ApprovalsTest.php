<?php
/**
 * Tests for sign-off: decisions, outdated approvals, asking again, visits.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Tests\Unit;

use AryanX\Axle\Review\Approvals;
use AryanX\Axle\Review\Fingerprint;
use AryanX\Axle\Review\Links;
use AryanX\Axle\Review\Visits;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers Approvals and Visits.
 */
final class ApprovalsTest extends ReviewTestCase {

	/**
	 * The blocks parse_blocks() returns.
	 *
	 * @var array
	 */
	private array $blocks = array();

	/**
	 * The post get_post() returns.
	 *
	 * @var \WP_Post
	 */
	private \WP_Post $post;

	/**
	 * Comment calls the plugin made: status changes and inserts.
	 *
	 * @var array
	 */
	private array $calls = array();

	protected function setUp(): void {
		parent::setUp();
		$this->calls            = array();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->blocks           = $this->paragraphs( array( 'One', 'Two' ) );
		$this->post             = new \WP_Post( array( 'ID' => 42, 'post_type' => 'post', 'post_title' => 'Spring menu', 'post_status' => 'draft', 'post_content' => 'x' ) );

		Functions\when( 'parse_blocks' )->alias( fn() => $this->blocks );
		Functions\when( 'get_post' )->alias( fn() => $this->post );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'post_type_supports' )->justReturn( true );
		Functions\when( 'get_post_types' )->justReturn( array( 'post' ) );
		Functions\when( 'post_type_exists' )->justReturn( true );
		Functions\when( 'get_all_post_type_supports' )->justReturn( array() );
		Functions\when( 'wp_revisions_to_keep' )->justReturn( -1 );
		Functions\when( 'wp_get_post_revisions' )->justReturn( array() );
		Functions\when( 'wp_privacy_anonymize_ip' )->returnArg();
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_update_comment' )->justReturn( 1 );
		Functions\when( 'wp_set_comment_status' )->alias(
			function ( $id, $status ) {
				$this->calls[] = array( 'status', $id, $status );
				return true;
			}
		);
		Functions\when( 'wp_insert_comment' )->alias(
			function () {
				$this->calls[] = array( 'insert' );
				return 77;
			}
		);
		Functions\when( 'get_comment' )->alias( fn( $id ) => 77 === (int) $id ? new \WP_Comment( array( 'comment_ID' => '77', 'comment_post_ID' => '42' ) ) : null );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	private function link( array $fields = array() ): array {
		return Links::normalize( $this->link_row( $fields ) );
	}

	/**
	 * Columns written by the last update, keyed by column.
	 *
	 * @return array
	 */
	private function written(): array {
		$merged = array();
		foreach ( $this->wpdb->updated as $update ) {
			$merged = array_merge( $merged, $update[1] );
		}
		return $merged;
	}

	private function approved_row( array $fields = array() ): array {
		return array_merge(
			array(
				'decision'          => 'approved',
				'reviewer_name'     => 'Priya',
				'decided_at'        => '2026-11-10 10:00:00',
				'decision_snapshot' => Fingerprint::encode( Fingerprint::of( $this->post ) ),
			),
			$fields
		);
	}

	public function test_a_reviewer_can_approve_and_the_decision_is_stored_and_mirrored(): void {
		$this->wpdb->rows[] = $this->link_row( $this->approved_row() );
		Actions\expectDone( 'axle_review_approved' )->once()->with( Mockery::type( 'array' ), $this->post, '' );
		Actions\expectDone( 'axle_review_guest_note_created' )->once();

		$state = $this->approvals()->decide( $this->link(), 'approved', 'Priya', '  Looks right.  ' );

		$written = $this->written();
		$this->assertSame( 'approved', $written['decision'] );
		$this->assertSame( 'Priya', $written['reviewer_name'] );
		$this->assertSame( 'Looks right.', $written['decision_comment'] );
		$this->assertSame( 77, $written['decision_note_id'] );
		$this->assertNull( $written['outdated_at'] );
		$this->assertArrayHasKey( 'hash', Fingerprint::decode( $written['decision_snapshot'] ) );
		$this->assertSame( 'decision', $this->wpdb->inserted['wp_axle_review_events'][0]['type'] );
		$this->assertSame( 'approved', $this->comment_meta[77]['_axr_decision'] );
		$this->assertSame( 7, $this->comment_meta[77]['_axr_link_id'] );
		$this->assertSame( array( array( 'insert' ) ), $this->calls );
		$this->assertSame( 'approved', $state['decision'] );
		$this->assertFalse( $state['edited'] );
	}

	public function test_asking_for_changes_needs_a_comment_and_every_decision_needs_a_name(): void {
		$this->assertSame( 'axle_review_comment_required', $this->approvals()->decide( $this->link(), 'changes_requested', 'Priya', ' ' )->get_error_code() );
		$this->assertSame( 'axle_review_name_required', $this->approvals()->decide( $this->link(), 'approved', '  ' )->get_error_code() );
		$this->assertSame( 'axle_review_bad_decision', $this->approvals()->decide( $this->link(), 'maybe', 'Priya' )->get_error_code() );
		$this->assertSame( array(), $this->wpdb->updated, 'Nothing was saved.' );
	}

	public function test_an_answer_to_a_page_that_has_changed_since_is_refused(): void {
		$seen = Fingerprint::of( $this->post )['hash'];
		$this->blocks = $this->paragraphs( array( 'One', 'Two, edited' ) );

		$error = $this->approvals()->decide( $this->link(), 'approved', 'Priya', '', $seen );

		$this->assertSame( 'axle_review_changed_meanwhile', $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
		$this->assertSame( array(), $this->wpdb->updated );
	}

	public function test_changing_ones_mind_updates_the_same_note_and_reports_the_earlier_decision(): void {
		$this->wpdb->rows[] = $this->link_row( $this->approved_row( array( 'decision' => 'changes_requested', 'decision_note_id' => '77' ) ) );
		Actions\expectDone( 'axle_review_changes_requested' )->once()->with( Mockery::type( 'array' ), $this->post, 'approved' );

		$this->approvals()->decide( $this->link( array( 'decision' => 'approved', 'decision_note_id' => '77' ) ), 'changes_requested', 'Priya', 'Fix the price.' );
		$this->assertSame( array( array( 'status', 77, 'hold' ) ), $this->calls, 'The note is updated, not inserted again.' );
		$this->assertSame( 'changes_requested', $this->comment_meta[77]['_axr_decision'] );
	}

	public function test_an_edit_after_an_approval_marks_it_outdated_once(): void {
		$this->wpdb->lists[] = array( $this->link_row( $this->approved_row() ) );
		$approved            = $this->approved_row();
		$this->blocks        = $this->paragraphs( array( 'One', 'Two, edited' ) );
		Actions\expectDone( 'axle_review_approval_outdated' )->once()->with( Mockery::type( 'array' ), $this->post, Mockery::on( fn( $changes ) => 1 === count( $changes['changed'] ) ) );

		$this->approvals()->post_saved( 42, $this->post );
		$this->assertNotEmpty( $this->written()['outdated_at'] );

		// Already marked: no second action.
		$this->wpdb->updated = array();
		$this->wpdb->lists[] = array( $this->link_row( array_merge( $approved, array( 'outdated_at' => '2026-11-11 10:00:00' ) ) ) );
		$this->approvals()->post_saved( 42, $this->post );
		$this->assertSame( array(), $this->wpdb->updated );
	}

	public function test_putting_the_post_back_makes_the_approval_fresh_again(): void {
		$this->wpdb->lists[] = array( $this->link_row( $this->approved_row( array( 'outdated_at' => '2026-11-11 10:00:00' ) ) ) );
		Actions\expectDone( 'axle_review_approval_outdated' )->never();

		$this->approvals()->post_saved( 42, $this->post );
		$this->assertArrayHasKey( 'outdated_at', $this->written() );
		$this->assertNull( $this->written()['outdated_at'] );
	}

	public function test_posts_that_cannot_have_links_cost_nothing(): void {
		$order = new \WP_Post( array( 'ID' => 9, 'post_type' => 'shop_order', 'post_status' => 'publish' ) );
		Functions\when( 'get_post_types' )->justReturn( array( 'post' ) );

		$this->approvals()->post_saved( 9, $order );
		$this->assertSame( array(), $this->wpdb->queries, 'No database query for a post type the plugin does not cover.' );
	}

	public function test_revisions_autosaves_and_posts_without_approvals_are_left_alone(): void {
		Functions\when( 'wp_is_post_revision' )->justReturn( true );
		$this->approvals()->post_saved( 42, $this->post );
		$this->assertSame( array(), $this->wpdb->queries );

		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		$this->wpdb->lists[] = array( $this->link_row() );
		$this->approvals()->post_saved( 42, $this->post );
		$this->assertSame( array(), $this->wpdb->updated, 'No approval, no work.' );
	}

	public function test_only_the_featured_image_key_triggers_a_recheck_from_meta(): void {
		$this->approvals()->meta_changed( 1, 42, '_edit_lock' );
		$this->assertSame( array(), $this->wpdb->queries );

		$this->wpdb->lists[] = array();
		$this->approvals()->meta_changed( 1, 42, '_thumbnail_id' );
		$this->assertCount( 1, $this->wpdb->queries );
	}

	public function test_the_state_lists_what_changed_with_labels_and_can_ask_again(): void {
		$snapshot     = Fingerprint::encode( Fingerprint::of( $this->post ) );
		$this->blocks = $this->paragraphs( array( 'One', 'Two, edited', 'Three' ) );
		$state        = $this->approvals()->link_state( $this->link( array( 'decision' => 'approved', 'reviewer_name' => 'Priya', 'decision_snapshot' => $snapshot ) ), $this->post );

		$this->assertTrue( $state['edited'] );
		$this->assertTrue( $state['outdated'] );
		$this->assertTrue( $state['can_ask'] );
		$this->assertSame( 'Paragraph', $state['changes']['changed'][0]['label'] );
		$this->assertCount( 1, $state['changes']['added'] );

		$none = $this->approvals()->link_state( $this->link(), $this->post );
		$this->assertSame( '', $none['decision'] );
		$this->assertFalse( $none['edited'] );
	}

	public function test_the_summary_text_reads_like_a_person_would_say_it(): void {
		$base = array( 'reviewers' => 3, 'approved' => 2, 'outdated' => 0, 'changes' => 0, 'waiting' => 1 );

		$this->assertSame( '2 of 3 approved', Approvals::summary_text( $base ) );
		$this->assertSame( '1 of 3 approved, 1 edited since approval', Approvals::summary_text( array_merge( $base, array( 'approved' => 1, 'outdated' => 1 ) ) ) );
		$this->assertSame( 'Changes requested by Priya', Approvals::summary_text( array_merge( $base, array( 'changes' => 1 ) ), array( 'Priya' ) ) );
		$this->assertSame( 'Changes requested by Priya and 1 more', Approvals::summary_text( array_merge( $base, array( 'changes' => 2 ) ), array( 'Priya', 'Sam' ) ) );
		$this->assertSame( 'Waiting for 2 reviewers', Approvals::summary_text( array( 'reviewers' => 2, 'approved' => 0, 'outdated' => 0, 'changes' => 0, 'waiting' => 2 ) ) );
		$this->assertSame( '', Approvals::summary_text( array( 'reviewers' => 0, 'approved' => 0, 'outdated' => 0, 'changes' => 0, 'waiting' => 0 ) ) );

		Filters\expectApplied( 'axle_review_state_text' )->once()->andReturn( 'Custom' );
		$this->assertSame( 'Custom', Approvals::summary_text( $base ) );
	}

	public function test_the_post_state_skips_revoked_links_and_counts_each_kind(): void {
		$this->wpdb->lists[] = array(
			$this->link_row( $this->approved_row( array( 'id' => '1' ) ) ),
			$this->link_row( $this->approved_row( array( 'id' => '2', 'decision' => 'changes_requested', 'reviewer_name' => 'Sam' ) ) ),
			$this->link_row( array( 'id' => '3' ) ),
			$this->link_row( $this->approved_row( array( 'id' => '4', 'revoked_at' => '2026-11-11 10:00:00' ) ) ),
		);

		$state = $this->approvals()->post_state( $this->post );

		$this->assertSame( 3, $state['summary']['reviewers'] );
		$this->assertSame( 1, $state['summary']['approved'] );
		$this->assertSame( 1, $state['summary']['changes'] );
		$this->assertSame( 1, $state['summary']['waiting'] );
		$this->assertSame( 'Changes requested by Sam', $state['text'] );
		$this->assertTrue( $state['revisions'] );
	}

	public function test_gate_counts_ignore_approvals_of_an_older_version_when_asked(): void {
		$this->options['axle_review_settings'] = array( 'gate_min_approvals' => 2 );
		$old                                   = Fingerprint::encode( Fingerprint::of( $this->post ) );
		$this->blocks                          = $this->paragraphs( array( 'One', 'Changed' ) );

		$this->wpdb->lists[] = array(
			$this->link_row( $this->approved_row( array( 'id' => '1' ) ) ),
			$this->link_row( $this->approved_row( array( 'id' => '2', 'decision_snapshot' => $old ) ) ),
		);
		$strict = $this->approvals()->gate_count( $this->post );
		$this->assertSame( array( 2, 1, 2, 1, false ), array( $strict['required'], $strict['counted'], $strict['approved'], $strict['outdated'], $strict['met'] ) );

		$this->options['axle_review_settings']['gate_fresh_only'] = false;
		$this->wpdb->lists[]                                      = array( $this->link_row( $this->approved_row( array( 'decision_snapshot' => $old ) ) ), $this->link_row( $this->approved_row( array( 'id' => '2' ) ) ) );
		$this->assertTrue( $this->approvals()->gate_count( $this->post )['met'] );
	}

	public function test_the_required_approvals_are_clamped_and_filterable(): void {
		$this->options['axle_review_settings'] = array( 'gate_min_approvals' => 500 );
		$this->assertSame( 20, Approvals::required_approvals() );

		Filters\expectApplied( 'axle_review_gate_min_approvals' )->once()->with( 500 )->andReturn( -3 );
		$this->assertSame( 0, Approvals::required_approvals() );
	}

	public function test_asking_again_gives_the_link_a_new_url_and_emails_the_address(): void {
		$row                 = $this->link_row( $this->approved_row( array( 'email' => 'legal@example.com' ) ) );
		$this->wpdb->rows[]  = $row;
		$this->wpdb->rows[]  = $row;
		$this->wpdb->rows[]  = array_merge( $row, array( 'reask_at' => '2026-11-12 10:00:00' ) );
		Functions\when( 'get_userdata' )->justReturn( new \WP_User( array( 'ID' => 1, 'display_name' => 'Aryan' ) ) );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'get_the_title' )->justReturn( 'Spring menu' );
		$sent = array();
		Functions\when( 'wp_mail' )->alias(
			static function ( $to, $subject, $message ) use ( &$sent ) {
				$sent = array( $to, $subject, $message );
				return true;
			}
		);
		Actions\expectDone( 'axle_review_reask_sent' )->once()->with( Mockery::type( 'array' ), 1, true );

		$result = $this->approvals()->ask_again( 7, 1 );

		$this->assertSame( 'legal@example.com', $sent[0] );
		$this->assertStringContainsString( $result['url'], $sent[2] );
		$this->assertStringContainsString( 'axle_review=', $result['url'] );
		$this->assertTrue( $result['emailed'] );
		$this->assertSame( Links::hash( substr( $result['url'], strpos( $result['url'], '=' ) + 1 ) ), $this->wpdb->updated[0][1]['token_hash'] );
		$this->assertNotEmpty( $this->written()['reask_at'] );
		$this->assertGreaterThan( time() + 2 * DAY_IN_SECONDS, strtotime( $this->written()['expires_at'] . ' UTC' ) );
	}

	public function test_nobody_is_asked_again_who_has_not_decided_or_whose_link_ended(): void {
		$this->wpdb->rows[] = $this->link_row();
		$this->assertSame( 'axle_review_no_decision', $this->approvals()->ask_again( 7, 1 )->get_error_code() );

		$this->wpdb->rows[] = $this->link_row( $this->approved_row( array( 'revoked_at' => '2026-11-11 10:00:00' ) ) );
		$this->wpdb->rows[] = $this->link_row( $this->approved_row( array( 'revoked_at' => '2026-11-11 10:00:00' ) ) );
		$this->assertSame( 'axle_review_link_ended', $this->approvals()->ask_again( 7, 1 )->get_error_code() );

		$this->assertSame( 'axle_review_no_link', $this->approvals()->ask_again( 8, 1 )->get_error_code() );
		$this->assertSame( array(), $this->wpdb->updated );
	}

	private function visits(): Visits {
		return new Visits( new Links() );
	}

	public function test_a_first_visit_has_nothing_to_compare_and_records_the_page(): void {
		$visit = $this->visits()->record( $this->link(), $this->post, true );

		$this->assertNull( $visit['changes'] );
		$this->assertSame( Fingerprint::of( $this->post )['hash'], $visit['hash'] );
		$this->assertArrayHasKey( 'visit_snapshot', $this->written() );
		$this->assertArrayNotHasKey( 'seen_snapshot', $this->written() );
	}

	public function test_a_new_session_compares_with_the_last_visit_and_promotes_it(): void {
		$last         = Fingerprint::encode( Fingerprint::of( $this->post ) );
		$this->blocks = $this->paragraphs( array( 'One', 'Two, edited', 'Three' ) );

		$visit = $this->visits()->record( $this->link( array( 'visit_snapshot' => $last, 'visit_at' => '2026-11-09 09:00:00' ) ), $this->post, true );

		$this->assertSame( 2, $visit['changes']['count'] );
		$this->assertSame( '2026-11-09T09:00:00+00:00', $visit['since'] );
		$this->assertSame( $last, $this->written()['seen_snapshot'] );
		$this->assertNotSame( $last, $this->written()['visit_snapshot'] );
	}

	public function test_reloading_keeps_the_highlights_and_does_not_move_the_baseline(): void {
		$seen         = Fingerprint::encode( Fingerprint::of( $this->post ) );
		$this->blocks = $this->paragraphs( array( 'One', 'Two, edited' ) );
		$current      = Fingerprint::encode( Fingerprint::of( $this->post ) );

		$visit = $this->visits()->record( $this->link( array( 'seen_snapshot' => $seen, 'seen_at' => '2026-11-09 09:00:00', 'visit_snapshot' => $current ) ), $this->post, false );

		$this->assertSame( 1, $visit['changes']['count'] );
		$this->assertSame( array(), $this->wpdb->updated, 'The page did not change since the last load, so nothing is written.' );
	}

	public function test_after_asking_again_the_reviewer_sees_what_changed_since_their_answer(): void {
		$answered     = Fingerprint::encode( Fingerprint::of( $this->post ) );
		$this->blocks = $this->paragraphs( array( 'One', 'Two, edited' ) );
		$later        = Fingerprint::encode( Fingerprint::of( $this->post ) );

		$visit = $this->visits()->record(
			$this->link(
				array(
					'decision'          => 'approved',
					'decision_snapshot' => $answered,
					'decided_at'        => '2026-11-10 10:00:00',
					'reask_at'          => '2026-11-12 10:00:00',
					'seen_snapshot'     => $later,
					'visit_snapshot'    => $later,
				)
			),
			$this->post,
			false
		);

		$this->assertSame( 1, $visit['changes']['count'] );
		$this->assertSame( '2026-11-10T10:00:00+00:00', $visit['since'] );
	}

	public function test_the_changes_can_be_filtered_and_revisions_off_is_reported(): void {
		Functions\when( 'wp_revisions_to_keep' )->justReturn( 0 );
		$last         = Fingerprint::encode( Fingerprint::of( $this->post ) );
		$this->blocks = $this->paragraphs( array( 'One', 'Two, edited' ) );
		Filters\expectApplied( 'axle_review_visit_changes' )->once()->andReturn( null );

		$visit = $this->visits()->record( $this->link( array( 'visit_snapshot' => $last, 'seen_snapshot' => $last ) ), $this->post, false );

		$this->assertNull( $visit['changes'] );
		$this->assertFalse( $visit['revisions'] );
	}

	public function test_the_eraser_also_forgets_the_reviewers_name_and_comment(): void {
		$this->wpdb->rows[] = $this->link_row( $this->approved_row( array( 'email' => 'legal@example.com' ) ) );
		$this->wpdb->rows[] = $this->link_row( $this->approved_row( array( 'email' => 'legal@example.com' ) ) );

		( new Links() )->forget_email( 7 );

		$forgotten = end( $this->wpdb->updated )[1];
		$this->assertArrayHasKey( 'reviewer_name', $forgotten );
		$this->assertNull( $forgotten['reviewer_name'] );
		$this->assertNull( $forgotten['decision_comment'] );
		$this->assertNull( $forgotten['email'] );
	}

	public function test_the_privacy_export_includes_the_answer(): void {
		$row                 = $this->link_row( $this->approved_row( array( 'email' => 'legal@example.com', 'decision_comment' => 'Terms are fine.' ) ) );
		$this->wpdb->lists[] = array( $row );
		$this->wpdb->lists[] = array();
		Functions\when( 'get_comments' )->justReturn( array() );
		Functions\when( 'get_the_title' )->justReturn( 'Spring menu' );

		$export = ( new \AryanX\Axle\Review\Privacy( new Links(), new \AryanX\Axle\Review\Events() ) )->export( 'legal@example.com', 1 );

		$fields = array_column( $export['data'][0]['data'], 'value', 'name' );
		$this->assertSame( 'Priya', $fields['Name'] );
		$this->assertSame( 'Approved', $fields['Decision'] );
		$this->assertSame( 'Terms are fine.', $fields['Comment'] );
	}

	public function test_a_link_gets_a_new_token_only_while_it_is_active(): void {
		$this->wpdb->rows[] = $this->link_row();
		$rotated            = ( new Links() )->rotate_token( 7 );
		$this->assertSame( Links::hash( substr( $rotated['url'], strpos( $rotated['url'], '=' ) + 1 ) ), $this->wpdb->updated[0][1]['token_hash'] );

		$this->wpdb->rows[] = $this->link_row( array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
		$this->assertSame( 'axle_review_link_ended', ( new Links() )->rotate_token( 7 )->get_error_code() );
	}
}
