<?php
/**
 * Detect Utility Class
 *
 * Handles detection and validation of S3 path properties.
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
 * Handles detection and validation of S3 path properties
 */
class Detect {

	/**
	 * Check if a path looks like an S3 path
	 *
	 * Validates paths in the following formats:
	 * - s3://bucket/object.ext
	 * - bucket/object.ext
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

		// Strip s3:// protocol if present
		if ( str_starts_with( $path, 's3://' ) ) {
			$path = substr( $path, 5 );
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
		$filename = basename( $path );

		return ! empty( pathinfo( $filename, PATHINFO_EXTENSION ) );
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
	 * This is the main method used by WooCommerce integration to determine
	 * if a file path should be handled by S3 logic.
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

}