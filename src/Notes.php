<?php
/**
 * Core Notes: guest notes, replies, resolve and reopen, queries and counts.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the plugin writes is a normal core Note (a comment of type
 * note), so editors work with it in the block editor's Notes sidebar.
 *
 * Open is comment_approved 0, resolved is 1. Resolving and reopening write
 * the same activity reply the editor writes: an empty child note with the
 * _wp_note_status meta.
 */
final class Notes {

	public const CACHE_GROUP = 'axle_review';

	/**
	 * The longest note a guest may leave.
	 *
	 * @return int
	 */
	public static function max_length(): int {
		/**
		 * Filters the longest note a reviewer may leave, in characters.
		 *
		 * @param int $length Default 2000, between 1 and 20000.
		 */
		return max( 1, min( 20000, (int) apply_filters( 'axle_review_note_max_length', 2000 ) ) );
	}

	/**
	 * Cleans guest text: plain text only, length capped.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function clean_text( string $text ): string {
		$text = wp_strip_all_tags( $text );
		$text = trim( (string) preg_replace( "/\r\n?/", "\n", $text ) );

		return mb_substr( $text, 0, self::max_length() );
	}

	/**
	 * Inserts a guest note as a core Note.
	 *
	 * @param array $args post_id, link_id, session, text, name, email, verified, anchor (path, hash, blockName, quote, excerpt), parent.
	 * @return int|\WP_Error Note ID.
	 */
	public function create_guest_note( array $args ) {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$parent  = (int) ( $args['parent'] ?? 0 );
		$text    = self::clean_text( (string) ( $args['text'] ?? '' ) );
		$anchor  = isset( $args['anchor'] ) && is_array( $args['anchor'] ) ? $args['anchor'] : array();
		$quote   = self::clean_text( (string) ( $anchor['quote'] ?? '' ) );
		$quote   = mb_substr( $quote, 0, 500 );

		if ( '' === $text ) {
			return new \WP_Error( 'axle_review_empty', __( 'Write a note first.', 'axle-review' ), array( 'status' => 400 ) );
		}

		if ( 0 === $parent && '' !== $quote ) {
			/* translators: %s: text the reviewer selected. */
			$text = sprintf( __( 'About "%s":', 'axle-review' ), $quote ) . "\n\n" . $text;
		}

		/**
		 * Filters a reviewer's note text before it is saved. It is stripped of
		 * tags again afterwards.
		 *
		 * @param string $text Note text.
		 * @param array  $args post_id, link_id, name, email, anchor, parent.
		 */
		$text = self::clean_text( (string) apply_filters( 'axle_review_note_content', $text, $args ) );
		if ( '' === $text ) {
			return new \WP_Error( 'axle_review_empty', __( 'Write a note first.', 'axle-review' ), array( 'status' => 400 ) );
		}

		$name  = mb_substr( sanitize_text_field( (string) ( $args['name'] ?? '' ) ), 0, 100 );
		$email = sanitize_email( (string) ( $args['email'] ?? '' ) );

		$id = wp_insert_comment(
			wp_slash(
				array(
					'comment_post_ID'      => $post_id,
					'comment_type'         => 'note',
					'comment_approved'     => 0,
					'comment_parent'       => $parent,
					'user_id'              => 0,
					'comment_author'       => '' === $name ? __( 'Reviewer', 'axle-review' ) : $name,
					'comment_author_email' => is_email( $email ) ? $email : '',
					'comment_author_url'   => '',
					'comment_author_IP'    => '',
					'comment_agent'        => '',
					'comment_content'      => $text,
				)
			)
		);

		if ( ! $id ) {
			return new \WP_Error( 'axle_review_db', __( 'The note could not be saved.', 'axle-review' ), array( 'status' => 500 ) );
		}

		add_comment_meta( (int) $id, '_axr_link_id', (int) ( $args['link_id'] ?? 0 ), true );
		add_comment_meta( (int) $id, '_axr_session', (string) ( $args['session'] ?? '' ), true );
		add_comment_meta( (int) $id, '_axr_email_verified', empty( $args['verified'] ) ? 0 : 1, true );
		if ( 0 === $parent ) {
			add_comment_meta(
				(int) $id,
				'_axr_anchor',
				wp_json_encode(
					array(
						'path'      => (string) ( $anchor['path'] ?? '' ),
						'hash'      => (string) ( $anchor['hash'] ?? '' ),
						'blockName' => (string) ( $anchor['blockName'] ?? '' ),
						'quote'     => $quote,
					)
				),
				true
			);
			add_comment_meta( (int) $id, '_axr_pending_attach', time(), true );
		}

		return (int) $id;
	}

