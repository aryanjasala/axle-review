<?php
/**
 * What a reviewer saw: a fingerprint of a post, and the blocks that differ between two.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review;

defined( 'ABSPATH' ) || exit;

/**
 * A snapshot is a small array: one hash for the whole post, a hash for the
 * title, the excerpt and the featured image, and one hash per block, keyed by
 * the block's path (the same paths Anchor uses). It holds no text, so it can
 * sit in the links table.
 *
 * Block hashes cover the block name, its attributes and its own HTML, but not
 * the Notes attached to it: attaching a reviewer's note rewrites
 * metadata.noteId, and that is not an edit.
 *
 * Comparing two snapshots matches blocks by hash, so moving a block is not a
 * change, and one block inserted at the top does not mark everything below it.
 */
final class Fingerprint {

	public const VERSION = 1;

	/**
	 * The snapshot of a post as it is now.
	 *
	 * @param \WP_Post $post Post (or a candidate with the content about to be saved).
	 * @return array{v: int, hash: string, title: string, excerpt: string, image: string, blocks: array<string, string>, revision: int}
	 */
	public static function of( \WP_Post $post ): array {
		$blocks = array();
		$free   = 0;
		self::collect( parse_blocks( (string) $post->post_content ), '', $blocks, $free );

		$title   = self::short( (string) $post->post_title );
		$excerpt = self::short( (string) $post->post_excerpt );
		$image   = (string) (int) get_post_meta( (int) $post->ID, '_thumbnail_id', true );

		return array(
			'v'        => self::VERSION,
			'hash'     => hash( 'sha256', (string) wp_json_encode( array( $title, $excerpt, $image, $blocks ) ) ),
			'title'    => $title,
			'excerpt'  => $excerpt,
			'image'    => $image,
			'blocks'   => $blocks,
			'revision' => 0,
		);
	}

	/**
	 * The snapshot, with the newest revision that holds exactly this content,
	 * so the old text can be shown later.
	 *
	 * @param \WP_Post $post Post as stored.
	 * @return array
	 */
	public static function with_revision( \WP_Post $post ): array {
		$snapshot             = self::of( $post );
		$snapshot['revision'] = self::matching_revision( $post );

		return $snapshot;
	}

	/**
	 * Whether this WordPress keeps revisions of the post.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public static function revisions_on( \WP_Post $post ): bool {
		if ( ! post_type_supports( $post->post_type, 'revisions' ) ) {
			return false;
		}

		return 0 !== (int) wp_revisions_to_keep( $post );
	}

	/**
	 * The newest revision (not an autosave) with the same content as the post.
	 *
	 * @param \WP_Post $post Post as stored.
	 * @return int Revision ID, 0 when there is none.
	 */
	public static function matching_revision( \WP_Post $post ): int {
		if ( ! self::revisions_on( $post ) ) {
			return 0;
		}

		$revisions = wp_get_post_revisions(
			(int) $post->ID,
			array(
				'numberposts' => 5,
				'order'       => 'DESC',
				'orderby'     => 'ID',
			)
		);

		foreach ( (array) $revisions as $revision ) {
			if ( ! $revision instanceof \WP_Post || false !== strpos( (string) $revision->post_name, '-autosave-v' ) ) {
				continue;
			}
			if ( (string) $revision->post_content === (string) $post->post_content ) {
				return (int) $revision->ID;
			}
		}

		return 0;
	}

	/**
	 * Encodes a snapshot for the database.
	 *
	 * @param array $snapshot Snapshot.
	 * @return string
	 */
	public static function encode( array $snapshot ): string {
		return (string) wp_json_encode( $snapshot );
	}

	/**
	 * Reads a stored snapshot.
	 *
	 * @param string $stored JSON from the database.
	 * @return array|null Null when empty or from an unknown version.
	 */
	public static function decode( string $stored ): ?array {
		if ( '' === $stored ) {
			return null;
		}

		$snapshot = json_decode( $stored, true );
		if ( ! is_array( $snapshot ) || self::VERSION !== (int) ( $snapshot['v'] ?? 0 ) || ! isset( $snapshot['hash'], $snapshot['blocks'] ) || ! is_array( $snapshot['blocks'] ) ) {
			return null;
		}

		return $snapshot;
	}

