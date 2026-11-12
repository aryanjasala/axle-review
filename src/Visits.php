<?php
/**
 * What changed since a reviewer last looked.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review;

defined( 'ABSPATH' ) || exit;

/**
 * Each link keeps two snapshots of the post (see Fingerprint): the one from
 * the reviewer's last finished visit, which is the baseline for the current
 * visit, and the one from the latest page load. A new session promotes the
 * latest load to baseline, so reloading the page keeps the highlights, and
 * the next visit starts fresh.
 *
 * When the reviewer was asked to look again, the baseline is their decision,
 * so they see everything edited since they answered.
 *
 * Old text comes from core revisions. With revisions off, only the blocks
 * that changed are known, not what they said before.
 */
final class Visits {

	/**
	 * Links.
	 *
	 * @var Links
	 */
	private Links $links;

	/**
	 * Sets up visits.
	 *
	 * @param Links $links Links.
	 */
	public function __construct( Links $links ) {
		$this->links = $links;
	}

	/**
	 * Records a page load and works out what changed since the baseline.
	 *
	 * @param array    $link        The link.
	 * @param \WP_Post $post        The post being shown.
	 * @param bool     $new_session Whether this load started a new session.
	 * @return array{hash: string, since: string, changes: array|null, revisions: bool}
	 */
	public function record( array $link, \WP_Post $post, bool $new_session ): array {
		$current = Fingerprint::of( $post );
		$columns = array();

		$last = Fingerprint::decode( (string) $link['visit_snapshot'] );
		$seen = Fingerprint::decode( (string) $link['seen_snapshot'] );
		$when = (string) $link['seen_at'];

		if ( $new_session && $last ) {
			$seen                     = $last;
			$when                     = (string) $link['visit_at'];
			$columns['seen_snapshot'] = (string) $link['visit_snapshot'];
			$columns['seen_at']       = (string) $link['visit_at'];
		}

		$asked = '' !== (string) $link['reask_at'] ? Fingerprint::decode( (string) $link['decision_snapshot'] ) : null;
		if ( $asked ) {
			$seen = $asked;
			$when = (string) $link['decided_at'];
		}

		if ( ! $last || ! hash_equals( (string) $last['hash'], (string) $current['hash'] ) ) {
			$stored                    = $current;
			$stored['revision']        = Fingerprint::matching_revision( $post );
			$columns['visit_snapshot'] = Fingerprint::encode( $stored );
			$columns['visit_at']       = gmdate( 'Y-m-d H:i:s' );
		} elseif ( $new_session ) {
			$columns['visit_at'] = gmdate( 'Y-m-d H:i:s' );
		}

		if ( $columns ) {
			$this->links->update_columns( (int) $link['id'], $columns );
		}

		$changes = null;
		if ( $seen ) {
			$diff = Fingerprint::compare( $seen, $current );
			if ( $diff['count'] > 0 ) {
				$changes = Approvals::describe( $diff, (int) $post->ID, $seen );
			}
		}

		/**
		 * Filters what the review page shows as changed since the reviewer's last look.
		 *
		 * @param array|null $changes changed, added, removed, meta and count, or null when nothing changed or this is the first visit.
		 * @param array      $link    The link.
		 * @param \WP_Post   $post    The post.
		 */
		$changes = apply_filters( 'axle_review_visit_changes', $changes, $link, $post );

		return array(
			'hash'      => (string) $current['hash'],
			'since'     => Links::iso( $when ),
			'changes'   => is_array( $changes ) ? $changes : null,
			'revisions' => Fingerprint::revisions_on( $post ),
		);
	}
}
