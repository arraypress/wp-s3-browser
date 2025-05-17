<?php
/**
 * Path Utility Functions
 *
 * Path and folder-related utility functions for S3 operations.
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
 * Class PathUtils
 */
class Path {
	
	/**
	 * Get folder name from prefix
	 *
	 * @param string $prefix Prefix (folder path)
	 *
	 * @return string Folder name
	 */
	public static function get_folder_name( string $prefix ): string {
		$prefix = rtrim( $prefix, '/' );
		$parts  = explode( '/', $prefix );

		return end( $parts );
	}

	/**
	 * Check if a path is a directory/folder (ends with slash)
	 *
	 * @param string $path Path to check
	 *
	 * @return bool True if the path is a directory
	 */
	public static function is_directory( string $path ): bool {
		return substr( $path, - 1 ) === '/';
	}

	/**
	 * Get parent directory path
	 *
	 * @param string $path Current path
	 *
	 * @return string Parent directory path
	 */
	public static function get_parent_directory( string $path ): string {
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
	public static function get_path_parts( string $prefix ): array {
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

}