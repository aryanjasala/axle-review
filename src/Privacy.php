<?php
/**
 * Personal data export, erase and the privacy policy text.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review;

defined( 'ABSPATH' ) || exit;

/**
 * Reviewers leave a name, an email, note text, and link activity with an
 * anonymised IP address and browser family. Everything is keyed by email.
 *
 * The eraser replaces a reviewer's name and email on their notes with
 * "Reviewer" and keeps the text (the filter can delete the notes instead),
 * revokes links locked to that email and forgets the email, and deletes the
 * activity of those links.
 */
final class Privacy implements Service {

	public const ID   = 'axle-review';
	private const PER = 100;

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
	 * Sets up the privacy tools.
	 *
	 * @param Links  $links  Links.
	 * @param Events $events Events.
	 */
	public function __construct( Links $links, Events $events ) {
		$this->links  = $links;
		$this->events = $events;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'policy_text' ) );
	}

	/**
	 * Registers the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function add_exporter( $exporters ): array {
		$exporters             = (array) $exporters;
		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'Review links and reviewer notes', 'axle-review' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function add_eraser( $erasers ): array {
		$erasers             = (array) $erasers;
		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'Review links and reviewer notes', 'axle-review' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Guest notes left with an email, one page.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page, from 1.
	 * @return \WP_Comment[]
	 */
	private function guest_notes( string $email, int $page ): array {
		$notes = get_comments(
			array(
				'type'         => 'note',
				'author_email' => $email,
				'user_id'      => 0,
				'status'       => 'all',
				'number'       => self::PER,
				'offset'       => ( $page - 1 ) * self::PER,
				'orderby'      => 'comment_ID',
				'order'        => 'ASC',
				'meta_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Privacy request, run by hand.
					array(
						'key'     => '_axr_link_id',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		return array_values( array_filter( (array) $notes, static fn( $note ) => $note instanceof \WP_Comment ) );
	}

	/**
	 * Exports one page of a reviewer's data.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return array{data: array, done: bool}
	 */
	public function export( $email, $page = 1 ): array {
		$email = strtolower( sanitize_email( (string) $email ) );
		$page  = max( 1, (int) $page );
		$data  = array();
		$notes = $this->guest_notes( $email, $page );

		foreach ( $notes as $note ) {
			$data[] = array(
				'group_id'    => 'axle-review-notes',
				'group_label' => __( 'Reviewer notes', 'axle-review' ),
				'item_id'     => 'axle-review-note-' . (int) $note->comment_ID,
				'data'        => array(
					array(
						'name'  => __( 'Name', 'axle-review' ),
						'value' => (string) $note->comment_author,
					),
					array(
						'name'  => __( 'Note', 'axle-review' ),
						'value' => (string) $note->comment_content,
					),
					array(
						'name'  => __( 'Date', 'axle-review' ),
						'value' => (string) $note->comment_date_gmt,
					),
					array(
						'name'  => __( 'Post', 'axle-review' ),
						'value' => get_the_title( (int) $note->comment_post_ID ),
					),
				),
			);
		}

		$links = 1 === $page ? $this->links->query(
			array(
				'email' => $email,
				'limit' => 500,
			)
		)['items'] : array();

		foreach ( $links as $link ) {
			$fields = array(
				array(
					'name'  => __( 'Email', 'axle-review' ),
					'value' => (string) $link['email'],
				),
				array(
					'name'  => __( 'Post', 'axle-review' ),
					'value' => get_the_title( (int) $link['post_id'] ),
				),
				array(
					'name'  => __( 'Created', 'axle-review' ),
					'value' => (string) $link['created_at'],
				),
			);
			foreach ( $this->events->for_link( (int) $link['id'], 1000 ) as $event ) {
				$fields[] = array(
					'name'  => $event['type'],
					'value' => trim( $event['created_at'] . ' ' . $event['ip'] . ' ' . $event['browser'] ),
				);
			}

			$data[] = array(
				'group_id'    => 'axle-review-links',
				'group_label' => __( 'Review links', 'axle-review' ),
				'item_id'     => 'axle-review-link-' . (int) $link['id'],
				'data'        => $fields,
			);
		}

		return array(
			'data' => $data,
			'done' => count( $notes ) < self::PER,
		);
	}

	/**
	 * Erases one page of a reviewer's data.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1. Always 1 here: erased notes drop out of the query.
	 * @return array{items_removed: bool, items_retained: bool, messages: array, done: bool}
	 */
	public function erase( $email, $page = 1 ): array {
		unset( $page );
		$email   = strtolower( sanitize_email( (string) $email ) );
		$removed = false;

		/**
		 * Filters whether erasing a reviewer's data deletes their notes rather
		 * than replacing their name and email with "Reviewer".
		 *
		 * @param bool   $delete Default false.
		 * @param string $email  Email address.
		 */
		$delete = (bool) apply_filters( 'axle_review_erase_deletes_notes', false, $email );

		$notes = $this->guest_notes( $email, 1 );
		foreach ( $notes as $note ) {
			if ( $delete ) {
				wp_delete_comment( (int) $note->comment_ID, true );
			} else {
				wp_update_comment(
					wp_slash(
						array(
							'comment_ID'           => (int) $note->comment_ID,
							'comment_author'       => __( 'Reviewer', 'axle-review' ),
							'comment_author_email' => '',
						)
					)
				);
			}
			$removed = true;
		}

		$links = $this->links->query(
			array(
				'email' => $email,
				'limit' => 500,
			)
		)['items'];
		foreach ( $links as $link ) {
			$this->events->delete_for_link( (int) $link['id'] );
			$this->links->forget_email( (int) $link['id'] );
			$removed = true;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $notes ) < self::PER,
		);
	}

	/**
	 * Suggested privacy policy text.
	 */
	public function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$text = '<p>' . esc_html__( 'When we share a draft with you for review, we keep the name you give and, if the link is locked to your email address, that address, so we can send you a one-time code. Notes you leave are stored with the post as editorial notes, with your name and email address.', 'axle-review' ) . '</p>' .
			'<p>' . esc_html__( 'For each review link we record when it was opened, an anonymised IP address and your browser family (for example Firefox), for 90 days. You can ask us to export or erase this data.', 'axle-review' ) . '</p>';

		wp_add_privacy_policy_content( __( 'Axle Review', 'axle-review' ), wp_kses_post( $text ) );
	}
}
