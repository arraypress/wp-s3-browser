<?php
/**
 * Directory Utility Class
 *
 * Handles directory/folder-related operations.
 *
 * @package     ArrayPress\S3\Utils
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      ArrayPress Team
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Utils;

/**
 * Class Directory
 *
 * Handles directory/folder-related operations
 */
class Directory {

	/**
	 * Get directory path from object key
	 *
	 * @param string $object_key Object key
	 *
	 * @return string Directory path
	 */
	public static function path( string $object_key ): string {
		return dirname( $object_key );
	}

	/**
	 * Get folder name from prefix
	 *
	 * @param string $prefix Prefix (folder path)
	 *
	 * @return string Folder name
	 */
	public static function name( string $prefix ): string {
		$prefix = rtrim( $prefix, '/' );
		$parts  = explode( '/', $prefix );

		return end( $parts );
	}

	/**
	 * Get parent directory path
	 *
	 * @param string $path Current path
	 *
	 * @return string Parent directory path
	 */
	public static function parent( string $path ): string {
		$path       = rtrim( $path, '/' );
		$last_slash = strrpos( $path, '/' );

		if ( $last_slash === false ) {
			return '';
		}

		return substr( $path, 0, $last_slash + 1 );
	}

	/**
	 * Create path parts for building breadcrumbs
	 *
	 * @param string $prefix Full path prefix
	 *
	 * @return array Array of path segments with names and full paths
	 */
	public static function breadcrumbs( string $prefix ): array {
		$prefix       = rtrim( $prefix, '/' );
		$parts        = explode( '/', $prefix );
		$result       = [];
		$current_path = '';

		foreach ( $parts as $part ) {
			if ( empty( $part ) ) {
				continue;
			}

			$current_path .= $part . '/';
			$result[]     = [
				'name' => $part,
				'path' => $current_path
			];
		}

		return $result;
	}

	/**
	 * Extract directory prefix from a file path
	 *
	 * @param string $object_key          Object key/path
	 * @param bool   $with_trailing_slash Whether to include trailing slash
	 *
	 * @return string Directory prefix
	 */
	public static function prefix( string $object_key, bool $with_trailing_slash = true ): string {
		$prefix_parts = explode( '/', $object_key );
		// Remove the filename (last part)
		array_pop( $prefix_parts );
		$prefix = implode( '/', $prefix_parts );

		// Add trailing slash if requested and if the prefix is not empty
		if ( $with_trailing_slash && ! empty( $prefix ) ) {
			$prefix .= '/';
		}

		return $prefix;
	}

	/**
	 * Check if a path is a directory/folder (ends with slash)
	 *
	 * @param string $path Path to check
	 *
	 * @return bool True if the path is a directory
	 */
	public static function is_directory( string $path ): bool {
		return str_ends_with( $path, '/' );
	}
}