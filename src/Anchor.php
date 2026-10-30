<?php
/**
 * Finding a block in post content and linking a note to it.
 *
 * @package AryanX\Axle\Review
 */

namespace AryanX\Axle\Review;

defined( 'ABSPATH' ) || exit;

/**
 * Works on the block tree from parse_blocks().
 *
 * A path is the block's position in the tree, counting only real blocks (the
 * whitespace between blocks parses as a block without a name and is skipped),
 * so "2.0" is the first inner block of the third top-level block. That matches
 * the block editor's own tree. The hash is a short fingerprint of the block's
 * name and text, which survives the block moving.
 */
final class Anchor {

	/**
	 * Whether this WordPress stores several note IDs on a block (7.1+), as an array.
	 *
	 * @return string scalar or array.
	 */
	public static function shape(): string {
		$shape = version_compare( (string) get_bloginfo( 'version' ), '7.1-alpha', '>=' ) ? 'array' : 'scalar';

		/**
		 * Filters how a note ID is written into a block's metadata.noteId:
		 * a single number (WordPress 6.9 and 7.0) or an array (7.1 and later).
		 * A block that already has notes keeps the shape it has.
		 *
		 * @param string $shape scalar or array.
		 */
		$shape = apply_filters( 'axle_review_note_id_shape', $shape );

		return 'array' === $shape ? 'array' : 'scalar';
	}

	/**
	 * The fingerprint of a block: 12 hex characters of its name and text.
	 *
	 * @param array $block Parsed block.
	 * @return string
	 */
	public static function hash( array $block ): string {
		return substr( md5( (string) ( $block['blockName'] ?? '' ) . '|' . self::text( $block ) ), 0, 12 );
	}

