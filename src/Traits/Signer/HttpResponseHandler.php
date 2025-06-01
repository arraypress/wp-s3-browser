<?php
/**
 * HTTP Response Handling Trait
 *
 * Provides consolidated HTTP response handling for S3 operations.
 *
 * @package     ArrayPress\S3\Traits\Signer
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Signer;

use ArrayPress\S3\Responses\ErrorResponse;
use WP_Error;

/**
 * Trait HttpResponseHandler
 */
trait HttpResponseHandler {

	/**
	 * Handle HTTP response with consolidated error checking
	 *
	 * @param array|WP_Error $response  The wp_remote_* response
	 * @param string         $operation Operation name for error messages
	 *
	 * @return ErrorResponse|null Returns ErrorResponse on failure, null on success
	 */
	protected function handle_http_response( $response, string $operation ): ?ErrorResponse {
		// Handle WP_Error responses
		if ( is_wp_error( $response ) ) {
			return ErrorResponse::from_wp_error( $response );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		// Debug the response
		$this->debug( ucfirst( $operation ) . " Response Status", $status_code );
		$this->debug( ucfirst( $operation ) . " Response Body", $body );

		// Check for error status codes
		if ( $status_code < 200 || $status_code >= 300 ) {
			return $this->handle_error_response( $status_code, $body, "Failed to {$operation}" );
		}

		return null; // Success
	}

	/**
	 * Extract response data after successful response handling
	 *
	 * @param array|WP_Error $response The wp_remote_* response
	 *
	 * @return array Response data with status_code, body, and headers
	 */
	protected function extract_response_data( $response ): array {
		return [
			'status_code' => wp_remote_retrieve_response_code( $response ),
			'body'        => wp_remote_retrieve_body( $response ),
			'headers'     => wp_remote_retrieve_headers( $response )
		];
	}

	/**
	 * Debug HTTP request details
	 *
	 * @param string $operation Operation name
	 * @param string $url       Request URL
	 * @param array  $headers   Request headers
	 */
	protected function debug_request( string $operation, string $url, array $headers = [] ): void {
		$operation_title = ucfirst( str_replace( '_', ' ', $operation ) );

		$this->debug( "{$operation_title} Request URL", $url );

		if ( ! empty( $headers ) ) {
			$this->debug( "{$operation_title} Request Headers", $headers );
		}
	}

}