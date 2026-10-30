<?php
/**
 * Abilities: read and work notes, check publish readiness, manage links.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Axle Review's abilities, for agents through the MCP adapter and
 * anything else through /wp-abilities/v1/. Each one checks the same
 * capabilities as the screens. There are no abilities to pause links, change
 * settings or publish past the gate.
 */
final class Abilities implements Service {

	public const CATEGORY = 'axle-review';

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
	 * Gate.
	 *
	 * @var Gate
	 */
	private Gate $gate;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up the abilities.
	 *
	 * @param Settings $settings Settings.
	 * @param Links    $links    Links.
	 * @param Events   $events   Events.
	 * @param Notes    $notes    Notes.
	 * @param Gate     $gate     Gate.
	 */
	public function __construct( Settings $settings, Links $links, Events $events, Notes $notes, Gate $gate ) {
		$this->settings = $settings;
		$this->links    = $links;
		$this->events   = $events;
		$this->notes    = $notes;
		$this->gate     = $gate;
	}

	/**
	 * Hooks into the Abilities API when WordPress has it.
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers the category.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Axle Review', 'axle-review' ),
				'description' => __( 'Read and work editorial notes, check whether posts are ready to publish, and manage review links.', 'axle-review' ),
			)
		);
	}

	/**
	 * Registers the abilities.
	 */
	public function register_abilities(): void {
		$note_schema = self::note_schema();
		$link_schema = self::link_schema();
		$inbox       = static fn(): bool => current_user_can( Settings::inbox_capability() );
		$note_id     = array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);

