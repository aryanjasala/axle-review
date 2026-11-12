<?php
/**
 * Tools > Review Links.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Admin;

use AryanX\Axle\Review\Approvals;
use AryanX\Axle\Review\Events;
use AryanX\Axle\Review\Links;
use AryanX\Axle\Review\Rest\EditorRoutes;
use AryanX\Axle\Review\Service;
use AryanX\Axle\Review\Settings;
use const AryanX\Axle\Review\PLUGIN_FILE;
use const AryanX\Axle\Review\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Every review link on the site: filter by post or creator, revoke in bulk,
 * pause all of them at once, see a link's activity, and create a link for a
 * post without the block editor.
 */
final class LinksPage implements Service {

	public const SLUG = 'axle-review-links';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

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
	 * Approvals.
	 *
	 * @var Approvals
	 */
	private Approvals $approvals;

	/**
	 * Sets up the page.
	 *
	 * @param Settings  $settings  Settings.
	 * @param Links     $links     Links.
	 * @param Events    $events    Events.
	 * @param Approvals $approvals Approvals.
	 */
	public function __construct( Settings $settings, Links $links, Events $events, Approvals $approvals ) {
		$this->settings  = $settings;
		$this->links     = $links;
		$this->events    = $events;
		$this->approvals = $approvals;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_axle_review_revoke', array( $this, 'handle_revoke' ) );
		add_action( 'admin_post_axle_review_pause', array( $this, 'handle_pause' ) );
		add_action( 'admin_post_axle_review_create_link', array( $this, 'handle_create' ) );
		add_action( 'admin_post_axle_review_ask_again', array( $this, 'handle_ask_again' ) );
		add_action( 'admin_post_axle_review_dismiss_steps', array( $this, 'handle_dismiss_steps' ) );
		add_action( 'admin_notices', array( $this, 'paused_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'plugin_links' ) );
	}

	/**
	 * Adds "Review Links" to the plugin's row on the Plugins screen.
	 *
	 * @param mixed $links Action links.
	 * @return array
	 */
	public function plugin_links( $links ): array {
		$links = (array) $links;
		if ( current_user_can( Settings::inbox_capability() ) ) {
			array_unshift( $links, sprintf( '<a href="%1$s">%2$s</a>', esc_url( self::url() ), esc_html__( 'Review Links', 'axle-review' ) ) );
		}

		return $links;
	}

	/**
	 * Adds Tools > Review Links.
	 */
	public function menu(): void {
		$hook = add_management_page(
			__( 'Review Links', 'axle-review' ),
			__( 'Review Links', 'axle-review' ),
			Settings::inbox_capability(),
			self::SLUG,
			array( $this, 'render' )
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'load' ) );
		}
	}

