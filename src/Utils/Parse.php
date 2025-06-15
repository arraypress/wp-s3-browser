<?php
/**
 * Parse Utility Class
 *
 * Simplified parsing logic for S3 paths.
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
 * Class Parse
 */
class Parse {

	/**
	 * Parse S3 path into bucket and object components
	 *
	 * @param string $path Path to parse
	 *
	 * @return array|false Array with 'bucket' and 'object' keys or false on failure
	 */
	public static function path( string $path ) {
		$path = trim( $path );

		if ( empty( $path ) ) {
			return false;
		}

		// If it's a full URL, it's not a simple S3 path
		if ( filter_var( $path, FILTER_VALIDATE_URL ) !== false ) {
			return false;
		}

		// Skip shortcodes - let WooCommerce handle them
		if ( Detect::has_shortcodes( $path ) ) {
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

		// Split on first slash only
		$parts = explode( '/', $path, 2 );

		// Must have both bucket and object
		if ( count( $parts ) !== 2 || empty( $parts[0] ) || empty( $parts[1] ) ) {
			return false;
		}

		return [
			'bucket' => $parts[0],
			'object' => $parts[1]
		];
	}

}