	/**
	 * A note, if the ID is a note.
	 *
	 * @param int $id Note ID.
	 * @return \WP_Comment|null
	 */
	public static function get( int $id ): ?\WP_Comment {
		$note = get_comment( $id );
		return $note instanceof \WP_Comment && 'note' === $note->comment_type ? $note : null;
	}

	/**
	 * Resolves or reopens a top-level note, the way the editor does. Setting
	 * the status a note already has changes nothing.
	 *
	 * @param int    $id      Note ID.
	 * @param string $to      resolved or open.
	 * @param int    $user_id Who.
	 * @return true|\WP_Error
	 */
	public function set_status( int $id, string $to, int $user_id ) {
		$note = self::get( $id );
		if ( ! $note || 0 !== (int) $note->comment_parent ) {
			return new \WP_Error( 'axle_review_no_note', __( 'That note does not exist, or it is a reply.', 'axle-review' ), array( 'status' => 404 ) );
		}

		if ( 'trash' === $note->comment_approved ) {
			return new \WP_Error( 'axle_review_trashed', __( 'That note is in the trash.', 'axle-review' ), array( 'status' => 400 ) );
		}

		$resolve = 'resolved' === $to;
		$target  = $resolve ? '1' : '0';
		if ( (string) $note->comment_approved === $target ) {
			return true;
		}

		wp_set_comment_status( $id, $resolve ? 'approve' : 'hold' );

		$user  = get_userdata( $user_id );
		$reply = wp_insert_comment(
			wp_slash(
				array(
					'comment_post_ID'      => (int) $note->comment_post_ID,
					'comment_type'         => 'note',
					'comment_approved'     => (int) $target,
					'comment_parent'       => $id,
					'user_id'              => $user_id,
					'comment_author'       => $user ? $user->display_name : '',
					'comment_author_email' => $user ? $user->user_email : '',
					'comment_content'      => '',
				)
			)
		);
		if ( $reply ) {
			add_comment_meta( (int) $reply, '_wp_note_status', $resolve ? 'resolved' : 'reopen', true );
		}

		$note = self::get( $id );

		if ( $resolve ) {
			/**
			 * Fires after a note was resolved from the inbox, an ability or WP-CLI.
			 *
			 * @param \WP_Comment $note    The note.
			 * @param int         $user_id Who resolved it.
			 */
			do_action( 'axle_review_note_resolved', $note, $user_id );
		} else {
			/**
			 * Fires after a note was reopened from the inbox, an ability or WP-CLI.
			 *
			 * @param \WP_Comment $note    The note.
			 * @param int         $user_id Who reopened it.
			 */
			do_action( 'axle_review_note_reopened', $note, $user_id );
		}

		return true;
	}

	/**
	 * Adds a note or a reply as a user. Content may hold core mention chips.
	 *
	 * @param int    $post_id Post ID.
	 * @param int    $user_id Author.
	 * @param string $content Note HTML.
	 * @param int    $thread  Thread to reply to, 0 for a new note.
	 * @return int|\WP_Error Note ID.
	 */
	public function add_user_note( int $post_id, int $user_id, string $content, int $thread = 0 ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new \WP_Error( 'axle_review_no_user', __( 'Unknown user.', 'axle-review' ), array( 'status' => 400 ) );
		}

		$content = trim( wp_kses( $content, self::allowed_html() ) );
		if ( '' === $content ) {
			return new \WP_Error( 'axle_review_empty', __( 'Write a note first.', 'axle-review' ), array( 'status' => 400 ) );
		}

		$id = wp_insert_comment(
			wp_slash(
				array(
					'comment_post_ID'      => $post_id,
					'comment_type'         => 'note',
					'comment_approved'     => 0,
					'comment_parent'       => $thread,
					'user_id'              => $user_id,
					'comment_author'       => $user->display_name,
					'comment_author_email' => $user->user_email,
					'comment_content'      => $content,
				)
			)
		);

		if ( ! $id ) {
			return new \WP_Error( 'axle_review_db', __( 'The note could not be saved.', 'axle-review' ), array( 'status' => 500 ) );
		}

		// Core emails mentioned users from rest_insert_comment only; a note saved in PHP has to ask.
		if ( function_exists( 'wp_notify_note_mentions' ) ) {
			$saved = get_comment( (int) $id );
			wp_notify_note_mentions( $saved instanceof \WP_Comment ? $saved : null );
		}