	/**
	 * A short hash of a string.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function short( string $text ): string {
		return substr( md5( $text ), 0, 12 );
	}

	/**
	 * The hash of one block: name, attributes without its Notes, and its own HTML.
	 *
	 * @param array $block Parsed block.
	 * @return string
	 */
	public static function block_hash( array $block ): string {
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		if ( isset( $attrs['metadata'] ) && is_array( $attrs['metadata'] ) ) {
			unset( $attrs['metadata']['noteId'] );
			if ( ! $attrs['metadata'] ) {
				unset( $attrs['metadata'] );
			}
		}
		unset( $attrs['axrPath'], $attrs['axrHash'] );

		return substr( md5( (string) ( $block['blockName'] ?? '' ) . '|' . wp_json_encode( $attrs ) . '|' . trim( (string) ( $block['innerHTML'] ?? '' ) ) ), 0, 12 );
	}

	/**
	 * Adds every block to the map, depth first. Paths match Anchor's. Classic
	 * (freeform) content with text in it gets a path like "f0" that Anchor
	 * does not have, so it is listed but never outlined.
	 *
	 * @param array                     $blocks Parsed blocks.
	 * @param string                    $prefix Path of the parent.
	 * @param array<int|string, string> $map Result, path => "name|hash".
	 * @param int                       $free   Running count of freeform parts.
	 */
	private static function collect( array $blocks, string $prefix, array &$map, int &$free ): void {
		$index = 0;
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( empty( $block['blockName'] ) ) {
				if ( '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
					$map[ 'f' . $free ] = 'core/freeform|' . self::block_hash( array( 'innerHTML' => (string) $block['innerHTML'] ) );
					++$free;
				}
				continue;
			}

			$path         = '' === $prefix ? (string) $index : $prefix . '.' . $index;
			$map[ $path ] = $block['blockName'] . '|' . self::block_hash( $block );
			if ( ! empty( $block['innerBlocks'] ) ) {
				self::collect( (array) $block['innerBlocks'], $path, $map, $free );
			}
			++$index;
		}
	}

	/**
	 * What changed between an older snapshot and a newer one.
	 *
	 * @param array $old Older snapshot.
	 * @param array $latest Newer snapshot.
	 * @return array{same: bool, changed: array<int, array{path: string, name: string}>, added: array<int, array{path: string, name: string}>, removed: array<int, array{path: string, name: string}>, meta: string[], count: int}
	 */
	public static function compare( array $old, array $latest ): array {
		$result = array(
			'same'    => ( $old['hash'] ?? '' ) === ( $latest['hash'] ?? '' ),
			'changed' => array(),
			'added'   => array(),
			'removed' => array(),
			'meta'    => array(),
			'count'   => 0,
		);
		if ( $result['same'] ) {
			return $result;
		}

		foreach ( array( 'title', 'excerpt', 'image' ) as $key ) {
			if ( ( $old[ $key ] ?? '' ) !== ( $latest[ $key ] ?? '' ) ) {
				$result['meta'][] = $key;
			}
		}

		$old_blocks = (array) ( $old['blocks'] ?? array() );
		$new_blocks = (array) ( $latest['blocks'] ?? array() );

		// Hashes both sides have, counted, so a repeated block is matched as often as it repeats.
		$available = array();
		foreach ( $old_blocks as $entry ) {
			$hash               = self::entry_hash( (string) $entry );
			$available[ $hash ] = ( $available[ $hash ] ?? 0 ) + 1;
		}

		$unmatched_new = array();
		foreach ( $new_blocks as $path => $entry ) {
			$hash = self::entry_hash( (string) $entry );
			if ( ! empty( $available[ $hash ] ) ) {
				--$available[ $hash ];
				continue;
			}
			$unmatched_new[ (string) $path ] = (string) $entry;
		}

		// What is left of the old side: the entries whose hash was not matched.
		$left = $available;
		$gone = array();
		foreach ( $old_blocks as $path => $entry ) {
			$hash = self::entry_hash( (string) $entry );
			if ( ! empty( $left[ $hash ] ) ) {
				--$left[ $hash ];
				$gone[ (string) $path ] = (string) $entry;
			}
		}

		foreach ( $unmatched_new as $path => $entry ) {
			$path = (string) $path;
			$name = self::entry_name( $entry );
			if ( isset( $gone[ $path ] ) && self::entry_name( $gone[ $path ] ) === $name ) {
				$result['changed'][] = array(
					'path' => $path,
					'name' => $name,
				);
				unset( $gone[ $path ] );
			} else {
				$result['added'][] = array(
					'path' => $path,
					'name' => $name,
				);
			}
		}

		foreach ( $gone as $path => $entry ) {
			$result['removed'][] = array(
				'path' => (string) $path,
				'name' => self::entry_name( $entry ),
			);
		}

		$result['count'] = count( $result['changed'] ) + count( $result['added'] ) + count( $result['removed'] ) + count( $result['meta'] );

		return $result;
	}

	/**
	 * The hash part of a map entry.
	 *
	 * @param string $entry "name|hash".
	 * @return string
	 */
	private static function entry_hash( string $entry ): string {
		$at = strrpos( $entry, '|' );
		return false === $at ? $entry : substr( $entry, $at + 1 );
	}

	/**
	 * The block name part of a map entry.
	 *
	 * @param string $entry "name|hash".
	 * @return string
	 */
	private static function entry_name( string $entry ): string {
		$at = strrpos( $entry, '|' );
		return false === $at ? '' : substr( $entry, 0, $at );
	}

	/**
	 * A readable name for a block type, such as "Paragraph".
	 *
	 * @param string $name Block name, such as core/paragraph.
	 * @return string
	 */
	public static function block_label( string $name ): string {
		if ( 'core/freeform' === $name ) {
			return __( 'Classic content', 'axle-review' );
		}

		$type = class_exists( '\WP_Block_Type_Registry' ) ? \WP_Block_Type_Registry::get_instance()->get_registered( $name ) : null;
		if ( $type && ! empty( $type->title ) ) {
			return (string) $type->title;
		}

		$slug = false === strpos( $name, '/' ) ? $name : substr( $name, (int) strpos( $name, '/' ) + 1 );

		return ucfirst( str_replace( '-', ' ', $slug ) );
	}

	/**
	 * The labels of the title, excerpt and image parts that changed.
	 *
	 * @param string[] $keys Keys from compare().
	 * @return string[]
	 */
	public static function meta_labels( array $keys ): array {
		$labels = array(
			'title'   => __( 'Title', 'axle-review' ),
			'excerpt' => __( 'Excerpt', 'axle-review' ),
			'image'   => __( 'Featured image', 'axle-review' ),
		);

		return array_values( array_map( static fn( $key ) => $labels[ $key ] ?? (string) $key, $keys ) );
	}

	/**
	 * The old text of blocks, from the revision a snapshot recorded.
	 *
	 * @param int      $post_id  Post ID.
	 * @param array    $snapshot The older snapshot.
	 * @param string[] $paths    Block paths to read.
	 * @return array<string, string> Path => text. Empty when there is no revision.
	 */
	public static function previous_text( int $post_id, array $snapshot, array $paths ): array {
		$revision_id = (int) ( $snapshot['revision'] ?? 0 );
		if ( $revision_id <= 0 || ! $paths ) {
			return array();
		}

		$revision = get_post( $revision_id );
		if ( ! $revision instanceof \WP_Post || (int) $revision->post_parent !== $post_id ) {
			return array();
		}

		$blocks = parse_blocks( (string) $revision->post_content );
		$found  = array();
		foreach ( array_slice( array_values( array_unique( $paths ) ), 0, 200 ) as $path ) {
			$block = Anchor::at( $blocks, $path );
			$text  = $block ? Anchor::text( $block ) : '';
			if ( '' !== $text ) {
				$found[ $path ] = mb_substr( $text, 0, 600 );
			}
		}

		return $found;
	}
}
