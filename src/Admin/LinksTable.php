<?php
/**
 * The review links table.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Admin;

use AryanX\Axle\Review\Events;
use AryanX\Axle\Review\Links;
use AryanX\Axle\Review\Settings;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Every link the user may manage, with bulk revoke.
 */
final class LinksTable extends \WP_List_Table {

	/**
	 * Links.
	 *
	 * @var Links
	 */
	private Links $links;

	/**
	 * Events.
	 *
	 * @var Events
	 */
	private Events $events;

	/**
	 * Filters.
	 *
	 * @var array
	 */
	private array $filters;

	/**
	 * Notes per link.
	 *
	 * @var array<int, int>
	 */
	private array $counts = array();

	/**
	 * Sets up the table.
	 *
	 * @param Links  $links   Links.
	 * @param Events $events  Events.
	 * @param array  $filters post_id, created_by, status, page.
	 */
	public function __construct( Links $links, Events $events, array $filters ) {
		parent::__construct(
			array(
				'singular' => 'link',
				'plural'   => 'links',
				'ajax'     => false,
				'screen'   => 'tools_page_axle-review-links',
			)
		);
		$this->links   = $links;
		$this->events  = $events;
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
			'label'   => __( 'Link', 'axle-review' ),
			'post'    => __( 'Post', 'axle-review' ),
			'creator' => __( 'Created by', 'axle-review' ),
			'access'  => __( 'Access', 'axle-review' ),
			'views'   => __( 'Views', 'axle-review' ),
			'notes'   => __( 'Notes', 'axle-review' ),
			'expires' => __( 'Expires', 'axle-review' ),
			'status'  => __( 'Status', 'axle-review' ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions(): array {
		return array( 'revoke' => __( 'Revoke', 'axle-review' ) );
	}

	/**
	 * Loads the rows.
	 */
	public function prepare_items(): void {
		$per_page = 20;
		$page     = max( 1, (int) ( $this->filters['page'] ?? 1 ) );
		$found    = $this->links->query(
			array(
				'post_id'    => (int) ( $this->filters['post_id'] ?? 0 ),
				'created_by' => (int) ( $this->filters['created_by'] ?? 0 ),
				'status'     => (string) ( $this->filters['status'] ?? '' ),
				'limit'      => $per_page,
				'offset'     => ( $page - 1 ) * $per_page,
			)
		);

		$this->items  = array_values( array_filter( $found['items'], static fn( $link ) => Settings::can_share( (int) $link['post_id'] ) ) );
		$this->counts = $this->events->note_counts( array_column( $this->items, 'id' ) );

		$this->_column_headers = array( $this->get_columns(), array(), array(), 'label' );
		$this->set_pagination_args(
			array(
				'total_items' => $found['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Message when there are no links.
	 */
	public function no_items(): void {
		esc_html_e( 'No review links yet. Create one from the "Share for review" panel in the editor, or with "Share for review" under a draft in the posts list.', 'axle-review' );
	}

	/**
	 * Checkbox.
	 *
	 * @param array $item Link.
	 * @return string
	 */
	protected function column_cb( $item ): string {
		return sprintf(
			'<label class="screen-reader-text" for="axr-link-%1$d">%2$s</label><input type="checkbox" id="axr-link-%1$d" name="links[]" value="%1$d">',
			(int) $item['id'],
			/* translators: %d: link ID. */
			esc_html( sprintf( __( 'Select link %d', 'axle-review' ), (int) $item['id'] ) )
		);
	}

	/**
	 * Label and row actions.
	 *
	 * @param array $item Link.
	 * @return string
	 */
	protected function column_label( $item ): string {
		$label   = '' === $item['label'] ? sprintf( /* translators: %d: link ID. */ __( 'Link %d', 'axle-review' ), (int) $item['id'] ) : $item['label'];
		$actions = array(
			'activity' => sprintf( '<a href="%1$s">%2$s</a>', esc_url( LinksPage::url( array( 'activity' => (int) $item['id'] ) ) ), esc_html__( 'Activity', 'axle-review' ) ),
		);
		if ( 'revoked' !== $item['status'] ) {
			$actions['revoke'] = sprintf(
				'<a href="%1$s" class="axr-danger">%2$s</a>',
				esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'action' => 'axle_review_revoke',
								'link'   => (int) $item['id'],
							),
							admin_url( 'admin-post.php' ) 
						),
						'axle_review_revoke_' . (int) $item['id'] 
					) 
				),
				esc_html__( 'Revoke', 'axle-review' )
			);
		}

		return '<strong>' . esc_html( $label ) . '</strong>' . $this->row_actions( $actions );
	}

	/**
	 * Post.
	 *
	 * @param array $item Link.
	 * @return string
	 */
	protected function column_post( $item ): string {
		$post = get_post( (int) $item['post_id'] );
		if ( ! $post ) {
			return '';
		}

		return sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( LinksPage::url( array( 'post_id' => (int) $post->ID ) ) ),
			esc_html( '' === get_the_title( $post ) ? __( '(no title)', 'axle-review' ) : get_the_title( $post ) )
		);
	}

	/**
	 * Creator.
	 *
	 * @param array $item Link.
	 * @return string
	 */
	protected function column_creator( $item ): string {
		$user = get_userdata( (int) $item['created_by'] );
		return esc_html( $user ? $user->display_name : __( 'Deleted user', 'axle-review' ) );
	}

	/**
	 * Locks on the link.
	 *
	 * @param array $item Link.
	 * @return string
	 */
	protected function column_access( $item ): string {
		$parts = array();
		if ( '' !== $item['email'] ) {
			/* translators: %s: email address. */
			$parts[] = sprintf( __( 'Email code to %s', 'axle-review' ), $item['email'] );
		}
		if ( $item['has_password'] ) {
			$parts[] = __( 'Password', 'axle-review' );
		}
		if ( ! $item['allow_notes'] ) {
			$parts[] = __( 'Read only', 'axle-review' );
		}

		return esc_html( $parts ? implode( ', ', $parts ) : __( 'Anyone with the link', 'axle-review' ) );
	}

	/**
	 * Views used of the limit.
	 *
	 * @param array $item Link.
	 * @return string
	 */
	protected function column_views( $item ): string {
		/* translators: 1: views, 2: view limit. */
		return esc_html( $item['max_views'] ? sprintf( __( '%1$d of %2$d', 'axle-review' ), $item['views'], $item['max_views'] ) : (string) $item['views'] );
	}

	/**
	 * Notes left.
	 *
	 * @param array $item Link.
	 * @return string
	 */
	protected function column_notes( $item ): string {
		return (string) ( $this->counts[ (int) $item['id'] ] ?? 0 );
	}

	/**
	 * Expiry.
	 *
	 * @param array $item Link.
	 * @return string
	 */
	protected function column_expires( $item ): string {
		$time = (int) strtotime( (string) $item['expires_at'] . ' UTC' );
		return esc_html( wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $time ) );
	}

	/**
	 * Status.
	 *
	 * @param array $item Link.
	 * @return string
	 */
	protected function column_status( $item ): string {
		return sprintf( '<span class="axr-status axr-status--%1$s">%2$s</span>', esc_attr( $item['status'] ), esc_html( LinksPage::status_label( (string) $item['status'] ) ) );
	}
}
