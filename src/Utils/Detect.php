<?php
/**
 * Detect Utility Class
 *
 * Handles detection and checking of S3 path properties.
 *
 * @package     ArrayPress\S3\Utils
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Utils;

use ArrayPress\S3\Abstracts\Provider;

/**
 * Class Detect
 *
 * Handles detection and checking of S3 path properties
 */
class Detect {

	/**
	 * Check if a path looks like an S3 path
	 *
	 * @param string $path Path to check
	 * @return bool
	 */
	public static function is_s3_path( string $path ): bool {
		$path = trim( $path );

		if ( empty( $path ) ) {
			return false;
		}

		// Has s3:// protocol
		if ( str_starts_with( $path, 's3://' ) ) {
			return self::path_has_file_extension( $path );
		}

		// Must contain at least one slash for bucket/object format
		if ( strpos( $path, '/' ) === false ) {
			return false;
		}

		// Exclude obvious non-S3 paths
		if ( str_starts_with( $path, 'http://' ) ||
		     str_starts_with( $path, 'https://' ) ||
		     str_starts_with( $path, 'ftp://' ) ||
		     str_starts_with( $path, './' ) ||
		     str_starts_with( $path, '../' ) ||
		     strpos( $path, '\\' ) !== false ) {
			return false;
		}

		// If it contains common file system indicators, probably not S3
		if ( str_starts_with( $path, '/home/' ) ||
		     str_starts_with( $path, '/var/' ) ||
		     str_starts_with( $path, '/tmp/' ) ||
		     str_starts_with( $path, 'C:\\' ) ||
		     str_starts_with( $path, 'D:\\' ) ) {
			return false;
		}

		// Must have file extension
		return self::path_has_file_extension( $path );
	}

	/**
	 * Check if a URL belongs to a specific provider
	 *
	 * @param string   $url      URL to check
	 * @param Provider $provider Provider instance
	 * @return bool
	 */
	public static function is_provider_url( string $url, Provider $provider ): bool {
		return $provider->is_provider_url( $url );
	}

	/**
	 * Check if a path has a file extension
	 *
	 * @param string $path Full path to check
	 * @return bool
	 */
	public static function path_has_file_extension( string $path ): bool {
		$parsed = Parse::path( $path );

		if ( ! $parsed ) {
			// If parsing fails, check the path directly
			return File::has_extension( basename( $path ) );
		}

		return File::has_extension( $parsed['object'] );
	}

	/**
	 * Check if an object key represents a file (has extension)
	 *
	 * @param string $object Object key
	 * @return bool
	 */
	public static function is_file( string $object ): bool {
		return File::has_extension( $object );
	}

	/**
	 * Check if an object key represents a directory (no extension, ends with /)
	 *
	 * @param string $object Object key
	 * @return bool
	 */
	public static function is_directory( string $object ): bool {
		return ! File::has_extension( $object ) || str_ends_with( $object, '/' );
	}

}