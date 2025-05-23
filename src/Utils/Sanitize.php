<?php
/**
 * Sanitize Utility Class
 *
 * Handles sanitization of S3 paths, buckets, and objects.
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
 * Class Sanitize
 *
 * Handles sanitization of S3 paths, buckets, and objects
 */
class Sanitize {

	/**
	 * Sanitize bucket name for S3 compatibility
	 *
	 * @param string $bucket Bucket name to sanitize
	 *
	 * @return string
	 */
	public static function bucket( string $bucket ): string {
		$bucket = trim( $bucket );
		$bucket = strtolower( $bucket );

		// Remove invalid characters, keep only lowercase letters, numbers, hyphens, dots
		$bucket = preg_replace( '/[^a-z0-9\-\.]/', '', $bucket );

		// Remove leading/trailing hyphens and dots
		return trim( $bucket, '-.' );
	}

	/**
	 * Sanitize object key for S3 compatibility
	 *
	 * @param string $object Object key to sanitize
	 *
	 * @return string
	 */
	public static function object( string $object ): string {
		$object = trim( $object );

		// Remove null bytes and control characters
		$object = preg_replace( '/[\x00-\x1F\x7F]/', '', $object );

		// Remove leading slashes
		return ltrim( $object, '/' );
	}

}