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
		$parsed = Parse::path( $path );

		if ( ! $parsed ) {
			return false;
		}

		// Additional check: Must have file extension
		return ! empty( pathinfo( basename( $parsed['object'] ), PATHINFO_EXTENSION ) );
	}

}