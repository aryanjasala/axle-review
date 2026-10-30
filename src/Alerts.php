<?php
/**
 * Email alerts, digests and Slack.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review;

defined( 'ABSPATH' ) || exit;

/**
 * Sends what core does not: email about reviewers' notes and replies in
 * threads a user started, instantly or as an hourly or daily digest, and a
 * Slack message for new reviewer notes and gate overrides.
 *
 * Core keeps sending its own emails: the post author's email for notes made
 * in the editor, and mention emails (7.1). Digests list mentions too.
 */
final class Alerts implements Service {

	public const PREF_KEY      = 'axle_review_notify';
	public const SENT_KEY      = 'axle_review_digest_sent';
	public const SLACK_STATUS  = 'axle_review_slack_status';
	public const PREFERENCES   = array( 'instant', 'hourly', 'daily', 'off' );
	public const DEFAULT_PREF  = 'instant';
	private const DIGEST_LIMIT = 50;

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'axle_review_guest_note_created', array( $this, 'on_guest_note' ), 10, 2 );
		add_action( 'wp_insert_comment', array( $this, 'on_reply' ), 20, 2 );
		add_action( 'axle_review_gate_overridden', array( $this, 'on_override' ), 10, 3 );
		add_action( Schema::DIGEST_HOOKS['hourly'], array( $this, 'send_hourly' ) );
		add_action( Schema::DIGEST_HOOKS['daily'], array( $this, 'send_daily' ) );
	}

	/**
	 * A user's notification preference.
	 *
	 * @param int $user_id User ID.
	 * @return string instant, hourly, daily or off.
	 */
	public static function preference( int $user_id ): string {
		$pref = (string) get_user_meta( $user_id, self::PREF_KEY, true );
		return in_array( $pref, self::PREFERENCES, true ) ? $pref : self::DEFAULT_PREF;
	}

	/**
	 * Runs an email subject through its filter.
	 *
	 * @param string $subject Subject.
	 * @param string $event   code, link, guest_note, reply or digest.
	 * @param array  $context What the email is about.
	 * @return string
	 */
	public static function subject( string $subject, string $event, array $context ): string {
		/**
		 * Filters the subject of an email Axle Review sends.
		 *
		 * @param string $subject Subject.
		 * @param string $event   code, link, guest_note, reply or digest.
		 * @param array  $context What the email is about (link, note, post, user, items).
		 */
		$filtered = (string) apply_filters( 'axle_review_email_subject', $subject, $event, $context );
		return '' !== trim( $filtered ) ? $filtered : $subject;
	}

	/**
	 * Runs an email body through its filter.
	 *
	 * @param string $message Body.
	 * @param string $event   code, link, guest_note, reply or digest.
	 * @param array  $context What the email is about.
	 * @return string
	 */
	public static function message( string $message, string $event, array $context ): string {
		/**
		 * Filters the body of an email Axle Review sends.
		 *
		 * @param string $message Plain text body.
		 * @param string $event   code, link, guest_note, reply or digest.
		 * @param array  $context What the email is about.
		 */
		$filtered = (string) apply_filters( 'axle_review_email_message', $message, $event, $context );
		return '' !== trim( $filtered ) ? $filtered : $message;
	}

	/**
	 * Who hears about an event, before preferences.
	 *
	 * @param int[]  $user_ids Default recipients.
	 * @param string $event    guest_note or reply.
	 * @param array  $context  note, post.
	 * @return int[]
	 */
	public static function recipients( array $user_ids, string $event, array $context ): array {
		/**
		 * Filters who is told about a reviewer's note or a reply. Each user's
		 * preference (instant, digest or off) still applies.
		 *
		 * @param int[]  $user_ids Default: the post author for guest notes, the thread starter for replies.
		 * @param string $event    guest_note or reply.
		 * @param array  $context  note (WP_Comment) and post (WP_Post).
		 */
		$user_ids = (array) apply_filters( 'axle_review_notify_recipients', $user_ids, $event, $context );

		return array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
	}

	/**
	 * Instant email and Slack message for a new reviewer note.
	 *
	 * @param mixed $note Note.
	 * @param array $link Link it came through.
	 */
	public function on_guest_note( $note, $link ): void {
		if ( ! $note instanceof \WP_Comment ) {
			return;
		}

		$post = get_post( (int) $note->comment_post_ID );
		if ( ! $post ) {
			return;
		}

		$context = array(
			'note' => $note,
			'post' => $post,
			'link' => $link,
		);

		foreach ( self::recipients( array( (int) $post->post_author ), 'guest_note', $context ) as $user_id ) {
			if ( 'instant' === self::preference( $user_id ) ) {
				$this->send_note_email( $user_id, 'guest_note', $note, $post );
			}
		}

		$this->slack(
			'guest_note',
			sprintf(
				/* translators: 1: reviewer name, 2: post title, 3: note text, 4: edit link. */
				__( '%1$s left a note on "%2$s": %3$s %4$s', 'axle-review' ),
				$note->comment_author,
				wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ),
				wp_trim_words( wp_strip_all_tags( (string) $note->comment_content ), 40 ),
				self::edit_link( $post )
			),
			$context
		);
	}

	/**
	 * Instant email to the thread starter when someone replies.
	 *
	 * @param int   $id      Comment ID.
	 * @param mixed $comment Comment.
	 */
	public function on_reply( $id, $comment ): void {
		if ( ! $comment instanceof \WP_Comment || 'note' !== $comment->comment_type || ! (int) $comment->comment_parent || '' === trim( (string) $comment->comment_content ) ) {
			return;
		}

		$root = Notes::get( (int) $comment->comment_parent );
		$post = get_post( (int) $comment->comment_post_ID );
		if ( ! $root || ! $post || ! (int) $root->user_id || (int) $root->user_id === (int) $comment->user_id ) {
			return;
		}

		$context = array(
			'note' => $comment,
			'post' => $post,
		);

		foreach ( self::recipients( array( (int) $root->user_id ), 'reply', $context ) as $user_id ) {
			if ( $user_id !== (int) $comment->user_id && 'instant' === self::preference( $user_id ) ) {
				$this->send_note_email( $user_id, 'reply', $comment, $post );
			}
		}
	}

	/**
	 * The editor link of a post, for emails.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private static function edit_link( \WP_Post $post ): string {
		return admin_url( 'post.php?post=' . (int) $post->ID . '&action=edit' );
	}

	/**
	 * One email about one note.
	 *
	 * @param int         $user_id Recipient.
	 * @param string      $event   guest_note or reply.
	 * @param \WP_Comment $note    Note.
	 * @param \WP_Post    $post    Post.
	 */
	private function send_note_email( int $user_id, string $event, \WP_Comment $note, \WP_Post $post ): void {
		$user = get_userdata( $user_id );
		if ( ! $user || ! $user->user_email || ! user_can( $user, 'edit_post', (int) $post->ID ) ) {
			return;
		}

		$switched = switch_to_user_locale( $user_id );
		$site     = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$title    = wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES );
		$text     = trim( html_entity_decode( wp_strip_all_tags( (string) $note->comment_content ), ENT_QUOTES, 'UTF-8' ) );

		if ( 'reply' === $event ) {
			/* translators: 1: site name, 2: post title. */
			$subject = sprintf( __( '[%1$s] New reply to your note on "%2$s"', 'axle-review' ), $site, $title );
			/* translators: 1: who replied, 2: post title. */
			$intro = sprintf( __( '%1$s replied to your note on "%2$s":', 'axle-review' ), $note->comment_author, $title );
		} else {
			/* translators: 1: site name, 2: post title. */
			$subject = sprintf( __( '[%1$s] A reviewer left a note on "%2$s"', 'axle-review' ), $site, $title );
			/* translators: 1: reviewer name, 2: post title. */
			$intro = sprintf( __( '%1$s left a note on "%2$s" through a review link:', 'axle-review' ), $note->comment_author, $title );
		}

		$message = $intro . "\n\n" . $text . "\n\n" .
			/* translators: %s: editor URL. */
			sprintf( __( 'Open it in the editor: %s', 'axle-review' ), self::edit_link( $post ) );

		$context = array(
			'note' => $note,
			'post' => $post,
			'user' => $user,
		);
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- One email per note, to the people who chose instant alerts.
		wp_mail( $user->user_email, self::subject( $subject, $event, $context ), self::message( $message, $event, $context ) );

		if ( $switched ) {
			restore_previous_locale();
		}
	}

	/**
	 * Hourly digests.
	 */
	public function send_hourly(): void {
		$this->send_digests( 'hourly' );
	}

	/**
	 * Daily digests.
	 */
	public function send_daily(): void {
		$this->send_digests( 'daily' );
	}

	/**
	 * Sends the digests of one frequency, to users who chose it.
	 *
	 * @param string $frequency hourly or daily.
	 * @return int Digests sent.
	 */
	public function send_digests( string $frequency ): int {
		$users = get_users(
			array(
				'meta_key'   => self::PREF_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Runs from cron, hourly at most.
				'meta_value' => $frequency, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Same.
				'fields'     => 'ID',
				'number'     => 500,
			)
		);

		$sent = 0;
		foreach ( (array) $users as $user_id ) {
			if ( $this->send_digest( (int) $user_id, 'hourly' === $frequency ? HOUR_IN_SECONDS : DAY_IN_SECONDS ) ) {
				++$sent;
			}
		}

		return $sent;
	}

	/**
	 * What a user's digest would list: reviewer notes on their posts, replies
	 * in threads they started, and mentions, since a time.
	 *
	 * @param int $user_id User ID.
	 * @param int $since   Unix time.
	 * @return array<int, array{kind: string, note: \WP_Comment}>
	 */
	public function digest_items( int $user_id, int $since ): array {
		$items = array();
		$after = array(
			array(
				'after'     => gmdate( 'Y-m-d H:i:s', $since ),
				'column'    => 'comment_date_gmt',
				'inclusive' => false,
			),
		);

		$guest = get_comments(
			array(
				'type'            => 'note',
				'status'          => array( 'hold', 'approve' ),
				'post_author__in' => array( $user_id ),
				'parent'          => 0,
				'user_id'         => 0,
				'number'          => self::DIGEST_LIMIT,
				'date_query'      => $after,
				'meta_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Cron, bounded.
					array(
						'key'     => '_axr_link_id',
						'compare' => 'EXISTS',
					),
				),
			)
		);
		foreach ( (array) $guest as $note ) {
			if ( $note instanceof \WP_Comment ) {
				$items[ (int) $note->comment_ID ] = array(
					'kind' => 'guest_note',
					'note' => $note,
				);
			}
		}

		$threads = get_comments(
			array(
				'type'    => 'note',
				'status'  => array( 'hold', 'approve' ),
				'user_id' => $user_id,
				'parent'  => 0,
				'fields'  => 'ids',
				'number'  => 200,
			)
		);
		if ( $threads ) {
			$replies = get_comments(
				array(
					'type'           => 'note',
					'status'         => array( 'hold', 'approve' ),
					'parent__in'     => array_map( 'intval', (array) $threads ),
					'author__not_in' => array( $user_id ),
					'number'         => self::DIGEST_LIMIT,
					'date_query'     => $after,
				)
			);
			foreach ( (array) $replies as $note ) {
				if ( $note instanceof \WP_Comment && '' !== trim( (string) $note->comment_content ) ) {
					$items[ (int) $note->comment_ID ] = array(
						'kind' => 'reply',
						'note' => $note,
					);
				}
			}
		}

		if ( Notes::has_mentions() ) {
			$mentions = get_comments(
				array(
					'type'       => 'note',
					'status'     => array( 'hold', 'approve' ),
					'search'     => 'user-' . $user_id,
					'number'     => self::DIGEST_LIMIT,
					'date_query' => $after,
				)
			);
			foreach ( (array) $mentions as $note ) {
				if ( $note instanceof \WP_Comment && (int) $note->user_id !== $user_id && in_array( $user_id, Notes::mentioned( (string) $note->comment_content ), true ) ) {
					$items[ (int) $note->comment_ID ] = array(
						'kind' => 'mention',
						'note' => $note,
					);
				}
			}
		}

		$items = array_filter(
			$items,
			static function ( $item ) use ( $user_id ) {
				return user_can( $user_id, 'edit_post', (int) $item['note']->comment_post_ID );
			}
		);
		ksort( $items );

		return array_values( $items );
	}

	/**
	 * Sends one user's digest when there is something in it.
	 *
	 * @param int $user_id User ID.
	 * @param int $period  Seconds to look back the first time.
	 * @return bool Whether an email went out.
	 */
	public function send_digest( int $user_id, int $period ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user || ! $user->user_email ) {
			return false;
		}

		$last  = (int) get_user_meta( $user_id, self::SENT_KEY, true );
		$since = $last ? $last : time() - $period;
		$now   = time();
		$items = $this->digest_items( $user_id, $since );
		update_user_meta( $user_id, self::SENT_KEY, $now );

		if ( ! $items ) {
			return false;
		}

		$switched = switch_to_user_locale( $user_id );
		$site     = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$labels   = array(
			'guest_note' => __( 'Reviewer note', 'axle-review' ),
			'reply'      => __( 'Reply to you', 'axle-review' ),
			'mention'    => __( 'Mentions you', 'axle-review' ),
		);

		$lines = array();
		foreach ( $items as $item ) {
			$note    = $item['note'];
			$post    = get_post( (int) $note->comment_post_ID );
			$lines[] = sprintf(
				'%1$s, %2$s, "%3$s": %4$s',
				$labels[ $item['kind'] ],
				$note->comment_author,
				$post ? wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ) : '',
				wp_trim_words( html_entity_decode( wp_strip_all_tags( (string) $note->comment_content ), ENT_QUOTES, 'UTF-8' ), 30 )
			);
			if ( $post ) {
				$lines[] = '  ' . self::edit_link( $post );
			}
		}

		/* translators: 1: site name, 2: number of notes. */
		$subject = sprintf( _n( '[%1$s] %2$d new note', '[%1$s] %2$d new notes', count( $items ), 'axle-review' ), $site, count( $items ) );
		$message = __( 'New notes since your last digest:', 'axle-review' ) . "\n\n" . implode( "\n", $lines ) . "\n\n" .
			/* translators: %s: inbox URL. */
			sprintf( __( 'All open notes: %s', 'axle-review' ), admin_url( 'index.php?page=axle-review-notes' ) );

		$context = array(
			'user'  => $user,
			'items' => $items,
		);
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- One digest per user and period.
		wp_mail( $user->user_email, self::subject( $subject, 'digest', $context ), self::message( $message, 'digest', $context ) );

		if ( $switched ) {
			restore_previous_locale();
		}

		/**
		 * Fires after a digest email was sent.
		 *
		 * @param int   $user_id Recipient.
		 * @param array $items   What it listed: kind and note.
		 */
		do_action( 'axle_review_digest_sent', $user_id, $items );

		return true;
	}

	/**
	 * Slack message for a gate override.
	 *
	 * @param mixed  $post    Post.
	 * @param int    $user_id Who.
	 * @param string $reason  Reason.
	 */
	public function on_override( $post, $user_id, $reason ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$user = get_userdata( (int) $user_id );
		$this->slack(
			'gate_override',
			sprintf(
				/* translators: 1: user name, 2: post title, 3: reason. */
				__( '%1$s published "%2$s" with open notes. Reason: %3$s', 'axle-review' ),
				$user ? $user->display_name : '',
				wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ),
				(string) $reason
			),
			array(
				'post'   => $post,
				'reason' => $reason,
			)
		);
	}

	/**
	 * Posts a message to the site's Slack webhook, when one is set.
	 *
	 * @param string $event   guest_note, gate_override or test.
	 * @param string $text    Message.
	 * @param array  $context What it is about.
	 * @return bool Whether Slack took it.
	 */
	public function slack( string $event, string $text, array $context = array() ): bool {
		$url = Settings::slack_webhook();
		if ( '' === $url ) {
			return false;
		}

		/**
		 * Filters the JSON payload sent to the Slack webhook. Return an empty
		 * array to send nothing.
		 *
		 * @param array  $payload Default { text }.
		 * @param string $event   guest_note, gate_override or test.
		 * @param array  $context What it is about.
		 */
		$payload = (array) apply_filters( 'axle_review_slack_payload', array( 'text' => $text ), $event, $context );
		if ( ! $payload ) {
			return false;
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 3,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode( $payload ),
			)
		);

		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$ok   = $code >= 200 && $code < 300;
		update_option(
			self::SLACK_STATUS,
			array(
				'ok'    => $ok,
				'code'  => $code,
				'error' => is_wp_error( $response ) ? $response->get_error_message() : '',
				'time'  => time(),
			),
			false
		);

		if ( ! $ok ) {
			/**
			 * Fires when Slack refused a message or could not be reached.
			 *
			 * @param array|\WP_Error $response The HTTP response.
			 * @param string          $event    guest_note, gate_override or test.
			 */
			do_action( 'axle_review_slack_failed', $response, $event );
		}

		return $ok;
	}
}
