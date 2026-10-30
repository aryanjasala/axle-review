<?php
/**
 * Tests for sessions, codes and passwords.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Tests\Unit;

use AryanX\Axle\Review\Access;
use AryanX\Axle\Review\Events;
use AryanX\Axle\Review\Links;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Covers Access.
 */
final class AccessTest extends ReviewTestCase {

	/**
	 * Access under test.
	 *
	 * @var Access
	 */
	private Access $access;

	/**
	 * Emails sent: to, subject, message.
	 *
	 * @var array[]
	 */
	private array $mail = array();

	protected function setUp(): void {
		parent::setUp();
		$this->access = new Access( new Links(), new Events() );
		$this->mail   = array();
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $message ) {
				$this->mail[] = compact( 'to', 'subject', 'message' );
				return true;
			}
		);
		Functions\when( 'get_post' )->justReturn( new \WP_Post( array( 'ID' => 42, 'post_title' => 'Draft' ) ) );
		Functions\when( 'get_the_title' )->justReturn( 'Draft' );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'wp_privacy_anonymize_ip' )->returnArg();
	}

	protected function tearDown(): void {
		$_COOKIE = array();
		parent::tearDown();
	}

	/**
	 * A normalised link.
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	private function link( array $fields = array() ): array {
		return Links::normalize( $this->link_row( $fields ) );
	}

	public function test_a_signed_cookie_round_trips_and_tampering_fails(): void {
		$value = Access::cookie_value( 7, str_repeat( 'a', 24 ), time() + 60, true );
		$this->assertSame(
			array(
				'id'       => str_repeat( 'a', 24 ),
				'expires'  => time() + 60,
				'verified' => true,
			),
			Access::parse_cookie( 7, $value, time() )
		);

		// Another link, a flipped flag, an expired time.
		$this->assertNull( Access::parse_cookie( 8, $value, time() ) );
		$this->assertNull( Access::parse_cookie( 7, str_replace( '|1|', '|0|', $value ), time() ) );
		$this->assertNull( Access::parse_cookie( 7, $value, time() + 120 ) );
		$this->assertNull( Access::parse_cookie( 7, 'junk', time() ) );
	}

	public function test_session_reads_the_cookie(): void {
		$_COOKIE['axr_7'] = Access::cookie_value( 7, str_repeat( 'b', 24 ), time() + 60, false );
		$this->assertSame( str_repeat( 'b', 24 ), $this->access->session( $this->link() )['id'] );
	}

	public function test_starting_a_session_ends_with_the_link(): void {
		Functions\when( 'is_ssl' )->justReturn( false );
		Filters\expectApplied( 'axle_review_session_ttl' )->once()->with( 12 * HOUR_IN_SECONDS )->andReturn( 10 );
		$session = $this->access->start( $this->link( array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) ) ), false );

		// At least five minutes, at most the link's expiry.
		$this->assertEqualsWithDelta( time() + 5 * MINUTE_IN_SECONDS, $session['expires'], 2 );
		$this->assertArrayHasKey( 'axr_7', $_COOKIE );
	}

	public function test_nonce_and_form_tokens_are_bound_to_the_link(): void {
		$this->assertNotSame( Access::nonce( 7, 'abc' ), Access::nonce( 8, 'abc' ) );
		$this->assertTrue( Access::check_form_token( 7, Access::form_token( 7 ) ) );
		$this->assertFalse( Access::check_form_token( 8, Access::form_token( 7 ) ) );
		$this->assertTrue( Access::check_step_proof( 7, Access::step_proof( 7 ) ) );
	}

	public function test_code_rules_are_filtered_and_clamped(): void {
		Filters\expectApplied( 'axle_review_code_length' )->once()->with( 6 )->andReturn( 50 );
		Filters\expectApplied( 'axle_review_code_ttl' )->once()->with( 600 )->andReturn( 1 );
		Filters\expectApplied( 'axle_review_code_max_tries' )->once()->with( 5 )->andReturn( 3 );

		$this->assertSame(
			array(
				'length' => 10,
				'ttl'    => 60,
				'tries'  => 3,
			),
			Access::code_rules()
		);
	}

	public function test_send_code_emails_the_link_address_only(): void {
		Actions\expectDone( 'axle_review_code_sent' )->once();
		$this->assertTrue( $this->access->send_code( $this->link( array( 'email' => 'legal@example.com' ) ) ) );
		$this->assertSame( 'legal@example.com', $this->mail[0]['to'] );
		$this->assertMatchesRegularExpression( '/\b\d{6}\b/', $this->mail[0]['message'] );

		$this->assertSame( 'axle_review_no_email', $this->access->send_code( $this->link() )->get_error_code() );
	}

	public function test_the_right_code_works_once(): void {
		$link = $this->link( array( 'email' => 'legal@example.com' ) );
		$this->access->send_code( $link );
		preg_match( '/\b(\d{6})\b/', $this->mail[0]['message'], $match );

		$this->assertTrue( $this->access->check_code( $link, $match[1] ) );
		$this->assertSame( 'axle_review_bad_code', $this->access->check_code( $link, $match[1] )->get_error_code() );
	}

	public function test_wrong_codes_lock_the_link_out(): void {
		$link = $this->link( array( 'email' => 'legal@example.com' ) );
		$this->access->send_code( $link );
		preg_match( '/\b(\d{6})\b/', $this->mail[0]['message'], $match );
		$wrong = str_pad( (string) ( ( (int) $match[1] + 1 ) % 1000000 ), 6, '0', STR_PAD_LEFT );

		Actions\expectDone( 'axle_review_code_failed' )->times( 5 );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertSame( 'axle_review_bad_code', $this->access->check_code( $link, $wrong )->get_error_code() );
		}

		$this->assertTrue( $this->access->locked( 7 ) );
		$this->assertSame( 'axle_review_locked', $this->access->check_code( $link, $match[1] )->get_error_code() );
		$this->assertSame( 'axle_review_locked', $this->access->send_code( $link )->get_error_code() );
	}

	public function test_passwords(): void {
		Functions\when( 'wp_check_password' )->alias( static fn( $password, $hash ) => 'hashed:' . $password === $hash );
		$link = $this->link( array( 'password_hash' => 'hashed:open' ) );

		$this->assertSame( 'axle_review_bad_password', $this->access->check_password( $link, 'nope' )->get_error_code() );
		$this->assertTrue( $this->access->check_password( $link, 'open' ) );
	}
}
