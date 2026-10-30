<?php
/**
 * Settings > Writing > Review.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review\Admin;

use AryanX\Axle\Review\Alerts;
use AryanX\Axle\Review\Service;
use AryanX\Axle\Review\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The gate, link defaults and Slack, in a section of Settings > Writing.
 */
final class SettingsSection implements Service {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up the section.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'settings' ) );
	}

	/**
	 * Registers the settings and the section.
	 */
	public function settings(): void {
		register_setting(
			'writing',
			Settings::OPTION,
			array(
				'type'              => 'object',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'show_in_rest'      => false,
				'default'           => Settings::defaults(),
			)
		);
		register_setting(
			'writing',
			Settings::SLACK_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_slack' ),
				'show_in_rest'      => false,
				'default'           => '',
			)
		);

		if ( ! current_user_can( Settings::settings_capability() ) ) {
			return;
		}

		add_settings_section(
			'axle_review',
			__( 'Review', 'axle-review' ),
			array( $this, 'intro' ),
			'writing',
			array(
				'before_section' => '<div id="axle-review" class="axr-settings">',
				'after_section'  => '</div>',
			)
		);

		add_settings_field( 'axr_gate', __( 'Publish gate', 'axle-review' ), array( $this, 'field_gate' ), 'writing', 'axle_review' );
		add_settings_field( 'axr_links', __( 'New review links', 'axle-review' ), array( $this, 'field_links' ), 'writing', 'axle_review' );
		add_settings_field( 'axr_slack', __( 'Slack', 'axle-review' ), array( $this, 'field_slack' ), 'writing', 'axle_review', array( 'label_for' => 'axr-slack' ) );
	}

	/**
	 * Sanitises the settings array, keeping the stored value when a user
	 * without the settings capability posts the form.
	 *
	 * @param mixed $input Posted settings.
	 * @return array
	 */
	public function sanitize( $input ): array {
		if ( ! current_user_can( Settings::settings_capability() ) ) {
			return $this->settings->all();
		}

		$input = is_array( $input ) ? $input : array();
		// Unchecked boxes are absent from the form.
		foreach ( array( 'gate_enabled', 'gate_guest_notes', 'gate_orphans', 'guest_sees_others' ) as $box ) {
			$input[ $box ] = ! empty( $input[ $box ] );
		}

		return Settings::sanitize( $input );
	}

	/**
	 * Sanitises the webhook: a Slack https URL, empty, or the stored one when
	 * the masked value comes back unchanged.
	 *
	 * @param mixed $input Posted URL.
	 * @return string
	 */
	public function sanitize_slack( $input ): string {
		$stored = Settings::slack_webhook();
		if ( ! current_user_can( Settings::settings_capability() ) ) {
			return $stored;
		}

		$input = trim( (string) $input );
		if ( '' === $input ) {
			return '';
		}

		if ( Settings::mask( $stored ) === $input ) {
			return $stored;
		}

		$url  = esc_url_raw( $input, array( 'https' ) );
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $url || ! preg_match( '/(^|\.)slack\.com$/', $host ) ) {
			add_settings_error( Settings::SLACK_OPTION, 'axle_review_slack', __( 'The Slack webhook must be an https://hooks.slack.com/ URL. The old value was kept.', 'axle-review' ) );
			return $stored;
		}

		return $url;
	}

	/**
	 * Section intro.
	 */
	public function intro(): void {
		printf(
			'<p>%1$s <a href="%2$s">%3$s</a></p>',
			esc_html__( 'Share drafts for review and collect feedback as block notes. Each user picks their own email alerts on their profile.', 'axle-review' ),
			esc_url( LinksPage::url() ),
			esc_html__( 'Tools > Review Links', 'axle-review' )
		);
	}

	/**
	 * Gate fields.
	 */
	public function field_gate(): void {
		$settings = $this->settings->all();
		$name     = Settings::OPTION;

		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Publish gate', 'axle-review' ) . '</legend>';
		printf( '<label><input type="checkbox" id="axr-gate-enabled" name="%1$s[gate_enabled]" value="1"%2$s> %3$s</label><br>', esc_attr( $name ), checked( $settings['gate_enabled'], true, false ), esc_html__( 'Do not publish posts with open notes', 'axle-review' ) );
		printf( '<label><input type="checkbox" name="%1$s[gate_guest_notes]" value="1"%2$s> %3$s</label><br>', esc_attr( $name ), checked( $settings['gate_guest_notes'], true, false ), esc_html__( 'Count notes from reviewers', 'axle-review' ) );
		printf( '<label><input type="checkbox" name="%1$s[gate_orphans]" value="1"%2$s> %3$s</label>', esc_attr( $name ), checked( $settings['gate_orphans'], true, false ), esc_html__( 'Count notes whose block was deleted', 'axle-review' ) );

		echo '<p class="description">' . esc_html__( 'Post types (none checked means all):', 'axle-review' ) . '</p>';
		foreach ( Settings::post_types() as $type ) {
			$object = get_post_type_object( $type );
			printf(
				'<label class="axr-inline"><input type="checkbox" name="%1$s[gate_post_types][]" value="%2$s"%3$s> %4$s</label> ',
				esc_attr( $name ),
				esc_attr( $type ),
				checked( in_array( $type, $settings['gate_post_types'], true ), true, false ),
				esc_html( $object ? (string) $object->labels->name : $type )
			);
		}
		echo '<p class="description">' . esc_html__( 'People who can edit others\' posts can still publish with open notes after giving a reason, which is added as a reply to each open note. Scheduled posts are checked when they are scheduled.', 'axle-review' ) . '</p>';
		echo '</fieldset>';
	}

	/**
	 * Link default fields.
	 */
	public function field_links(): void {
		$settings = $this->settings->all();
		$name     = Settings::OPTION;

		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'New review links', 'axle-review' ) . '</legend>';
		echo '<label for="axr-default-expiry">' . esc_html__( 'Expire after', 'axle-review' ) . '</label> ';
		printf( '<select id="axr-default-expiry" name="%s[default_expiry]">', esc_attr( $name ) );
		foreach ( Settings::expiry_choices() as $seconds => $label ) {
			printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $seconds, selected( $settings['default_expiry'], $seconds, false ), esc_html( $label ) );
		}
		echo '</select><br>';
		printf(
			'<label for="axr-default-views">%1$s</label> <input type="number" id="axr-default-views" name="%2$s[default_max_views]" min="0" step="1" class="small-text" value="%3$d"> <span class="description">%4$s</span><br>',
			esc_html__( 'View limit', 'axle-review' ),
			esc_attr( $name ),
			(int) $settings['default_max_views'],
			esc_html__( '0 is unlimited.', 'axle-review' )
		);
		printf( '<label><input type="checkbox" name="%1$s[guest_sees_others]" value="1"%2$s> %3$s</label>', esc_attr( $name ), checked( $settings['guest_sees_others'], true, false ), esc_html__( 'Reviewers see notes other reviewers left', 'axle-review' ) );
		echo '</fieldset>';
	}

	/**
	 * Slack field.
	 */
	public function field_slack(): void {
		$url    = Settings::slack_webhook();
		$status = get_option( Alerts::SLACK_STATUS );

		printf(
			'<input type="text" id="axr-slack" name="%1$s" value="%2$s" class="regular-text code" autocomplete="off" spellcheck="false" placeholder="https://hooks.slack.com/services/..." aria-describedby="axr-slack-help">',
			esc_attr( Settings::SLACK_OPTION ),
			esc_attr( Settings::mask( $url ) )
		);
		echo '<p class="description" id="axr-slack-help">' . esc_html__( 'Optional. An incoming webhook URL. Slack gets a message for each new reviewer note and each time someone publishes past the gate. The URL is never shown in full again.', 'axle-review' ) . '</p>';
		if ( '' !== $url && is_array( $status ) && empty( $status['ok'] ) ) {
			/* translators: %s: HTTP status code or error. */
			echo '<p class="axr-warning">' . esc_html( sprintf( __( 'The last message to Slack failed (%s).', 'axle-review' ), $status['error'] ? (string) $status['error'] : (string) $status['code'] ) ) . '</p>';
		}
	}
}
