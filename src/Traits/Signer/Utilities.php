<?php
/**
 * Utilities Trait
 *
 * Provides general utility methods for S3 operations.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Signer;

use ArrayPress\S3\Responses\ErrorResponse;

/**
 * Trait Utilities
 */
trait Utilities {

	/**
	 * Log debug information if callback is set
	 *
	 * @param string $title Debug title
	 * @param mixed  $data  Debug data
	 */
	private function debug( string $title, $data ): void {
		if ( is_callable( $this->debug_callback ) ) {
			call_user_func( $this->debug_callback, $title, $data );
		}
	}

	/**
	 * Handle error responses
	 *
	 * @param int    $status_code HTTP status code
	 * @param string $body        Response body
	 * @param string $default_msg Default error message
	 *
	 * @return ErrorResponse
	 */
	private function handle_error_response( int $status_code, string $body, string $default_msg ): ErrorResponse {
		// Try to parse an error message from XML if available
		if ( strpos( $body, '<?xml' ) !== false ) {
			$error_xml = $this->parse_xml_response( $body, false );
			if ( ! is_wp_error( $error_xml ) && isset( $error_xml['Error'] ) ) {
				$error_info    = $error_xml['Error'];
				$error_message = $error_info['Message']['value'] ?? 'Unknown error';
				$error_code    = $error_info['Code']['value'] ?? 'unknown_error';

				return new ErrorResponse( $error_message, $error_code, $status_code );
			}
		}

		return new ErrorResponse( $default_msg, 'request_failed', $status_code );
	}

	/**
	 * Get a value from a dot-notation path in an array
	 *
	 * @param array  $array The array to search
	 * @param string $path  Dot notation path (e.g. "Buckets.Bucket")
	 *
	 * @return mixed|null The value or null if not found
	 */
	private function get_value_from_path( array $array, string $path ) {
		$keys    = explode( '.', $path );
		$current = $array;

		foreach ( $keys as $key ) {
			if ( ! is_array( $current ) || ! isset( $current[ $key ] ) ) {
				return null;
			}
			$current = $current[ $key ];
		}

		return $current;
	}

}