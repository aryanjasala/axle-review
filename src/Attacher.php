<?php
/**
 * Links guest notes to their block in post_content.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review;

defined( 'ABSPATH' ) || exit;

/**
 * Writes a guest note's ID into its block's metadata.noteId, which is how the
 * editor knows which block a note is on.
 *
 * An editor who has the post open would overwrite that on their next save, so
 * each guest note keeps a pending flag until a save arrives that already has
 * its ID. Until then every save of the post puts the ID back on the block.
 */
final class Attacher implements Service {

	public const PENDING_KEY = '_axr_pending_attach';

	/**
	 * The post being written by attach(), to skip the save filter and revisions.
	 *
	 * @var int
	 */
	private int $writing = 0;

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_filter( 'wp_insert_post_data', array( $this, 'keep_pending' ), 99, 2 );
		add_filter( 'wp_revisions_to_keep', array( $this, 'no_revision' ), 99, 2 );
	}

	/**
	 * Attaches a guest note to its block now.
	 *
	 * @param int $note_id Note ID.
	 * @return array{path: string, orphan: bool}|\WP_Error Where it went.
	 */
	public function attach( int $note_id ) {
		$note = Notes::get( $note_id );
		if ( ! $note ) {
			return new \WP_Error( 'axle_review_no_note', __( 'That note does not exist.', 'axle-review' ) );
		}

		$post_id = (int) $note->comment_post_ID;
		$lock    = 'axle_review_lock_' . $post_id;
		if ( ! add_option( $lock, time(), '', false ) ) {
			$since = (int) get_option( $lock );
			if ( $since > time() - 10 ) {
				return new \WP_Error( 'axle_review_locked', __( 'Another note is being attached to this post. It will be attached on the next save.', 'axle-review' ) );
			}
			update_option( $lock, time(), false );
		}

		try {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return new \WP_Error( 'axle_review_no_post', __( 'That post does not exist.', 'axle-review' ) );
			}

			$blocks = parse_blocks( (string) $post->post_content );
			$placed = $this->place( $blocks, $note );
			if ( is_wp_error( $placed ) ) {
				return $placed;
			}

			if ( null !== $placed['blocks'] ) {
				$this->write( $post_id, serialize_blocks( $placed['blocks'] ) );
			}

			return array(
				'path'   => $placed['path'],
				'orphan' => $placed['orphan'],
			);
		} finally {
			delete_option( $lock );
		}
	}

	/**
	 * Works out where a note goes in a block tree and adds it there.
	 *
	 * @param array       $blocks Parsed blocks.
	 * @param \WP_Comment $note   Note.
	 * @return array{blocks: array|null, path: string, orphan: bool}|\WP_Error Blocks null when already there.
	 */
	public function place( array $blocks, \WP_Comment $note ) {
		$note_id = (int) $note->comment_ID;
		$found   = Anchor::find_note( $blocks, $note_id );
		if ( null !== $found ) {
			return array(
				'blocks' => null,
				'path'   => $found,
				'orphan' => false,
			);
		}

		$shape    = Anchor::shape();
		$anchor   = Notes::anchor( $note_id );
		$resolved = Anchor::resolve( $blocks, $anchor, $shape );

		/**
		 * Filters the block a reviewer's note is attached to.
		 *
		 * @param array|null  $resolved Where it goes (path, orphan); null leaves it unattached.
		 * @param \WP_Comment                            $note     The note.
		 * @param array                                  $anchor   path, hash, blockName and quote the reviewer clicked.
		 * @param array                                  $blocks   Parsed blocks of the post.
		 */
		$resolved = apply_filters( 'axle_review_anchor', $resolved, $note, $anchor, $blocks );

		if ( ! is_array( $resolved ) || ! isset( $resolved['path'] ) || ! is_scalar( $resolved['path'] ) ) {
			return new \WP_Error( 'axle_review_no_block', __( 'No block can take this note. It stays in the Notes inbox.', 'axle-review' ) );
		}

		$path    = (string) $resolved['path'];
		$changed = Anchor::add_note_id( $blocks, $path, $note_id, $shape );
		if ( null === $changed ) {
			return new \WP_Error( 'axle_review_no_block', __( 'No block can take this note. It stays in the Notes inbox.', 'axle-review' ) );
		}

		if ( ! empty( $resolved['orphan'] ) ) {
			$this->mark_orphan( $note, $anchor );
		}

		return array(
			'blocks' => $changed,
			'path'   => $path,
			'orphan' => ! empty( $resolved['orphan'] ),
		);
	}

	/**
	 * The block a note was on is gone: prefix the note with what the reviewer
	 * quoted, so the context survives, and say so once.
	 *
	 * @param \WP_Comment $note   Note.
	 * @param array       $anchor Anchor.
	 */
	private function mark_orphan( \WP_Comment $note, array $anchor ): void {
		$note_id = (int) $note->comment_ID;
		if ( get_comment_meta( $note_id, '_axr_orphaned', true ) ) {
			return;
		}

		update_comment_meta( $note_id, '_axr_orphaned', 1 );

		$quote = (string) ( $anchor['quote'] ?? '' );
		if ( '' !== $quote && false === strpos( (string) $note->comment_content, $quote ) ) {
			wp_update_comment(
				wp_slash(
					array(
						'comment_ID'      => $note_id,
						/* translators: %s: text the reviewer quoted. */
						'comment_content' => sprintf( __( 'About "%s":', 'axle-review' ), $quote ) . "\n\n" . $note->comment_content,
					)
				)
			);
		}

		/**
		 * Fires when a reviewer's note could not go on the block they clicked,
		 * because that block was changed or removed, and went on the first block.
		 *
		 * @param \WP_Comment $note   The note.
		 * @param array       $anchor What the reviewer clicked.
		 */
		do_action( 'axle_review_guest_note_orphaned', $note, $anchor );
	}

	/**
	 * Saves new content without a revision, filters or a changed author.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $content Content.
	 */
	private function write( int $post_id, string $content ): void {
		$this->writing = $post_id;

		// A guest request has no user, so kses would strip what editors may write (embeds, Custom HTML).
		$kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses ) {
			kses_remove_filters();
		}

		wp_update_post(
			wp_slash(
				array(
					'ID'           => $post_id,
					'post_content' => $content,
				)
			)
		);

		if ( $kses ) {
			kses_init_filters();
		}

		$this->writing = 0;
	}

	/**
	 * No revision for the attach write.
	 *
	 * @param int   $keep Revisions to keep.
	 * @param mixed $post Post.
	 * @return int
	 */
	public function no_revision( $keep, $post ): int {
		if ( $this->writing && $post instanceof \WP_Post && (int) $post->ID === $this->writing ) {
			return 0;
		}

		return (int) $keep;
	}

	/**
	 * On every save of a post with pending guest notes, puts back the IDs a
	 * save from an open editor left out. A note whose ID arrives in the saved
	 * content is no longer pending: the editor has it.
	 *
	 * @param array $data    Slashed post data.
	 * @param array $postarr Raw post array.
	 * @return array
	 */
	public function keep_pending( $data, $postarr ): array {
		$data    = (array) $data;
		$post_id = (int) ( $postarr['ID'] ?? 0 );

		if ( ! $post_id || $this->writing === $post_id || ! isset( $data['post_content'] ) ) {
			return $data;
		}

		if ( in_array( (string) ( $data['post_type'] ?? '' ), array( 'revision', 'nav_menu_item' ), true ) ) {
			return $data;
		}

		$pending = $this->pending( $post_id );
		if ( ! $pending ) {
			return $data;
		}

		$blocks  = parse_blocks( wp_unslash( (string) $data['post_content'] ) );
		$changed = false;

		foreach ( $pending as $note ) {
			$note_id = (int) $note->comment_ID;
			if ( null !== Anchor::find_note( $blocks, $note_id ) ) {
				if ( $this->saved_has( $post_id, $note_id ) ) {
					delete_comment_meta( $note_id, self::PENDING_KEY );
				}
				continue;
			}

			$placed = $this->place( $blocks, $note );
			if ( ! is_wp_error( $placed ) && null !== $placed['blocks'] ) {
				$blocks  = $placed['blocks'];
				$changed = true;
			}
		}

		if ( $changed ) {
			$data['post_content'] = wp_slash( serialize_blocks( $blocks ) );
		}

		return $data;
	}

	/**
	 * Whether the content stored now (before this save) already had the note.
	 * When both have it, the save came from an editor that knows the note.
	 *
	 * @param int $post_id Post ID.
	 * @param int $note_id Note ID.
	 * @return bool
	 */
	private function saved_has( int $post_id, int $note_id ): bool {
		$post = get_post( $post_id );
		return $post && null !== Anchor::find_note( parse_blocks( (string) $post->post_content ), $note_id );
	}

	/**
	 * Guest notes on a post still waiting for an editor save that has them.
	 * Pending flags older than a day are dropped.
	 *
	 * @param int $post_id Post ID.
	 * @return \WP_Comment[]
	 */
	public function pending( int $post_id ): array {
		$notes = get_comments(
			array(
				'type'     => 'note',
				'post_id'  => $post_id,
				'parent'   => 0,
				'status'   => array( 'hold', 'approve' ),
				'number'   => 100,
				'meta_key' => self::PENDING_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Few notes per post.
			)
		);

		$fresh = array();
		foreach ( (array) $notes as $note ) {
			if ( ! $note instanceof \WP_Comment ) {
				continue;
			}
			$since = (int) get_comment_meta( (int) $note->comment_ID, self::PENDING_KEY, true );
			if ( $since && $since < time() - DAY_IN_SECONDS ) {
				delete_comment_meta( (int) $note->comment_ID, self::PENDING_KEY );
				continue;
			}
			$fresh[] = $note;
		}

		return $fresh;
	}

	/**
	 * Retries every pending note of a post, for WP-CLI.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array|\WP_Error> Note ID => result.
	 */
	public function reattach( int $post_id ): array {
		$results = array();
		foreach ( $this->pending( $post_id ) as $note ) {
			$results[ (int) $note->comment_ID ] = $this->attach( (int) $note->comment_ID );
		}

		return $results;
	}
}