	/**
	 * A block's own text (not its inner blocks), with spaces collapsed.
	 *
	 * @param array $block Parsed block.
	 * @return string
	 */
	public static function text( array $block ): string {
		$html = (string) ( $block['innerHTML'] ?? '' );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Calls a function for every real block, depth first, with its path.
	 *
	 * @param array    $blocks   Parsed blocks.
	 * @param callable $callback function ( array $block, string $path ).
	 * @param string   $prefix   Path of the parent.
	 */
	public static function walk( array $blocks, callable $callback, string $prefix = '' ): void {
		$index = 0;
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}

			$path = '' === $prefix ? (string) $index : $prefix . '.' . $index;
			$callback( $block, $path );
			if ( ! empty( $block['innerBlocks'] ) ) {
				self::walk( (array) $block['innerBlocks'], $callback, $path );
			}
			++$index;
		}
	}

	/**
	 * Adds the path and hash to every block's attributes as axrPath and
	 * axrHash, for the review render.
	 *
	 * @param array  $blocks Parsed blocks.
	 * @param string $prefix Path of the parent.
	 * @return array
	 */
	public static function mark( array $blocks, string $prefix = '' ): array {
		$index = 0;
		foreach ( $blocks as $key => $block ) {
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}

			$path                          = '' === $prefix ? (string) $index : $prefix . '.' . $index;
			$attrs                         = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$attrs['axrPath']              = $path;
			$attrs['axrHash']              = self::hash( $block );
			$blocks[ $key ]['attrs']       = $attrs;
			$blocks[ $key ]['innerBlocks'] = self::mark( (array) ( $block['innerBlocks'] ?? array() ), $path );
			++$index;
		}

		return $blocks;
	}

	/**
	 * The block at a path.
	 *
	 * @param array  $blocks Parsed blocks.
	 * @param string $path   Path.
	 * @return array|null
	 */
	public static function at( array $blocks, string $path ): ?array {
		$found = null;
		self::walk(
			$blocks,
			static function ( $block, $at ) use ( $path, &$found ) {
				if ( null === $found && $at === $path ) {
					$found = $block;
				}
			}
		);

		return $found;
	}

	/**
	 * The note IDs on a block.
	 *
	 * @param array $block Parsed block.
	 * @return int[]
	 */
	public static function note_ids( array $block ): array {
		$raw = $block['attrs']['metadata']['noteId'] ?? null;
		$raw = is_array( $raw ) ? $raw : array( $raw );

		return array_values( array_unique( array_filter( array_map( 'intval', $raw ) ) ) );
	}

	/**
	 * The path of the block that carries a note, or null.
	 *
	 * @param array $blocks  Parsed blocks.
	 * @param int   $note_id Note ID.
	 * @return string|null
	 */
	public static function find_note( array $blocks, int $note_id ): ?string {
		$found = null;
		self::walk(
			$blocks,
			static function ( $block, $path ) use ( $note_id, &$found ) {
				if ( null === $found && in_array( $note_id, self::note_ids( $block ), true ) ) {
					$found = $path;
				}
			}
		);

		return $found;
	}

	/**
	 * Every note ID linked to a block.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return int[]
	 */
	public static function all_note_ids( array $blocks ): array {
		$ids = array();
		self::walk(
			$blocks,
			static function ( $block ) use ( &$ids ) {
				foreach ( self::note_ids( $block ) as $id ) {
					$ids[] = $id;
				}
			}
		);

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Whether a block can take one more note in the given shape.
	 *
	 * @param array  $block Parsed block.
	 * @param string $shape scalar or array.
	 * @return bool
	 */
	public static function can_take( array $block, string $shape ): bool {
		$current = $block['attrs']['metadata']['noteId'] ?? null;

		return null === $current || '' === $current || is_array( $current ) || 'array' === $shape;
	}

	/**
	 * Works out which block a note belongs on.
	 *
	 * Tries the path, checking the hash; then any block with that hash; then,
	 * when the block is gone, the first block that can take the note.
	 *
	 * @param array  $blocks Parsed blocks.
	 * @param array  $anchor path, hash, blockName.
	 * @param string $shape  scalar or array.
	 * @return array{path: string, orphan: bool}|null Null when no block can take it.
	 */
	public static function resolve( array $blocks, array $anchor, string $shape ): ?array {
		$path = (string) ( $anchor['path'] ?? '' );
		$hash = (string) ( $anchor['hash'] ?? '' );

		$block = '' === $path ? null : self::at( $blocks, $path );
		if ( $block && ( '' === $hash || self::hash( $block ) === $hash ) && self::can_take( $block, $shape ) ) {
			return array(
				'path'   => $path,
				'orphan' => false,
			);
		}

		$match = null;
		if ( '' !== $hash ) {
			self::walk(
				$blocks,
				static function ( $candidate, $at ) use ( $hash, $shape, &$match ) {
					if ( null === $match && self::hash( $candidate ) === $hash && self::can_take( $candidate, $shape ) ) {
						$match = $at;
					}
				}
			);
		}

		if ( null !== $match ) {
			return array(
				'path'   => $match,
				'orphan' => false,
			);
		}

		$first = null;
		self::walk(
			$blocks,
			static function ( $candidate, $at ) use ( $shape, &$first ) {
				if ( null === $first && false === strpos( $at, '.' ) && self::can_take( $candidate, $shape ) ) {
					$first = $at;
				}
			}
		);

		return null === $first ? null : array(
			'path'   => $first,
			'orphan' => true,
		);
	}

	/**
	 * Adds a note ID to the block at a path.
	 *
	 * @param array  $blocks  Parsed blocks.
	 * @param string $path    Path.
	 * @param int    $note_id Note ID.
	 * @param string $shape   scalar or array, for a block with no notes yet.
	 * @return array|null The changed blocks, or null when the block is missing or cannot take it.
	 */
	public static function add_note_id( array $blocks, string $path, int $note_id, string $shape ): ?array {
		$parts = array_map( 'intval', explode( '.', $path ) );
		return self::add_at( $blocks, $parts, $note_id, $shape );
	}

	/**
	 * Recursive part of add_note_id().
	 *
	 * @param array  $blocks  Blocks at this level.
	 * @param int[]  $parts   Remaining path.
	 * @param int    $note_id Note ID.
	 * @param string $shape   Shape.
	 * @return array|null
	 */
	private static function add_at( array $blocks, array $parts, int $note_id, string $shape ): ?array {
		$want  = (int) array_shift( $parts );
		$index = 0;

		foreach ( $blocks as $key => $block ) {
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}

			if ( $index++ !== $want ) {
				continue;
			}

			if ( $parts ) {
				$inner = self::add_at( (array) ( $block['innerBlocks'] ?? array() ), $parts, $note_id, $shape );
				if ( null === $inner ) {
					return null;
				}
				$blocks[ $key ]['innerBlocks'] = $inner;
				return $blocks;
			}

			if ( in_array( $note_id, self::note_ids( $block ), true ) ) {
				return $blocks;
			}

			if ( ! self::can_take( $block, $shape ) ) {
				return null;
			}

			$attrs    = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$metadata = isset( $attrs['metadata'] ) && is_array( $attrs['metadata'] ) ? $attrs['metadata'] : array();
			$current  = $metadata['noteId'] ?? null;

			if ( is_array( $current ) ) {
				$current[]          = $note_id;
				$metadata['noteId'] = array_values( array_unique( array_map( 'intval', $current ) ) );
			} elseif ( null !== $current && '' !== $current ) {
				$metadata['noteId'] = array( (int) $current, $note_id );
			} else {
				$metadata['noteId'] = 'array' === $shape ? array( $note_id ) : $note_id;
			}

			$attrs['metadata']       = $metadata;
			$blocks[ $key ]['attrs'] = $attrs;
			return $blocks;
		}

		return null;
	}
}
