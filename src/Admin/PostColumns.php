<?php
/**
 * The Notes column and the "Share for review" row action on post lists.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Admin;

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
	 * Sets up the column.
	 *
	 * @param Notes $notes Notes.
	 */
	public function __construct( Notes $notes ) {
		$this->notes = $notes;
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
				$out['axr_notes'] = __( 'Notes', 'axle-review' );
			}
			$out[ $key ] = $label;
		}
		if ( ! isset( $out['axr_notes'] ) ) {
			$out['axr_notes'] = __( 'Notes', 'axle-review' );
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
		if ( 'axr_notes' !== $column ) {
			return;
		}

		$count = $this->notes->unresolved_count( (int) $post_id );
		if ( ! $count ) {
			echo '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">' . esc_html__( 'No open notes', 'axle-review' ) . '</span>';
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
