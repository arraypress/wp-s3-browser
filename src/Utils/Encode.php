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

use InvalidArgumentException;

/**
 * Class Encode
 *
 * Handles URL encoding for S3 operations
 */
class Encode {

	/**
	 * URL encode an S3 object key for use in a request path
	 *
	 * Percent-encodes the whole key, then restores '/' so multi-segment keys
	 * keep their hierarchy. The result is used both for the URL that goes on
	 * the wire and for the SigV4 canonical URI, so the two cannot drift.
	 *
	 * @param string $object_key S3 object key to encode
	 *
	 * @return string Encoded object key
	 *
	 * @throws InvalidArgumentException If the key contains a path-traversal
	 *                                  segment or a NUL byte.
	 */
	public static function object_key( string $object_key ): string {
		$object_key = ltrim( $object_key, '/' );

		if ( '' === $object_key ) {
			return '';
		}

		$decoded = rawurldecode( $object_key );

		// A NUL byte truncates the key in any C-backed consumer.
		if ( str_contains( $decoded, "\0" ) ) {
			throw new InvalidArgumentException( 'S3 object key contains a NUL byte.' );
		}

		// Reject traversal, but only where '.' or '..' is a whole path segment.
		// A substring test would also reject perfectly valid keys such as
		// "mixes/v1..2.wav" or "report..final.pdf" — S3 permits dots anywhere
		// in a key, and silently mangling those names is its own bug.
		foreach ( explode( '/', str_replace( '\\', '/', $decoded ) ) as $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				throw new InvalidArgumentException(
					'S3 object key contains a path traversal segment: ' . $object_key
				);
			}
		}

		return str_replace( '%2F', '/', rawurlencode( $decoded ) );
	}

	/**
	 * Encode an object key, returning null instead of throwing
	 *
	 * For callers that want to branch on a rejected key rather than handle an
	 * exception.
	 *
	 * @param string $object_key S3 object key to encode
	 *
	 * @return string|null Encoded key, or null if the key was rejected
	 */
	public static function try_object_key( string $object_key ): ?string {
		try {
			return self::object_key( $object_key );
		} catch ( InvalidArgumentException $e ) {
			return null;
		}
	}

}
