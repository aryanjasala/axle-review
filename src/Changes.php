<?php
/**
 * Review a change to a live page: working copies.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review;

defined( 'ABSPATH' ) || exit;

/**
 * A published post has no review links: they stop when it is published. For a
 * change to a live page, an editor asks for review of a change. That makes a
 * working copy: a draft that points to the original. Review links, the gate
 * and Notes work on the copy as on any draft. Reviewers see the copy with
 * "changed since live" marks. Publishing the change merges the copy into the
 * original: it keeps its ID, URL, slug, date, author, comments and terms, and
 * takes the copy's title, content, excerpt and meta (the featured image is
 * meta). The copy's Notes move to the original, the live version before the
 * merge is kept as Undo, and the copy is deleted.
 *
 * The service methods do not check capabilities. Callers do, per object:
 * REST routes and abilities through the can_* helpers, WP-CLI as a trusted
 * context.
 */
final class Changes implements Service {

	/**
	 * Post meta on the copy: the ID of the live post it changes.
	 */
	public const META_OF = '_axr_change_of';

	/**
	 * Post meta on the live post: the ID of its working copy.
	 */
	public const META_COPY = '_axr_change_copy';

	/**
	 * Post meta on the copy: hash of the live post when the copy was made.
	 */
	public const META_BASE = '_axr_change_base';

	/**
	 * Post meta on the live post: what Undo puts back (JSON).
	 */
	public const META_UNDO = '_axr_change_undo';

	/**
	 * Error code of a direct publish of a copy.
	 */
	public const ERROR_USE_MERGE = 'axle_review_change_use_merge';

	/**
	 * Gate.
	 *
	 * @var Gate
	 */
	private Gate $gate;

