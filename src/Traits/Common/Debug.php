<?php
/**
 * Debug Trait
 *
 * Provides unified debug functionality for S3 operations.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Common;

/**
 * Trait Debug
 *
 * Unified debug functionality for consistent logging across S3 operations
 */
trait Debug {

	/**
	 * Debug mode flag
	 *
	 * @var bool
	 */
	private bool $debug = false;

	/**
	 * Debug callback function
	 *
	 * @var callable|null
	 */
	private $debug_callback = null;

	/**
	 * Log debug information if debug mode is enabled
	 *
	 * @param string $title Debug message title
	 * @param mixed  $data  Optional data to include in debug output
	 */
	protected function debug( string $title, $data = null ): void {
		if ( ! $this->debug ) {
			return;
		}

		if ( is_callable( $this->debug_callback ) ) {
			call_user_func( $this->debug_callback, $title, $data );
			return;
		}

		// Fallback to error_log
		error_log( "[S3 DEBUG] {$title}" );
		if ( $data !== null ) {
			error_log( print_r( $data, true ) );
		}
	}

	/**
	 * Debug request details with standardized formatting
	 *
	 * @param string $operation Operation name (e.g., 'get_object', 'list_buckets')
	 * @param string $url       Request URL
	 * @param array  $headers   Request headers
	 */
	protected function debug_request_details( string $operation, string $url, array $headers = [] ): void {
		if ( ! $this->debug ) {
			return;
		}

		$operation_title = ucfirst( str_replace( '_', ' ', $operation ) );

		$this->debug( "{$operation_title} Request URL", $url );

		if ( ! empty( $headers ) ) {
			// Filter sensitive headers for debug output
			$safe_headers = $this->filter_sensitive_headers( $headers );
			$this->debug( "{$operation_title} Request Headers", $safe_headers );
		}
	}

	/**
	 * Debug response details with standardized formatting
	 *
	 * @param string $operation    Operation name
	 * @param int    $status_code  HTTP status code
	 * @param mixed  $body         Response body (optional)
	 * @param array  $headers      Response headers (optional)
	 */
	protected function debug_response_details( string $operation, int $status_code, $body = null, array $headers = [] ): void {
		if ( ! $this->debug ) {
			return;
		}

		$operation_title = ucfirst( str_replace( '_', ' ', $operation ) );

		$this->debug( "{$operation_title} Response Status", $status_code );

		if ( ! empty( $headers ) ) {
			$this->debug( "{$operation_title} Response Headers", $headers );
		}

		if ( $body !== null ) {
			// Truncate very long response bodies for readability
			$debug_body = is_string( $body ) && strlen( $body ) > 1000
				? substr( $body, 0, 1000 ) . '... [truncated]'
				: $body;
			$this->debug( "{$operation_title} Response Body", $debug_body );
		}
	}

	/**
	 * Filter sensitive information from headers for debug output
	 *
	 * @param array $headers Original headers
	 *
	 * @return array Filtered headers with sensitive info masked
	 */
	private function filter_sensitive_headers( array $headers ): array {
		$filtered = [];
		$sensitive_keys = [
			'authorization',
			'x-amz-signature',
			'x-amz-credential'
		];

		foreach ( $headers as $key => $value ) {
			$lower_key = strtolower( $key );

			if ( in_array( $lower_key, $sensitive_keys, true ) ) {
				$filtered[ $key ] = '[FILTERED]';
			} else {
				$filtered[ $key ] = $value;
			}
		}

		return $filtered;
	}

	/**
	 * Enable or disable debug mode
	 *
	 * @param bool $enable Whether to enable debug mode
	 *
	 * @return self
	 */
	public function set_debug( bool $enable ): self {
		$this->debug = $enable;

		return $this;
	}

	/**
	 * Check if debug mode is enabled
	 *
	 * @return bool
	 */
	public function is_debug_enabled(): bool {
		return $this->debug;
	}

	/**
	 * Set a custom debug callback function
	 *
	 * @param callable $callback Function to call for debug logging
	 *                          Signature: function ( string $title, mixed $data )
	 *
	 * @return self
	 */
	public function set_debug_callback( callable $callback ): self {
		$this->debug_callback = $callback;

		return $this;
	}

	/**
	 * Remove the debug callback (fallback to error_log)
	 *
	 * @return self
	 */
	public function remove_debug_callback(): self {
		$this->debug_callback = null;

		return $this;
	}

}