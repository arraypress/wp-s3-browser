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
 * @author      David Sherlock
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
	 * Uses conservative approach to support all S3 providers
	 *
	 * @param string $bucket Bucket name to sanitize
	 * @return string
	 */
	public static function bucket( string $bucket ): string {
		$bucket = sanitize_text_field( $bucket );
		$bucket = trim( $bucket );
		$bucket = strtolower( $bucket );

		// Only remove obviously invalid characters for bucket names
		// Keep it conservative to support different providers
		$bucket = preg_replace( '/[^a-z0-9\-.]/', '', $bucket );

		// Remove leading/trailing hyphens and dots (common S3 rule)
		return trim( $bucket, '-.' );
	}

	/**
	 * Sanitize object key for S3 compatibility
	 * Uses conservative approach to support all S3 providers
	 *
	 * @param string $object Object key to sanitize
	 * @return string
	 */
	public static function object( string $object ): string {
		$object = trim( $object );

		// Only remove null bytes and control characters (universally bad)
		$object = preg_replace( '/[\x00-\x1F\x7F]/', '', $object );

		// Remove leading slashes (common S3 rule)
		return ltrim( $object, '/' );
	}

	/**
	 * Sanitize the full S3 path (bucket/object)
	 *
	 * @param string $path Full S3 path to sanitize
	 * @return string Sanitized path or empty string if invalid
	 */
	public static function path( string $path ): string {
		$parsed = Parse::path( $path );
		if ( ! $parsed ) {
			return '';
		}

		return self::bucket( $parsed['bucket'] ) . '/' . self::object( $parsed['object'] );
	}

	/**
	 * Sanitize access key ID
	 * Conservative approach - just trim and basic sanitization
	 *
	 * @param string $access_key Access key ID to sanitize
	 * @return string Sanitized access key
	 */
	public static function access_key( string $access_key ): string {
		return sanitize_text_field( trim( $access_key ) );
	}

	/**
	 * Sanitize secret access key
	 * Very conservative - only trim whitespace
	 *
	 * @param string $secret_key Secret access key to sanitize
	 * @return string Sanitized secret key
	 */
	public static function secret_key( string $secret_key ): string {
		return trim( $secret_key );
	}

	/**
	 * Sanitize account ID
	 * Conservative approach for maximum provider compatibility
	 *
	 * @param string $account_id Account ID to sanitize
	 * @return string Sanitized account ID
	 */
	public static function account_id( string $account_id ): string {
		return sanitize_text_field( trim( $account_id ) );
	}

}
