<?php
/**
 * Error Handling Trait - PHP 7.4 Compatible
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
 * Trait ErrorHandling
 */
trait ErrorHandling {

	/**
	 * Handle error responses with enhanced XML parsing
	 *
	 * @param int    $status_code HTTP status code
	 * @param string $body        Response body
	 * @param string $default_msg Default error message
	 *
	 * @return ErrorResponse
	 */
	private function handle_error_response( int $status_code, string $body, string $default_msg ): ErrorResponse {
		// Parse the provider's error document when there is one.
		//
		// This used to require an '<?xml' declaration. Cloudflare R2 returns
		// the <Error> element without a prolog, so every R2 failure fell
		// through to the caller's generic default and the provider's actual
		// error code — the thing that distinguishes a scoped token from bad
		// credentials — was discarded.
		if ( false !== stripos( $body, '<Error' ) ) {
			$error_xml = $this->parse_response( $body, false );

			if ( ! is_wp_error( $error_xml ) && is_array( $error_xml ) ) {
				// S3 returns <Error> as the *root* element, so the parser hands
				// back its children — ['Code' => ..., 'Message' => ...] — with
				// no 'Error' key to look under. This previously tested for that
				// key alone, which is never present in a real S3 error
				// document, so every provider error fell through to the
				// caller's generic default and the actual code and message were
				// discarded. Accept both shapes.
				$error_info = $error_xml['Error'] ?? $error_xml;

				if ( isset( $error_info['Code'] ) || isset( $error_info['Message'] ) ) {
					return new ErrorResponse(
						$this->extract_text_value( $error_info['Message'] ?? '' ) ?: $default_msg,
						$this->extract_text_value( $error_info['Code'] ?? '' ) ?: 'unknown_error',
						$status_code
					);
				}
			}
		}

		return new ErrorResponse( $default_msg, 'request_failed', $status_code );
	}

}