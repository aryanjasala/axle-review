<?php
/**
 * Site Health checks.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Health;

use AryanX\Axle\Review\Admin\LinksPage;
use AryanX\Axle\Review\Alerts;
use AryanX\Axle\Review\Gate;
use AryanX\Axle\Review\Links;
use AryanX\Axle\Review\Rest\GuestRoutes;
use AryanX\Axle\Review\Schema;
use AryanX\Axle\Review\Service;
use AryanX\Axle\Review\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Adds an Axle Review test to Tools > Site Health.
 *
 * Critical when the tables are missing. Recommended when links live longer
 * than 30 days, the Slack webhook fails, a page cache served a review page,
 * or the gate holds posts back for over two weeks.
 */
final class SiteHealth implements Service {

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
	 * Gate.
	 *
	 * @var Gate
	 */
	private Gate $gate;

	/**
	 * Sets up the check.
	 *
	 * @param Settings $settings Settings.
	 * @param Links    $links    Links.
	 * @param Gate     $gate     Gate.
	 */
	public function __construct( Settings $settings, Links $links, Gate $gate ) {
		$this->settings = $settings;
		$this->links    = $links;
		$this->gate     = $gate;
	}

	/**
	 * Registers the test.
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_test' ) );
	}

	/**
	 * Adds the test.
	 *
	 * @param array $tests Tests.
	 * @return array
	 */
	public function add_test( $tests ): array {
		$tests                          = (array) $tests;
		$tests['direct']['axle_review'] = array(
			'label' => __( 'Axle Review', 'axle-review' ),
			'test'  => array( $this, 'run' ),
		);

		return $tests;
	}

	/**
	 * The problems found, each [ severity, text ].
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	public function problems(): array {
		$problems = array();

		if ( ! Schema::tables_exist() ) {
			$problems[] = array( 'critical', __( 'The review link tables are missing, so review links cannot work. Deactivate and activate Axle Review to create them.', 'axle-review' ) );
			return $problems;
		}

		$long = $this->links->count_long_lived();
		if ( $long ) {
			/* translators: %d: number of links. */
			$problems[] = array( 'recommended', sprintf( _n( '%d review link works for more than 30 days. Long-lived links to drafts are easy to forget; revoke it if it is no longer needed.', '%d review links work for more than 30 days. Long-lived links to drafts are easy to forget; revoke the ones no longer needed.', $long, 'axle-review' ), $long ) );
		}

		$slack = get_option( Alerts::SLACK_STATUS );
		if ( '' !== Settings::slack_webhook() && is_array( $slack ) && empty( $slack['ok'] ) ) {
			$problems[] = array( 'recommended', __( 'The Slack webhook is set, but the last message to it failed. Check the URL in Settings > Writing > Review.', 'axle-review' ) );
		}

		$cached = (int) get_option( GuestRoutes::CACHE_WARN_OPTION );
		if ( $cached && $cached > time() - 7 * DAY_IN_SECONDS ) {
			$problems[] = array( 'recommended', __( 'A page cache served a review page that had already been served before. Review pages send no-store headers; set your cache or CDN to skip URLs with the axle_review query parameter, or drafts may reach people without the link.', 'axle-review' ) );
		}

		$stuck = $this->stuck_posts();
		if ( $stuck ) {
			/* translators: %d: number of posts. */
			$problems[] = array( 'recommended', sprintf( _n( 'The publish gate holds back %d post that has not changed in two weeks. Its open notes may need someone to resolve them.', 'The publish gate holds back %d posts that have not changed in two weeks. Their open notes may need someone to resolve them.', $stuck, 'axle-review' ), $stuck ) );
		}

		return $problems;
	}

	/**
	 * Gated drafts with open notes, untouched for two weeks.
	 *
	 * @return int
	 */
	private function stuck_posts(): int {
		if ( ! $this->settings->get( 'gate_enabled' ) ) {
			return 0;
		}

		$posts = get_posts(
			array(
				'post_type'      => Settings::post_types(),
				'post_status'    => array( 'draft', 'pending' ),
				'posts_per_page' => 50,
				'date_query'     => array(
					array(
						'column' => 'post_modified_gmt',
						'before' => gmdate( 'Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS ),
					),
				),
				'no_found_rows'  => true,
			)
		);

		$count = 0;
		foreach ( $posts as $post ) {
			if ( $this->gate->readiness( $post )['blocks'] ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Runs the test.
	 *
	 * @return array
	 */
	public function run(): array {
		$problems = $this->problems();
		$status   = 'good';
		foreach ( $problems as $problem ) {
			if ( 'critical' === $problem[0] ) {
				$status = 'critical';
			} elseif ( 'good' === $status ) {
				$status = 'recommended';
			}
		}

		$description = '<p>' . esc_html__( 'Axle Review shares drafts through private links and collects feedback as block notes.', 'axle-review' ) . '</p>';
		if ( $problems ) {
			$description .= '<ul>';
			foreach ( $problems as $problem ) {
				$description .= '<li>' . esc_html( $problem[1] ) . '</li>';
			}
			$description .= '</ul>';
		}

		return array(
			'label'       => $problems ? __( 'Axle Review needs attention', 'axle-review' ) : __( 'Review links are set up safely', 'axle-review' ),
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Security', 'axle-review' ),
				'color' => 'blue',
			),
			'description' => $description,
			'actions'     => sprintf( '<p><a href="%1$s">%2$s</a></p>', esc_url( LinksPage::url() ), esc_html__( 'Review Links', 'axle-review' ) ),
			'test'        => 'axle_review',
		);
	}
}
