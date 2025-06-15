<?php
/**
 * Directory Utility Class - Enhanced with Rename Operations
 *
 * Handles directory/folder-related operations including file renaming logic.
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
	 * Get directory path from object key, normalized for concatenation
	 *
	 * Extracts the directory path from an object key and ensures it's properly
	 * formatted for concatenating with a new filename.
	 *
	 * @param string $object_key The full object key (path + filename)
	 *
	 * @return string Directory path with trailing slash, or empty string for root
	 */
	public static function get_directory_path( string $object_key ): string {
		$directory_path = dirname( $object_key );

		// dirname() returns '.' for files in root directory
		return ( $directory_path === '.' ) ? '' : $directory_path . '/';
	}

	/**
	 * Build new object key for file rename operation
	 *
	 * Combines the directory path from the current key with a new filename
	 * to create the new object key for rename operations.
	 *
	 * @param string $current_key  Current object key
	 * @param string $new_filename New filename (without path)
	 *
	 * @return string New object key
	 */
	public static function build_rename_key( string $current_key, string $new_filename ): string {
		$directory_path = self::get_directory_path( $current_key );

		return $directory_path . $new_filename;
	}

	/**
	 * Check if rename would result in the same key
	 *
	 * Utility method to determine if a rename operation would result in
	 * the same object key (i.e., no actual change).
	 *
	 * @param string $current_key  Current object key
	 * @param string $new_filename New filename
	 *
	 * @return bool True if the keys would be the same
	 */
	public static function is_rename_same_key( string $current_key, string $new_filename ): bool {
		$new_key = self::build_rename_key( $current_key, $new_filename );

		return $new_key === $current_key;
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

	/**
	 * Extract filename from object key
	 *
	 * @param string $object_key Object key/path
	 *
	 * @return string Filename only
	 */
	public static function filename( string $object_key ): string {
		return basename( $object_key );
	}

	/**
	 * Check if object key represents a folder (ends with /)
	 *
	 * @param string $object_key Object key to check
	 *
	 * @return bool True if it represents a folder
	 */
	public static function is_folder( string $object_key ): bool {
		return str_ends_with( $object_key, '/' );
	}

	/**
	 * Get the depth level of a path (number of folder levels)
	 *
	 * @param string $path Path to analyze
	 *
	 * @return int Depth level (0 for root)
	 */
	public static function depth( string $path ): int {
		$path = trim( $path, '/' );

		if ( empty( $path ) ) {
			return 0;
		}

		return substr_count( $path, '/' ) + 1;
	}

	/**
	 * Build S3 object key from WordPress file path
	 *
	 * Converts a WordPress file path to an S3 object key by extracting
	 * the relative path from the uploads directory and adding a prefix.
	 *
	 * @param string $file_path WordPress file path
	 * @param string $prefix    Optional S3 prefix (e.g., 'uploads/')
	 *
	 * @return string S3 object key
	 */
	public static function build_wp_object_key( string $file_path, string $prefix = '' ): string {
		$upload_dir    = wp_upload_dir();
		$relative_path = str_replace( $upload_dir['basedir'] . '/', '', $file_path );

		return ltrim( $prefix . $relative_path, '/' );
	}

}