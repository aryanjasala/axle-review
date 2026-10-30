<?php
/**
 * Test doubles for Axle Review: an in-memory $wpdb, and the WordPress classes
 * the template stubs do not cover.
 *
 * @package AryanX\Axle\Review
 */

// phpcs:ignoreFile

foreach ( array(
	'ARRAY_A'           => 'ARRAY_A',
	'MINUTE_IN_SECONDS' => 60,
	'HOUR_IN_SECONDS'   => 3600,
	'DAY_IN_SECONDS'    => 86400,
	'WEEK_IN_SECONDS'   => 604800,
) as $axr_name => $axr_value ) {
	defined( $axr_name ) || define( $axr_name, $axr_value );
}

// The main plugin file defines these; the tests do not load it.
defined( 'AryanX\\Axle\\Review\\PLUGIN_FILE' ) || define( 'AryanX\\Axle\\Review\\PLUGIN_FILE', dirname( __DIR__, 2 ) . '/axle-review.php' );
defined( 'AryanX\\Axle\\Review\\VERSION' ) || define( 'AryanX\\Axle\\Review\\VERSION', '1.0.0' );

if ( ! class_exists( 'WP_Comment' ) ) {
	/**
	 * A comment built from an array of fields.
	 */
	#[\AllowDynamicProperties]
	class WP_Comment {
		/** @var string */
		public $comment_ID = '0';
		/** @var string */
		public $comment_post_ID = '0';
		/** @var string */
		public $comment_author = '';
		/** @var string */
		public $comment_author_email = '';
		/** @var string */
		public $comment_date_gmt = '2026-10-30 10:00:00';
		/** @var string */
		public $comment_content = '';
		/** @var string */
		public $comment_approved = '0';
		/** @var string */
		public $comment_type = 'note';
		/** @var string */
		public $comment_parent = '0';
		/** @var string */
		public $user_id = '0';

		public function __construct( $fields = array() ) {
			foreach ( $fields as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	/**
	 * A user built from an array of fields.
	 */
	#[\AllowDynamicProperties]
	class WP_User {
		/** @var int */
		public $ID = 0;
		/** @var string */
		public $display_name = '';
		/** @var string */
		public $user_email = '';

		public function __construct( $fields = array() ) {
			foreach ( $fields as $key => $value ) {
				$this->$key = $value;
			}
		}

		public function exists() {
			return $this->ID > 0;
		}
	}
}

/**
 * A stand-in for $wpdb: records writes, and answers reads from queues the test fills.
 */
class Axr_Fake_Wpdb {

	/** @var string */
	public $prefix = 'wp_';

	/** @var string */
	public $commentmeta = 'wp_commentmeta';

	/** @var string */
	public $options = 'wp_options';

	/** @var int */
	public $insert_id = 0;

	/** @var array<string, array[]> */
	public $inserted = array();

	/** @var array[] */
	public $updated = array();

	/** @var string[] */
	public $queries = array();

	/** @var array Rows get_row() returns, in order. */
	public $rows = array();

	/** @var array Values get_var() returns, in order. */
	public $vars = array();

	/** @var array Lists get_col() and get_results() return, in order. */
	public $lists = array();

	/** @var int What query() returns. */
	public $affected = 1;

	public function prepare( $query, ...$args ) {
		$args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
		return $query . ' | ' . implode( ', ', array_map( 'strval', $args ) );
	}

	public function insert( $table, $row ) {
		$this->inserted[ $table ][] = $row;
		$this->insert_id++;
		return 1;
	}

	public function update( $table, $data, $where ) {
		$this->updated[] = array( $table, $data, $where );
		return 1;
	}

	public function delete( $table, $where ) {
		$this->queries[] = 'DELETE ' . $table . ' ' . wp_json_encode_stub( $where );
		return 1;
	}

	public function query( $query ) {
		$this->queries[] = $query;
		return $this->affected;
	}

	public function get_row( $query, $output = null ) {
		$this->queries[] = $query;
		return array_shift( $this->rows );
	}

	public function get_var( $query ) {
		$this->queries[] = $query;
		return array_shift( $this->vars );
	}

	public function get_col( $query ) {
		$this->queries[] = $query;
		return (array) array_shift( $this->lists );
	}

	public function get_results( $query, $output = null ) {
		$this->queries[] = $query;
		return (array) array_shift( $this->lists );
	}

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	public function get_charset_collate() {
		return '';
	}
}

function wp_json_encode_stub( $value ) {
	return json_encode( $value );
}
