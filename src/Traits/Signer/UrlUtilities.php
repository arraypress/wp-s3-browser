<?php
/**
 * URL Utilities Trait
 *
 * Provides URL encoding and path utilities for S3 operations.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Signer;

/**
 * Trait UrlUtilities
 */
trait UrlUtilities {

	/**
	 * URL encode S3 object key specially for URLs, avoiding double encoding
	 *
	 * @param string $object_key S3 object key to encode
	 *
	 * @return string Encoded object key
	 */
	protected function encode_object_key_for_url( string $object_key ): string {
		// Remove any leading slash
		$object_key = ltrim( $object_key, '/' );

		// First, make sure any percent-encoded parts are decoded to avoid double encoding
		// This handles cases where the object key was already URL-encoded
		$decoded_key = rawurldecode( $object_key );

		// Now encode spaces (not as plus signs) and special characters, but preserve slashes
		$encoded = '';
		$len     = strlen( $decoded_key );

		for ( $i = 0; $i < $len; $i ++ ) {
			$char = $decoded_key[ $i ];
			if ( $char === '/' ) {
				$encoded .= '/';
			} else if ( $char === ' ' ) {
				$encoded .= '%20';
			} else if ( preg_match( '/[0-9a-zA-Z_.-]/', $char ) ) {
				$encoded .= $char;
			} else {
				$encoded .= rawurlencode( $char );
			}
		}

		return $encoded;
	}

}