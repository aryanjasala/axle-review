<?php
/**
 * Dashboard > Notes.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Admin;

use AryanX\Axle\Review\Notes;
use AryanX\Axle\Review\Service;
use AryanX\Axle\Review\Settings;
use const AryanX\Axle\Review\PLUGIN_FILE;
use const AryanX\Axle\Review\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Every note on the site in one place: open notes first, notes that mention
 * you (7.1+), notes on your posts and notes from reviewers. Reply, resolve
 * and reopen without opening each post.
 */
final class Inbox implements Service {

	public const SLUG     = 'axle-review-notes';
	public const SEEN_KEY = 'axle_review_inbox_seen';

	/**
	 * Notes.
	 *
	 * @var Notes
	 */
	private Notes $notes;

	/**
	 * Threads that need a reply form after the table.
	 *
	 * @var int[]
	 */
	private static array $reply_forms = array();

	/**
	 * Sets up the inbox.
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
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_axle_review_note', array( $this, 'handle_action' ) );
		add_action( 'admin_post_axle_review_reply', array( $this, 'handle_reply' ) );
	}

	/**
	 * Adds Dashboard > Notes.
	 */
	public function menu(): void {
		$hook = add_dashboard_page(
			__( 'Notes', 'axle-review' ),
			__( 'Notes', 'axle-review' ),
			Settings::inbox_capability(),
			self::SLUG,
			array( $this, 'render' )
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'load' ) );
		}
	}

	/**
	 * Rows per page.
	 *
	 * @return int
	 */
	public static function per_page(): int {
		/**
		 * Filters how many notes the inbox shows per page.
		 *
		 * @param int $per_page Default 20, between 1 and 100.
		 */
		return max( 1, min( 100, (int) apply_filters( 'axle_review_inbox_per_page', 20 ) ) );
	}

	/**
	 * The inbox views, key => label.
	 *
	 * @return array<string, string>
	 */
	public static function views(): array {
		$views = array(
			'unresolved' => __( 'Unresolved', 'axle-review' ),
			'mentions'   => __( 'Mentions me', 'axle-review' ),
			'mine'       => __( 'On my posts', 'axle-review' ),
			'guests'     => __( 'From reviewers', 'axle-review' ),
			'resolved'   => __( 'Resolved', 'axle-review' ),
			'all'        => __( 'All', 'axle-review' ),
		);

		if ( ! Notes::has_mentions() ) {
			unset( $views['mentions'] );
		}

		/**
		 * Filters the inbox views. Keys other than the built-in ones are
		 * ignored; remove a key to hide that view.
		 *
		 * @param array $views Key => label.
		 */
		$filtered = array_intersect_key( (array) apply_filters( 'axle_review_inbox_views', $views ), $views );

		return $filtered ? array_map( 'strval', $filtered ) : array( 'unresolved' => $views['unresolved'] );
	}

	/**
	 * The filters of the current request.
	 *
	 * @return array
	 */
	private function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$views = self::views();
		$view  = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		$view  = isset( $views[ $view ] ) ? $view : (string) array_key_first( $views );
		$range = isset( $_GET['since'] ) ? sanitize_key( wp_unslash( $_GET['since'] ) ) : '';
		$args  = array(
			'view'      => $view,
			'status'    => in_array( $view, array( 'resolved', 'all' ), true ) ? $view : ( in_array( $view, array( 'mentions', 'mine', 'guests' ), true ) ? 'all' : 'unresolved' ),
			'post_id'   => isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0,
			'post_type' => isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '',
			'author'    => isset( $_GET['author'] ) ? absint( $_GET['author'] ) : 0,
			'page'      => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
			'range'     => $range,
		);
		// phpcs:enable

		$days = array(
			'day'   => 1,
			'week'  => 7,
			'month' => 30,
		);
		if ( isset( $days[ $range ] ) ) {
			$args['since'] = time() - $days[ $range ] * DAY_IN_SECONDS;
		}
		if ( 'mentions' === $view ) {
			$args['mentions'] = get_current_user_id();
		}
		if ( 'mine' === $view ) {
			$args['on_posts_by'] = get_current_user_id();
		}
		if ( 'guests' === $view ) {
			$args['guests'] = true;
		}
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$args['restrict_author'] = get_current_user_id();
		}

		return $args;
	}

	/**
	 * Bulk actions, assets and the seen time.
	 */
	public function load(): void {
		$table  = new NotesTable( $this->notes, $this->filters() );
		$action = $table->current_action();
		if ( in_array( $action, array( 'resolve', 'reopen' ), true ) ) {
			check_admin_referer( 'bulk-notes' );
			$ids  = isset( $_REQUEST['notes'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['notes'] ) ) : array();
			$done = 0;
			foreach ( $ids as $id ) {
				$note = Notes::get( $id );
				if ( $note && current_user_can( 'edit_post', (int) $note->comment_post_ID ) && true === $this->notes->set_status( $id, 'resolve' === $action ? 'resolved' : 'open', get_current_user_id() ) ) {
					++$done;
				}
			}
			wp_safe_redirect(
				add_query_arg(
					array(
						'axr_done'         => $done,
						'axr_did'          => $action,
						'action'           => false,
						'action2'          => false,
						'notes'            => false,
						'_wpnonce'         => false,
						'_wp_http_referer' => false,
					),
					wp_get_referer() ? wp_get_referer() : self::url()
				)
			);
			exit;
		}

		update_user_meta( get_current_user_id(), self::SEEN_KEY, time() );

		wp_enqueue_style( 'axle-review-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array(), VERSION );
		wp_enqueue_script( 'axle-review-inbox', plugins_url( 'assets/inbox.js', PLUGIN_FILE ), array( 'wp-hooks' ), VERSION, true );
	}

	/**
	 * The inbox URL.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'index.php?page=' . self::SLUG ) );
	}

	/**
	 * The editor URL that opens a note.
	 *
	 * @param \WP_Comment $note Note.
	 * @return string
	 */
	public static function editor_url( \WP_Comment $note ): string {
		$thread = (int) $note->comment_parent ? (int) $note->comment_parent : (int) $note->comment_ID;
		return add_query_arg(
			array(
				'post'     => (int) $note->comment_post_ID,
				'action'   => 'edit',
				'axr_note' => $thread,
			),
			admin_url( 'post.php' )
		);
	}

	/**
	 * A resolve or reopen URL with its nonce.
	 *
	 * @param string $op      resolve or reopen.
	 * @param int    $note_id Note ID.
	 * @return string
	 */
	public static function action_url( string $op, int $note_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'axle_review_note',
					'op'     => $op,
					'note'   => $note_id,
				),
				admin_url( 'admin-post.php' )
			),
			'axle_review_note_' . $note_id
		);
	}

	/**
	 * Remembers that a thread needs a reply form.
	 *
	 * @param int $thread Thread note ID.
	 */
	public static function queue_reply_form( int $thread ): void {
		self::$reply_forms[ $thread ] = $thread;
	}

	/**
	 * Resolve or reopen one note.
	 */
	public function handle_action(): void {
		$id = isset( $_GET['note'] ) ? absint( $_GET['note'] ) : 0;
		check_admin_referer( 'axle_review_note_' . $id );

		$note = Notes::get( $id );
		if ( ! $note || ! current_user_can( 'edit_post', (int) $note->comment_post_ID ) ) {
			wp_die( esc_html__( 'You cannot change this note.', 'axle-review' ), 403 );
		}

		$op = isset( $_GET['op'] ) ? sanitize_key( wp_unslash( $_GET['op'] ) ) : '';
		$this->notes->set_status( $id, 'resolve' === $op ? 'resolved' : 'open', get_current_user_id() );

		wp_safe_redirect(
			add_query_arg(
				array(
					'axr_done' => 1,
					'axr_did'  => 'resolve' === $op ? 'resolve' : 'reopen',
				),
				wp_get_referer() ? wp_get_referer() : self::url()
			)
		);
		exit;
	}

	/**
	 * Reply to a thread.
	 */
	public function handle_reply(): void {
		$id = isset( $_POST['note'] ) ? absint( $_POST['note'] ) : 0;
		check_admin_referer( 'axle_review_reply_' . $id );

		$note = Notes::get( $id );
		if ( ! $note || ! current_user_can( 'edit_post', (int) $note->comment_post_ID ) ) {
			wp_die( esc_html__( 'You cannot reply to this note.', 'axle-review' ), 403 );
		}

		$text = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : '';
		$done = 0;
		if ( '' !== trim( $text ) ) {
			$result = $this->notes->add_user_note( (int) $note->comment_post_ID, get_current_user_id(), nl2br( esc_html( $text ), false ), $id );
			$done   = is_wp_error( $result ) ? 0 : 1;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'axr_done' => $done,
					'axr_did'  => 'reply',
				),
				wp_get_referer() ? wp_get_referer() : self::url()
			)
		);
		exit;
	}

	/**
	 * Prints the inbox.
	 */
	public function render(): void {
		$filters = $this->filters();
		$table   = new NotesTable( $this->notes, $filters );
		$table->prepare_items();

		echo '<div class="wrap axr-inbox">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Notes', 'axle-review' ) . '</h1>';
		echo '<hr class="wp-header-end">';

		$this->notice();
		$this->print_views( $filters );

		echo '<form method="get" class="axr-inbox-form">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<input type="hidden" name="view" value="' . esc_attr( (string) $filters['view'] ) . '">';
		$this->filter_bar( $filters );
		$table->display();
		echo '</form>';

		foreach ( self::$reply_forms as $thread ) {
			printf(
				'<form id="axr-reply-form-%1$d" method="post" action="%2$s" class="axr-hidden-form"><input type="hidden" name="action" value="axle_review_reply"><input type="hidden" name="note" value="%1$d">%3$s</form>',
				(int) $thread,
				esc_url( admin_url( 'admin-post.php' ) ),
				wp_nonce_field( 'axle_review_reply_' . $thread, '_wpnonce', true, false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core markup.
			);
		}

		echo '</div>';
	}

	/**
	 * Result notice after an action.
	 */
	private function notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only.
		if ( ! isset( $_GET['axr_done'], $_GET['axr_did'] ) ) {
			return;
		}

		$count = absint( $_GET['axr_done'] );
		$did   = sanitize_key( wp_unslash( $_GET['axr_did'] ) );
		// phpcs:enable

		if ( 'reply' === $did ) {
			$message = $count ? __( 'Reply added.', 'axle-review' ) : __( 'The reply was empty, so nothing was added.', 'axle-review' );
		} elseif ( 'resolve' === $did ) {
			/* translators: %d: number of notes. */
			$message = sprintf( _n( '%d note resolved.', '%d notes resolved.', $count, 'axle-review' ), $count );
		} else {
			/* translators: %d: number of notes. */
			$message = sprintf( _n( '%d note reopened.', '%d notes reopened.', $count, 'axle-review' ), $count );
		}

		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}

	/**
	 * The view links with counts.
	 *
	 * @param array $filters Filters.
	 */
	private function print_views( array $filters ): void {
		$links = array();
		$views = self::views();
		$last  = array_key_last( $views );
		foreach ( $views as $key => $label ) {
			$url     = self::url( array_filter( array( 'view' => $key ) ) );
			$current = $key === $filters['view'];
			$count   = '';
			if ( 'unresolved' === $key ) {
				$args = $filters;
				unset( $args['mentions'], $args['on_posts_by'], $args['guests'], $args['since'] );
				$query = Notes::query_args( array_merge( $args, array( 'status' => 'unresolved' ) ) );
				$count = ' <span class="count">(' . number_format_i18n( $this->notes->cached_count( array_merge( $query, array( 'count' => true ) ) ) ) . ')</span>';
			}
			$links[] = sprintf(
				'<li class="%1$s"><a href="%2$s"%3$s>%4$s%5$s</a>%6$s</li>',
				esc_attr( $key ),
				esc_url( $url ),
				$current ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				wp_kses( $count, array( 'span' => array( 'class' => true ) ) ),
				$key === $last ? '' : ' |'
			);
		}

		echo '<ul class="subsubsub">' . implode( '', $links ) . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
	}

	/**
	 * Post type, post, author and date filters.
	 *
	 * @param array $filters Filters.
	 */
	private function filter_bar( array $filters ): void {
		echo '<div class="axr-filters">';

		echo '<label for="axr-filter-type" class="screen-reader-text">' . esc_html__( 'Post type', 'axle-review' ) . '</label>';
		echo '<select id="axr-filter-type" name="post_type"><option value="">' . esc_html__( 'All post types', 'axle-review' ) . '</option>';
		foreach ( Settings::post_types() as $type ) {
			$object = get_post_type_object( $type );
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $type ), selected( $filters['post_type'], $type, false ), esc_html( $object ? (string) $object->labels->name : $type ) );
		}
		echo '</select>';

		echo '<label for="axr-filter-author" class="screen-reader-text">' . esc_html__( 'Note author', 'axle-review' ) . '</label>';
		wp_dropdown_users(
			array(
				'name'             => 'author',
				'id'               => 'axr-filter-author',
				'capability'       => 'edit_posts',
				'show_option_all'  => __( 'All authors', 'axle-review' ),
				'selected'         => (int) $filters['author'],
				'include_selected' => true,
			)
		);

		echo '<label for="axr-filter-since" class="screen-reader-text">' . esc_html__( 'Date', 'axle-review' ) . '</label>';
		echo '<select id="axr-filter-since" name="since">';
		foreach ( array(
			''      => __( 'Any time', 'axle-review' ),
			'day'   => __( 'Last 24 hours', 'axle-review' ),
			'week'  => __( 'Last 7 days', 'axle-review' ),
			'month' => __( 'Last 30 days', 'axle-review' ),
		) as $value => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $filters['range'], $value, false ), esc_html( $label ) );
		}
		echo '</select>';

		if ( $filters['post_id'] ) {
			printf( '<input type="hidden" name="post_id" value="%d">', (int) $filters['post_id'] );
		}

		submit_button( __( 'Filter', 'axle-review' ), '', 'axr-filter', false );

		if ( $filters['post_id'] ) {
			printf(
				' <span class="axr-filter-post">%1$s <a href="%2$s">%3$s</a></span>',
				/* translators: %s: post title. */
				esc_html( sprintf( __( 'Showing notes on "%s".', 'axle-review' ), get_the_title( (int) $filters['post_id'] ) ) ),
				esc_url( self::url( array( 'view' => $filters['view'] ) ) ),
				esc_html__( 'Show all posts', 'axle-review' )
			);
		}

		echo '</div>';
	}
}