		$this->ability(
			'list-notes',
			__( 'List notes', 'axle-review' ),
			__( 'Editorial notes (block Notes) across the site, newest first. Filter by status (unresolved, resolved, all), post, post type, a mentioned user, notes from reviewers, or a start date.', 'axle-review' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'status'    => array(
						'type'    => 'string',
						'enum'    => array( 'unresolved', 'resolved', 'all' ),
						'default' => 'unresolved',
					),
					'post'      => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'post_type' => array( 'type' => 'string' ),
					'mentions'  => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'User ID. Only notes that mention this user (WordPress 7.1+).', 'axle-review' ),
					),
					'guests'    => array(
						'type'        => 'boolean',
						'description' => __( 'Only notes left by reviewers through a link.', 'axle-review' ),
					),
					'since'     => array(
						'type'        => 'string',
						'description' => __( 'Only notes from this date and time on (anything strtotime() reads).', 'axle-review' ),
					),
					'page'      => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page'  => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20,
					),
				),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'total' => array( 'type' => 'integer' ),
					'notes' => array(
						'type'  => 'array',
						'items' => $note_schema,
					),
				),
			),
			array( $this, 'list_notes' ),
			$inbox,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'get-note-thread',
			__( 'Get a note thread', 'axle-review' ),
			__( 'A note with its replies, the block it is on (name, path and text) and its status history.', 'axle-review' ),
			$note_id,
			array(
				'type'       => 'object',
				'properties' => array(
					'note'    => $note_schema,
					'replies' => array(
						'type'  => 'array',
						'items' => $note_schema,
					),
					'block'   => array( 'type' => array( 'object', 'null' ) ),
					'history' => array( 'type' => 'array' ),
				),
			),
			array( $this, 'get_thread' ),
			array( $this, 'can_edit_note' ),
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'add-note',
			__( 'Add a note', 'axle-review' ),
			__( 'Adds a note as the current user on a block, found by its path ("2" is the third top-level block, "2.0" its first inner block) or by text it contains, or replies to a thread. On WordPress 7.1+ it can mention users by ID.', 'axle-review' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'post'     => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'text'     => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 5000,
					),
					'block'    => array(
						'type'    => 'string',
						'pattern' => '^[0-9]+(\.[0-9]+)*$',
					),
					'match'    => array(
						'type'        => 'string',
						'description' => __( 'Text the block contains, when no path is given.', 'axle-review' ),
					),
					'reply_to' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'mentions' => array(
						'type'  => 'array',
						'items' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
				),
				'required'             => array( 'post', 'text' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'note'  => $note_schema,
					'block' => array( 'type' => array( 'string', 'null' ) ),
				),
			),
			array( $this, 'add_note' ),
			array( $this, 'can_edit_input_post' ),
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			)
		);

		foreach ( array(
			'resolve-note' => array( __( 'Resolve a note', 'axle-review' ), __( 'Marks a note thread resolved, with the same activity reply the editor writes. Resolving a resolved note changes nothing.', 'axle-review' ), 'resolved' ),
			'reopen-note'  => array( __( 'Reopen a note', 'axle-review' ), __( 'Reopens a resolved note thread. Reopening an open note changes nothing.', 'axle-review' ), 'open' ),
		) as $name => $about ) {
			$to = $about[2];
			$this->ability(
				$name,
				$about[0],
				$about[1],
				$note_id,
				$note_schema,
				function ( $input ) use ( $to ) {
					return $this->set_status( (array) $input, $to );
				},
				array( $this, 'can_edit_note' ),
				array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				)
			);
		}

		$this->ability(
			'get-publish-readiness',
			__( 'Get publish readiness', 'axle-review' ),
			__( 'Open notes on a post and whether the publish gate would hold it back.', 'axle-review' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'post' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'required'             => array( 'post' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'post'         => array( 'type' => 'integer' ),
					'gate_enabled' => array( 'type' => 'boolean' ),
					'would_block'  => array( 'type' => 'boolean' ),
					'open_notes'   => array( 'type' => 'integer' ),
					'blocking'     => array(
						'type'  => 'array',
						'items' => $note_schema,
					),
				),
			),
			array( $this, 'readiness' ),
			array( $this, 'can_edit_input_post' ),
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'list-review-links',
			__( 'List review links', 'axle-review' ),
			__( 'Review links of a post, or every link you may see, newest first, with views, notes and status.', 'axle-review' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'post'   => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'status' => array(
						'type' => 'string',
						'enum' => array( '', 'active', 'used', 'expired', 'revoked' ),
					),
				),
				'additionalProperties' => false,
			),
			array(
				'type'  => 'array',
				'items' => $link_schema,
			),
			array( $this, 'list_links' ),
			$inbox,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'create-review-link',
			__( 'Create a review link', 'axle-review' ),
			__( 'Creates a private link to a draft for someone without an account. The URL is returned once and never stored.', 'axle-review' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'post'        => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'label'       => array( 'type' => 'string' ),
					'expires'     => array(
						'type'        => 'integer',
						'minimum'     => 60,
						'description' => __( 'Seconds until it expires. Default 7 days, at most 30.', 'axle-review' ),
					),
					'max_views'   => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( '0 for unlimited, 1 to open once.', 'axle-review' ),
					),
					'email'       => array(
						'type'   => 'string',
						'format' => 'email',
					),
					'allow_notes' => array( 'type' => 'boolean' ),
				),
				'required'             => array( 'post' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'url'  => array( 'type' => 'string' ),
					'link' => $link_schema,
				),
			),
			array( $this, 'create_link' ),
			array( $this, 'can_share_input_post' ),
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			)
		);

		$this->ability(
			'revoke-review-link',
			__( 'Revoke a review link', 'axle-review' ),
			__( 'Turns a review link off for good. Revoking a revoked link changes nothing.', 'axle-review' ),
			$note_id,
			$link_schema,
			array( $this, 'revoke_link' ),
			array( $this, 'can_manage_link' ),
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			)
		);
	}

	/**
	 * Registers one ability.
	 *
	 * @param string   $name        Name without the category.
	 * @param string   $label       Label.
	 * @param string   $description Description.
	 * @param array    $input       Input schema.
	 * @param array    $output      Output schema.
	 * @param callable $execute     Execute callback.
	 * @param callable $permission  Permission callback.
	 * @param array    $annotations Annotations.
	 */
	private function ability( string $name, string $label, string $description, array $input, array $output, callable $execute, callable $permission, array $annotations ): void {
		wp_register_ability(
			self::CATEGORY . '/' . $name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => self::CATEGORY,
				'input_schema'        => $input,
				'output_schema'       => $output,
				'execute_callback'    => $execute,
				'permission_callback' => $permission,
				'meta'                => array(
					'annotations'  => $annotations,
					'show_in_rest' => true,
					'public'       => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}

	/**
	 * Schema of a note.
	 *
	 * @return array
	 */
	public static function note_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'         => array( 'type' => 'integer' ),
				'post_id'    => array( 'type' => 'integer' ),
				'post_title' => array( 'type' => 'string' ),
				'parent'     => array( 'type' => 'integer' ),
				'author'     => array( 'type' => 'string' ),
				'user_id'    => array( 'type' => 'integer' ),
				'guest'      => array( 'type' => 'boolean' ),
				'status'     => array( 'type' => 'string' ),
				'content'    => array( 'type' => 'string' ),
				'text'       => array( 'type' => 'string' ),
				'date'       => array( 'type' => 'string' ),
				'block'      => array( 'type' => 'string' ),
				'mentions'   => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
			),
		);
	}

	/**
	 * Schema of a link.
	 *
	 * @return array
	 */
	public static function link_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'             => array( 'type' => 'integer' ),
				'post_id'        => array( 'type' => 'integer' ),
				'label'          => array( 'type' => 'string' ),
				'status'         => array( 'type' => 'string' ),
				'created_by'     => array( 'type' => 'integer' ),
				'creator'        => array( 'type' => 'string' ),
				'created_at'     => array( 'type' => 'string' ),
				'expires_at'     => array( 'type' => 'string' ),
				'max_views'      => array( 'type' => 'integer' ),
				'views'          => array( 'type' => 'integer' ),
				'email'          => array( 'type' => 'string' ),
				'has_password'   => array( 'type' => 'boolean' ),
				'allow_notes'    => array( 'type' => 'boolean' ),
				'share_notes'    => array( 'type' => 'boolean' ),
				'revoked_at'     => array( 'type' => 'string' ),
				'last_opened_at' => array( 'type' => 'string' ),
				'notes'          => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * Permission: may edit the post in the input.
	 *
	 * @param array|null $input Input.
	 * @return bool
	 */
	public function can_edit_input_post( $input = null ): bool {
		$input = (array) $input;
		return current_user_can( 'edit_post', (int) ( $input['post'] ?? 0 ) );
	}

	/**
	 * Permission: may share the post in the input.
	 *
	 * @param array|null $input Input.
	 * @return bool
	 */
	public function can_share_input_post( $input = null ): bool {
		$input = (array) $input;
		return Settings::can_share( (int) ( $input['post'] ?? 0 ) );
	}

	/**
	 * Permission: may edit the post of the note.
	 *
	 * @param array|null $input Input.
	 * @return bool
	 */
	public function can_edit_note( $input = null ): bool {
		$input = (array) $input;
		$note  = Notes::get( (int) ( $input['id'] ?? 0 ) );
		return $note && current_user_can( 'edit_post', (int) $note->comment_post_ID );
	}

	/**
	 * Permission: may share the post of the link.
	 *
	 * @param array|null $input Input.
	 * @return bool
	 */
	public function can_manage_link( $input = null ): bool {
		$input = (array) $input;
		$link  = $this->links->get( (int) ( $input['id'] ?? 0 ) );
		return $link && Settings::can_share( (int) $link['post_id'] );
	}

	/**
	 * Execute: list-notes.
	 *
	 * @param array|null $input Input.
	 * @return array
	 */
	public function list_notes( $input = null ): array {
		$input = (array) $input;
		$since = isset( $input['since'] ) ? strtotime( (string) $input['since'] ) : false;
		$args  = array(
			'status'    => (string) ( $input['status'] ?? 'unresolved' ),
			'post_id'   => (int) ( $input['post'] ?? 0 ),
			'post_type' => (string) ( $input['post_type'] ?? '' ),
			'mentions'  => Notes::has_mentions() ? (int) ( $input['mentions'] ?? 0 ) : 0,
			'guests'    => ! empty( $input['guests'] ),
			'since'     => false === $since ? 0 : $since,
			'page'      => (int) ( $input['page'] ?? 1 ),
			'per_page'  => (int) ( $input['per_page'] ?? 20 ),
		);
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$args['restrict_author'] = get_current_user_id();
		}

		$found = $this->notes->query( $args );
		$notes = array();
		foreach ( $found['items'] as $note ) {
			if ( current_user_can( 'edit_post', (int) $note->comment_post_ID ) ) {
				$notes[] = Notes::to_array( $note );
			}
		}

		return array(
			'total' => $found['total'],
			'notes' => $notes,
		);
	}

	/**
	 * Execute: get-note-thread.
	 *
	 * @param array|null $input Input.
	 * @return array|\WP_Error
	 */
	public function get_thread( $input = null ) {
		$input = (array) $input;
		$note  = Notes::get( (int) ( $input['id'] ?? 0 ) );
		if ( ! $note ) {
			return new \WP_Error( 'axle_review_no_note', __( 'That note does not exist.', 'axle-review' ) );
		}

		if ( (int) $note->comment_parent ) {
			$root = Notes::get( (int) $note->comment_parent );
			$note = $root ? $root : $note;
		}

		$replies = array();
		$history = array();
		foreach ( $this->notes->replies( (int) $note->comment_ID ) as $reply ) {
			if ( Notes::is_activity( $reply ) ) {
				$history[] = array(
					'status' => (string) get_comment_meta( (int) $reply->comment_ID, '_wp_note_status', true ),
					'by'     => (string) $reply->comment_author,
					'date'   => mysql_to_rfc3339( (string) $reply->comment_date_gmt ),
				);
				continue;
			}
			$replies[] = Notes::to_array( $reply );
		}

		$block = null;
		$post  = get_post( (int) $note->comment_post_ID );
		if ( $post ) {
			$blocks = parse_blocks( (string) $post->post_content );
			$path   = Anchor::find_note( $blocks, (int) $note->comment_ID );
			$found  = null === $path ? null : Anchor::at( $blocks, $path );
			if ( $found ) {
				$block = array(
					'name' => (string) $found['blockName'],
					'path' => (string) $path,
					'text' => mb_substr( Anchor::text( $found ), 0, 300 ),
				);
			}
		}

		return array(
			'note'    => Notes::to_array( $note ),
			'replies' => $replies,
			'block'   => $block,
			'history' => $history,
		);
	}

	/**
	 * Execute: add-note.
	 *
	 * @param array|null $input Input.
	 * @return array|\WP_Error
	 */
	public function add_note( $input = null ) {
		$input   = (array) $input;
		$post_id = (int) ( $input['post'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, Settings::post_types(), true ) ) {
			return new \WP_Error( 'axle_review_post_type', __( 'Notes are not available for this post.', 'axle-review' ) );
		}

		$content = esc_html( (string) ( $input['text'] ?? '' ) );
		if ( ! empty( $input['mentions'] ) && Notes::has_mentions() ) {
			$chips = array();
			foreach ( (array) $input['mentions'] as $user_id ) {
				$user = get_userdata( (int) $user_id );
				if ( $user && user_can( $user, 'edit_post', $post_id ) ) {
					$chips[] = Notes::mention( $user );
				}
			}
			if ( $chips ) {
				$content = implode( ' ', $chips ) . ' ' . $content;
			}
		}

		$parent = (int) ( $input['reply_to'] ?? 0 );
		if ( $parent ) {
			$root = Notes::get( $parent );
			if ( ! $root || (int) $root->comment_post_ID !== $post_id ) {
				return new \WP_Error( 'axle_review_no_note', __( 'That thread is not on this post.', 'axle-review' ) );
			}
			$parent = (int) $root->comment_parent ? (int) $root->comment_parent : $parent;
		}

		$path   = null;
		$blocks = parse_blocks( (string) $post->post_content );
		if ( ! $parent ) {
			$path = $this->find_block( $blocks, $input );
			if ( null === $path ) {
				return new \WP_Error( 'axle_review_no_block', __( 'No block matches that path or text.', 'axle-review' ) );
			}
			if ( ! Anchor::can_take( (array) Anchor::at( $blocks, $path ), Anchor::shape() ) ) {
				return new \WP_Error( 'axle_review_block_taken', __( 'That block already has a note, and this WordPress allows one note per block. Reply to that note instead.', 'axle-review' ) );
			}
		}

		$id = $this->notes->add_user_note( $post_id, get_current_user_id(), $content, $parent );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		if ( null !== $path ) {
			$changed = Anchor::add_note_id( $blocks, $path, $id, Anchor::shape() );
			if ( null !== $changed ) {
				wp_update_post(
					wp_slash(
						array(
							'ID'           => $post_id,
							'post_content' => serialize_blocks( $changed ),
						)
					)
				);
			}
		}

		$note = Notes::get( $id );

		return array(
			'note'  => $note ? Notes::to_array( $note ) : array(),
			'block' => $path,
		);
	}

	/**
	 * The block for add-note: by path, else the first whose text has the match.
	 *
	 * @param array $blocks Parsed blocks.
	 * @param array $input  Input.
	 * @return string|null
	 */
	private function find_block( array $blocks, array $input ): ?string {
		if ( isset( $input['block'] ) && '' !== (string) $input['block'] ) {
			return Anchor::at( $blocks, (string) $input['block'] ) ? (string) $input['block'] : null;
		}

		$match = trim( (string) ( $input['match'] ?? '' ) );
		$found = null;
		Anchor::walk(
			$blocks,
			static function ( $block, $path ) use ( $match, &$found ) {
				if ( null === $found && ( '' === $match || false !== mb_stripos( Anchor::text( $block ), $match ) ) && ( '' !== $match || '' !== Anchor::text( $block ) ) ) {
					$found = $path;
				}
			}
		);

		return $found;
	}

	/**
	 * Execute: resolve-note and reopen-note.
	 *
	 * @param array  $input Input.
	 * @param string $to    resolved or open.
	 * @return array|\WP_Error
	 */
	public function set_status( array $input, string $to ) {
		$id     = (int) ( $input['id'] ?? 0 );
		$result = $this->notes->set_status( $id, $to, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$note = Notes::get( $id );
		return $note ? Notes::to_array( $note ) : array();
	}

	/**
	 * Execute: get-publish-readiness.
	 *
	 * @param array|null $input Input.
	 * @return array|\WP_Error
	 */
	public function readiness( $input = null ) {
		$input = (array) $input;
		$post  = get_post( (int) ( $input['post'] ?? 0 ) );
		if ( ! $post ) {
			return new \WP_Error( 'axle_review_no_post', __( 'That post does not exist.', 'axle-review' ) );
		}

		$ready = $this->gate->readiness( $post );

		return array(
			'post'         => (int) $post->ID,
			'gate_enabled' => $ready['enabled'],
			'would_block'  => $ready['blocks'],
			'open_notes'   => $ready['open'],
			'blocking'     => array_map( array( Notes::class, 'to_array' ), $ready['notes'] ),
		);
	}

	/**
	 * Execute: list-review-links.
	 *
	 * @param array|null $input Input.
	 * @return array
	 */
	public function list_links( $input = null ): array {
		$input = (array) $input;
		$found = $this->links->query(
			array(
				'post_id' => (int) ( $input['post'] ?? 0 ),
				'status'  => (string) ( $input['status'] ?? '' ),
				'limit'   => 100,
			)
		);

		$counts = $this->events->note_counts( array_column( $found['items'], 'id' ) );
		$links  = array();
		foreach ( $found['items'] as $link ) {
			if ( Settings::can_share( (int) $link['post_id'] ) ) {
				$links[] = Links::to_public( $link, $counts[ $link['id'] ] ?? 0 );
			}
		}

		return $links;
	}

	/**
	 * Execute: create-review-link.
	 *
	 * @param array|null $input Input.
	 * @return array|\WP_Error
	 */
	public function create_link( $input = null ) {
		$input   = (array) $input;
		$created = $this->links->create(
			array(
				'post_id'     => (int) ( $input['post'] ?? 0 ),
				'label'       => (string) ( $input['label'] ?? '' ),
				'expires'     => (int) ( $input['expires'] ?? $this->settings->default_expiry() ),
				'max_views'   => (int) ( $input['max_views'] ?? $this->settings->default_max_views() ),
				'email'       => (string) ( $input['email'] ?? '' ),
				'allow_notes' => (bool) ( $input['allow_notes'] ?? true ),
				'share_notes' => (bool) $this->settings->get( 'guest_sees_others' ),
			)
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		return array(
			'url'  => $created['url'],
			'link' => Links::to_public( $created['link'] ),
		);
	}

	/**
	 * Execute: revoke-review-link.
	 *
	 * @param array|null $input Input.
	 * @return array|\WP_Error
	 */
	public function revoke_link( $input = null ) {
		$input = (array) $input;
		$id    = (int) ( $input['id'] ?? 0 );
		if ( ! $this->links->revoke( $id, get_current_user_id() ) ) {
			return new \WP_Error( 'axle_review_no_link', __( 'That link does not exist.', 'axle-review' ) );
		}

		$link = $this->links->get( $id );
		return $link ? Links::to_public( $link ) : array();
	}
}
