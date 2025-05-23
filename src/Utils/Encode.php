<?php
/**
 * Encode Utility Class
 *
 * Handles URL encoding for S3 operations.
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
 * Class Encode
 *
 * Handles URL encoding for S3 operations
 */
class Encode {

	/**
	 * URL encode S3 object key properly for all use cases
	 *
	 * @param string $object_key S3 object key to encode
	 *
	 * @return string Encoded object key
	 */
	public static function object_key( string $object_key ): string {
		// Remove any leading slash
		$object_key = ltrim( $object_key, '/' );

		if ( empty( $object_key ) ) {
			return '';
		}

		// Decode first to avoid double encoding, then encode properly
		$decoded = rawurldecode( $object_key );

		// Encode but preserve forward slashes for path structure
		return str_replace( '%2F', '/', rawurlencode( $decoded ) );
	}

	/**
	 * Decode object key from URL
	 *
	 * @param string $encoded_key Encoded object key
	 *
	 * @return string Decoded object key
	 */
	public static function decode_object_key( string $encoded_key ): string {
		return rawurldecode( $encoded_key );
	}

}