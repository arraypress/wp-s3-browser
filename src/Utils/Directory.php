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
 * @author      David Sherlock
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
	 * Build a full folder key from the current prefix and folder name
	 *
	 * @param string $current_prefix Current prefix/path
	 * @param string $folder_name    Folder name to append
	 *
	 * @return string Full folder key with trailing slash
	 */
	public static function build_folder_key( string $current_prefix, string $folder_name ): string {
		// Build the full folder key
		$folder_key = rtrim( $current_prefix, '/' );
		if ( ! empty( $folder_key ) ) {
			$folder_key .= '/';
		}
		$folder_key .= $folder_name . '/';

		return $folder_key;
	}

	/**
	 * Normalize a folder path by ensuring it has a trailing slash
	 *
	 * @param string $folder_path Folder path to normalize
	 *
	 * @return string Normalized folder path with trailing slash
	 */
	public static function normalize( string $folder_path ): string {
		return rtrim( $folder_path, '/' ) . '/';
	}

}