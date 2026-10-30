<?php
/**
 * The Notes inbox table.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Admin;

use AryanX\Axle\Review\Anchor;
use AryanX\Axle\Review\Notes;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * One row per note, with reply, resolve and reopen.
 */
final class NotesTable extends \WP_List_Table {

	/**
	 * Notes.
	 *
	 * @var Notes
	 */
	private Notes $notes;

	/**
	 * Filters from the request.
	 *
	 * @var array
	 */
	private array $filters;

	/**
	 * Block paths per post, so each post is parsed once.
	 *
	 * @var array<int, array>
	 */
	private array $blocks = array();

	/**
	 * Sets up the table.
	 *
	 * @param Notes $notes   Notes.
	 * @param array $filters Filters (see Notes::query()) plus view.
	 */
	public function __construct( Notes $notes, array $filters ) {
		parent::__construct(
			array(
				'singular' => 'note',
				'plural'   => 'notes',
				'ajax'     => false,
				'screen'   => 'dashboard_page_axle-review-notes',
			)
		);
		$this->notes   = $notes;
		$this->filters = $filters;
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns(): array {
		return array(
			'cb'      => '<input type="checkbox">',
			'note'    => __( 'Note', 'axle-review' ),
			'author'  => __( 'Author', 'axle-review' ),
			'post'    => __( 'Post', 'axle-review' ),
			'block'   => __( 'Block', 'axle-review' ),
			'age'     => __( 'Age', 'axle-review' ),
			'replies' => __( 'Replies', 'axle-review' ),
			'status'  => __( 'Status', 'axle-review' ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions(): array {
		return array(
			'resolve' => __( 'Resolve', 'axle-review' ),
			'reopen'  => __( 'Reopen', 'axle-review' ),
		);
	}

	/**
	 * Loads the rows.
	 */
	public function prepare_items(): void {
		$per_page = Inbox::per_page();
		$args     = $this->filters;
		$found    = $this->notes->query( array_merge( $args, array( 'per_page' => $per_page ) ) );

		$this->items = array_values(
			array_filter(
				$found['items'],
				static fn( $note ) => current_user_can( 'edit_post', (int) $note->comment_post_ID )
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), array(), 'note' );
		$this->set_pagination_args(
			array(
				'total_items' => $found['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Message when there are no notes.
	 */
	public function no_items(): void {
		if ( 'unresolved' === ( $this->filters['view'] ?? '' ) ) {
			esc_html_e( 'No open notes. Everything is resolved.', 'axle-review' );
			return;
		}

		esc_html_e( 'No notes match.', 'axle-review' );
	}

	/**
	 * Checkbox column.
	 *
	 * @param \WP_Comment $item Note.
	 * @return string
	 */
	protected function column_cb( $item ): string {
		return sprintf(
			'<label class="screen-reader-text" for="axr-note-%1$d">%2$s</label><input type="checkbox" id="axr-note-%1$d" name="notes[]" value="%1$d">',
			(int) $item->comment_ID,
			/* translators: %d: note ID. */
			esc_html( sprintf( __( 'Select note %d', 'axle-review' ), (int) $item->comment_ID ) )
		);
	}

	/**
	 * The note text, row actions and the inline reply.
	 *
	 * @param \WP_Comment $item Note.
	 * @return string
	 */
	protected function column_note( $item ): string {
		$id      = (int) $item->comment_ID;
		$text    = wp_trim_words( html_entity_decode( wp_strip_all_tags( (string) $item->comment_content ), ENT_QUOTES, 'UTF-8' ), 40 );
		$resolve = '1' !== (string) $item->comment_approved && 0 === (int) $item->comment_parent;
		$reopen  = '1' === (string) $item->comment_approved && 0 === (int) $item->comment_parent;
		$actions = array(
			'edit' => sprintf( '<a href="%1$s">%2$s</a>', esc_url( Inbox::editor_url( $item ) ), esc_html__( 'Open in editor', 'axle-review' ) ),
		);
		if ( $resolve ) {
			$actions['resolve'] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( Inbox::action_url( 'resolve', $id ) ), esc_html__( 'Resolve', 'axle-review' ) );
		}
		if ( $reopen ) {
			$actions['reopen'] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( Inbox::action_url( 'reopen', $id ) ), esc_html__( 'Reopen', 'axle-review' ) );
		}

		$thread = (int) $item->comment_parent ? (int) $item->comment_parent : $id;
		$reply  = sprintf(
			'<details class="axr-reply"><summary>%1$s</summary><label for="axr-reply-text-%2$d" class="screen-reader-text">%3$s</label><textarea id="axr-reply-text-%2$d" name="text" rows="3" form="axr-reply-form-%2$d" required></textarea><button type="submit" class="button button-small" form="axr-reply-form-%2$d">%4$s</button></details>',
			esc_html__( 'Reply', 'axle-review' ),
			$thread,
			esc_html__( 'Your reply', 'axle-review' ),
			esc_html__( 'Send reply', 'axle-review' )
		);
		Inbox::queue_reply_form( $thread );

		return sprintf( '<p class="axr-note-text">%s</p>', esc_html( $text ) ) . $this->row_actions( $actions ) . $reply;
	}

	/**
	 * Author: user name, or "Guest: name".
	 *
	 * @param \WP_Comment $item Note.
	 * @return string
	 */
	protected function column_author( $item ): string {
		if ( Notes::is_guest( $item ) ) {
			/* translators: %s: reviewer name. */
			return esc_html( sprintf( __( 'Guest: %s', 'axle-review' ), $item->comment_author ) );
		}

		return esc_html( (string) $item->comment_author );
	}

	/**
	 * Post title with the editor link.
	 *
	 * @param \WP_Comment $item Note.
	 * @return string
	 */
	protected function column_post( $item ): string {
		$post = get_post( (int) $item->comment_post_ID );
		if ( ! $post ) {
			return '';
		}

		$title = get_the_title( $post );
		$title = '' === $title ? __( '(no title)', 'axle-review' ) : $title;
		$type  = get_post_type_object( $post->post_type );

		return sprintf(
			'<a href="%1$s">%2$s</a><br><span class="axr-muted">%3$s, %4$s</span>',
			esc_url( Inbox::editor_url( $item ) ),
			esc_html( $title ),
			esc_html( $type ? (string) $type->labels->singular_name : $post->post_type ),
			esc_html( (string) get_post_status_object( $post->post_status )->label )
		);
	}

	/**
	 * Block type the note is on.
	 *
	 * @param \WP_Comment $item Note.
	 * @return string
	 */
	protected function column_block( $item ): string {
		$post_id = (int) $item->comment_post_ID;
		if ( ! isset( $this->blocks[ $post_id ] ) ) {
			$post                     = get_post( $post_id );
			$this->blocks[ $post_id ] = $post ? parse_blocks( (string) $post->post_content ) : array();
		}

		$thread = (int) $item->comment_parent ? (int) $item->comment_parent : (int) $item->comment_ID;
		$path   = Anchor::find_note( $this->blocks[ $post_id ], $thread );
		if ( null === $path ) {
			return '<span class="axr-muted">' . esc_html__( 'Not on a block', 'axle-review' ) . '</span>';
		}

		$block = Anchor::at( $this->blocks[ $post_id ], $path );
		$name  = $block ? (string) $block['blockName'] : '';
		$type  = \WP_Block_Type_Registry::get_instance()->get_registered( $name );

		return esc_html( $type && $type->title ? $type->title : $name );
	}

	/**
	 * How long ago.
	 *
	 * @param \WP_Comment $item Note.
	 * @return string
	 */
	protected function column_age( $item ): string {
		$time = (int) strtotime( (string) $item->comment_date_gmt . ' UTC' );
		return sprintf(
			'<time datetime="%1$s">%2$s</time>',
			esc_attr( gmdate( 'c', $time ) ),
			/* translators: %s: time span, such as "2 hours". */
			esc_html( sprintf( __( '%s ago', 'axle-review' ), human_time_diff( $time ) ) )
		);
	}

	/**
	 * Reply count.
	 *
	 * @param \WP_Comment $item Note.
	 * @return string
	 */
	protected function column_replies( $item ): string {
		return (int) $item->comment_parent ? '' : (string) $this->notes->reply_count( (int) $item->comment_ID );
	}

	/**
	 * Status.
	 *
	 * @param \WP_Comment $item Note.
	 * @return string
	 */
	protected function column_status( $item ): string {
		$resolved = '1' === (string) $item->comment_approved;
		return sprintf(
			'<span class="axr-status axr-status--%1$s">%2$s</span>',
			$resolved ? 'resolved' : 'open',
			$resolved ? esc_html__( 'Resolved', 'axle-review' ) : esc_html__( 'Open', 'axle-review' )
		);
	}
}
