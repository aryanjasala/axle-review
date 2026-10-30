<?php
/**
 * Plugin bootstrap.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review;

use AryanX\Axle\Review\Admin\EditorAssets;
use AryanX\Axle\Review\Admin\Inbox;
use AryanX\Axle\Review\Admin\LinksPage;
use AryanX\Axle\Review\Admin\NetworkPause;
use AryanX\Axle\Review\Admin\PostColumns;
use AryanX\Axle\Review\Admin\Profile;
use AryanX\Axle\Review\Admin\SettingsSection;
use AryanX\Axle\Review\Cli\Command;
use AryanX\Axle\Review\Cli\LinkCommand;
use AryanX\Axle\Review\Cli\NotesCommand;
use AryanX\Axle\Review\Frontend\ReviewView;
use AryanX\Axle\Review\Health\SiteHealth;
use AryanX\Axle\Review\Rest\EditorRoutes;
use AryanX\Axle\Review\Rest\GuestRoutes;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the services once and registers their hooks.
 */
final class Plugin {

	/**
	 * The running instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

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
	 * Notes.
	 *
	 * @var Notes
	 */
	private Notes $notes;

	/**
	 * Attacher.
	 *
	 * @var Attacher
	 */
	private Attacher $attacher;

	/**
	 * Access.
	 *
	 * @var Access
	 */
	private Access $access;

	/**
	 * Gate.
	 *
	 * @var Gate
	 */
	private Gate $gate;

	/**
	 * Alerts.
	 *
	 * @var Alerts
	 */
	private Alerts $alerts;

	/**
	 * Abilities.
	 *
	 * @var Abilities
	 */
	private Abilities $abilities;

	/**
	 * Starts the plugin. Runs on `plugins_loaded`.
	 */
	public static function boot(): void {
		if ( null !== self::$instance ) {
			return;
		}

		if ( ! Requirements::met() ) {
			Requirements::fail();
			return;
		}

		self::$instance = new self();
		self::$instance->register();
	}

	/**
	 * The running instance.
	 *
	 * @return Plugin|null
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Builds the shared objects.
	 */
	private function __construct() {
		$this->settings  = new Settings();
		$this->links     = new Links();
		$this->events    = new Events();
		$this->notes     = new Notes();
		$this->attacher  = new Attacher();
		$this->access    = new Access( $this->links, $this->events );
		$this->gate      = new Gate( $this->settings, $this->notes );
		$this->alerts    = new Alerts();
		$this->abilities = new Abilities( $this->settings, $this->links, $this->events, $this->notes, $this->gate );
	}

	/**
	 * Links, for other plugins.
	 *
	 * @return Links
	 */
	public function links(): Links {
		return $this->links;
	}

	/**
	 * Notes, for other plugins.
	 *
	 * @return Notes
	 */
	public function notes(): Notes {
		return $this->notes;
	}

	/**
	 * The publish gate, for other plugins.
	 *
	 * @return Gate
	 */
	public function gate(): Gate {
		return $this->gate;
	}

	/**
	 * Registers the services.
	 */
	private function register(): void {
		Schema::maybe_upgrade();

		$services = array(
			$this->attacher,
			$this->gate,
			$this->alerts,
			$this->abilities,
			new Lifecycle( $this->links, $this->events ),
			new Privacy( $this->links, $this->events ),
			new ReviewView( $this->links, $this->events, $this->access ),
			new GuestRoutes( $this->links, $this->events, $this->access, $this->notes, $this->attacher ),
			new EditorRoutes( $this->settings, $this->links, $this->events, $this->attacher, $this->gate ),
			new Inbox( $this->notes ),
			new LinksPage( $this->settings, $this->links, $this->events ),
			new SettingsSection( $this->settings ),
			new Profile(),
			new PostColumns( $this->notes ),
			new EditorAssets(),
			new NetworkPause(),
			new SiteHealth( $this->settings, $this->links, $this->gate ),
		);

		foreach ( $services as $service ) {
			$service->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$command = new Command( $this->gate, $this->alerts, $this->events );
			\WP_CLI::add_command( 'axle-review link', new LinkCommand( $this->settings, $this->links, $this->events ) );
			\WP_CLI::add_command( 'axle-review notes', new NotesCommand( $this->notes, $this->attacher, $this->abilities ) );
			\WP_CLI::add_command( 'axle-review gate check', array( $command, 'gate_check' ) );
			\WP_CLI::add_command( 'axle-review digest send', array( $command, 'digest_send' ) );
			\WP_CLI::add_command( 'axle-review prune', array( $command, 'prune' ) );
		}
	}
}