	/**
	 * Sets up working copies.
	 *
	 * @param Gate $gate Gate.
	 */
	public function __construct( Gate $gate ) {
		$this->gate = $gate;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_filter( 'wp_insert_post_data', array( $this, 'hold_copy' ), 5, 2 );
		add_action( 'init', array( $this, 'rest_hooks' ), 99 );
		add_action( 'trashed_post', array( $this, 'forget_copy' ) );
		add_action( 'before_delete_post', array( $this, 'on_before_delete' ) );
		add_filter( 'display_post_states', array( $this, 'post_states' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Refuses a direct publish of a copy in REST saves (the block editor).
	 */
	public function rest_hooks(): void {
		foreach ( Settings::post_types() as $type ) {
			add_filter( 'rest_pre_insert_' . $type, array( $this, 'rest_check' ), 9, 2 );
		}
	}

	/* The two posts. */

	/**
	 * The live post a copy changes.
	 *
	 * @param int $copy_id Copy ID.
	 * @return int Post ID, 0 when the post is not a working copy or the live post is gone.
	 */
	public static function original_of( int $copy_id ): int {
		if ( $copy_id <= 0 ) {
			return 0;
		}

		$original = (int) get_post_meta( $copy_id, self::META_OF, true );

		return $original > 0 && get_post( $original ) instanceof \WP_Post ? $original : 0;
	}

	/**
	 * The working copy of a live post.
	 *
	 * @param int $original_id Live post ID.
	 * @return int Copy ID, 0 when none is open.
	 */
	public static function copy_of( int $original_id ): int {
		if ( $original_id <= 0 ) {
			return 0;
		}

		$copy = (int) get_post_meta( $original_id, self::META_COPY, true );
		if ( $copy > 0 && (int) get_post_meta( $copy, self::META_OF, true ) === $original_id && get_post( $copy ) instanceof \WP_Post ) {
			return $copy;
		}

		return 0;
	}

	/**
	 * Whether a post is a working copy (even when its live post is gone).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_copy( int $post_id ): bool {
		return $post_id > 0 && (int) get_post_meta( $post_id, self::META_OF, true ) > 0;
	}

	/**
	 * The statuses a post can have to be changed through a copy.
	 *
	 * @return string[]
	 */
	public static function source_statuses(): array {
		/**
		 * Filters the post statuses that can be changed through a working copy.
		 *
		 * @param string[] $statuses Default publish.
		 */
		$statuses = (array) apply_filters( 'axle_review_change_source_statuses', array( 'publish' ) );

		return array_values( array_filter( array_map( 'strval', $statuses ) ) );
	}

	/**
	 * The snapshot of the live post a reviewer of the copy compares with.
	 *
	 * @param \WP_Post $copy The copy.
	 * @return array{snapshot: array, post_id: int}|null Null when the post is not a copy or its live post is gone.
	 */
	public static function live_baseline( \WP_Post $copy ): ?array {
		$original_id = self::original_of( (int) $copy->ID );
		$original    = $original_id ? get_post( $original_id ) : null;
		if ( ! $original instanceof \WP_Post ) {
			return null;
		}

		return array(
			'snapshot' => Fingerprint::with_revision( $original ),
			'post_id'  => (int) $original->ID,
		);
	}

	/* Capabilities, per object. */

	/**
	 * Whether the current user may ask for review of a change to this post.
	 *
	 * @param int $post_id Live post ID.
	 * @return bool
	 */
	public static function can_request( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! Settings::can_share( $post_id ) ) {
			return false;
		}

		$type = get_post_type_object( $post->post_type );

		return $type && current_user_can( $type->cap->create_posts );
	}

	/**
	 * Whether the current user may publish the change in this copy.
	 *
	 * @param int $copy_id Copy ID.
	 * @return bool
	 */
	public static function can_publish( int $copy_id ): bool {
		$original = self::original_of( $copy_id );
		$post     = $original ? get_post( $original ) : null;
		$type     = $post ? get_post_type_object( $post->post_type ) : null;

		return $type && current_user_can( 'edit_post', $copy_id ) && current_user_can( 'edit_post', $original ) && current_user_can( $type->cap->publish_posts );
	}

	/**
	 * Whether the current user may undo the last published change of a post.
	 *
	 * @param int $original_id Live post ID.
	 * @return bool
	 */
	public static function can_undo( int $original_id ): bool {
		$post = $original_id > 0 ? get_post( $original_id ) : null;
		$type = $post ? get_post_type_object( $post->post_type ) : null;

		return $type && current_user_can( 'edit_post', $original_id ) && current_user_can( $type->cap->publish_posts );
	}

	/* Asking for review of a change. */

	/**
	 * Makes a working copy of a live post.
	 *
	 * @param int $post_id Live post ID.
	 * @param int $user_id Who asked.
	 * @return array{copy: int, original: int}|\WP_Error
	 */
	public function request( int $post_id, int $user_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'axle_review_no_post', __( 'That post does not exist.', 'axle-review' ), array( 'status' => 404 ) );
		}

		if ( ! in_array( $post->post_type, Settings::post_types(), true ) || self::is_copy( $post_id ) ) {
			return new \WP_Error( 'axle_review_change_type', __( 'A change to this post cannot be sent for review.', 'axle-review' ), array( 'status' => 400 ) );
		}

		if ( ! in_array( $post->post_status, self::source_statuses(), true ) ) {
			return new \WP_Error( 'axle_review_change_status', __( 'Only a published post can have a change sent for review. Share a draft with a review link instead.', 'axle-review' ), array( 'status' => 400 ) );
		}

		$existing = self::copy_of( $post_id );
		if ( $existing ) {
			return new \WP_Error(
				'axle_review_change_exists',
				__( 'A change to this post is already in review.', 'axle-review' ),
				array(
					'status' => 409,
					'copy'   => current_user_can( 'edit_post', $existing ) ? $existing : 0,
				)
			);
		}

		$copy_id = $this->make_copy( $post, $user_id );
		if ( is_wp_error( $copy_id ) ) {
			return $copy_id;
		}

		update_post_meta( $post_id, self::META_COPY, $copy_id );

		/**
		 * Fires after a working copy was made of a live post.
		 *
		 * @param int $copy_id     The copy.
		 * @param int $original_id The live post.
		 * @param int $user_id     Who asked.
		 */
		do_action( 'axle_review_change_requested', $copy_id, $post_id, $user_id );

		return array(
			'copy'     => $copy_id,
			'original' => $post_id,
		);
	}

	/**
	 * Inserts the copy: a draft with the live post's content, excerpt and meta.
	 *
	 * @param \WP_Post $post    Live post.
	 * @param int      $user_id Author of the copy.
	 * @return int|\WP_Error
	 */
	private function make_copy( \WP_Post $post, int $user_id ) {
		$args = array(
			'post_type'      => $post->post_type,
			'post_status'    => 'draft',
			'post_title'     => $post->post_title,
			'post_content'   => self::strip_note_ids( (string) $post->post_content ),
			'post_excerpt'   => $post->post_excerpt,
			'post_author'    => $user_id > 0 ? $user_id : (int) $post->post_author,
			'post_parent'    => (int) $post->post_parent,
			'menu_order'     => (int) $post->menu_order,
			'post_password'  => $post->post_password,
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		);

		/**
		 * Filters the fields of a new working copy before it is inserted.
		 *
		 * @param array    $args The fields for wp_insert_post().
		 * @param \WP_Post $post The live post.
		 */
		$args = (array) apply_filters( 'axle_review_change_copy_args', $args, $post );

		// The content is a copy of what is already stored on the live post, so
		// nothing new enters. Without this, a user who may not write unfiltered
		// HTML would silently lose an embed or Custom HTML block from the copy.
		$kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses ) {
			kses_remove_filters();
		}

