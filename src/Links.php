<?php
/**
 * The review links table.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review;

defined( 'ABSPATH' ) || exit;

/**
 * Creates, finds and revokes review links.
 *
 * A link's token is 32 random bytes in base64url. Only its keyed hash is
 * stored, so the URL can be shown once and never again. Times are UTC.
 */
final class Links {

	public const STATUSES = array( 'active', 'used', 'expired', 'revoked' );

	/**
	 * Creates a link.
	 *
	 * @param array $args post_id, label, expires (seconds from now), max_views, email, password, allow_notes, share_notes, created_by.
	 * @return array{id: int, token: string, url: string, link: array}|\WP_Error
	 */
	public function create( array $args ) {
		global $wpdb;

		$post = get_post( (int) ( $args['post_id'] ?? 0 ) );
		if ( ! $post ) {
			return new \WP_Error( 'axle_review_no_post', __( 'That post does not exist.', 'axle-review' ), array( 'status' => 404 ) );
		}

		if ( ! in_array( $post->post_type, Settings::post_types(), true ) ) {
			return new \WP_Error( 'axle_review_post_type', __( 'Review links are not available for this post type.', 'axle-review' ), array( 'status' => 400 ) );
		}

		if ( in_array( $post->post_status, self::dead_statuses(), true ) ) {
			return new \WP_Error( 'axle_review_post_status', __( 'This post is already published, private or in the trash, so a review link would stop working at once.', 'axle-review' ), array( 'status' => 400 ) );
		}

		$email = isset( $args['email'] ) ? sanitize_email( (string) $args['email'] ) : '';
		if ( isset( $args['email'] ) && '' !== trim( (string) $args['email'] ) && ! is_email( $email ) ) {
			return new \WP_Error( 'axle_review_bad_email', __( 'That email address is not valid.', 'axle-review' ), array( 'status' => 400 ) );
		}

		$expires   = (int) ( $args['expires'] ?? 0 );
		$expires   = max( MINUTE_IN_SECONDS, min( Settings::max_expiry(), $expires > 0 ? $expires : 7 * DAY_IN_SECONDS ) );
		$max_views = max( 0, min( 100000, (int) ( $args['max_views'] ?? 0 ) ) );
		$password  = isset( $args['password'] ) ? (string) $args['password'] : '';
		$token     = self::new_token();

		$row = array(
			'post_id'       => (int) $post->ID,
			'token_hash'    => self::hash( $token ),
			'label'         => mb_substr( sanitize_text_field( (string) ( $args['label'] ?? '' ) ), 0, 191 ),
			'created_by'    => (int) ( $args['created_by'] ?? get_current_user_id() ),
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			'expires_at'    => gmdate( 'Y-m-d H:i:s', time() + $expires ),
			'max_views'     => $max_views,
			'views'         => 0,
			'email'         => '' === $email ? null : strtolower( $email ),
			'password_hash' => '' === $password ? null : wp_hash_password( $password ),
			'allow_notes'   => isset( $args['allow_notes'] ) ? (int) (bool) $args['allow_notes'] : 1,
			'share_notes'   => (int) ! empty( $args['share_notes'] ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table.
		if ( ! $wpdb->insert( Schema::links_table(), $row ) ) {
			return new \WP_Error( 'axle_review_db', __( 'The link could not be saved.', 'axle-review' ), array( 'status' => 500 ) );
		}

		$id   = (int) $wpdb->insert_id;
		$link = $this->get( $id );
		$link = $link ? $link : array();
		$url  = self::url( $token );

		/**
		 * Fires after a review link was created.
		 *
		 * @param array  $link The link row (no token).
		 * @param string $url  The link URL, shown once.
		 */
		do_action( 'axle_review_link_created', $link, $url );

		return array(
			'id'    => $id,
			'token' => $token,
			'url'   => $url,
			'link'  => $link,
		);
	}

	/**
	 * A fresh token: 32 random bytes, base64url.
	 *
	 * @return string
	 */
	public static function new_token(): string {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe token, not obfuscation.
	}

	/**
	 * The stored hash of a token.
	 *
	 * @param string $token Token.
	 * @return string 64 hex characters.
	 */
	public static function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/**
	 * The review URL for a token.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function url( string $token ): string {
		return add_query_arg( 'axle_review', rawurlencode( $token ), home_url( '/' ) );
	}

	/**
	 * Post statuses that end a post's links.
	 *
	 * @return string[]
	 */
	public static function dead_statuses(): array {
		/**
		 * Filters the post statuses that revoke every review link of a post.
		 *
		 * @param string[] $statuses Default publish, private and trash.
		 */
		$statuses = (array) apply_filters( 'axle_review_kill_link_on_status', array( 'publish', 'private', 'trash' ) );

		return array_values( array_filter( array_map( 'strval', $statuses ) ) );
	}

	/**
	 * Finds a link by its token. Unknown tokens and dead links look the same to
	 * the caller only through the denied page; this returns the row either way.
	 *
	 * @param string $token Token from the URL.
	 * @return array|null
	 */
	public function find_by_token( string $token ): ?array {
		global $wpdb;

		$token = (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $token );
		$hash  = self::hash( $token );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A security check: always the live row.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_hash = %s', Schema::links_table(), $hash ), ARRAY_A );

		if ( ! is_array( $row ) || ! hash_equals( (string) $row['token_hash'], $hash ) || strlen( $token ) < 40 ) {
			return null;
		}

		return self::normalize( $row );
	}

	/**
	 * A link by ID.
	 *
	 * @param int $id Link ID.
	 * @return array|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Always the live row.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::links_table(), $id ), ARRAY_A );

		return is_array( $row ) ? self::normalize( $row ) : null;
	}

	/**
	 * Links matching filters, newest first.
	 *
	 * @param array $args post_id, created_by, email, status (active, used, expired, revoked), limit, offset.
	 * @return array{items: array[], total: int}
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$post_id    = (int) ( $args['post_id'] ?? 0 );
		$created_by = (int) ( $args['created_by'] ?? 0 );
		$email      = strtolower( (string) ( $args['email'] ?? '' ) );
		$status     = (string) ( $args['status'] ?? '' );
		if ( ! in_array( $status, array( 'revoked', 'expired', 'used', 'active' ), true ) ) {
			$status = '';
		}
		$now    = gmdate( 'Y-m-d H:i:s' );
		$limit  = max( 1, min( 500, (int) ( $args['limit'] ?? 20 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$table  = Schema::links_table();

		// Every filter is a fixed condition that a placeholder switches on or off, so no SQL is built from variables.
		$filter = "( %d = 0 OR post_id = %d )
			AND ( %d = 0 OR created_by = %d )
			AND ( %s = '' OR email = %s )
			AND (
				%s = ''
				OR ( %s = 'revoked' AND revoked_at IS NOT NULL )
				OR ( %s = 'expired' AND revoked_at IS NULL AND expires_at <= %s )
				OR ( %s = 'used' AND revoked_at IS NULL AND expires_at > %s AND max_views > 0 AND views >= max_views )
				OR ( %s = 'active' AND revoked_at IS NULL AND expires_at > %s AND ( max_views = 0 OR views < max_views ) )
			)";
		$values = array(
			$post_id,
			$post_id,
			$created_by,
			$created_by,
			$email,
			$email,
			$status,
			$status,
			$status,
			$now,
			$status,
			$now,
			$status,
			$now,
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The plugin's own table.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$filter}", array_merge( array( $table ), $values ) ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE {$filter} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( array( $table ), $values, array( $limit, $offset ) ) ), ARRAY_A );
		// phpcs:enable

		return array(
			'items' => array_map( array( self::class, 'normalize' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Types the row's columns.
	 *
	 * @param array $row Database row.
	 * @return array
	 */
	public static function normalize( array $row ): array {
		$link = array(
			'id'             => (int) $row['id'],
			'post_id'        => (int) $row['post_id'],
			'token_hash'     => (string) $row['token_hash'],
			'label'          => (string) $row['label'],
			'created_by'     => (int) $row['created_by'],
			'created_at'     => (string) $row['created_at'],
			'expires_at'     => (string) $row['expires_at'],
			'max_views'      => (int) $row['max_views'],
			'views'          => (int) $row['views'],
			'email'          => null === $row['email'] ? '' : (string) $row['email'],
			'has_password'   => ! empty( $row['password_hash'] ),
			'password_hash'  => (string) $row['password_hash'],
			'allow_notes'    => (bool) (int) $row['allow_notes'],
			'share_notes'    => (bool) (int) $row['share_notes'],
			'revoked_at'     => (string) $row['revoked_at'],
			'revoked_by'     => (int) $row['revoked_by'],
			'last_opened_at' => (string) $row['last_opened_at'],
		);

		$link['status'] = self::status( $link );

		return $link;
	}

	/**
	 * The status of a link: active, used, expired or revoked.
	 *
	 * @param array    $link Link.
	 * @param int|null $now  Unix time, for tests.
	 * @return string
	 */
	public static function status( array $link, ?int $now = null ): string {
		$now = null === $now ? time() : $now;

		if ( '' !== (string) ( $link['revoked_at'] ?? '' ) ) {
			return 'revoked';
		}

		$expires = strtotime( (string) ( $link['expires_at'] ?? '' ) . ' UTC' );
		if ( false === $expires || $expires <= $now ) {
			return 'expired';
		}

		if ( (int) ( $link['max_views'] ?? 0 ) > 0 && (int) ( $link['views'] ?? 0 ) >= (int) $link['max_views'] ) {
			return 'used';
		}

		return 'active';
	}

	/**
	 * Counts a new session against the view limit. Atomic, so two people
	 * opening a view-once link at the same moment cannot both get in.
	 *
	 * @param array $link Link.
	 * @return bool Whether the view was counted (false: the limit was reached).
	 */
	public function count_view( array $link ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic counter on the plugin's own table.
		$updated = (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET views = views + 1, last_opened_at = %s WHERE id = %d AND revoked_at IS NULL AND ( max_views = 0 OR views < max_views )', Schema::links_table(), gmdate( 'Y-m-d H:i:s' ), (int) $link['id'] ) );

		if ( 1 !== $updated ) {
			return false;
		}

		$fresh = $this->get( (int) $link['id'] );
		if ( $fresh && $fresh['max_views'] > 0 && $fresh['views'] >= $fresh['max_views'] ) {
			/**
			 * Fires when a link reached its view limit. Sessions already open keep working.
			 *
			 * @param array $link The link.
			 */
			do_action( 'axle_review_link_used_up', $fresh );
		}

		return true;
	}

	/**
	 * Notes the time a session came back.
	 *
	 * @param int $id Link ID.
	 */
	public function touch( int $id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$wpdb->update( Schema::links_table(), array( 'last_opened_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id ) );
	}

	/**
	 * Revokes one link. Revoking a revoked link changes nothing.
	 *
	 * @param int $id Link ID.
	 * @param int $by User ID, 0 for the system.
	 * @return bool Whether the link exists.
	 */
	public function revoke( int $id, int $by ): bool {
		global $wpdb;

		$link = $this->get( $id );
		if ( ! $link ) {
			return false;
		}

		if ( 'revoked' === $link['status'] ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$wpdb->update(
			Schema::links_table(),
			array(
				'revoked_at' => gmdate( 'Y-m-d H:i:s' ),
				'revoked_by' => $by,
			),
			array( 'id' => $id )
		);

		$link = $this->get( $id );

		/**
		 * Fires after a review link was revoked.
		 *
		 * @param array $link The link.
		 * @param int   $by   Who revoked it, 0 when the post was published, trashed or its creator deleted.
		 */
		do_action( 'axle_review_link_revoked', $link, $by );

		return true;
	}

	/**
	 * Revokes every live link of a post.
	 *
	 * @param int $post_id Post ID.
	 * @param int $by      User ID, 0 for the system.
	 * @return int Links revoked.
	 */
	public function revoke_for_post( int $post_id, int $by ): int {
		return $this->revoke_where( 'post_id', $post_id, $by );
	}

	/**
	 * Revokes every live link a user created.
	 *
	 * @param int $user_id Creator.
	 * @param int $by      User ID, 0 for the system.
	 * @return int Links revoked.
	 */
	public function revoke_by_creator( int $user_id, int $by ): int {
		return $this->revoke_where( 'created_by', $user_id, $by );
	}

	/**
	 * Revokes the live links where a column matches.
	 *
	 * @param string $column post_id or created_by.
	 * @param int    $value  Value.
	 * @param int    $by     User ID.
	 * @return int
	 */
	private function revoke_where( string $column, int $value, int $by ): int {
		global $wpdb;

		$column = 'created_by' === $column ? 'created_by' : 'post_id';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE %i = %d AND revoked_at IS NULL', Schema::links_table(), $column, $value ) );

		$count = 0;
		foreach ( (array) $ids as $id ) {
			if ( $this->revoke( (int) $id, $by ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Deletes every link of a post and its events, when the post is deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	public function delete_for_post( int $post_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE post_id = %d', Schema::links_table(), $post_id ) ) );
		if ( ! $ids ) {
			return;
		}

		foreach ( $ids as $id ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own tables.
			$wpdb->delete( Schema::events_table(), array( 'link_id' => $id ) );
			$wpdb->delete( Schema::links_table(), array( 'id' => $id ) );
			// phpcs:enable
		}
	}

	/**
	 * Removes the email from a link, and revokes it, for the privacy eraser.
	 *
	 * @param int $id Link ID.
	 */
	public function forget_email( int $id ): void {
		global $wpdb;

		$this->revoke( $id, 0 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$wpdb->update( Schema::links_table(), array( 'email' => null ), array( 'id' => $id ) );
	}

	/**
	 * Active links that live longer than the default maximum, for Site Health.
	 *
	 * @return int
	 */
	public function count_long_lived(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, for Site Health.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE revoked_at IS NULL AND expires_at > %s', Schema::links_table(), gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS + HOUR_IN_SECONDS ) ) );
	}

	/**
	 * Public fields of a link, for screens, REST and abilities: no hashes.
	 *
	 * @param array $link  Link.
	 * @param int   $notes Notes left through it.
	 * @return array
	 */
	public static function to_public( array $link, int $notes = 0 ): array {
		$creator = get_userdata( (int) $link['created_by'] );

		return array(
			'id'             => (int) $link['id'],
			'post_id'        => (int) $link['post_id'],
			'label'          => (string) $link['label'],
			'status'         => (string) $link['status'],
			'created_by'     => (int) $link['created_by'],
			'creator'        => $creator ? (string) $creator->display_name : '',
			'created_at'     => self::iso( (string) $link['created_at'] ),
			'expires_at'     => self::iso( (string) $link['expires_at'] ),
			'max_views'      => (int) $link['max_views'],
			'views'          => (int) $link['views'],
			'email'          => (string) $link['email'],
			'has_password'   => (bool) $link['has_password'],
			'allow_notes'    => (bool) $link['allow_notes'],
			'share_notes'    => (bool) $link['share_notes'],
			'revoked_at'     => self::iso( (string) $link['revoked_at'] ),
			'last_opened_at' => self::iso( (string) $link['last_opened_at'] ),
			'notes'          => $notes,
		);
	}

	/**
	 * A UTC MySQL time as ISO 8601, or an empty string.
	 *
	 * @param string $mysql Time.
	 * @return string
	 */
	public static function iso( string $mysql ): string {
		if ( '' === $mysql || 0 === strpos( $mysql, '0000' ) ) {
			return '';
		}

		$time = strtotime( $mysql . ' UTC' );
		return false === $time ? '' : gmdate( 'c', $time );
	}
}
