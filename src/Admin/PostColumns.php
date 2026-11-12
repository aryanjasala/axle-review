<?php
/**
 * The Notes column and the "Share for review" row action on post lists.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Admin;

use AryanX\Axle\Review\Approvals;
use AryanX\Axle\Review\Links;
use AryanX\Axle\Review\Notes;
use AryanX\Axle\Review\Service;
use AryanX\Axle\Review\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Open notes per post, linking to the inbox filtered by that post. The count
 * is cached until a note changes.
 */
final class PostColumns implements Service {

	/**
	 * Notes.
	 *
	 * @var Notes
	 */
	private Notes $notes;

	/**
	 * Links.
	 *
	 * @var Links
	 */
	private Links $links;

	/**
	 * Review state of the posts on this page, loaded once.
	 *
	 * @var array<int, array>|null
	 */
	private ?array $summaries = null;

	/**
	 * Sets up the column.
	 *
	 * @param Notes $notes Notes.
	 * @param Links $links Links.
	 */
	public function __construct( Notes $notes, Links $links ) {
		$this->notes = $notes;
		$this->links = $links;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'columns' ) );
		add_filter( 'post_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'row_action' ), 10, 2 );
	}

	/**
	 * Hooks the column on each supported post type.
	 */
	public function columns(): void {
		foreach ( Settings::post_types() as $type ) {
			add_filter( "manage_{$type}_posts_columns", array( $this, 'add_column' ) );
			add_action( "manage_{$type}_posts_custom_column", array( $this, 'column' ), 10, 2 );
		}
	}

	/**
	 * Adds the Notes column before the date.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function add_column( $columns ): array {
		$columns = (array) $columns;
		$out     = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$out['axr_notes']  = __( 'Notes', 'axle-review' );
				$out['axr_review'] = __( 'Review', 'axle-review' );
			}
			$out[ $key ] = $label;
		}
		if ( ! isset( $out['axr_notes'] ) ) {
			$out['axr_notes']  = __( 'Notes', 'axle-review' );
			$out['axr_review'] = __( 'Review', 'axle-review' );
		}

		return $out;
	}

	/**
	 * Prints the open note count.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post ID.
	 */
	public function column( $column, $post_id ): void {
		if ( 'axr_review' === $column ) {
			$this->review_column( (int) $post_id );
			return;
		}

		if ( 'axr_notes' !== $column ) {
			return;
		}

		$count = $this->notes->unresolved_count( (int) $post_id );
		if ( ! $count ) {
			/* translators: %d: number of open notes. */
			echo esc_html( sprintf( _n( '%d open', '%d open', 0, 'axle-review' ), 0 ) );
			return;
		}

		printf(
			'<a href="%1$s" class="axr-count">%2$s</a>',
			esc_url( Inbox::url( array( 'post_id' => (int) $post_id ) ) ),
			/* translators: %d: number of open notes. */
			esc_html( sprintf( _n( '%d open', '%d open', $count, 'axle-review' ), $count ) )
		);
	}

	/**
	 * Prints the review state: who approved, who asked for changes. One query
	 * covers every post on the page, and no post content is read.
	 *
	 * @param int $post_id Post ID.
	 */
	private function review_column( int $post_id ): void {
		if ( null === $this->summaries ) {
			$posts           = isset( $GLOBALS['wp_query']->posts ) && is_array( $GLOBALS['wp_query']->posts ) ? $GLOBALS['wp_query']->posts : array();
			$this->summaries = $this->links->decision_summaries( array_map( static fn( $post ) => (int) ( $post->ID ?? 0 ), $posts ) );
		}

		$counts = $this->summaries[ $post_id ] ?? null;
		if ( ! $counts ) {
			echo '<span class="axle-muted">' . esc_html__( 'Not shared', 'axle-review' ) . '</span>';
			return;
		}

		$text = Approvals::summary_text( $counts, $counts['names'] );
		$tone = 'info';
		if ( $counts['changes'] > 0 || $counts['outdated'] > 0 ) {
			$tone = 'warning';
		} elseif ( $counts['approved'] > 0 && 0 === $counts['waiting'] ) {
			$tone = 'good';
		}

		printf(
			'<a href="%1$s" class="axle-badge axle-badge--%2$s">%3$s</a>',
			esc_url( LinksPage::url( array( 'post_id' => $post_id ) ) ),
			esc_attr( $tone ),
			esc_html( $text )
		);
	}

	/**
	 * Adds "Share for review" under drafts.
	 *
	 * @param array $actions Row actions.
	 * @param mixed $post    Post.
	 * @return array
	 */
	public function row_action( $actions, $post ): array {
		$actions = (array) $actions;
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, Settings::post_types(), true ) || in_array( $post->post_status, Links::dead_statuses(), true ) || ! Settings::can_share( (int) $post->ID ) ) {
			return $actions;
		}

		$actions['axr_share'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( LinksPage::url( array( 'post_id' => (int) $post->ID ) ) . '#axr-new' ),
			esc_html__( 'Share for review', 'axle-review' )
		);

		return $actions;
	}
}
