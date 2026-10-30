<?php
/**
 * Tests for review links.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Tests\Unit;

use AryanX\Axle\Review\Links;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers Links.
 */
final class LinksTest extends ReviewTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_post' )->justReturn(
			new \WP_Post(
				array(
					'ID'          => 42,
					'post_type'   => 'post',
					'post_status' => 'draft',
				)
			)
		);
		Functions\when( 'get_post_types' )->justReturn( array( 'post' ) );
		Functions\when( 'post_type_supports' )->justReturn( true );
		Functions\when( 'post_type_exists' )->justReturn( true );
		Functions\when( 'get_all_post_type_supports' )->justReturn( array() );
		Functions\when( 'wp_hash_password' )->alias( static fn( $password ) => 'hashed:' . $password );
		Functions\when( 'get_userdata' )->justReturn( new \WP_User( array( 'ID' => 1, 'display_name' => 'Admin' ) ) );
	}

	public function test_tokens_are_long_url_safe_and_only_their_hash_is_stored(): void {
		$token = Links::new_token();
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $token );
		$this->assertSame( 64, strlen( Links::hash( $token ) ) );
		$this->assertNotSame( Links::hash( $token ), Links::hash( Links::new_token() ) );
		$this->assertSame( 'https://example.com/?axle_review=' . $token, Links::url( $token ) );
	}

	public function test_create_stores_a_hash_and_fires_the_action(): void {
		$this->wpdb->rows[] = $this->link_row( array( 'id' => '1' ) );
		Actions\expectDone( 'axle_review_link_created' )->once()->with( Mockery::type( 'array' ), Mockery::pattern( '/axle_review=/' ) );

		$created = ( new Links() )->create(
			array(
				'post_id'   => 42,
				'label'     => '<b>Legal</b>',
				'expires'   => 999 * DAY_IN_SECONDS,
				'max_views' => 1,
				'email'     => 'Legal@Example.com',
				'password'  => 'secret',
			)
		);

		$row = $this->wpdb->inserted['wp_axle_review_links'][0];
		$this->assertSame( Links::hash( $created['token'] ), $row['token_hash'] );
		$this->assertSame( 'Legal', $row['label'] );
		$this->assertSame( 'legal@example.com', $row['email'] );
		$this->assertSame( 'hashed:secret', $row['password_hash'] );
		$this->assertSame( 1, $row['max_views'] );
		// Clamped to 30 days.
		$this->assertEqualsWithDelta( time() + 30 * DAY_IN_SECONDS, strtotime( $row['expires_at'] . ' UTC' ), 5 );
	}

	public function test_create_refuses_bad_input(): void {
		$links = new Links();
		$this->assertSame( 'axle_review_bad_email', $links->create( array( 'post_id' => 42, 'email' => 'nope' ) )->get_error_code() );

		Functions\when( 'get_post' )->justReturn( new \WP_Post( array( 'ID' => 42, 'post_type' => 'post', 'post_status' => 'publish' ) ) );
		$this->assertSame( 'axle_review_post_status', $links->create( array( 'post_id' => 42 ) )->get_error_code() );
	}

	public function test_dead_statuses_are_filterable(): void {
		Filters\expectApplied( 'axle_review_kill_link_on_status' )->once()->with( array( 'publish', 'private', 'trash' ) )->andReturn( array( 'publish', '' ) );
		$this->assertSame( array( 'publish' ), Links::dead_statuses() );
	}

	public function test_status_of_a_link(): void {
		$now  = time();
		$link = Links::normalize( $this->link_row() );
		$this->assertSame( 'active', Links::status( $link, $now ) );
		$this->assertSame( 'used', Links::status( array_merge( $link, array( 'max_views' => 1, 'views' => 1 ) ), $now ) );
		$this->assertSame( 'expired', Links::status( $link, $now + 2 * DAY_IN_SECONDS ) );
		$this->assertSame( 'revoked', Links::status( array_merge( $link, array( 'revoked_at' => '2026-10-30 10:00:00' ) ), $now ) );
	}

	public function test_find_by_token_compares_the_hash(): void {
		$token              = Links::new_token();
		$this->wpdb->rows[] = $this->link_row( array( 'token_hash' => Links::hash( $token ) ) );
		$this->assertSame( 7, ( new Links() )->find_by_token( $token )['id'] );

		$this->wpdb->rows[] = $this->link_row();
		$this->assertNull( ( new Links() )->find_by_token( $token ) );
		$this->assertNull( ( new Links() )->find_by_token( 'short' ) );
	}

	public function test_counting_the_last_view_fires_used_up(): void {
		$this->wpdb->rows[] = $this->link_row( array( 'max_views' => '1', 'views' => '1' ) );
		Actions\expectDone( 'axle_review_link_used_up' )->once();

		$this->assertTrue( ( new Links() )->count_view( Links::normalize( $this->link_row( array( 'max_views' => '1' ) ) ) ) );
	}

	public function test_a_view_over_the_limit_is_refused(): void {
		$this->wpdb->affected = 0;
		Actions\expectDone( 'axle_review_link_used_up' )->never();
		$this->assertFalse( ( new Links() )->count_view( Links::normalize( $this->link_row() ) ) );
	}

	public function test_revoke_fires_once_and_is_idempotent(): void {
		$this->wpdb->rows[] = $this->link_row();
		$this->wpdb->rows[] = $this->link_row( array( 'revoked_at' => '2026-10-30 10:00:00', 'revoked_by' => '1' ) );
		Actions\expectDone( 'axle_review_link_revoked' )->once()->with( Mockery::type( 'array' ), 1 );
		$this->assertTrue( ( new Links() )->revoke( 7, 1 ) );

		$this->wpdb->rows[] = $this->link_row( array( 'revoked_at' => '2026-10-30 10:00:00' ) );
		$this->assertTrue( ( new Links() )->revoke( 7, 1 ) );

		$this->assertFalse( ( new Links() )->revoke( 8, 1 ) );
	}

	public function test_public_fields_leave_out_the_hashes(): void {
		$public = Links::to_public( Links::normalize( $this->link_row( array( 'password_hash' => 'x' ) ) ), 3 );
		$this->assertArrayNotHasKey( 'token_hash', $public );
		$this->assertArrayNotHasKey( 'password_hash', $public );
		$this->assertTrue( $public['has_password'] );
		$this->assertSame( 3, $public['notes'] );
		$this->assertSame( 'Admin', $public['creator'] );
		$this->assertSame( '', Links::iso( '' ) );
	}
}
