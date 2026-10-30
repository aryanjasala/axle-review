<?php
/**
 * Base class for Axle Review unit tests.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Tests\Unit;

use Brain\Monkey\Functions;

require_once dirname( __DIR__ ) . '/Stubs/axle-review.php';

/**
 * A fake $wpdb and the WordPress helpers most tests need.
 */
abstract class ReviewTestCase extends TestCase {

	/**
	 * The fake database.
	 *
	 * @var \Axr_Fake_Wpdb
	 */
	protected $wpdb;

	/**
	 * Transients, keyed by name.
	 *
	 * @var array<string, mixed>
	 */
	protected $transients = array();

	/**
	 * Comment meta, keyed by comment ID then key.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected $comment_meta = array();

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$this->wpdb       = new \Axr_Fake_Wpdb();
		$wpdb             = $this->wpdb;
		$this->transients = array();
		$this->comment_meta = array();

		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $text ) => trim( strip_tags( (string) $text ) ) );
		Functions\when( 'sanitize_key' )->alias( static fn( $key ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) ) );
		Functions\when( 'sanitize_email' )->alias( static fn( $email ) => trim( (string) $email ) );
		Functions\when( 'is_email' )->alias( static fn( $email ) => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $text ) => trim( strip_tags( (string) $text ) ) );
		Functions\when( 'wp_slash' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'get_bloginfo' )->alias( static fn( $show = '' ) => 'version' === $show ? '7.1.3' : 'Test site' );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $key, $value = null, $url = '' ) {
				return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $key . '=' . $value;
			}
		);
		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
		Functions\when( 'get_comment_meta' )->alias( fn( $id, $key = '', $single = false ) => $this->comment_meta[ (int) $id ][ $key ] ?? '' );
		Functions\when( 'add_comment_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->comment_meta[ (int) $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'update_comment_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->comment_meta[ (int) $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_comment_meta' )->alias(
			function ( $id, $key ) {
				unset( $this->comment_meta[ (int) $id ][ $key ] );
				return true;
			}
		);
	}

	/**
	 * A link row as the database returns it.
	 *
	 * @param array $fields Fields to change.
	 * @return array
	 */
	protected function link_row( array $fields = array() ): array {
		return array_merge(
			array(
				'id'             => '7',
				'post_id'        => '42',
				'token_hash'     => str_repeat( 'a', 64 ),
				'label'          => 'Legal',
				'created_by'     => '1',
				'created_at'     => gmdate( 'Y-m-d H:i:s', time() - 60 ),
				'expires_at'     => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'max_views'      => '0',
				'views'          => '0',
				'email'          => null,
				'password_hash'  => null,
				'allow_notes'    => '1',
				'share_notes'    => '0',
				'revoked_at'     => null,
				'revoked_by'     => '0',
				'last_opened_at' => null,
			),
			$fields
		);
	}

	/**
	 * Parsed paragraph blocks.
	 *
	 * @param string[] $texts Paragraph texts.
	 * @return array
	 */
	protected function paragraphs( array $texts ): array {
		$blocks = array();
		foreach ( $texts as $text ) {
			$blocks[] = array(
				'blockName'    => 'core/paragraph',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '<p>' . $text . '</p>',
				'innerContent' => array( '<p>' . $text . '</p>' ),
			);
			$blocks[] = array(
				'blockName'    => null,
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => "\n\n",
				'innerContent' => array( "\n\n" ),
			);
		}

		return $blocks;
	}
}
