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

use WP_REST_Request;

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
	 * Check whether a bucket may be addressed through this browser
	 *
	 * Every AJAX endpoint takes the bucket straight from $_POST, so without a
	 * check here the capability gate ('upload_files' by default — Author and
	 * up) grants read, write, rename and delete over *every* bucket the
	 * configured credentials can reach, not just the one the browser is
	 * pointed at. S3 credentials are usually account-wide, so that is a real
	 * privilege boundary and not a theoretical one.
	 *
	 * An empty allow-list preserves the historical behaviour of permitting any
	 * bucket. Sites that only ever use one bucket should set it — see
	 * Browser::set_allowed_buckets() and the 's3_browser_allowed_buckets'
	 * filter.
	 *
	 * @param string $bucket Bucket name from the request
	 *
	 * @return bool True if the bucket may be used
	 */
	private function is_bucket_allowed( string $bucket ): bool {
		if ( '' === $bucket ) {
			return false;
		}

		// Reject anything that is not a syntactically valid bucket name before
		// it reaches URL building — the value ends up in a Host header or a
		// request path.
		if ( ! preg_match( '/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]$/i', $bucket ) ) {
			return false;
		}

		$allowed = $this->get_allowed_buckets();

		if ( empty( $allowed ) ) {
			return true;
		}

		return in_array( $bucket, $allowed, true );
	}

	/**
	 * Validate the bucket for this request, responding with an error if not allowed
	 *
	 * @param string $bucket Bucket name from the request
	 *
	 * @return bool True if the request may proceed
	 */
	private function verify_bucket( string $bucket ): bool {
		if ( $this->is_bucket_allowed( $bucket ) ) {
			return true;
		}

		wp_send_json_error( [ 'message' => __( 'You do not have permission to access this bucket', 'arraypress' ) ] );

		return false;
	}

	/**
	 * Run a REST handler from a legacy admin-ajax request
	 *
	 * The REST routes are the single implementation of every operation; these
	 * AJAX endpoints remain only so existing front-end code keeps working.
	 * Rather than duplicate the logic — which is how the signing layer ended up
	 * with three diverging copies of one canonical request — each AJAX handler
	 * verifies its nonce and capability, then delegates here.
	 *
	 * Note that $params must already be sanitized by the caller: a manually
	 * constructed WP_REST_Request does not run the route's `args`
	 * sanitize_callbacks, which only fire during real REST dispatch.
	 *
	 * @param string $method REST handler method name on this class.
	 * @param array  $params Sanitized parameters.
	 *
	 * @return void Always terminates via wp_send_json_*().
	 */
	private function dispatch_to_rest( string $method, array $params = [] ): void {
		if ( isset( $params['bucket'] ) && ! $this->verify_bucket( (string) $params['bucket'] ) ) {
			return;
		}

		$request = new WP_REST_Request();

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$result = $this->{$method}( $request );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				[
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				],
				(int) ( $result->get_error_data()['status'] ?? 400 )
			);
		}

		wp_send_json_success( $result->get_data() );
	}

	/**
	 * Get sanitized POST data with proper character handling
	 *
	 * Unslash first, then sanitize. WordPress adds slashes to everything in
	 * $_POST, so sanitizing before unslashing operates on escaped input — which
	 * is why object keys containing an apostrophe used to arrive as "it\'s.wav".
	 * The old default path never unslashed at all, leaving literal backslashes
	 * in every key that contained a quote or backslash.
	 *
	 * @param string $key                    Key to retrieve from $_POST
	 * @param bool   $preserve_special_chars Retained for backwards compatibility.
	 *                                       Both paths now handle special
	 *                                       characters correctly, so this no
	 *                                       longer changes the result.
	 *
	 * @return string Sanitized value or empty string
	 */
	private function get_sanitized_post( string $key, bool $preserve_special_chars = false ): string {
		if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
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