		try {
			$copy_id = wp_insert_post( wp_slash( $args ), true );
		} finally {
			if ( $kses ) {
				kses_init_filters();
			}
		}

		if ( is_wp_error( $copy_id ) ) {
			return $copy_id;
		}

		$copy_id = (int) $copy_id;
		update_post_meta( $copy_id, self::META_OF, (int) $post->ID );
		update_post_meta( $copy_id, self::META_BASE, (string) Fingerprint::of( $post )['hash'] );
		self::sync_meta( (int) $post->ID, $copy_id );

		// Terms go with the copy so the theme shows it as it will look. They are not merged back (see merge_terms()).
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( (int) $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_array( $terms ) && $terms ) {
				wp_set_object_terms( $copy_id, array_map( 'intval', $terms ), $taxonomy );
			}
		}

		return $copy_id;
	}

	/* Publishing the change. */

	/**
	 * Merges a copy into its live post.
	 *
	 * @param int  $copy_id   Copy ID.
	 * @param int  $user_id   Who publishes.
	 * @param bool $overwrite Publish even though the live post was edited after the copy was made.
	 * @return array{original: int, notes: int, undo: bool}|\WP_Error
	 */
	public function publish( int $copy_id, int $user_id, bool $overwrite = false ) {
		$copy        = get_post( $copy_id );
		$original_id = $copy instanceof \WP_Post ? self::original_of( $copy_id ) : 0;
		$original    = $original_id ? get_post( $original_id ) : null;
		if ( ! $copy instanceof \WP_Post || ! $original instanceof \WP_Post ) {
			return new \WP_Error( 'axle_review_change_missing', __( 'This is not a working copy of a live post.', 'axle-review' ), array( 'status' => 404 ) );
		}

		if ( ! in_array( $original->post_status, self::source_statuses(), true ) ) {
			return new \WP_Error( 'axle_review_change_not_live', __( 'The live post is no longer published, so there is nothing to change.', 'axle-review' ), array( 'status' => 409 ) );
		}

		if ( in_array( $copy->post_status, array( 'trash', 'publish', 'future' ), true ) ) {
			return new \WP_Error( 'axle_review_change_missing', __( 'This working copy cannot be published.', 'axle-review' ), array( 'status' => 409 ) );
		}

		$blocked = $this->gate->check( $copy, 'publish' );
		if ( $blocked ) {
			return $blocked;
		}

		if ( ! $overwrite && (string) get_post_meta( $copy_id, self::META_BASE, true ) !== (string) Fingerprint::of( $original )['hash'] ) {
			return new \WP_Error( 'axle_review_live_changed', __( 'The live post was edited after this copy was made. Publishing the change replaces those edits. They stay in the undo.', 'axle-review' ), array( 'status' => 409 ) );
		}

		$lock = 'axle_review_merge_' . (int) $original->ID;
		if ( get_transient( $lock ) ) {
			return new \WP_Error( 'axle_review_change_busy', __( 'A change is being published right now. Try again in a minute.', 'axle-review' ), array( 'status' => 409 ) );
		}
		set_transient( $lock, 1, MINUTE_IN_SECONDS );

		try {
			$result = $this->merge( $copy, $original, $user_id );
		} finally {
			delete_transient( $lock );
		}

		return $result;
	}

	/**
	 * The merge itself, after every check.
	 *
	 * @param \WP_Post $copy     Copy.
	 * @param \WP_Post $original Live post.
	 * @param int      $user_id  Who publishes.
	 * @return array{original: int, notes: int, undo: bool}|\WP_Error
	 */
	private function merge( \WP_Post $copy, \WP_Post $original, int $user_id ) {
		$original_id = (int) $original->ID;
		$copy_id     = (int) $copy->ID;

		// 1. Keep the live version: a core revision, and everything a revision does not hold.
		$revision = 0;
		if ( Fingerprint::revisions_on( $original ) ) {
			$revision = Fingerprint::matching_revision( $original );
			if ( ! $revision ) {
				$saved    = _wp_put_post_revision( $original );
				$revision = is_int( $saved ) ? $saved : 0;
			}
		}
		$this->store_undo( $original, $revision );

		// 2. The content. Notes that were already on the live post stay on their blocks where the block is unchanged.
		$content = self::carry_notes( (string) $original->post_content, (string) $copy->post_content );
		$updated = wp_update_post(
			wp_slash(
				array(
					'ID'           => $original_id,
					'post_title'   => $copy->post_title,
					'post_content' => $content,
					'post_excerpt' => $copy->post_excerpt,
				)
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			delete_post_meta( $original_id, self::META_UNDO );

			return $updated;
		}

		// 3. Meta and featured image, then terms when a site asks for it.
		self::sync_meta( $copy_id, $original_id );
		$this->merge_terms( $copy, $original );

		// 4. The copy's Notes belong to the live post now.
		$moved = $this->move_notes( $copy_id, $original_id );

		// 5. A record on the live post, then the copy goes.
		$this->log( $original_id, $user_id, $this->approved_by( $copy_id ) );
		$this->remember_after( $original_id );
		delete_post_meta( $original_id, self::META_COPY );
		delete_post_meta( $copy_id, self::META_OF );
		wp_delete_post( $copy_id, true );

		/**
		 * Fires after a working copy was merged into its live post.
		 *
		 * @param int   $original_id The live post.
		 * @param int   $copy_id     The copy, now deleted.
		 * @param int   $user_id     Who published the change.
		 * @param array $info        notes (how many moved), revision (the revision kept as undo, 0 when revisions are off).
		 */
		do_action(
			'axle_review_change_published',
			$original_id,
			$copy_id,
			$user_id,
			array(
				'notes'    => $moved,
				'revision' => $revision,
			)
		);

		return array(
			'original' => $original_id,
			'notes'    => $moved,
			'undo'     => true,
		);
	}

	/**
	 * Copies post meta from one post to another: every key except the skipped
	 * ones. A key the target has and the source lacks is removed, so the target
	 * ends up like the source.
	 *
	 * @param int $from Source post.
	 * @param int $to   Target post.
	 */
	public static function sync_meta( int $from, int $to ): void {
		$source = self::syncable_meta( $from );
		$target = self::syncable_meta( $to );

		foreach ( array_keys( $target ) as $key ) {
			if ( ! array_key_exists( $key, $source ) ) {
				delete_post_meta( $to, $key );
			}
		}

		foreach ( $source as $key => $values ) {
			if ( ! array_key_exists( $key, $target ) || $values !== $target[ $key ] ) {
				delete_post_meta( $to, $key );
				foreach ( $values as $value ) {
					add_post_meta( $to, $key, wp_slash( $value ) );
				}
			}
		}
	}

	/**
	 * A post's meta that moves between a live post and its copy.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, array> Key => values.
	 */
	public static function syncable_meta( int $post_id ): array {
		$meta = array();
		foreach ( (array) get_post_meta( $post_id ) as $key => $values ) {
			if ( ! self::skips_meta( (string) $key ) ) {
				$meta[ (string) $key ] = array_map( 'maybe_unserialize', (array) $values );
			}
		}

		return $meta;
	}

	/**
	 * Whether a meta key stays out of the copy and the merge.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function skips_meta( string $key ): bool {
		$skip = array(
			'_edit_lock',
			'_edit_last',
			'_wp_old_slug',
			'_wp_old_date',
			'_wp_desired_post_slug',
			'_encloseme',
			'_pingme',
			'_edit_*',
			'_wp_trash_meta_*',
			'_oembed_*',
			'_axr_*',
		);

		/**
		 * Filters the post meta keys that are not copied to a working copy and
		 * not merged back. A key ending in * matches every key that starts the same.
		 *
		 * @param string[] $skip Keys and prefixes.
		 */
		$skip = (array) apply_filters( 'axle_review_change_skip_meta', $skip );

		foreach ( $skip as $pattern ) {
			$pattern = (string) $pattern;
			if ( '' === $pattern ) {
				continue;
			}
			if ( '*' === substr( $pattern, -1 ) ? 0 === strpos( $key, substr( $pattern, 0, -1 ) ) : $key === $pattern ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Copies terms from the copy to the live post, when a site asks for it.
	 * By default the live post keeps its own terms.
	 *
	 * @param \WP_Post $copy     Copy.
	 * @param \WP_Post $original Live post.
	 */
	private function merge_terms( \WP_Post $copy, \WP_Post $original ): void {
		/**
		 * Filters whether publishing a change also takes the copy's categories,
		 * tags and other terms. The default keeps the live post's own terms.
		 *
		 * @param bool     $merge    Default false.
		 * @param \WP_Post $copy     The copy.
		 * @param \WP_Post $original The live post.
		 */
		if ( ! apply_filters( 'axle_review_change_merge_terms', false, $copy, $original ) ) {
			return;
		}

		foreach ( get_object_taxonomies( $original->post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( (int) $copy->ID, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_array( $terms ) ) {
				wp_set_object_terms( (int) $original->ID, array_map( 'intval', $terms ), $taxonomy );
			}
		}
	}

	/**
	 * Moves every Note of the copy (threads, replies, resolved ones) to the live post.
	 *
	 * @param int $copy_id     Copy.
	 * @param int $original_id Live post.
	 * @return int Notes moved.
	 */
	private function move_notes( int $copy_id, int $original_id ): int {
		global $wpdb;

		$ids = get_comments(
			array(
				'post_id' => $copy_id,
				'type'    => 'note',
				'status'  => 'all',
				'fields'  => 'ids',
				'number'  => 0,
			)
		);
		if ( ! $ids ) {
			return 0;
		}

		foreach ( array_chunk( array_map( 'intval', $ids ), 200 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Moving comment rows between two posts; the cache is cleared right after.
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET comment_post_ID = %d WHERE comment_ID IN (' . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')', array_merge( array( $wpdb->comments, $original_id ), $chunk ) ) );
			clean_comment_cache( $chunk );
		}

		Notes::flush();

		return count( $ids );
	}

	/**
	 * How many reviewers approved the copy (their names are not copied, so
	 * erasing a reviewer's data stays complete).
	 *
	 * @param int $copy_id Copy.
	 * @return int
	 */
	private function approved_by( int $copy_id ): int {
		$links    = new Links();
		$approved = 0;
		foreach ( $links->for_post( $copy_id ) as $link ) {
			if ( 'approved' === $link['decision'] && 'revoked' !== $link['status'] ) {
				++$approved;
			}
		}

		return $approved;
	}

	/**
	 * Writes a resolved note on the live post saying the change went live.
	 *
	 * @param int $post_id  Live post.
	 * @param int $user_id  Who published.
	 * @param int $approved Reviewers who approved.
	 */
	private function log( int $post_id, int $user_id, int $approved ): void {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		$text = $approved > 0
			? sprintf(
				/* translators: %d: number of reviewers who approved. */
				_n( 'Published a reviewed change. %d reviewer approved it.', 'Published a reviewed change. %d reviewers approved it.', $approved, 'axle-review' ),
				$approved
			)
			: __( 'Published a reviewed change.', 'axle-review' );

		$id = wp_insert_comment(
			wp_slash(
				array(
					'comment_post_ID'      => $post_id,
					'comment_type'         => 'note',
					'comment_approved'     => 1,
					'comment_parent'       => 0,
					'user_id'              => $user ? (int) $user->ID : 0,
					'comment_author'       => $user ? $user->display_name : __( 'WP-CLI', 'axle-review' ),
					'comment_author_email' => $user ? $user->user_email : '',
					'comment_content'      => esc_html( $text ),
				)
			)
		);
		if ( $id ) {
			Notes::flush();
		}
	}

	/* Undo. */

	/**
	 * Saves what Undo puts back: title, content, excerpt and meta of the live post.
	 *
	 * @param \WP_Post $original Live post, before the merge.
	 * @param int      $revision Core revision of it, 0 when revisions are off.
	 */
	private function store_undo( \WP_Post $original, int $revision ): void {
		$record = array(
			'time'     => time(),
			'revision' => $revision,
			'title'    => (string) $original->post_title,
			'content'  => (string) $original->post_content,
			'excerpt'  => (string) $original->post_excerpt,
			'meta'     => self::syncable_meta( (int) $original->ID ),
			'after'    => '',
		);

		update_post_meta( (int) $original->ID, self::META_UNDO, wp_slash( (string) wp_json_encode( $record ) ) );
	}

	/**
	 * Remembers the live post's hash after the merge, so Undo can tell when it was edited since.
	 *
	 * @param int $post_id Live post.
	 */
	private function remember_after( int $post_id ): void {
		$record = self::undo_record( $post_id );
		$post   = get_post( $post_id );
		if ( ! $record || ! $post instanceof \WP_Post ) {
			return;
		}

		$record['after'] = (string) Fingerprint::of( $post )['hash'];
		update_post_meta( $post_id, self::META_UNDO, wp_slash( (string) wp_json_encode( $record ) ) );
	}

	/**
	 * The undo record of a live post.
	 *
	 * @param int $post_id Live post.
	 * @return array|null
	 */
	public static function undo_record( int $post_id ): ?array {
		$raw    = (string) get_post_meta( $post_id, self::META_UNDO, true );
		$record = '' === $raw ? null : json_decode( $raw, true );

		return is_array( $record ) && isset( $record['content'], $record['title'] ) ? $record : null;
	}

	/**
	 * What the undo of a post looks like, for screens.
	 *
	 * @param int $post_id Live post.
	 * @return array{available: bool, time: string, edited: bool}
	 */
	public static function undo_state( int $post_id ): array {
		$record = self::undo_record( $post_id );
		$post   = get_post( $post_id );
		if ( ! $record || ! $post instanceof \WP_Post ) {
			return array(
				'available' => false,
				'time'      => '',
				'edited'    => false,
			);
		}

		return array(
			'available' => true,
			'time'      => gmdate( 'c', (int) $record['time'] ),
			'edited'    => '' !== (string) $record['after'] && (string) Fingerprint::of( $post )['hash'] !== (string) $record['after'],
		);
	}

	/**
	 * Puts the live post back to how it was before the last published change.
	 *
	 * @param int  $post_id   Live post.
	 * @param int  $user_id   Who undoes it.
	 * @param bool $overwrite Undo even though the post was edited after the change.
	 * @return true|\WP_Error
	 */
	public function undo( int $post_id, int $user_id, bool $overwrite = false ) {
		$post   = get_post( $post_id );
		$record = self::undo_record( $post_id );
		if ( ! $post instanceof \WP_Post || ! $record ) {
			return new \WP_Error( 'axle_review_no_undo', __( 'There is no published change to undo on this post.', 'axle-review' ), array( 'status' => 404 ) );
		}

		$state = self::undo_state( $post_id );
		if ( $state['edited'] && ! $overwrite ) {
			return new \WP_Error( 'axle_review_undo_edited', __( 'The post was edited after the change went live. Undoing the change also removes those edits.', 'axle-review' ), array( 'status' => 409 ) );
		}

		$updated = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post_id,
					'post_title'   => (string) $record['title'],
					'post_content' => (string) $record['content'],
					'post_excerpt' => (string) $record['excerpt'],
				)
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$source = array();
		foreach ( (array) ( $record['meta'] ?? array() ) as $key => $values ) {
			$source[ (string) $key ] = (array) $values;
		}
		self::restore_meta( $post_id, $source );

		delete_post_meta( $post_id, self::META_UNDO );

		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		$id   = wp_insert_comment(
			wp_slash(
				array(
					'comment_post_ID'      => $post_id,
					'comment_type'         => 'note',
					'comment_approved'     => 1,
					'comment_parent'       => 0,
					'user_id'              => $user ? (int) $user->ID : 0,
					'comment_author'       => $user ? $user->display_name : __( 'WP-CLI', 'axle-review' ),
					'comment_author_email' => $user ? $user->user_email : '',
					'comment_content'      => esc_html__( 'Undid the last published change.', 'axle-review' ),
				)
			)
		);
		if ( $id ) {
			Notes::flush();
		}

		/**
		 * Fires after the last published change of a post was undone.
		 *
		 * @param int $post_id The live post.
		 * @param int $user_id Who undid it.
		 */
		do_action( 'axle_review_change_undone', $post_id, $user_id );

		return true;
	}

	/**
	 * Sets a post's syncable meta to exactly the given values.
	 *
	 * @param int                  $post_id Post.
	 * @param array<string, array> $source  Key => values.
	 */
	private static function restore_meta( int $post_id, array $source ): void {
		foreach ( array_keys( self::syncable_meta( $post_id ) ) as $key ) {
			if ( ! array_key_exists( $key, $source ) ) {
				delete_post_meta( $post_id, $key );
			}
		}

		foreach ( $source as $key => $values ) {
			if ( self::skips_meta( $key ) ) {
				continue;
			}
			delete_post_meta( $post_id, $key );
			foreach ( $values as $value ) {
				add_post_meta( $post_id, $key, wp_slash( $value ) );
			}
		}
	}

	/* Discarding. */

	/**
	 * Deletes a working copy and its review links. The live post is not touched.
	 *
	 * @param int $copy_id Copy.
	 * @param int $user_id Who discards it.
	 * @return true|\WP_Error
	 */
	public function discard( int $copy_id, int $user_id ) {
		$copy = get_post( $copy_id );
		if ( ! $copy instanceof \WP_Post || ! self::is_copy( $copy_id ) ) {
			return new \WP_Error( 'axle_review_change_missing', __( 'This is not a working copy of a live post.', 'axle-review' ), array( 'status' => 404 ) );
		}

		$original_id = (int) get_post_meta( $copy_id, self::META_OF, true );
		if ( $original_id && (int) get_post_meta( $original_id, self::META_COPY, true ) === $copy_id ) {
			delete_post_meta( $original_id, self::META_COPY );
		}

		wp_delete_post( $copy_id, true );

		/**
		 * Fires after a working copy was discarded without publishing it.
		 *
		 * @param int $copy_id     The copy, now deleted.
		 * @param int $original_id The live post.
		 * @param int $user_id     Who discarded it.
		 */
		do_action( 'axle_review_change_discarded', $copy_id, $original_id, $user_id );

		return true;
	}

	/* State, for screens. */

	/**
	 * What a screen needs to know about a post's changes. Titles and links are
	 * only given for posts the current user may edit.
	 *
	 * @param \WP_Post $post The post being edited.
	 * @return array
	 */
	public function state( \WP_Post $post ): array {
		$id    = (int) $post->ID;
		$state = array(
			'role'        => 'none',
			'canRequest'  => false,
			'canPublish'  => false,
			'canUndo'     => false,
			'copy'        => null,
			'original'    => null,
			'liveChanged' => false,
			'undo'        => self::undo_state( $id ),
		);

		if ( self::is_copy( $id ) ) {
			$state['role']       = 'copy';
			$original_id         = self::original_of( $id );
			$original            = $original_id ? get_post( $original_id ) : null;
			$state['canPublish'] = $original instanceof \WP_Post && self::can_publish( $id );
			if ( $original instanceof \WP_Post ) {
				$may                  = current_user_can( 'edit_post', $original_id );
				$state['original']    = array(
					'id'      => $original_id,
					'status'  => $original->post_status,
					'title'   => $may ? get_the_title( $original ) : '',
					'url'     => 'publish' === $original->post_status ? get_permalink( $original ) : '',
					'editUrl' => $may ? (string) get_edit_post_link( $original_id, 'raw' ) : '',
				);
				$state['liveChanged'] = (string) get_post_meta( $id, self::META_BASE, true ) !== (string) Fingerprint::of( $original )['hash'];
			}

			return $state;
		}

		$state['canUndo'] = $state['undo']['available'] && self::can_undo( $id );
		if ( ! $state['canUndo'] ) {
			$state['undo'] = array(
				'available' => false,
				'time'      => '',
				'edited'    => false,
			);
		}

		if ( in_array( $post->post_status, self::source_statuses(), true ) && in_array( $post->post_type, Settings::post_types(), true ) ) {
			$state['role']       = 'original';
			$state['canRequest'] = self::can_request( $id );
			$copy_id             = self::copy_of( $id );
			if ( $copy_id && current_user_can( 'edit_post', $copy_id ) ) {
				$state['copy'] = array(
					'id'      => $copy_id,
					'status'  => get_post_status( $copy_id ),
					'editUrl' => (string) get_edit_post_link( $copy_id, 'raw' ),
				);
			} elseif ( $copy_id ) {
				$state['copy'] = array(
					'id'      => 0,
					'status'  => '',
					'editUrl' => '',
				);
			}
		}

		return $state;
	}

	/* Hooks. */

	/**
	 * Keeps a copy from being published directly: the change goes live only by merging.
	 *
	 * @param mixed $data    Slashed post data.
	 * @param mixed $postarr Raw post array.
	 * @return array
	 */
	public function hold_copy( $data, $postarr ): array {
		$data = (array) $data;
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $data;
		}

		$post_id = (int) ( ( (array) $postarr )['ID'] ?? 0 );
		if ( ! $post_id || ! self::is_copy( $post_id ) || ! in_array( (string) ( $data['post_status'] ?? '' ), array( 'publish', 'future' ), true ) ) {
			return $data;
		}

		$data['post_status'] = 'draft';
		if ( get_current_user_id() ) {
			set_transient( 'axle_review_change_notice_' . get_current_user_id(), self::use_merge_message(), MINUTE_IN_SECONDS );
		}

		return $data;
	}

	/**
	 * Refuses a REST save that would publish a copy.
	 *
	 * @param \stdClass|\WP_Error $prepared Prepared post.
	 * @param \WP_REST_Request    $request  Request.
	 * @return \stdClass|\WP_Error
	 */
	public function rest_check( $prepared, $request ) {
		unset( $request );
		if ( is_wp_error( $prepared ) || empty( $prepared->ID ) || empty( $prepared->post_status ) ) {
			return $prepared;
		}

		if ( self::is_copy( (int) $prepared->ID ) && in_array( (string) $prepared->post_status, array( 'publish', 'future' ), true ) ) {
			return new \WP_Error( self::ERROR_USE_MERGE, self::use_merge_message(), array( 'status' => 409 ) );
		}

		return $prepared;
	}

	/**
	 * The message when someone publishes a copy directly.
	 *
	 * @return string
	 */
	public static function use_merge_message(): string {
		$message = __( 'This is a working copy of a live post. Open Share for review and choose Publish the change to put it live.', 'axle-review' );

		/**
		 * Filters the message shown when someone tries to publish a working copy directly.
		 *
		 * @param string $message Message.
		 */
		$filtered = (string) apply_filters( 'axle_review_change_use_merge_message', $message );

		return '' !== trim( $filtered ) ? $filtered : $message;
	}

	/**
	 * Tells a classic-editor user why the copy was not published.
	 */
	public function notice(): void {
		$key     = 'axle_review_change_notice_' . get_current_user_id();
		$message = get_transient( $key );
		if ( ! is_string( $message ) || '' === $message ) {
			return;
		}

		delete_transient( $key );
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
	}

	/**
	 * A trashed copy no longer counts as the open change of its live post.
	 *
	 * @param int $post_id Post ID.
	 */
	public function forget_copy( $post_id ): void {
		$original = (int) get_post_meta( (int) $post_id, self::META_OF, true );
		if ( $original && (int) get_post_meta( $original, self::META_COPY, true ) === (int) $post_id ) {
			delete_post_meta( $original, self::META_COPY );
		}
	}

	/**
	 * Before a post is deleted for good: a copy clears its live post's pointer;
	 * a live post takes its open copy with it.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_before_delete( $post_id ): void {
		$post_id = (int) $post_id;
		$this->forget_copy( $post_id );

		$copy = (int) get_post_meta( $post_id, self::META_COPY, true );
		if ( $copy && (int) get_post_meta( $copy, self::META_OF, true ) === $post_id ) {
			delete_post_meta( $post_id, self::META_COPY );
			wp_delete_post( $copy, true );
		}
	}

	/**
	 * Marks both posts in the posts list.
	 *
	 * @param array    $states Post states.
	 * @param \WP_Post $post   Post.
	 * @return array
	 */
	public function post_states( $states, $post ): array {
		$states = (array) $states;

		if ( self::is_copy( (int) $post->ID ) ) {
			$states['axr_change'] = __( 'Change in review', 'axle-review' );
		} elseif ( self::copy_of( (int) $post->ID ) && current_user_can( 'edit_post', (int) $post->ID ) ) {
			$states['axr_change'] = __( 'Has a change in review', 'axle-review' );
		}

		return $states;
	}

	/* Content helpers. */

	/**
	 * Removes every Note ID from a post's blocks. A copy starts with no Notes.
	 *
	 * @param string $content Block markup.
	 * @return string
	 */
	public static function strip_note_ids( string $content ): string {
		if ( false === strpos( $content, 'noteId' ) ) {
			return $content;
		}

		$strip = static function ( array $blocks ) use ( &$strip ): array {
			foreach ( $blocks as $key => $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}
				if ( isset( $block['attrs']['metadata']['noteId'] ) ) {
					unset( $blocks[ $key ]['attrs']['metadata']['noteId'] );
					if ( ! $blocks[ $key ]['attrs']['metadata'] ) {
						unset( $blocks[ $key ]['attrs']['metadata'] );
					}
				}
				if ( ! empty( $block['innerBlocks'] ) ) {
					$blocks[ $key ]['innerBlocks'] = $strip( $block['innerBlocks'] );
				}
			}

			return $blocks;
		};

		return serialize_blocks( $strip( parse_blocks( $content ) ) );
	}

	/**
	 * Puts the Notes that were on the live post's blocks onto the same blocks
	 * of the new content, where the block is unchanged. Notes whose block was
	 * changed or removed stay on the post, without a block (see Dashboard > Notes).
	 *
	 * @param string $live Content of the live post.
	 * @param string $incoming Content to publish.
	 * @return string
	 */
	public static function carry_notes( string $live, string $incoming ): string {
		if ( false === strpos( $live, 'noteId' ) ) {
			return $incoming;
		}

		$old_blocks = parse_blocks( $live );
		$new_blocks = parse_blocks( $incoming );
		$shape      = Anchor::shape();
		$taken      = array();

		$wanted = array();
		Anchor::walk(
			$old_blocks,
			static function ( $block ) use ( &$wanted ) {
				$ids = Anchor::note_ids( $block );
				if ( $ids ) {
					$wanted[] = array(
						'key' => (string) $block['blockName'] . '|' . Anchor::hash( $block ),
						'ids' => $ids,
					);
				}
			}
		);

		foreach ( $wanted as $entry ) {
			$path = null;
			Anchor::walk(
				$new_blocks,
				static function ( $block, $at ) use ( $entry, &$taken, &$path ) {
					if ( null === $path && ! isset( $taken[ $at ] ) && (string) $block['blockName'] . '|' . Anchor::hash( $block ) === $entry['key'] ) {
						$path         = $at;
						$taken[ $at ] = true;
					}
				}
			);

			if ( null === $path ) {
				continue;
			}

			foreach ( $entry['ids'] as $id ) {
				$next = Anchor::add_note_id( $new_blocks, $path, (int) $id, $shape );
				if ( null !== $next ) {
					$new_blocks = $next;
				}
			}
		}

		return serialize_blocks( $new_blocks );
	}

	/**
	 * Working copies whose live post is gone or no longer published, for Site Health.
	 *
	 * @return int[] Copy IDs.
	 */
	public static function stranded(): array {
		$copies = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'meta_key'       => self::META_OF, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A rare meta key, read by Site Health only.
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$stranded = array();
		foreach ( $copies as $copy_id ) {
			$original_id = self::original_of( (int) $copy_id );
			$original    = $original_id ? get_post( $original_id ) : null;
			if ( ! $original instanceof \WP_Post || ! in_array( $original->post_status, self::source_statuses(), true ) ) {
				$stranded[] = (int) $copy_id;
			}
		}

		return $stranded;
	}
}
