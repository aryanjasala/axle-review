<?php
/**
 * Sign-off: reviewers approve or ask for changes, and later edits are noticed.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review;

defined( 'ABSPATH' ) || exit;

/**
 * One decision per link. The decision, the reviewer's name, the comment and
 * a snapshot of the post (see Fingerprint) are stored on the link row, and a
 * Note on the post mirrors it so it shows in Dashboard > Notes.
 *
 * When the post changes after an approval, the approval is marked outdated.
 * Reviewers can be asked again: the link gets a new URL, and the reviewer sees
 * only what changed since their decision.
 *
 * Nothing here lets a user, an ability or a command approve for a reviewer.
 */
final class Approvals implements Service {

	public const APPROVED          = 'approved';
	public const CHANGES_REQUESTED = 'changes_requested';

	/**
	 * Links.
	 *
	 * @var Links
	 */
	private Links $links;

	/**
	 * Notes.
	 *
	 * @var Notes
	 */
	private Notes $notes;

	/**
	 * Events.
	 *
	 * @var Events
	 */
	private Events $events;

	/**
	 * Sets up approvals.
	 *
	 * @param Links  $links  Links.
	 * @param Notes  $notes  Notes.
	 * @param Events $events Events.
	 */
	public function __construct( Links $links, Notes $notes, Events $events ) {
		$this->links  = $links;
		$this->notes  = $notes;
		$this->events = $events;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'wp_after_insert_post', array( $this, 'post_saved' ), 20, 2 );
		add_action( 'added_post_meta', array( $this, 'meta_changed' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'meta_changed' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'meta_changed' ), 10, 3 );
	}

	/**
	 * The decisions a reviewer can make.
	 *
	 * @return string[]
	 */
	public static function decisions(): array {
		return array( self::APPROVED, self::CHANGES_REQUESTED );
	}

	/**
	 * A label for a decision.
	 *
	 * @param string $decision Decision.
	 * @return string
	 */
	public static function label( string $decision ): string {
		$labels = array(
			self::APPROVED          => __( 'Approved', 'axle-review' ),
			self::CHANGES_REQUESTED => __( 'Changes requested', 'axle-review' ),
		);

		return $labels[ $decision ] ?? __( 'No decision yet', 'axle-review' );
	}

	/**
	 * Records a reviewer's decision.
	 *
	 * @param array  $link      The link.
	 * @param string $decision  approved or changes_requested.
	 * @param string $name      The reviewer's name.
	 * @param string $comment   Optional comment.
	 * @param string $seen_hash The fingerprint hash of the page the reviewer was looking at, empty to skip the check.
	 * @return array|\WP_Error The state of the link.
	 */
	public function decide( array $link, string $decision, string $name, string $comment = '', string $seen_hash = '' ) {
		if ( ! in_array( $decision, self::decisions(), true ) ) {
			return new \WP_Error( 'axle_review_bad_decision', __( 'Choose Approve or Request changes.', 'axle-review' ), array( 'status' => 400 ) );
		}

		$name = mb_substr( trim( sanitize_text_field( $name ) ), 0, 100 );
		if ( '' === $name ) {
			return new \WP_Error( 'axle_review_name_required', __( 'Write your name first.', 'axle-review' ), array( 'status' => 400 ) );
		}

		$post = get_post( (int) $link['post_id'] );
		if ( ! $post ) {
			return new \WP_Error( 'axle_review_no_post', __( 'That post does not exist.', 'axle-review' ), array( 'status' => 404 ) );
		}

		$comment = Notes::clean_text( $comment );
		if ( self::CHANGES_REQUESTED === $decision && '' === $comment ) {
			return new \WP_Error( 'axle_review_comment_required', __( 'Say what should change.', 'axle-review' ), array( 'status' => 400 ) );
		}

		$current = Fingerprint::with_revision( $post );
		if ( '' !== $seen_hash && ! hash_equals( (string) $current['hash'], $seen_hash ) ) {
			return new \WP_Error( 'axle_review_changed_meanwhile', __( 'The draft changed while you were reading it. Reload the page to see what changed, then decide again.', 'axle-review' ), array( 'status' => 409 ) );
		}

		$previous = (string) $link['decision'];

		$note_id = $this->notes->save_decision_note( $link, $decision, $name, $comment );

		$this->links->update_columns(
			(int) $link['id'],
			array(
				'reviewer_name'     => $name,
				'decision'          => $decision,
				'decision_comment'  => $comment,
				'decided_at'        => gmdate( 'Y-m-d H:i:s' ),
				'decision_snapshot' => Fingerprint::encode( $current ),
				'decision_note_id'  => $note_id,
				'outdated_at'       => null,
				'reask_at'          => null,
			)
		);

		$this->events->add( (int) $link['id'], 'decision' );

		$fresh = $this->links->get( (int) $link['id'] );
		$fresh = $fresh ? $fresh : $link;

		if ( self::APPROVED === $decision ) {
			/**
			 * Fires after a reviewer approved a draft. Also fires when they change their mind back to approve.
			 *
			 * @param array    $link     The link, with the decision.
			 * @param \WP_Post $post     The post.
			 * @param string   $previous Their earlier decision, empty for the first.
			 */
			do_action( 'axle_review_approved', $fresh, $post, $previous );
		} else {
			/**
			 * Fires after a reviewer asked for changes.
			 *
			 * @param array    $link     The link, with the decision.
			 * @param \WP_Post $post     The post.
			 * @param string   $previous Their earlier decision, empty for the first.
			 */
			do_action( 'axle_review_changes_requested', $fresh, $post, $previous );
		}

		$note = $note_id ? Notes::get( $note_id ) : null;
		if ( $note ) {
			// Editors hear about it the same way they hear about a note: instant email, digest and Slack.
			do_action( 'axle_review_guest_note_created', $note, $fresh ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Same hook as a note left through a link.
		}

		Notes::flush();

		return $this->link_state( $fresh, $post, $current );
	}

	/**
	 * Notices an edit after approvals: marks the approvals outdated, or fresh
	 * again when the post is back to what was approved.
	 *
	 * @param int|string $post_id Post ID.
	 * @param mixed      $post    Post.
	 */
	public function post_saved( $post_id, $post = null ): void {
		$post = $post instanceof \WP_Post ? $post : get_post( (int) $post_id );
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		$this->recheck( $post );
	}

	/**
	 * Rechecks when the featured image changes, which a post save does not carry.
	 *
	 * @param int|string $meta_id Meta ID.
	 * @param int|string $post_id Post ID.
	 * @param string     $key     Meta key.
	 */
	public function meta_changed( $meta_id, $post_id, $key ): void {
		unset( $meta_id );
		if ( '_thumbnail_id' !== $key ) {
			return;
		}

		$post = get_post( (int) $post_id );
		if ( $post instanceof \WP_Post && ! wp_is_post_revision( $post ) ) {
			$this->recheck( $post );
		}
	}

	/**
	 * Compares every approval of a post with the post as it is now.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function recheck( \WP_Post $post ): void {
		// Every save on the site passes here: leave posts that cannot have links alone.
		if ( ! in_array( $post->post_type, Settings::post_types(), true ) || in_array( $post->post_status, array( 'auto-draft', 'inherit' ), true ) ) {
			return;
		}

		$approved = array();
		foreach ( $this->links->for_post( (int) $post->ID ) as $link ) {
			if ( self::APPROVED === $link['decision'] && 'revoked' !== $link['status'] ) {
				$approved[] = $link;
			}
		}

		if ( ! $approved ) {
			return;
		}

		$current = Fingerprint::of( $post );
		foreach ( $approved as $link ) {
			$old   = Fingerprint::decode( (string) $link['decision_snapshot'] );
			$same  = $old && hash_equals( (string) $old['hash'], (string) $current['hash'] );
			$stale = '' !== $link['outdated_at'];

			if ( $same && $stale ) {
				$this->links->update_columns( (int) $link['id'], array( 'outdated_at' => null ) );
			} elseif ( ! $same && ! $stale ) {
				$this->links->update_columns( (int) $link['id'], array( 'outdated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
				$link['outdated_at'] = gmdate( 'Y-m-d H:i:s' );
				$changes             = $old ? Fingerprint::compare( $old, $current ) : array();

				/**
				 * Fires once when a post was edited after a reviewer approved it.
				 *
				 * @param array    $link    The link, with the decision.
				 * @param \WP_Post $post    The post as it is now.
				 * @param array    $changes What changed since the approval (Fingerprint::compare()).
				 */
				do_action( 'axle_review_approval_outdated', $link, $post, $changes );
			}
		}

		Notes::flush();
	}

	/**
	 * The state of one link's decision against the post as it is now.
	 *
	 * @param array      $link    Link.
	 * @param \WP_Post   $post    Post.
	 * @param array|null $current Snapshot of the post now, to save work for many links.
	 * @return array
	 */
	public function link_state( array $link, \WP_Post $post, ?array $current = null ): array {
		$current  = $current ? $current : Fingerprint::of( $post );
		$decision = (string) $link['decision'];
		$old      = '' === $decision ? null : Fingerprint::decode( (string) $link['decision_snapshot'] );
		$edited   = '' !== $decision && ( ! $old || ! hash_equals( (string) $old['hash'], (string) $current['hash'] ) );
		$changes  = $edited && $old ? self::describe( Fingerprint::compare( $old, $current ), (int) $post->ID, $old ) : null;

		return array(
			'link'     => (int) $link['id'],
			'label'    => '' === $link['label'] ? sprintf( /* translators: %d: link ID. */ __( 'Link %d', 'axle-review' ), (int) $link['id'] ) : (string) $link['label'],
			'status'   => (string) $link['status'],
			'email'    => (string) $link['email'],
			'reviewer' => (string) $link['reviewer_name'],
			'decision' => $decision,
			'comment'  => (string) $link['decision_comment'],
			'decided'  => Links::iso( (string) $link['decided_at'] ),
			'edited'   => $edited,
			'outdated' => $edited && self::APPROVED === $decision,
			'asked'    => Links::iso( (string) $link['reask_at'] ),
			'changes'  => $changes,
			'can_ask'  => $edited && 'active' === $link['status'],
		);
	}

	/**
	 * Makes a comparison readable: labels, and the old text where revisions have it.
	 *
	 * @param array $diff    Result of Fingerprint::compare().
	 * @param int   $post_id Post ID.
	 * @param array $old     The older snapshot.
	 * @return array{changed: array, added: array, removed: array, meta: string[], count: int}
	 */
	public static function describe( array $diff, int $post_id, array $old ): array {
		$paths = array();
		foreach ( array_merge( $diff['changed'], $diff['removed'] ) as $item ) {
			$paths[] = $item['path'];
		}
		$before = Fingerprint::previous_text( $post_id, $old, $paths );

		$convert = static function ( array $items, bool $with_before ) use ( $before ): array {
			return array_map(
				static function ( $item ) use ( $before, $with_before ) {
					$out = array(
						'path'  => (string) $item['path'],
						'name'  => (string) $item['name'],
						'label' => Fingerprint::block_label( (string) $item['name'] ),
					);
					if ( $with_before && isset( $before[ $item['path'] ] ) ) {
						$out['before'] = $before[ $item['path'] ];
					}
					return $out;
				},
				array_slice( $items, 0, 200 )
			);
		};

		return array(
			'changed' => $convert( $diff['changed'], true ),
			'added'   => $convert( $diff['added'], false ),
			'removed' => $convert( $diff['removed'], true ),
			'meta'    => Fingerprint::meta_labels( $diff['meta'] ),
			'count'   => (int) $diff['count'],
		);
	}

	/**
	 * Every live link of a post with its decision, newest first, and the totals.
	 *
	 * @param \WP_Post $post Post.
	 * @return array{post: int, revisions: bool, summary: array, text: string, decisions: array}
	 */
	public function post_state( \WP_Post $post ): array {
		$current   = Fingerprint::of( $post );
		$decisions = array();
		$counts    = array(
			'reviewers' => 0,
			'approved'  => 0,
			'outdated'  => 0,
			'changes'   => 0,
			'waiting'   => 0,
		);
		$names     = array();

		foreach ( $this->links->for_post( (int) $post->ID ) as $link ) {
			if ( 'revoked' === $link['status'] ) {
				continue;
			}
			if ( '' === $link['decision'] && 'active' !== $link['status'] ) {
				continue;
			}

			$state       = $this->link_state( $link, $post, $current );
			$decisions[] = $state;
			++$counts['reviewers'];

			if ( '' === $state['decision'] ) {
				++$counts['waiting'];
			} elseif ( self::CHANGES_REQUESTED === $state['decision'] ) {
				++$counts['changes'];
				$names[] = $state['reviewer'];
			} elseif ( $state['outdated'] ) {
				++$counts['outdated'];
			} else {
				++$counts['approved'];
			}
		}

		return array(
			'post'      => (int) $post->ID,
			'revisions' => Fingerprint::revisions_on( $post ),
			'summary'   => $counts,
			'text'      => self::summary_text( $counts, $names ),
			'decisions' => $decisions,
		);
	}

	/**
	 * The one-line review state, such as "2 of 3 approved".
	 *
	 * @param array    $counts reviewers, approved, outdated, changes, waiting.
	 * @param string[] $names  Who asked for changes.
	 * @return string
	 */
	public static function summary_text( array $counts, array $names = array() ): string {
		$text = '';

		if ( (int) $counts['changes'] > 0 ) {
			$names = array_values( array_filter( $names ) );
			if ( count( $names ) > 1 ) {
				/* translators: 1: first reviewer, 2: number of other reviewers. */
				$text = sprintf( __( 'Changes requested by %1$s and %2$d more', 'axle-review' ), $names[0], count( $names ) - 1 );
			} elseif ( $names ) {
				/* translators: %s: reviewer name. */
				$text = sprintf( __( 'Changes requested by %s', 'axle-review' ), $names[0] );
			} else {
				$text = __( 'Changes requested', 'axle-review' );
			}
		} elseif ( (int) $counts['reviewers'] > 0 && ( (int) $counts['approved'] + (int) $counts['outdated'] ) > 0 ) {
			/* translators: 1: approvals, 2: reviewers. */
			$text = sprintf( __( '%1$d of %2$d approved', 'axle-review' ), (int) $counts['approved'], (int) $counts['reviewers'] );
			if ( (int) $counts['outdated'] > 0 ) {
				/* translators: %d: number of approvals that are out of date. */
				$text .= ', ' . sprintf( _n( '%d edited since approval', '%d edited since approval', (int) $counts['outdated'], 'axle-review' ), (int) $counts['outdated'] );
			}
		} elseif ( (int) $counts['reviewers'] > 0 ) {
			/* translators: %d: reviewers who have not decided. */
			$text = sprintf( _n( 'Waiting for %d reviewer', 'Waiting for %d reviewers', (int) $counts['reviewers'], 'axle-review' ), (int) $counts['reviewers'] );
		}

		/**
		 * Filters the one-line review state shown in the editor and on the posts list.
		 *
		 * @param string $text   The text, empty when nobody was asked.
		 * @param array  $counts reviewers, approved, outdated, changes, waiting.
		 */
		return (string) apply_filters( 'axle_review_state_text', $text, $counts );
	}

	/**
	 * Approvals that count towards the gate.
	 *
	 * @param \WP_Post   $post      Post, as it will be after the save.
	 * @param array|null $current   Snapshot of that post.
	 * @return array{required: int, counted: int, approved: int, outdated: int, fresh_only: bool, met: bool}
	 */
	public function gate_count( \WP_Post $post, ?array $current = null ): array {
		$required   = self::required_approvals();
		$fresh_only = self::fresh_only();
		$current    = $current ? $current : Fingerprint::of( $post );
		$approved   = 0;
		$outdated   = 0;

		foreach ( $this->links->for_post( (int) $post->ID ) as $link ) {
			if ( self::APPROVED !== $link['decision'] || 'revoked' === $link['status'] ) {
				continue;
			}

			++$approved;
			$old = Fingerprint::decode( (string) $link['decision_snapshot'] );
			if ( ! $old || ! hash_equals( (string) $old['hash'], (string) $current['hash'] ) ) {
				++$outdated;
			}
		}

		$counted = $fresh_only ? $approved - $outdated : $approved;

		return array(
			'required'   => $required,
			'counted'    => $counted,
			'approved'   => $approved,
			'outdated'   => $outdated,
			'fresh_only' => $fresh_only,
			'met'        => $counted >= $required,
		);
	}

	/**
	 * How many approvals the gate wants.
	 *
	 * @return int 0 for none.
	 */
	public static function required_approvals(): int {
		$settings = get_option( Settings::OPTION, array() );
		$settings = is_array( $settings ) ? $settings : array();
		$value    = (int) ( $settings['gate_min_approvals'] ?? 0 );

		/**
		 * Filters how many reviewer approvals the publish gate wants. 0 means none.
		 *
		 * @param int $required From the setting, default 0, between 0 and 20.
		 */
		return max( 0, min( 20, (int) apply_filters( 'axle_review_gate_min_approvals', $value ) ) );
	}

	/**
	 * Whether the gate ignores approvals of an older version of the post.
	 *
	 * @return bool
	 */
	public static function fresh_only(): bool {
		$settings = get_option( Settings::OPTION, array() );
		$settings = is_array( $settings ) ? $settings : array();
		$value    = isset( $settings['gate_fresh_only'] ) ? (bool) $settings['gate_fresh_only'] : true;

		/**
		 * Filters whether approvals of an older version of the post stop counting for the gate.
		 *
		 * @param bool $fresh_only From the setting, default true.
		 */
		return (bool) apply_filters( 'axle_review_gate_fresh_only', $value );
	}

	/**
	 * Asks a reviewer to look again: the link gets a new URL, the baseline for
	 * what they see is their decision, and an email goes out when the link has
	 * an address.
	 *
	 * @param int $link_id Link ID.
	 * @param int $by      User ID.
	 * @return array{url: string, emailed: bool, link: array}|\WP_Error
	 */
	public function ask_again( int $link_id, int $by ) {
		$link = $this->links->get( $link_id );
		if ( ! $link ) {
			return new \WP_Error( 'axle_review_no_link', __( 'That link does not exist.', 'axle-review' ), array( 'status' => 404 ) );
		}

		if ( '' === $link['decision'] ) {
			return new \WP_Error( 'axle_review_no_decision', __( 'This reviewer has not decided yet, so there is nothing to ask again.', 'axle-review' ), array( 'status' => 409 ) );
		}

		$rotated = $this->links->rotate_token( $link_id );
		if ( is_wp_error( $rotated ) ) {
			return $rotated;
		}

		/**
		 * Filters how many days a link has left at least after it was sent again.
		 *
		 * @param int $days Default 3, between 1 and 30.
		 */
		$days    = max( 1, min( 30, (int) apply_filters( 'axle_review_reask_days', 3 ) ) );
		$minimum = time() + min( $days * DAY_IN_SECONDS, Settings::max_expiry() );
		$expires = max( (int) strtotime( (string) $link['expires_at'] . ' UTC' ), $minimum );

		$this->links->update_columns(
			$link_id,
			array(
				'reask_at'   => gmdate( 'Y-m-d H:i:s' ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', $expires ),
			)
		);

		$fresh   = $this->links->get( $link_id );
		$fresh   = $fresh ? $fresh : $link;
		$emailed = '' !== $fresh['email'] && is_email( $fresh['email'] ) && self::email_reask( (string) $fresh['email'], $rotated['url'], $fresh, $by );

		/**
		 * Fires after a reviewer was asked to look again.
		 *
		 * @param array $link    The link.
		 * @param int   $by      Who asked.
		 * @param bool  $emailed Whether an email went to the link's address.
		 */
		do_action( 'axle_review_reask_sent', $fresh, $by, $emailed );

		return array(
			'url'     => $rotated['url'],
			'emailed' => $emailed,
			'link'    => $fresh,
		);
	}

	/**
	 * Emails a reviewer the new URL.
	 *
	 * @param string $to   Address.
	 * @param string $url  New URL.
	 * @param array  $link Link.
	 * @param int    $by   Who asked.
	 * @return bool
	 */
	public static function email_reask( string $to, string $url, array $link, int $by ): bool {
		$post  = get_post( (int) $link['post_id'] );
		$user  = get_userdata( $by );
		$site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$title = $post ? wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ) : '';

		/* translators: 1: site name, 2: post title. */
		$subject = sprintf( __( '[%1$s] "%2$s" changed. Please look again', 'axle-review' ), $site, $title );
		$message = sprintf(
			/* translators: 1: sender name, 2: post title, 3: link. */
			__( "%1\$s edited \"%2\$s\" after you gave your answer, and would like you to look again.\n\nOpen it here. The page shows only what changed:\n%3\$s\n\nThis is a new link. The old one no longer works.", 'axle-review' ),
			$user ? $user->display_name : $site,
			$title,
			$url
		);

		$context = array(
			'link' => $link,
			'url'  => $url,
		);

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- One email, sent by an editor to a reviewer.
		return (bool) wp_mail( $to, Alerts::subject( $subject, 'reask', $context ), Alerts::message( $message, 'reask', $context ) );
	}
}