		return (int) $id;
	}

	/**
	 * HTML allowed in notes written by users: what the editor writes.
	 *
	 * @return array
	 */
	public static function allowed_html(): array {
		return array(
			'span'   => array( 'class' => true ),
			'strong' => array(),
			'em'     => array(),
			'a'      => array( 'href' => true ),
			'code'   => array(),
			'br'     => array(),
		);
	}

	/**
	 * A core mention chip for a user, as the editor writes it in 7.1.
	 *
	 * @param \WP_User $user User.
	 * @return string
	 */
	public static function mention( \WP_User $user ): string {
		return sprintf( '<span class="wp-note-mention user-%d">@%s</span>', (int) $user->ID, esc_html( $user->display_name ) );
	}

	/**
	 * Whether this WordPress has mentions in notes (7.1+).
	 *
	 * @return bool
	 */
	public static function has_mentions(): bool {
		return function_exists( 'wp_get_note_mentioned_user_ids' );
	}

	/**
	 * User IDs mentioned in a note.
	 *
	 * @param string $content Note content.
	 * @return int[]
	 */
	public static function mentioned( string $content ): array {
		if ( function_exists( 'wp_get_note_mentioned_user_ids' ) ) {
			return array_map( 'intval', wp_get_note_mentioned_user_ids( $content ) );
		}

		return array();
	}

	/**
	 * Whether a note is an activity reply (resolved or reopened).
	 *
	 * @param \WP_Comment $note Note.
	 * @return bool
	 */
	public static function is_activity( \WP_Comment $note ): bool {
		$status = get_comment_meta( (int) $note->comment_ID, '_wp_note_status', true );
		return in_array( $status, array( 'resolved', 'reopen' ), true );
	}

	/**
	 * Whether a note came from a reviewer through a link.
	 *
	 * @param \WP_Comment $note Note.
	 * @return bool
	 */
	public static function is_guest( \WP_Comment $note ): bool {
		return 0 === (int) $note->user_id && '' !== (string) get_comment_meta( (int) $note->comment_ID, '_axr_link_id', true );
	}

	/**
	 * The stored anchor of a guest note.
	 *
	 * @param int $id Note ID.
	 * @return array
	 */
	public static function anchor( int $id ): array {
		$anchor = json_decode( (string) get_comment_meta( $id, '_axr_anchor', true ), true );
		return is_array( $anchor ) ? $anchor : array();
	}

	/**
	 * Notes matching filters.
	 *
	 * @param array $args status (unresolved, resolved, all), post_id, post_type, author (user ID), guests (bool),
	 *                    on_posts_by (user ID), mentions (user ID), since (Unix time), page, per_page.
	 * @return array{items: \WP_Comment[], total: int}
	 */
	public function query( array $args ): array {
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$query    = self::query_args( $args );

		if ( ! empty( $args['mentions'] ) ) {
			// A LIKE prefilter on the chip class, then the exact check.
			$user_id         = (int) $args['mentions'];
			$query['search'] = 'user-' . $user_id;
			unset( $query['parent'] );
			$query['number'] = 500;
			$candidates      = get_comments( $query );
			$matches         = array();
			foreach ( (array) $candidates as $note ) {
				if ( $note instanceof \WP_Comment && in_array( $user_id, self::mentioned( (string) $note->comment_content ), true ) ) {
					$matches[] = $note;
				}
			}

			return array(
				'items' => array_slice( $matches, ( $page - 1 ) * $per_page, $per_page ),
				'total' => count( $matches ),
			);
		}

		$count          = $query;
		$count['count'] = true;
		$total          = $this->cached_count( $count );

		$query['number'] = $per_page;
		$query['offset'] = ( $page - 1 ) * $per_page;

		return array(
			'items' => array_values( array_filter( (array) get_comments( $query ), static fn( $note ) => $note instanceof \WP_Comment ) ),
			'total' => $total,
		);
	}

	/**
	 * WP_Comment_Query arguments for the filters.
	 *
	 * @param array $args Filters, see query().
	 * @return array
	 */
	public static function query_args( array $args ): array {
		$status = (string) ( $args['status'] ?? 'unresolved' );
		$query  = array(
			'type'    => 'note',
			'parent'  => 0,
			'status'  => 'resolved' === $status ? 'approve' : ( 'all' === $status ? array( 'hold', 'approve' ) : 'hold' ),
			'orderby' => 'comment_date_gmt',
			'order'   => 'DESC',
		);

		if ( ! empty( $args['post_id'] ) ) {
			$query['post_id'] = (int) $args['post_id'];
		}
		if ( ! empty( $args['post_type'] ) ) {
			$query['post_type'] = sanitize_key( (string) $args['post_type'] );
		}
		if ( ! empty( $args['author'] ) ) {
			$query['user_id'] = (int) $args['author'];
		}
		if ( ! empty( $args['on_posts_by'] ) ) {
			$query['post_author__in'] = array( (int) $args['on_posts_by'] );
		}
		if ( ! empty( $args['guests'] ) ) {
			$query['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Guest notes carry this key; notes are few next to comments.
				array(
					'key'     => '_axr_link_id',
					'compare' => 'EXISTS',
				),
			);
		}
		if ( ! empty( $args['since'] ) ) {
			$query['date_query'] = array(
				array(
					'after'     => gmdate( 'Y-m-d H:i:s', (int) $args['since'] ),
					'column'    => 'comment_date_gmt',
					'inclusive' => true,
				),
			);
		}
		if ( ! empty( $args['restrict_author'] ) ) {
			$query['post_author__in'] = array( (int) $args['restrict_author'] );
		}

		return $query;
	}

	/**
	 * A comment count, cached until a note changes.
	 *
	 * @param array $query WP_Comment_Query arguments with count true.
	 * @return int
	 */
	public function cached_count( array $query ): int {
		$key   = 'count_' . md5( (string) wp_json_encode( $query ) ) . '_' . self::last_changed();
		$found = wp_cache_get( $key, self::CACHE_GROUP );
		if ( false !== $found ) {
			return (int) $found;
		}

		$count = (int) get_comments( $query );
		wp_cache_set( $key, $count, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $count;
	}

	/**
	 * The cache generation, bumped whenever a note changes.
	 *
	 * @return string
	 */
	public static function last_changed(): string {
		$changed = wp_cache_get( 'last_changed', self::CACHE_GROUP );
		if ( ! $changed ) {
			$changed = microtime();
			wp_cache_set( 'last_changed', $changed, self::CACHE_GROUP );
		}

		return (string) $changed;
	}

	/**
	 * Drops every cached count.
	 */
	public static function flush(): void {
		wp_cache_set( 'last_changed', microtime(), self::CACHE_GROUP );
	}

	/**
	 * Open top-level notes on a post.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public function unresolved_count( int $post_id ): int {
		return $this->cached_count(
			array(
				'type'    => 'note',
				'parent'  => 0,
				'status'  => 'hold',
				'post_id' => $post_id,
				'count'   => true,
			)
		);
	}

	/**
	 * Open top-level notes on a post, all of them (bounded).
	 *
	 * @param int $post_id Post ID.
	 * @return \WP_Comment[]
	 */
	public function unresolved( int $post_id ): array {
		$notes = get_comments(
			array(
				'type'    => 'note',
				'parent'  => 0,
				'status'  => 'hold',
				'post_id' => $post_id,
				'number'  => 500,
				'orderby' => 'comment_date_gmt',
				'order'   => 'ASC',
			)
		);

		return array_values( array_filter( (array) $notes, static fn( $note ) => $note instanceof \WP_Comment ) );
	}

	/**
	 * Replies in a thread, oldest first.
	 *
	 * @param int $id Thread note ID.
	 * @return \WP_Comment[]
	 */
	public function replies( int $id ): array {
		$replies = get_comments(
			array(
				'type'    => 'note',
				'parent'  => $id,
				'status'  => array( 'hold', 'approve' ),
				'number'  => 500,
				'orderby' => 'comment_date_gmt',
				'order'   => 'ASC',
			)
		);

		return array_values( array_filter( (array) $replies, static fn( $note ) => $note instanceof \WP_Comment ) );
	}

	/**
	 * The number of replies with text in a thread (activity replies left out).
	 *
	 * @param int $id Thread note ID.
	 * @return int
	 */
	public function reply_count( int $id ): int {
		$count = 0;
		foreach ( $this->replies( $id ) as $reply ) {
			if ( ! self::is_activity( $reply ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * A note as an array, for REST, abilities and WP-CLI.
	 *
	 * @param \WP_Comment $note Note.
	 * @return array
	 */
	public static function to_array( \WP_Comment $note ): array {
		$post   = get_post( (int) $note->comment_post_ID );
		$anchor = self::anchor( (int) $note->comment_ID );
		$guest  = self::is_guest( $note );

		return array(
			'id'         => (int) $note->comment_ID,
			'post_id'    => (int) $note->comment_post_ID,
			'post_title' => $post ? get_the_title( $post ) : '',
			'parent'     => (int) $note->comment_parent,
			'author'     => (string) $note->comment_author,
			'user_id'    => (int) $note->user_id,
			'guest'      => $guest,
			'status'     => '1' === (string) $note->comment_approved ? 'resolved' : ( 'trash' === $note->comment_approved ? 'trash' : 'open' ),
			'content'    => (string) $note->comment_content,
			'text'       => trim( html_entity_decode( wp_strip_all_tags( (string) $note->comment_content ), ENT_QUOTES, 'UTF-8' ) ),
			'date'       => mysql_to_rfc3339( (string) $note->comment_date_gmt ),
			'block'      => (string) ( $anchor['blockName'] ?? '' ),
			'mentions'   => self::mentioned( (string) $note->comment_content ),
		);
	}
}
