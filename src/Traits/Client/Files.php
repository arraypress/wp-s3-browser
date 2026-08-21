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
use ArrayPress\S3\Responses\SuccessResponse;
use Generator;

/**
 * Trait Files
 */
trait Files {

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
	 * @return ResponseInterface Response
	 */
	public function get_objects(
		string $bucket,
		int $max_keys = 1000,
		string $prefix = '',
		string $delimiter = '/',
		string $continuation_token = '',
		bool $use_cache = true
	): ResponseInterface {
		// Allow filtering list parameters
		$list_params = $this->apply_contextual_filters(
			'arraypress_s3_get_objects_params',
			[
				'bucket'             => $bucket,
				'max_keys'           => $max_keys,
				'prefix'             => $prefix,
				'delimiter'          => $delimiter,
				'continuation_token' => $continuation_token,
				'use_cache'          => $use_cache
			],
			$bucket,
			$prefix
		);

		// Extract potentially modified values
		$bucket             = $list_params['bucket'];
		$max_keys           = $list_params['max_keys'];
		$prefix             = $list_params['prefix'];
		$delimiter          = $list_params['delimiter'];
		$continuation_token = $list_params['continuation_token'];
		$use_cache          = $list_params['use_cache'];

		// Check cache if enabled
		if ( $use_cache && $this->is_cache_enabled() ) {
			$cache_key = $this->get_cache_key( 'objects_' . $bucket, [
				'max_keys'           => $max_keys,
				'prefix'             => $prefix,
				'delimiter'          => $delimiter,
				'continuation_token' => $continuation_token
			], $bucket );

			$cached = $this->get_from_cache( $cache_key );
			if ( $cached !== false ) {
				return $cached;
			}
		}

		// Use signer to list objects
		$result = $this->api->list_objects(
			$bucket,
			$max_keys,
			$prefix,
			$delimiter,
			$continuation_token
		);

		// Debug logging if enabled
		$this->debug( 'Client: Raw result from signer for objects:', $result );

		// Cache the result if successful
		if ( $use_cache && $this->is_cache_enabled() && $result->is_successful() ) {
			$this->save_to_cache( $cache_key, $result );
		}

		// Apply contextual filter to final response
		return $this->apply_contextual_filters(
			'arraypress_s3_get_objects_response',
			$result,
			$bucket,
			$prefix,
			$max_keys
		);
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
	 * @return ResponseInterface Response with object models
	 */
	public function get_object_models(
		string $bucket,
		int $max_keys = 1000,
		string $prefix = '',
		string $delimiter = '/',
		string $continuation_token = '',
		bool $use_cache = true
	): ResponseInterface {
		// Apply contextual filter to modify parameters
		$params = $this->apply_contextual_filters(
			'arraypress_s3_get_object_models_params',
			[
				'bucket'             => $bucket,
				'max_keys'           => $max_keys,
				'prefix'             => $prefix,
				'delimiter'          => $delimiter,
				'continuation_token' => $continuation_token,
				'use_cache'          => $use_cache
			],
			$bucket,
			$prefix
		);

		// Get regular object response
		$response = $this->get_objects(
			$params['bucket'],
			$params['max_keys'],
			$params['prefix'],
			$params['delimiter'],
			$params['continuation_token'],
			$params['use_cache']
		);

		if ( ! ( $response instanceof ObjectsResponse ) ) {
			return new ErrorResponse(
				__( 'Unable to retrieve objects. Please verify your access key, secret key, and region settings are correct.', 'arraypress' ),
				'object_retrieval_failed',
				400
			);
		}

		// Transform data
		$data = [
			'objects'            => $response->to_object_models(),
			'prefixes'           => $response->to_prefix_models(),
			'truncated'          => $response->is_truncated(),
			'continuation_token' => $response->get_continuation_token(),
			'response_object'    => $response
		];

		// Apply contextual filter to final response
		$success_response = new SuccessResponse(
			__( 'Object models retrieved successfully', 'arraypress' ),
			200,
			$data
		);

		return $this->apply_contextual_filters(
			'arraypress_s3_get_object_models_response',
			$success_response,
			$bucket,
			$prefix,
			$max_keys
		);
	}

}