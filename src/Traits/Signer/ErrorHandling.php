<?php
/**
 * Utilities Trait
 *
 * Provides essential utility methods for S3 operations.
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
trait ErrorHandling {

	/**
	 * Handle error responses
	 *
	 * @param int $status_code HTTP status code
	 * @param string $body Response body
	 * @param string $default_msg Default error message
	 * @return ErrorResponse
	 */
	private function handle_error_response( int $status_code, string $body, string $default_msg ): ErrorResponse {
		// Try to parse an error message from XML if available
		if ( strpos( $body, '<?xml' ) !== false ) {
			$error_xml = $this->parse_xml_response( $body, false );
			if ( ! is_wp_error( $error_xml ) && isset( $error_xml['Error'] ) ) {
				$error_info    = $error_xml['Error'];
				$error_message = $this->extract_text_value( $error_info['Message'] ?? '' );
				$error_code    = $this->extract_text_value( $error_info['Code'] ?? 'unknown_error' );

				return new ErrorResponse( $error_message, $error_code, $status_code );
			}
		}

		return new ErrorResponse( $default_msg, 'request_failed', $status_code );
	}

}