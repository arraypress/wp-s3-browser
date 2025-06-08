<?php
/**
 * Browser AJAX Helpers Trait
 *
 * Provides common helper methods for AJAX operations in the S3 Browser.
 *
 * @package     ArrayPress\S3\Traits\Browser\Ajax
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Browser\Ajax;

/**
 * Trait Helpers
 *
 * Provides reusable helper methods for AJAX request handling including
 * security verification, data sanitization, and common response patterns.
 */
trait Helpers {

	/**
	 * Verify AJAX request security and permissions
	 *
	 * Checks nonce verification and user capabilities in one call.
	 * Automatically sends JSON error response if verification fails.
	 *
	 * @return bool True if request is valid, false if error was sent
	 */
	private function verify_ajax_request(): bool {
		if ( ! check_ajax_referer( 's3_browser_nonce_' . $this->provider_id, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed', 'arraypress' ) ] );

			return false;
		}

		if ( ! current_user_can( $this->capability ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action', 'arraypress' ) ] );

			return false;
		}

		return true;
	}

	/**
	 * Get sanitized POST data with proper character handling
	 *
	 * @param string $key                    Key to retrieve from $_POST
	 * @param bool   $preserve_special_chars Whether to preserve special characters (default: false)
	 *
	 * @return string Sanitized value or empty string
	 */
	private function get_sanitized_post( string $key, bool $preserve_special_chars = false ): string {
		if ( ! isset( $_POST[ $key ] ) ) {
			return '';
		}

		$value = $_POST[ $key ];

		// Handle character preservation for file paths and names with special characters
		if ( $preserve_special_chars ) {
			$value = wp_unslash( sanitize_text_field( $value ) );
		} else {
			$value = sanitize_text_field( $value );
		}

		return $value;
	}

	/**
	 * Send standardized success response
	 *
	 * @param string $message Success message
	 * @param array  $data    Additional data to include
	 *
	 * @return void
	 */
	private function send_success_response( string $message, array $data = [] ): void {
		$response = array_merge( [ 'message' => $message ], $data );
		wp_send_json_success( $response );
	}

	/**
	 * Send standardized error response
	 *
	 * @param string $message Error message
	 * @param array  $data    Additional data to include
	 *
	 * @return void
	 */
	private function send_error_response( string $message, array $data = [] ): void {
		$response = array_merge( [ 'message' => $message ], $data );
		wp_send_json_error( $response );
	}

	/**
	 * Validate required POST parameters
	 *
	 * @param array $required_params        Array of required parameter names
	 * @param bool  $preserve_special_chars Whether to preserve special characters
	 *
	 * @return array|false Array of sanitized values or false if validation failed
	 */
	private function validate_required_params( array $required_params, bool $preserve_special_chars = false ) {
		$values = [];

		foreach ( $required_params as $param ) {
			$value = $this->get_sanitized_post( $param, $preserve_special_chars );

			if ( empty( $value ) ) {
				$this->send_error_response(
					sprintf( __( '%s is required', 'arraypress' ), ucfirst( str_replace( '_', ' ', $param ) ) )
				);

				return false;
			}

			$values[ $param ] = $value;
		}

		return $values;
	}

}