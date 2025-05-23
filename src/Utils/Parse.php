<?php
/**
 * Parse Utility Class
 *
 * Handles ONLY parsing S3 paths into components.
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
 *
 * Handles parsing S3 paths into components
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

		// Strip s3:// protocol if present
		if ( str_starts_with( $path, 's3://' ) ) {
			$path = substr( $path, 5 );
		}

		// Strip leading slash if present
		$path = ltrim( $path, '/' );

		// Split on first slash only
		$parts = explode( '/', $path, 2 );

		// Must have both bucket and object
		if ( count( $parts ) !== 2 || empty( $parts[0] ) || empty( $parts[1] ) ) {
			return false;
		}

		$bucket = $parts[0];
		$object = $parts[1];

		return [
			'bucket' => $bucket,
			'object' => $object
		];
	}

}