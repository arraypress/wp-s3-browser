<?php
/**
 * Enhanced Detect Utility Class
 *
 * Handles detection and checking of S3 path properties with additional URL utilities.
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
 * Enhanced detection and checking of S3 path properties
 */
class Detect {

	/**
	 * Check if a path looks like an S3 path
	 *
	 * @param string $path Path to check
	 *
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
		if ( self::is_normal_url( $path ) || self::is_filesystem_path( $path ) ) {
			return false;
		}

		// Must have file extension
		return self::path_has_file_extension( $path );
	}

	/**
	 * Check if a URL is a normal HTTP/HTTPS/FTP URL
	 *
	 * @param string $url URL to check
	 *
	 * @return bool
	 */
	public static function is_normal_url( string $url ): bool {
		return filter_var( $url, FILTER_VALIDATE_URL ) !== false;
	}

	/**
	 * Check if a path looks like a filesystem path
	 *
	 * @param string $path Path to check
	 *
	 * @return bool
	 */
	public static function is_filesystem_path( string $path ): bool {
		// Common filesystem indicators
		$filesystem_patterns = [
			'/^\.\.?\//',           // Relative paths: ./ or ../
			'/\\\\/',               // Windows backslashes
			'/^\/home\//',          // Unix home directories
			'/^\/var\//',           // Unix var directories
			'/^\/tmp\//',           // Unix temp directories
			'/^[A-Z]:\\\\/',        // Windows drive letters: C:\ D:\
		];

		foreach ( $filesystem_patterns as $pattern ) {
			if ( preg_match( $pattern, $path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a URL belongs to a specific provider
	 *
	 * @param string   $url      URL to check
	 * @param Provider $provider Provider instance
	 *
	 * @return bool
	 */
	public static function is_provider_url( string $url, Provider $provider ): bool {
		return $provider->is_provider_url( $url );
	}

	/**
	 * Check if a path is S3-compatible OR belongs to a provider
	 *
	 * @param string        $path     Path to check
	 * @param Provider|null $provider Optional provider instance
	 *
	 * @return bool
	 */
	public static function is_s3_compatible( string $path, ?Provider $provider = null ): bool {
		// Check if it's a standard S3 path
		if ( self::is_s3_path( $path ) ) {
			return true;
		}

		// Check if it's a provider-specific URL
		if ( $provider && self::is_provider_url( $path, $provider ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if a path looks like it should be S3 but isn't valid
	 * Useful for validation warnings
	 *
	 * @param string $path Path to check
	 *
	 * @return bool
	 */
	public static function is_invalid_s3_like_path( string $path ): bool {
		// Skip normal URLs and filesystem paths
		if ( self::is_normal_url( $path ) || self::is_filesystem_path( $path ) ) {
			return false;
		}

		// Has slash (looks like path structure) but isn't a valid S3 path
		return strpos( $path, '/' ) !== false && ! self::is_s3_path( $path );
	}

	/**
	 * Check if a path has a file extension
	 *
	 * @param string $path Full path to check
	 *
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
	 *
	 * @return bool
	 */
	public static function is_file( string $object ): bool {
		return File::has_extension( $object );
	}

}