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

namespace ArrayPress\S3\Traits\Shared;

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
	 * @param mixed  $headers      Response headers (optional - array or CaseInsensitiveDictionary)
	 */
	protected function debug_response_details( string $operation, int $status_code, $body = null, $headers = null ): void {
		if ( ! $this->debug ) {
			return;
		}

		$operation_title = ucfirst( str_replace( '_', ' ', $operation ) );

		$this->debug( "{$operation_title} Response Status", $status_code );

		if ( ! empty( $headers ) ) {
			// Convert headers to array if it's a CaseInsensitiveDictionary
			$headers_array = $this->normalize_headers( $headers );
			if ( ! empty( $headers_array ) ) {
				$this->debug( "{$operation_title} Response Headers", $headers_array );
			}
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
	 * Normalize headers to array format
	 *
	 * @param mixed $headers Headers in various formats
	 *
	 * @return array Normalized headers array
	 */
	private function normalize_headers( $headers ): array {
		if ( is_array( $headers ) ) {
			return $headers;
		}

		// Handle WordPress CaseInsensitiveDictionary
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			return $headers->getAll();
		}

		// Handle other objects that might be iterable
		if ( is_object( $headers ) && method_exists( $headers, 'toArray' ) ) {
			return $headers->toArray();
		}

		// Try to convert iterable objects to array
		if ( is_iterable( $headers ) ) {
			return array_map( function ( $value ) {
				return $value;
			}, $headers );
		}

		// Fallback for unexpected types
		return [];
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

		// A Client owns a Signer with its own flag. Toggling one and not the
		// other is how debug output came to be unreachable.
		//
		// Not `instanceof self`: inside a trait that resolves to the composing
		// class, so it would ask whether a Signer is a Client and always say
		// no.
		if ( isset( $this->signer ) && is_object( $this->signer ) && method_exists( $this->signer, 'set_debug' ) ) {
			$this->signer->set_debug( $enable );
		}

		return $this;
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

}