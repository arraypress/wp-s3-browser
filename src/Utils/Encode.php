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
 * @author      David Sherlock
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
		$object_key = ltrim( $object_key, '/' );

		if ( empty( $object_key ) ) {
			return '';
		}

		$decoded = rawurldecode( $object_key );

		// Silently reject path traversal attempts
		if ( str_contains( $decoded, '..' ) || str_contains( $decoded, "\0" ) ) {
			return '';
		}

		return str_replace( '%2F', '/', rawurlencode( $decoded ) );
	}

}