<?php
/**
 * Client Object Operations Trait
 *
 * Handles object-related operations for the S3 Client.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Client;

use ArrayPress\S3\Interfaces\Response as ResponseInterface;
use ArrayPress\S3\Responses\ErrorResponse;
use ArrayPress\S3\Responses\ObjectsResponse;
use WP_Error;
use Generator;

/**
 * Trait ObjectOperations
 */
trait Items {

	/**
	 * Get objects in a bucket
	 *
	 * @param string $bucket             Bucket name
	 * @param int    $max_keys           Maximum number of objects to return
	 * @param string $prefix             Prefix to filter objects
	 * @param string $delimiter          Delimiter (e.g., '/' for folder-like structure)
	 * @param string $continuation_token Continuation token for pagination
	 * @param bool   $use_cache          Whether to use cache
	 *
	 * @return ResponseInterface|WP_Error Response or error
	 */
	public function get_objects(
		string $bucket,
		int $max_keys = 1000,
		string $prefix = '',
		string $delimiter = '/',
		string $continuation_token = '',
		bool $use_cache = true
	) {
		// Check cache if enabled
		if ( $use_cache && $this->is_cache_enabled() ) {
			$cache_key = $this->get_cache_key( 'objects_' . $bucket, [
				'max_keys'           => $max_keys,
				'prefix'             => $prefix,
				'delimiter'          => $delimiter,
				'continuation_token' => $continuation_token
			] );

			$cached = $this->get_from_cache( $cache_key );
			if ( $cached !== false ) {
				return $cached;
			}
		}

		// Use signer to list objects
		$result = $this->signer->list_objects(
			$bucket,
			$max_keys,
			$prefix,
			$delimiter,
			$continuation_token
		);

		// Handle errors
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Debug logging if enabled
		if ( $this->debug ) {
			$this->log_debug( 'Client: Raw result from signer for objects:', $result );
		}

		// Cache the result if successful
		if ( $use_cache && $this->is_cache_enabled() && $result->is_successful() ) {
			$this->save_to_cache( $cache_key, $result );
		}

		return $result;
	}

	/**
	 * Get objects as models
	 *
	 * @param string $bucket             Bucket name
	 * @param int    $max_keys           Maximum number of objects to return
	 * @param string $prefix             Prefix to filter objects
	 * @param string $delimiter          Delimiter (e.g., '/' for folder-like structure)
	 * @param string $continuation_token Continuation token for pagination
	 * @param bool   $use_cache          Whether to use cache
	 *
	 * @return array|WP_Error Array of models or WP_Error
	 */
	public function get_object_models(
		string $bucket,
		int $max_keys = 1000,
		string $prefix = '',
		string $delimiter = '/',
		string $continuation_token = '',
		bool $use_cache = true
	) {
		// Get regular object response
		$response = $this->get_objects(
			$bucket,
			$max_keys,
			$prefix,
			$delimiter,
			$continuation_token,
			$use_cache
		);

		// Handle errors
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Check if the response is an ErrorResponse and convert it to WP_Error
		if ( $response instanceof ErrorResponse ) {
			return new WP_Error(
				$response->get_error_code(),
				$response->get_error_message(),
				[ 'status' => $response->get_status_code() ]
			);
		}

		if ( ! ( $response instanceof ObjectsResponse ) ) {
			return new WP_Error(
				'invalid_response',
				'Expected ObjectsResponse but got ' . get_class( $response )
			);
		}

		// Return result using response object's transformation methods
		return [
			'objects'            => $response->to_object_models(),
			'prefixes'           => $response->to_prefix_models(),
			'truncated'          => $response->is_truncated(),
			'continuation_token' => $response->get_continuation_token(),
			'response_object'    => $response  // Return the response object too
		];
	}

	/**
	 * Get objects as models using an iterator for automatic pagination
	 *
	 * @param string $bucket    Bucket name
	 * @param string $prefix    Prefix to filter objects
	 * @param string $delimiter Delimiter (e.g., '/' for folder-like structure)
	 * @param int    $max_keys  Maximum number of objects to return per request
	 * @param bool   $use_cache Whether to use cache
	 *
	 * @return Generator|WP_Error Generator yielding models or WP_Error
	 */
	public function get_objects_iterator(
		string $bucket,
		string $prefix = '',
		string $delimiter = '/',
		int $max_keys = 1000,
		bool $use_cache = true
	) {
		$continuation_token = '';

		do {
			$result = $this->get_object_models(
				$bucket,
				$max_keys,
				$prefix,
				$delimiter,
				$continuation_token,
				$use_cache
			);

			// Check for errors
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Yield the objects and prefixes
			foreach ( $result['objects'] as $object ) {
				yield 'object' => $object;
			}

			foreach ( $result['prefixes'] as $prefix_model ) {
				yield 'prefix' => $prefix_model;
			}

			// Update continuation token for the next iteration
			$continuation_token = $result['truncated'] ? $result['continuation_token'] : '';

		} while ( ! empty( $continuation_token ) );
	}

}