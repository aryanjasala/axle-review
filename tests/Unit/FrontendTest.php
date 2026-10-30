<?php
/**
 * Tests for the review view and the guest routes.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Tests\Unit;

use AryanX\Axle\Review\Access;
use AryanX\Axle\Review\Attacher;
use AryanX\Axle\Review\Events;
use AryanX\Axle\Review\Frontend\ReviewView;
use AryanX\Axle\Review\Links;
use AryanX\Axle\Review\Notes;
use AryanX\Axle\Review\Rest\GuestRoutes;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers ReviewView and GuestRoutes.
 */
final class FrontendTest extends ReviewTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'wp_privacy_anonymize_ip' )->returnArg();
		Functions\when( 'get_post' )->justReturn( new \WP_Post( array( 'ID' => 42, 'post_type' => 'post', 'post_status' => 'draft' ) ) );
	}

	protected function tearDown(): void {
		$_COOKIE = array();
		parent::tearDown();
	}

	/**
	 * The view.
	 *
	 * @return ReviewView
	 */
	private function view(): ReviewView {
		return new ReviewView( new Links(), new Events(), new Access( new Links(), new Events() ) );
	}

	public function test_denied_messages_are_filterable(): void {
		$this->assertStringContainsString( 'expired', ReviewView::denied_message( 'expired' ) );
		$this->assertStringContainsString( 'does not work', ReviewView::denied_message( 'whatever' ) );

		Filters\expectApplied( 'axle_review_denied_message' )->once()->with( Mockery::type( 'string' ), 'paused' )->andReturn( 'Back soon.' );
		$this->assertSame( 'Back soon.', ReviewView::denied_message( 'paused' ) );
	}

	public function test_toolbar_strings_are_filterable_key_by_key(): void {
		Filters\expectApplied( 'axle_review_toolbar_strings' )->once()->andReturn(
			array(
				'leaveNote' => 'Comment',
				'submit'    => 42,
			)
		);
		$strings = ReviewView::toolbar_strings();
		$this->assertSame( 'Comment', $strings['leaveNote'] );
		$this->assertSame( 'Send note', $strings['submit'] );
	}

	public function test_an_unknown_token_is_refused_with_an_action(): void {
		Actions\expectDone( 'axle_review_access_denied' )->once()->with( 'unknown', null );
		$vars = $this->view()->request( array( 'axle_review' => str_repeat( 'x', 43 ) ) );
		$this->assertSame( array( 'axle_review' => str_repeat( 'x', 43 ) ), $vars );
	}

	public function test_a_fresh_visit_opens_the_link_and_points_the_query_at_the_draft(): void {
		$token              = Links::new_token();
		$this->wpdb->rows[] = $this->link_row( array( 'token_hash' => Links::hash( $token ) ) );
		$this->wpdb->rows[] = $this->link_row( array( 'views' => '1' ) );
		$this->wpdb->rows[] = $this->link_row( array( 'views' => '1' ) );
		Actions\expectDone( 'axle_review_link_opened' )->once()->with( Mockery::type( 'array' ), Mockery::type( 'array' ) );

		$view = $this->view();
		$vars = $view->request( array( 'axle_review' => $token ) );

		$this->assertSame( 'render', $view->state() );
		$this->assertSame( 42, $vars['p'] );
		$this->assertSame( 'draft', $vars['post_status'] );
		$this->assertArrayHasKey( 'axr_7', $_COOKIE );
		$this->assertSame( 'open', $this->wpdb->inserted['wp_axle_review_events'][0]['type'] );
	}

	public function test_an_email_lock_asks_for_the_code_first(): void {
		$token              = Links::new_token();
		$this->wpdb->rows[] = $this->link_row( array( 'token_hash' => Links::hash( $token ), 'email' => 'legal@example.com' ) );

		$view = $this->view();
		$view->request( array( 'axle_review' => $token ) );
		$this->assertSame( 'code', $view->state() );
	}

	public function test_review_headers_keep_no_store_whatever_the_filter_does(): void {
		$token              = Links::new_token();
		$this->wpdb->rows[] = $this->link_row( array( 'token_hash' => Links::hash( $token ), 'email' => 'legal@example.com' ) );
		Functions\when( 'wp_get_nocache_headers' )->justReturn( array( 'Expires' => 'Wed, 11 Jan 1984 05:00:00 GMT' ) );
		Filters\expectApplied( 'axle_review_review_headers' )->once()->andReturn( array( 'Cache-Control' => 'public', 'X-Extra' => '1' ) );

		$view = $this->view();
		$this->assertSame( array( 'A' => 'b' ), $view->headers( array( 'A' => 'b' ) ) );
		$view->request( array( 'axle_review' => $token ) );
		$headers = $view->headers( array() );

		$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
		$this->assertSame( 'noindex, nofollow', $headers['X-Robots-Tag'] );
		$this->assertSame( '1', $headers['X-Extra'] );
		$this->assertTrue( $view->robots( array() )['noindex'] );
	}

	public function test_guest_route_limits_are_filterable(): void {
		Filters\expectApplied( 'axle_review_notes_per_link_per_hour' )->once()->with( 30 )->andReturn( 0 );
		$this->assertSame( 1, GuestRoutes::hourly_limit() );

		Filters\expectApplied( 'axle_review_min_seconds' )->once()->with( 3 )->andReturn( 999 );
		$this->assertSame( 60, GuestRoutes::min_seconds() );
	}

	/**
	 * A guest REST request for link 7 with a valid session.
	 *
	 * @param array $params Parameters.
	 * @return \WP_REST_Request
	 */
	private function guest_request( array $params ): \WP_REST_Request {
		$session          = str_repeat( 'c', 24 );
		$_COOKIE['axr_7'] = Access::cookie_value( 7, $session, time() + 600, false );
		$request          = Mockery::mock( \WP_REST_Request::class );
		$request->shouldReceive( 'get_param' )->andReturnUsing( static fn( $key ) => array_merge( array( 'link' => 7 ), $params )[ $key ] ?? '' );
		$request->shouldReceive( 'get_header' )->with( 'X-Axle-Review-Nonce' )->andReturn( Access::nonce( 7, $session ) );
		return $request;
	}

	public function test_a_guest_note_is_created_attached_and_announced(): void {
		$routes = new GuestRoutes( new Links(), new Events(), new Access( new Links(), new Events() ), new Notes(), new Attacher() );
		$this->wpdb->rows[]                          = $this->link_row();
		$this->wpdb->vars[]                          = 0;
		$this->transients['axle_review_render_abcd'] = time() - 10;

		Functions\when( 'wp_insert_comment' )->justReturn( 55 );
		Functions\when( 'get_comment' )->justReturn( new \WP_Comment( array( 'comment_ID' => '55', 'comment_post_ID' => '42', 'comment_author' => 'Priya' ) ) );
		Functions\when( 'add_option' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'parse_blocks' )->justReturn( $this->paragraphs( array( 'One' ) ) );
		Functions\when( 'serialize_blocks' )->justReturn( 'content' );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\expect( 'wp_update_post' )->once();
		Functions\when( 'rest_ensure_response' )->alias( static fn( $data ) => new \WP_REST_Response( $data ) );
		Functions\when( 'mysql_to_rfc3339' )->returnArg();

		Actions\expectDone( 'axle_review_guest_note_created' )->once()->with( Mockery::type( \WP_Comment::class ), Mockery::type( 'array' ) );
		Actions\expectDone( 'axle_review_guest_note_attached' )->once();

		$request = $this->guest_request(
			array(
				'text'    => 'Looks good.',
				'name'    => 'Priya',
				'path'    => '0',
				'render'  => 'abcd',
				'website' => '',
				'parent'  => 0,
			)
		);
		$this->assertTrue( $routes->check( $request ) );
		$data = $routes->create_note( $request )->get_data();

		$this->assertSame( 55, $data['id'] );
		$this->assertSame( '0', $data['attached']['path'] );
	}

	public function test_the_honeypot_and_a_missing_nonce_are_refused(): void {
		$routes             = new GuestRoutes( new Links(), new Events(), new Access( new Links(), new Events() ), new Notes(), new Attacher() );
		$this->wpdb->rows[] = $this->link_row();
		$request            = $this->guest_request( array( 'website' => 'spam.example' ) );
		$this->assertTrue( $routes->check( $request ) );
		$this->assertSame( 'axle_review_spam', $routes->create_note( $request )->get_error_code() );

		$this->wpdb->rows[] = $this->link_row();
		$bad                = Mockery::mock( \WP_REST_Request::class );
		$bad->shouldReceive( 'get_param' )->andReturn( 7 );
		$bad->shouldReceive( 'get_header' )->andReturn( 'nope' );
		$this->assertSame( 'axle_review_forbidden', $routes->check( $bad )->get_error_code() );
	}
}
