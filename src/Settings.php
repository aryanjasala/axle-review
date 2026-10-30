<?php
/**
 * Settings, defaults and the capability filters.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the `axle_review_settings` option and runs every default and limit
 * through its filter, with the result sanitised.
 */
final class Settings {

	public const OPTION        = 'axle_review_settings';
	public const PAUSED_OPTION = 'axle_review_paused';
	public const NETWORK_PAUSE = 'axle_review_network_paused';
	public const SLACK_OPTION  = 'axle_review_slack';

	/**
	 * The stored settings merged with the defaults.
	 *
	 * @return array{gate_enabled: bool, gate_post_types: string[], gate_guest_notes: bool, gate_orphans: bool, default_expiry: int, default_max_views: int, guest_sees_others: bool}
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return self::sanitize( array_merge( self::defaults(), $stored ) );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public function get( string $key ) {
		$all = $this->all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Saves settings, merged into the stored ones.
	 *
	 * @param array $changes Settings to change.
	 * @return array The saved settings.
	 */
	public function update( array $changes ): array {
		$settings = self::sanitize( array_merge( $this->all(), $changes ) );
		update_option( self::OPTION, $settings, false );
		return $settings;
	}

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'gate_enabled'      => false,
			'gate_post_types'   => array(),
			'gate_guest_notes'  => true,
			'gate_orphans'      => true,
			'default_expiry'    => 7 * DAY_IN_SECONDS,
			'default_max_views' => 0,
			'guest_sees_others' => false,
		);
	}

	/**
	 * Cleans a settings array.
	 *
	 * @param mixed $input Raw settings.
	 * @return array{gate_enabled: bool, gate_post_types: string[], gate_guest_notes: bool, gate_orphans: bool, default_expiry: int, default_max_views: int, guest_sees_others: bool}
	 */
	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$types    = isset( $input['gate_post_types'] ) ? (array) $input['gate_post_types'] : array();

		return array(
			'gate_enabled'      => ! empty( $input['gate_enabled'] ),
			'gate_post_types'   => array_values( array_unique( array_filter( array_map( 'sanitize_key', array_map( 'strval', $types ) ) ) ) ),
			'gate_guest_notes'  => isset( $input['gate_guest_notes'] ) ? (bool) $input['gate_guest_notes'] : $defaults['gate_guest_notes'],
			'gate_orphans'      => isset( $input['gate_orphans'] ) ? (bool) $input['gate_orphans'] : $defaults['gate_orphans'],
			'default_expiry'    => max( HOUR_IN_SECONDS, min( 30 * DAY_IN_SECONDS, (int) ( $input['default_expiry'] ?? $defaults['default_expiry'] ) ) ),
			'default_max_views' => max( 0, min( 1000, (int) ( $input['default_max_views'] ?? 0 ) ) ),
			'guest_sees_others' => ! empty( $input['guest_sees_others'] ),
		);
	}

	/**
	 * A capability from its filter, falling back to the default when the
	 * filter returns something unusable.
	 *
	 * @param string $name    Filter suffix: capability, inbox_capability, settings_capability, gate_override_capability.
	 * @param string $fallback Default capability.
	 * @return string
	 */
	private static function capability_filter( string $name, string $fallback ): string {
		$capability = (string) apply_filters( 'axle_review_' . $name, $fallback );
		return '' !== $capability ? $capability : $fallback;
	}

	/**
	 * Whether the current user may create and manage review links for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function can_share( int $post_id ): bool {
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return false;
		}

		/**
		 * Filters the capability needed to create and revoke review links for a post.
		 * It is checked as a meta capability with the post ID.
		 *
		 * @param string $capability Default edit_post.
		 * @param int    $post_id    Post ID.
		 */
		$capability = (string) apply_filters( 'axle_review_capability', 'edit_post', $post_id );
		$capability = '' !== $capability ? $capability : 'edit_post';

		return current_user_can( $capability, $post_id );
	}

	/**
	 * Who sees the Notes inbox.
	 *
	 * @return string
	 */
	public static function inbox_capability(): string {
		/**
		 * Filters the capability for Dashboard > Notes. Each row is still
		 * checked with edit_post on its post.
		 *
		 * @param string $capability Default edit_posts.
		 */
		return self::capability_filter( 'inbox_capability', 'edit_posts' );
	}

	/**
	 * Who changes the settings, sees Tools > Review Links for every post and
	 * pauses links.
	 *
	 * @return string
	 */
	public static function settings_capability(): string {
		/**
		 * Filters the capability for the settings, Tools > Review Links and pausing links.
		 *
		 * @param string $capability Default manage_options.
		 */
		return self::capability_filter( 'settings_capability', 'manage_options' );
	}

	/**
	 * Who may publish past the gate, with a reason.
	 *
	 * @return string
	 */
	public static function gate_override_capability(): string {
		/**
		 * Filters the capability to publish with open notes, giving a reason.
		 *
		 * @param string $capability Default edit_others_posts (editors and administrators).
		 */
		return self::capability_filter( 'gate_override_capability', 'edit_others_posts' );
	}

	/**
	 * Post types that get review links, the gate and the Notes column: the
	 * ones with Notes support.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		$types = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'names' ) as $type ) {
			if ( post_type_supports( (string) $type, 'editor' ) && self::supports_notes( (string) $type ) ) {
				$types[] = (string) $type;
			}
		}

		/**
		 * Filters the post types Axle Review works on.
		 *
		 * @param string[] $types Default: post types with Notes support (post and page in core).
		 */
		$types = (array) apply_filters( 'axle_review_post_types', $types );

		return array_values( array_filter( array_map( 'strval', $types ), 'post_type_exists' ) );
	}

	/**
	 * Whether a post type has Notes support: post and page do in core, others
	 * through 'editor' => array( 'notes' => true ) in supports.
	 *
	 * @param string $type Post type.
	 * @return bool
	 */
	public static function supports_notes( string $type ): bool {
		if ( in_array( $type, array( 'post', 'page' ), true ) ) {
			return true;
		}

		$supports = get_all_post_type_supports( $type );
		$editor   = $supports['editor'] ?? null;
		if ( is_array( $editor ) ) {
			foreach ( $editor as $item ) {
				if ( is_array( $item ) && ! empty( $item['notes'] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Expiry choices for new links, seconds => label.
	 *
	 * @return array<int, string>
	 */
	public static function expiry_choices(): array {
		$choices = array(
			HOUR_IN_SECONDS     => __( '1 hour', 'axle-review' ),
			DAY_IN_SECONDS      => __( '1 day', 'axle-review' ),
			3 * DAY_IN_SECONDS  => __( '3 days', 'axle-review' ),
			7 * DAY_IN_SECONDS  => __( '7 days', 'axle-review' ),
			14 * DAY_IN_SECONDS => __( '14 days', 'axle-review' ),
			30 * DAY_IN_SECONDS => __( '30 days', 'axle-review' ),
		);

		/**
		 * Filters the expiry choices offered for new links.
		 *
		 * @param array<int, string> $choices Seconds => label.
		 */
		$choices = (array) apply_filters( 'axle_review_expiry_choices', $choices );

		$clean = array();
		$max   = self::max_expiry();
		foreach ( $choices as $seconds => $label ) {
			$seconds = (int) $seconds;
			if ( $seconds >= MINUTE_IN_SECONDS && $seconds <= $max ) {
				$clean[ $seconds ] = (string) $label;
			}
		}
		ksort( $clean );

		return $clean ? $clean : array( 7 * DAY_IN_SECONDS => __( '7 days', 'axle-review' ) );
	}

	/**
	 * The default expiry of a new link, in seconds.
	 *
	 * @return int
	 */
	public function default_expiry(): int {
		/**
		 * Filters the default expiry of a new link.
		 *
		 * @param int $seconds Default from the setting, 7 days.
		 */
		$seconds = (int) apply_filters( 'axle_review_default_expiry', (int) $this->get( 'default_expiry' ) );

		return max( MINUTE_IN_SECONDS, min( self::max_expiry(), $seconds ) );
	}

	/**
	 * The longest a link may live, in seconds.
	 *
	 * @return int
	 */
	public static function max_expiry(): int {
		/**
		 * Filters the longest expiry a link may have.
		 *
		 * @param int $seconds Default 30 days. At least one hour.
		 */
		return max( HOUR_IN_SECONDS, (int) apply_filters( 'axle_review_max_expiry', 30 * DAY_IN_SECONDS ) );
	}

	/**
	 * The default view limit of a new link.
	 *
	 * @return int 0 for unlimited.
	 */
	public function default_max_views(): int {
		/**
		 * Filters the default view limit of a new link. 0 is unlimited, 1 is view once.
		 *
		 * @param int $views Default from the setting, 0.
		 */
		return max( 0, (int) apply_filters( 'axle_review_default_max_views', (int) $this->get( 'default_max_views' ) ) );
	}

	/**
	 * Whether a link lets its guests see notes other guests left.
	 *
	 * @param bool $share   The link's own setting.
	 * @param int  $link_id Link ID.
	 * @return bool
	 */
	public static function guest_sees_others( bool $share, int $link_id ): bool {
		/**
		 * Filters whether a reviewer sees notes other reviewers left on the post.
		 * Editors' notes stay hidden either way; replies to the reviewer's own
		 * threads are always shown.
		 *
		 * @param bool $share   Default: the link's setting, off unless chosen.
		 * @param int  $link_id Link ID.
		 */
		return (bool) apply_filters( 'axle_review_guest_sees_others', $share, $link_id );
	}

	/**
	 * Whether every link on this site is paused (here or network wide).
	 *
	 * @return bool
	 */
	public static function paused(): bool {
		if ( get_option( self::PAUSED_OPTION ) ) {
			return true;
		}

		return is_multisite() && (bool) get_site_option( self::NETWORK_PAUSE );
	}

	/**
	 * Pauses or resumes every link on this site.
	 *
	 * @param bool $paused Paused.
	 */
	public static function set_paused( bool $paused ): void {
		if ( $paused ) {
			update_option(
				self::PAUSED_OPTION,
				array(
					'by' => get_current_user_id(),
					'at' => time(),
				),
				false
			);

			/**
			 * Fires after every review link on the site was paused.
			 *
			 * @param int $user_id Who paused them.
			 */
			do_action( 'axle_review_links_paused', get_current_user_id() );
			return;
		}

		delete_option( self::PAUSED_OPTION );

		/**
		 * Fires after review links were resumed.
		 *
		 * @param int $user_id Who resumed them.
		 */
		do_action( 'axle_review_links_resumed', get_current_user_id() );
	}

	/**
	 * The Slack webhook URL, or an empty string.
	 *
	 * @return string
	 */
	public static function slack_webhook(): string {
		$url = get_option( self::SLACK_OPTION, '' );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * A webhook URL with all but the last four characters masked, for screens.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function mask( string $url ): string {
		if ( '' === $url ) {
			return '';
		}

		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return 'https://' . $host . '/' . str_repeat( '•', 8 ) . substr( $url, -4 );
	}
}
