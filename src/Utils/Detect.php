<?php
/**
 * Detect Utility Class
 *
 * Simplified detection logic for S3 paths.
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
 * Class Detect
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

		// If it's a full URL, it's not a simple S3 path
		if ( filter_var( $path, FILTER_VALIDATE_URL ) !== false ) {
			return false;
		}

		// Skip shortcodes - let WooCommerce handle them
		if ( self::has_shortcodes( $path ) ) {
			return false;
		}

		// Strip s3:// protocol if present
		if ( str_starts_with( $path, 's3://' ) ) {
			$path = substr( $path, 5 );
		}

		// Strip leading/trailing slashes
		$path = trim( $path, '/' );

		// Must contain slash for bucket/object format
		if ( strpos( $path, '/' ) === false ) {
			return false;
		}

		// Split and validate we have bucket and object
		$parts = explode( '/', $path, 2 );
		if ( count( $parts ) !== 2 || empty( $parts[0] ) || empty( $parts[1] ) ) {
			return false;
		}

		// Must have file extension
		return ! empty( pathinfo( basename( $parts[1] ), PATHINFO_EXTENSION ) );
	}

	/**
	 * Check if a path contains shortcodes
	 *
	 * @param string $path Path to check
	 *
	 * @return bool
	 */
	public static function has_shortcodes( string $path ): bool {
		return strpos( $path, '[' ) !== false && strpos( $path, ']' ) !== false;
	}

}