	/**
	 * The page URL.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'tools.php?page=' . self::SLUG ) );
	}

	/**
	 * A status label.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			'active'  => __( 'Active', 'axle-review' ),
			'used'    => __( 'Used up', 'axle-review' ),
			'expired' => __( 'Expired', 'axle-review' ),
			'revoked' => __( 'Revoked', 'axle-review' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * The filters of the current request.
	 *
	 * @return array
	 */
	private function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		return array(
			'post_id'    => isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0,
			'created_by' => isset( $_GET['created_by'] ) ? absint( $_GET['created_by'] ) : 0,
			'status'     => in_array( $status, Links::STATUSES, true ) ? $status : '',
			'page'       => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
		);
		// phpcs:enable
	}

	/**
	 * Bulk revoke and assets.
	 */
	public function load(): void {
		$table = new LinksTable( $this->links, $this->events, $this->filters() );
		if ( 'revoke' === $table->current_action() ) {
			check_admin_referer( 'bulk-links' );
			$ids  = isset( $_REQUEST['links'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['links'] ) ) : array();
			$done = 0;
			foreach ( $ids as $id ) {
				$link = $this->links->get( $id );
				if ( $link && Settings::can_share( (int) $link['post_id'] ) && $this->links->revoke( $id, get_current_user_id() ) ) {
					++$done;
				}
			}
			wp_safe_redirect( self::url( array( 'axr_revoked' => $done ) ) );
			exit;
		}

		AxleUi::enqueue();
		wp_enqueue_style( 'axle-review-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array( AxleUi::HANDLE ), VERSION );
		self::enqueue_script();
		AxleUi::help_tabs( self::help() );
	}

	/**
	 * The script for copy buttons, confirm steps and live announcements.
	 */
	public static function enqueue_script(): void {
		wp_enqueue_script( 'axle-review-links', plugins_url( 'assets/links.js', PLUGIN_FILE ), array( 'wp-a11y' ), VERSION, true );
		wp_localize_script( 'axle-review-links', 'axleReviewAdmin', array( 'cancel' => __( 'Cancel', 'axle-review' ) ) );
	}

	/**
	 * The Help tabs of this screen.
	 *
	 * @return array<int, array{id: string, title: string, content: string}>
	 */
	public static function help(): array {
		return array(
			array(
				'id'      => 'overview',
				'title'   => __( 'Overview', 'axle-review' ),
				'content' => '<p>' . esc_html__( 'A review link lets someone without an account read a draft and leave notes on it. This screen lists every link on the site, who opened it, and what each reviewer answered.', 'axle-review' ) . '</p><p>' . esc_html__( 'Create links from the "Share for review" panel in the editor, or with "Share for review" under a draft in the posts list.', 'axle-review' ) . '</p>',
			),
			array(
				'id'      => 'answers',
				'title'   => __( 'Answers', 'axle-review' ),
				'content' => '<p>' . esc_html__( 'A reviewer can approve the draft or ask for changes. The Answer column shows what they chose.', 'axle-review' ) . '</p><p>' . esc_html__( 'If you edit the post after an approval, the column says "Edited since approval". Use "Ask again" to send the reviewer a new link. They see only what changed.', 'axle-review' ) . '</p>',
			),
			array(
				'id'      => 'revoke',
				'title'   => __( 'Turning links off', 'axle-review' ),
				'content' => '<p>' . esc_html__( 'Revoke turns one link off for good. Pause all links stops every link on the site at once, for example after a leak. Resume brings back the links that were still valid.', 'axle-review' ) . '</p>',
			),
		);
	}

	/**
	 * Revoke one link.
	 */
	public function handle_revoke(): void {
		$id = isset( $_GET['link'] ) ? absint( $_GET['link'] ) : 0;
		check_admin_referer( 'axle_review_revoke_' . $id );

		$link = $this->links->get( $id );
		if ( ! $link || ! Settings::can_share( (int) $link['post_id'] ) ) {
			wp_die( esc_html__( 'You cannot revoke this link.', 'axle-review' ), 403 );
		}

		$this->links->revoke( $id, get_current_user_id() );
		wp_safe_redirect( add_query_arg( 'axr_revoked', 1, wp_get_referer() ? wp_get_referer() : self::url() ) );
		exit;
	}

	/**
	 * Pause or resume every link.
	 */
	public function handle_pause(): void {
		check_admin_referer( 'axle_review_pause' );
		if ( ! current_user_can( Settings::settings_capability() ) ) {
			wp_die( esc_html__( 'You cannot pause review links.', 'axle-review' ), 403 );
		}

		$pause = isset( $_POST['pause'] ) && '1' === sanitize_key( wp_unslash( $_POST['pause'] ) );
		Settings::set_paused( $pause );

		wp_safe_redirect( self::url( array( 'axr_paused' => $pause ? 1 : 0 ) ) );
		exit;
	}

	/**
	 * Create a link for a post from the form.
	 */
	public function handle_create(): void {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		check_admin_referer( 'axle_review_create_' . $post_id );
		if ( ! Settings::can_share( $post_id ) ) {
			wp_die( esc_html__( 'You cannot share this post.', 'axle-review' ), 403 );
		}

		$created = $this->links->create(
			array(
				'post_id'     => $post_id,
				'label'       => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '',
				'expires'     => isset( $_POST['expires'] ) ? absint( $_POST['expires'] ) : $this->settings->default_expiry(),
				'max_views'   => isset( $_POST['max_views'] ) ? absint( $_POST['max_views'] ) : 0,
				'email'       => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
				'password'    => isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Hashed, never shown.
				'allow_notes' => ! empty( $_POST['allow_notes'] ),
				'share_notes' => ! empty( $_POST['share_notes'] ),
			)
		);

		if ( is_wp_error( $created ) ) {
			set_transient( 'axle_review_new_link_' . get_current_user_id(), array( 'error' => $created->get_error_message() ), MINUTE_IN_SECONDS );
		} else {
			$send_to = isset( $_POST['send_to'] ) ? sanitize_email( wp_unslash( $_POST['send_to'] ) ) : '';
			$emailed = '' !== $send_to && is_email( $send_to ) && EditorRoutes::email_link( $send_to, $created['url'], $created['link'] );
			set_transient(
				'axle_review_new_link_' . get_current_user_id(),
				array(
					'url'     => $created['url'],
					'emailed' => $emailed ? $send_to : '',
				),
				MINUTE_IN_SECONDS
			);
		}

		wp_safe_redirect( self::url( array( 'post_id' => $post_id ) ) );
		exit;
	}

	/**
	 * Warns on every admin screen while links are paused.
	 */
	public function paused_notice(): void {
		if ( ! Settings::paused() || ! current_user_can( Settings::inbox_capability() ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning axr-paused-notice"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'Review links are paused. Nobody can open a review link until they are resumed.', 'axle-review' ),
			esc_url( self::url() ),
			esc_html__( 'Review Links', 'axle-review' )
		);
	}

	/**
	 * Prints the page.
	 */
	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$activity = isset( $_GET['activity'] ) ? absint( $_GET['activity'] ) : 0;

		echo '<div class="wrap axle-ui axr-links">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Review Links', 'axle-review' ) . '</h1>';
		if ( Settings::paused() ) {
			echo '<span class="axle-status"><span class="axle-badge axle-badge--warning">' . esc_html__( 'Paused', 'axle-review' ) . '</span></span>';
		}
		echo '<hr class="wp-header-end">';

		$this->notices();

		echo '<p class="axle-intro">' . esc_html__( 'Private links that let someone without an account read a draft and leave notes on it. See who opened each link, turn one off, or pause them all.', 'axle-review' ) . '</p>';

		if ( $activity ) {
			$this->render_activity( $activity );
			AxleUi::footer();
			echo '</div>';
			return;
		}

		$filters = $this->filters();

		if ( $filters['post_id'] && Settings::can_share( $filters['post_id'] ) ) {
			$this->create_form( $filters['post_id'] );
		}

		$table = new LinksTable( $this->links, $this->events, $filters );
		$table->prepare_items();

		$this->steps();

		printf(
			'<form method="get" data-axr-bulk-action="revoke" data-axr-bulk-confirm="%1$s" data-axr-bulk-label="%2$s">',
			/* translators: %d is replaced in the browser with the number of selected links. */
			esc_attr__( 'Revoke %d selected links? Nobody can open the drafts through them any more. This cannot be undone.', 'axle-review' ),
			esc_attr__( 'Revoke links', 'axle-review' )
		);
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		if ( $filters['post_id'] ) {
			printf( '<input type="hidden" name="post_id" value="%d">', (int) $filters['post_id'] );
		}
		$this->filter_bar( $filters );
		if ( $table->has_items() ) {
			printf( '<div class="axle-scroll" tabindex="0" role="region" aria-label="%s">', esc_attr__( 'All links', 'axle-review' ) );
			$table->display();
			echo '</div>';
		} else {
			$this->empty_state( $table->empty_message() );
		}
		echo '</form>';

		$this->pause_form();

		AxleUi::footer();
		echo '</div>';
	}

	/**
	 * What an empty list says: what will show up, and what to do first.
	 *
	 * @param string $message Message.
	 */
	private function empty_state( string $message ): void {
		echo '<div class="axle-empty"><p>' . esc_html( $message ) . '</p>';
		printf( '<p><a class="button" href="%1$s">%2$s</a></p>', esc_url( admin_url( 'edit.php?post_status=draft&post_type=post' ) ), esc_html__( 'Open your drafts', 'axle-review' ) );
		echo '</div>';
	}

	/**
	 * Whether this user hid the Get started card.
	 *
	 * @return bool
	 */
	private function steps_dismissed(): bool {
		return (bool) get_user_meta( get_current_user_id(), 'axle_review_steps_dismissed', true );
	}

	/**
	 * The "Get started" card, until the three steps are done or the user hides it.
	 */
	private function steps(): void {
		if ( $this->steps_dismissed() ) {
			return;
		}

		$has_links = $this->links->query( array( 'limit' => 1 ) )['total'] > 0;
		$answered  = $this->events->any_of( array( 'note', 'decision' ) );
		$gate      = (bool) $this->settings->get( 'gate_enabled' );

		$steps = array(
			array(
				'title' => __( 'Share a draft', 'axle-review' ),
				'text'  => __( 'Open a draft in the editor and use the "Share for review" panel. Pick how long the link works.', 'axle-review' ),
				'done'  => $has_links,
				'url'   => admin_url( 'edit.php?post_status=draft&post_type=post' ),
				'label' => __( 'Open your drafts', 'axle-review' ),
			),
			array(
				'title' => __( 'Get a reviewer\'s answer', 'axle-review' ),
				'text'  => __( 'Send the link. The reviewer leaves notes on blocks, or approves the draft. Their notes land in Dashboard > Notes.', 'axle-review' ),
				'done'  => $answered,
				'url'   => Inbox::url(),
				'label' => __( 'Open Notes', 'axle-review' ),
			),
			array(
				'title' => __( 'Choose what holds back publishing', 'axle-review' ),
				'text'  => __( 'Optional. Turn on the publish gate to stop posts with open notes, or without enough approvals, from going live.', 'axle-review' ),
				'done'  => $gate,
				'url'   => admin_url( 'options-writing.php#axle-review' ),
				'label' => __( 'Open the settings', 'axle-review' ),
			),
		);

		if ( ! array_filter( $steps, static fn( $step ) => ! $step['done'] ) ) {
			return;
		}

		echo '<section class="axle-card axr-steps"><h2 class="axle-card__title">' . esc_html__( 'Get started', 'axle-review' ) . '</h2><ol class="axle-steps">';
		foreach ( $steps as $step ) {
			printf(
				'<li class="axle-step"><div class="axle-step__body"><strong class="axle-step__title">%1$s</strong><p>%2$s</p></div>%3$s</li>',
				esc_html( $step['title'] ),
				esc_html( $step['text'] ),
				$step['done'] ? '<span class="axle-badge axle-badge--good">' . esc_html__( 'Done', 'axle-review' ) . '</span>' : sprintf( '<a class="button" href="%1$s">%2$s</a>', esc_url( $step['url'] ), esc_html( $step['label'] ) )
			);
		}
		echo '</ol>';
		printf(
			'<p><a class="button-link" href="%1$s">%2$s</a></p>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=axle_review_dismiss_steps' ), 'axle_review_dismiss_steps' ) ),
			esc_html__( 'Hide this card', 'axle-review' )
		);
		echo '</section>';
	}

	/**
	 * Hides the Get started card for this user.
	 */
	public function handle_dismiss_steps(): void {
		check_admin_referer( 'axle_review_dismiss_steps' );
		if ( ! current_user_can( Settings::inbox_capability() ) ) {
			wp_die( esc_html__( 'You cannot do that.', 'axle-review' ), 403 );
		}

		update_user_meta( get_current_user_id(), 'axle_review_steps_dismissed', 1 );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Asks a reviewer to look again, then shows the new link once.
	 */
	public function handle_ask_again(): void {
		$id = isset( $_GET['link'] ) ? absint( $_GET['link'] ) : 0;
		check_admin_referer( 'axle_review_ask_again_' . $id );

		$link = $this->links->get( $id );
		if ( ! $link || ! Settings::can_share( (int) $link['post_id'] ) ) {
			wp_die( esc_html__( 'You cannot ask this reviewer again.', 'axle-review' ), 403 );
		}

		$result = $this->approvals->ask_again( $id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			set_transient( 'axle_review_new_link_' . get_current_user_id(), array( 'error' => $result->get_error_message() ), MINUTE_IN_SECONDS );
		} else {
			set_transient(
				'axle_review_new_link_' . get_current_user_id(),
				array(
					'url'     => $result['url'],
					'emailed' => $result['emailed'] ? (string) $result['link']['email'] : '',
					'again'   => true,
				),
				MINUTE_IN_SECONDS
			);
		}

		wp_safe_redirect( self::url( array( 'post_id' => (int) $link['post_id'] ) ) );
		exit;
	}

	/**
	 * Notices after actions, and the new link shown once.
	 */
	private function notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only.
		if ( isset( $_GET['axr_revoked'] ) ) {
			$count = absint( $_GET['axr_revoked'] );
			/* translators: %d: number of links. */
			printf( '<div class="notice notice-success is-dismissible axr-live"><p>%s</p></div>', esc_html( sprintf( _n( '%d link revoked. A revoked link cannot be turned back on. Create a new link instead.', '%d links revoked. A revoked link cannot be turned back on. Create new links instead.', $count, 'axle-review' ), $count ) ) );
		}
		if ( isset( $_GET['axr_paused'] ) ) {
			$paused = (bool) absint( $_GET['axr_paused'] );
			echo '<div class="notice notice-success is-dismissible axr-live"><p>' . esc_html( $paused ? __( 'Every review link is paused.', 'axle-review' ) : __( 'Review links work again.', 'axle-review' ) ) . '</p>';
			if ( $paused && current_user_can( Settings::settings_capability() ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="axle_review_pause"><input type="hidden" name="pause" value="0">';
				wp_nonce_field( 'axle_review_pause' );
				echo '<p><button type="submit" class="button-link">' . esc_html__( 'Undo: resume all links', 'axle-review' ) . '</button></p></form>';
			}
			echo '</div>';
		}
		// phpcs:enable

		$key = 'axle_review_new_link_' . get_current_user_id();
		$new = get_transient( $key );
		if ( ! is_array( $new ) ) {
			return;
		}
		delete_transient( $key );

		if ( ! empty( $new['error'] ) ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( (string) $new['error'] ) );
			return;
		}

		echo '<div class="notice notice-success axr-new-link axr-live"><p><strong>' . esc_html( ! empty( $new['again'] ) ? __( 'The reviewer has a new link. Copy it now: it is not shown again, and the old link no longer works.', 'axle-review' ) : __( 'Link created. Copy it now: it is not shown again.', 'axle-review' ) ) . '</strong></p>';
		printf(
			'<p><label for="axr-new-url" class="screen-reader-text">%1$s</label><input type="text" id="axr-new-url" class="large-text code" readonly value="%2$s"> <button type="button" class="button axr-copy" data-target="axr-new-url" data-copied="%4$s">%3$s</button></p>',
			esc_html__( 'Review link', 'axle-review' ),
			esc_attr( (string) $new['url'] ),
			esc_html__( 'Copy', 'axle-review' ),
			esc_attr__( 'Copied', 'axle-review' )
		);
		if ( ! empty( $new['emailed'] ) ) {
			/* translators: %s: email address. */
			printf( '<p>%s</p>', esc_html( sprintf( __( 'Emailed to %s.', 'axle-review' ), (string) $new['emailed'] ) ) );
		}
		echo '</div>';
	}

	/**
	 * The pause and resume button.
	 */
	private function pause_form(): void {
		if ( ! current_user_can( Settings::settings_capability() ) ) {
			return;
		}

		$paused = Settings::paused();
		echo '<section class="axle-card' . ( $paused ? '' : ' axle-card--danger' ) . ' axr-pause">';
		echo '<h2 class="axle-card__title">' . esc_html( $paused ? __( 'Links are paused', 'axle-review' ) : __( 'Pause all links', 'axle-review' ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="axle_review_pause">';
		echo '<input type="hidden" name="pause" value="' . ( $paused ? '0' : '1' ) . '">';
		wp_nonce_field( 'axle_review_pause' );
		if ( $paused ) {
			echo '<p>' . esc_html__( 'Every review link is paused. Nobody can open one. Resuming brings back the ones that were still valid.', 'axle-review' ) . '</p>';
			submit_button( __( 'Resume all links', 'axle-review' ), 'secondary', 'submit', false );
		} else {
			echo '<p>' . esc_html__( 'If a link leaked, pause every link on the site at once. Nobody can open a review link until you resume them. Resuming brings back the ones that were still valid.', 'axle-review' ) . '</p>';
			echo '<p class="axr-confirm-after">';
			submit_button(
				__( 'Pause all links', 'axle-review' ),
				'secondary axle-button--danger',
				'submit',
				false,
				array(
					'data-axr-confirm'       => __( 'Pause every review link on this site? Nobody can open one until you resume them. You can undo this with "Resume all links".', 'axle-review' ),
					'data-axr-confirm-label' => __( 'Pause all links', 'axle-review' ),
				)
			);
			echo '</p>';
		}
		echo '</form></section>';
	}

	/**
	 * The create form for one post.
	 *
	 * @param int $post_id Post ID.
	 */
	private function create_form( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, Settings::post_types(), true ) || in_array( $post->post_status, Links::dead_statuses(), true ) ) {
			return;
		}

		$default = $this->settings->default_expiry();
		echo '<section class="axle-card axr-card" id="axr-new">';
		/* translators: %s: post title. */
		echo '<h2 class="axle-card__title">' . esc_html( sprintf( __( 'Share "%s" for review', 'axle-review' ), get_the_title( $post ) ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="axle_review_create_link">';
		printf( '<input type="hidden" name="post_id" value="%d">', (int) $post_id );
		wp_nonce_field( 'axle_review_create_' . $post_id );
		echo '<table class="form-table" role="presentation"><tbody>';

		printf( '<tr><th scope="row"><label for="axr-label">%1$s</label></th><td><input type="text" id="axr-label" name="label" class="regular-text" placeholder="%2$s"></td></tr>', esc_html__( 'Label', 'axle-review' ), esc_attr__( 'For example: Legal review', 'axle-review' ) );

		echo '<tr><th scope="row"><label for="axr-expires">' . esc_html__( 'Expires after', 'axle-review' ) . '</label></th><td><select id="axr-expires" name="expires">';
		foreach ( Settings::expiry_choices() as $seconds => $label ) {
			printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $seconds, selected( $default, $seconds, false ), esc_html( $label ) );
		}
		echo '</select></td></tr>';

		printf(
			'<tr><th scope="row"><label for="axr-views">%1$s</label></th><td><input type="number" id="axr-views" name="max_views" min="0" step="1" value="%2$d" class="small-text" aria-describedby="axr-views-help"><p class="description" id="axr-views-help">%3$s</p></td></tr>',
			esc_html__( 'View limit', 'axle-review' ),
			(int) $this->settings->default_max_views(),
			esc_html__( '0 is unlimited. 1 opens once: the first person to open it keeps a session for a few hours.', 'axle-review' )
		);

		printf(
			'<tr><th scope="row"><label for="axr-email">%1$s</label></th><td><input type="email" id="axr-email" name="email" class="regular-text" aria-describedby="axr-email-help"><p class="description" id="axr-email-help">%2$s</p></td></tr>',
			esc_html__( 'Lock to an email', 'axle-review' ),
			esc_html__( 'Optional. The reviewer gets a one-time code at this address before the page opens.', 'axle-review' )
		);

		printf( '<tr><th scope="row"><label for="axr-password">%1$s</label></th><td><input type="password" id="axr-password" name="password" class="regular-text" autocomplete="new-password"></td></tr>', esc_html__( 'Password (optional)', 'axle-review' ) );

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="allow_notes" value="1" checked> %2$s</label><br><label><input type="checkbox" name="share_notes" value="1"%3$s> %4$s</label></td></tr>',
			esc_html__( 'Notes', 'axle-review' ),
			esc_html__( 'Let the reviewer leave notes', 'axle-review' ),
			checked( (bool) $this->settings->get( 'guest_sees_others' ), true, false ),
			esc_html__( 'Show them notes other reviewers left', 'axle-review' )
		);

		printf(
			'<tr><th scope="row"><label for="axr-send-to">%1$s</label></th><td><input type="email" id="axr-send-to" name="send_to" class="regular-text"></td></tr>',
			esc_html__( 'Email the link to', 'axle-review' )
		);

		echo '</tbody></table>';
		submit_button( __( 'Create link', 'axle-review' ) );
		echo '</form></section>';
	}

	/**
	 * Post, creator and status filters.
	 *
	 * @param array $filters Filters.
	 */
	private function filter_bar( array $filters ): void {
		echo '<div class="axle-toolbar">';
		echo '<label for="axr-filter-status" class="screen-reader-text">' . esc_html__( 'Status', 'axle-review' ) . '</label>';
		echo '<select id="axr-filter-status" name="status"><option value="">' . esc_html__( 'All statuses', 'axle-review' ) . '</option>';
		foreach ( Links::STATUSES as $status ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $status ), selected( $filters['status'], $status, false ), esc_html( self::status_label( $status ) ) );
		}
		echo '</select>';

		echo '<label for="axr-filter-creator" class="screen-reader-text">' . esc_html__( 'Created by', 'axle-review' ) . '</label>';
		wp_dropdown_users(
			array(
				'name'             => 'created_by',
				'id'               => 'axr-filter-creator',
				'capability'       => 'edit_posts',
				'show_option_all'  => __( 'Anyone', 'axle-review' ),
				'selected'         => (int) $filters['created_by'],
				'include_selected' => true,
			)
		);
		submit_button( __( 'Filter', 'axle-review' ), '', 'axr-filter', false );

		if ( $filters['post_id'] ) {
			printf(
				' <span class="axr-filter-post">%1$s <a href="%2$s">%3$s</a></span>',
				/* translators: %s: post title. */
				esc_html( sprintf( __( 'Showing links to "%s".', 'axle-review' ), get_the_title( (int) $filters['post_id'] ) ) ),
				esc_url( self::url() ),
				esc_html__( 'Show all posts', 'axle-review' )
			);
		}
		echo '</div>';
	}

	/**
	 * One link's activity.
	 *
	 * @param int $link_id Link ID.
	 */
	private function render_activity( int $link_id ): void {
		$link = $this->links->get( $link_id );
		if ( ! $link || ! Settings::can_share( (int) $link['post_id'] ) ) {
			echo '<p>' . esc_html__( 'That link does not exist.', 'axle-review' ) . '</p>';
			return;
		}

		$labels = self::event_labels();
		printf( '<p><a href="%1$s">%2$s</a></p>', esc_url( self::url() ), esc_html__( 'Back to all links', 'axle-review' ) );

		echo '<section class="axle-card">';
		/* translators: 1: link label, 2: post title. */
		echo '<h2 class="axle-card__title">' . esc_html( sprintf( __( 'Activity of "%1$s" on "%2$s"', 'axle-review' ), '' === $link['label'] ? '#' . $link_id : $link['label'], get_the_title( (int) $link['post_id'] ) ) ) . '</h2>';

		$events = $this->events->for_link( $link_id, 200 );
		if ( ! $events ) {
			echo '<div class="axle-empty"><p>' . esc_html__( 'Nobody has opened this link yet. Opens, codes, notes and answers will be listed here. Send the link to a reviewer to get started.', 'axle-review' ) . '</p></div></section>';
			return;
		}

		printf( '<div class="axle-scroll" tabindex="0" role="region" aria-label="%s">', esc_attr__( 'Link activity', 'axle-review' ) );
		echo '<table class="widefat striped axr-activity"><thead><tr><th scope="col">' . esc_html__( 'When', 'axle-review' ) . '</th><th scope="col">' . esc_html__( 'What', 'axle-review' ) . '</th><th scope="col">' . esc_html__( 'Browser', 'axle-review' ) . '</th><th scope="col">' . esc_html__( 'IP address (anonymised)', 'axle-review' ) . '</th></tr></thead><tbody>';
		foreach ( $events as $event ) {
			$time = (int) strtotime( $event['created_at'] );
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td></tr>',
				AxleUi::relative_time( $time ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in AxleUi.
				esc_html( $labels[ $event['type'] ] ?? $event['type'] ),
				esc_html( $event['browser'] ),
				esc_html( $event['ip'] )
			);
		}
		echo '</tbody></table></div></section>';
	}

	/**
	 * Event labels.
	 *
	 * @return array<string, string>
	 */
	public static function event_labels(): array {
		return array(
			'open'          => __( 'Opened', 'axle-review' ),
			'code_sent'     => __( 'Code sent', 'axle-review' ),
			'code_ok'       => __( 'Right code', 'axle-review' ),
			'code_fail'     => __( 'Wrong code', 'axle-review' ),
			'password_fail' => __( 'Wrong password', 'axle-review' ),
			'note'          => __( 'Left a note', 'axle-review' ),
			'reply_seen'    => __( 'Saw replies', 'axle-review' ),
			'denied'        => __( 'Refused', 'axle-review' ),
			'decision'      => __( 'Answered', 'axle-review' ),
		);
	}
}
