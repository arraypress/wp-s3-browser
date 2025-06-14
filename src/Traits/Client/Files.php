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
use ArrayPress\S3\Utils\Directory;
use ArrayPress\S3\Utils\File;
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

	/**
	 * Get objects as models using an iterator for automatic pagination
	 *
	 * @param string $bucket    Bucket name
	 * @param string $prefix    Prefix to filter objects
	 * @param string $delimiter Delimiter (e.g., '/' for folder-like structure)
	 * @param int    $max_keys  Maximum number of objects to return per request
	 * @param bool   $use_cache Whether to use cache
	 *
	 * @return Generator Generator yielding models
	 */
	public function get_objects_iterator(
		string $bucket,
		string $prefix = '',
		string $delimiter = '/',
		int $max_keys = 1000,
		bool $use_cache = true
	): Generator {
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
			if ( ! $result->is_successful() ) {
				return;
			}

			$data = $result->get_data();

			// Yield the objects and prefixes
			foreach ( $data['objects'] as $object ) {
				yield 'object' => $object;
			}

			foreach ( $data['prefixes'] as $prefix_model ) {
				yield 'prefix' => $prefix_model;
			}

			// Update continuation token for the next iteration
			$continuation_token = $data['truncated'] ? $data['continuation_token'] : '';

		} while ( ! empty( $continuation_token ) );
	}

	/**
	 * Check if multiple objects exist in a bucket
	 *
	 * @param string $bucket      Bucket name
	 * @param array  $object_keys Array of object keys to check
	 * @param bool   $use_cache   Whether to use cache
	 *
	 * @return ResponseInterface Response with existence info for all objects
	 */
	public function objects_exist( string $bucket, array $object_keys, bool $use_cache = true ): ResponseInterface {
		// Apply contextual filter to modify parameters
		$params = $this->apply_contextual_filters(
			'arraypress_s3_objects_exist_params',
			[
				'bucket'      => $bucket,
				'object_keys' => $object_keys,
				'use_cache'   => $use_cache
			],
			$bucket
		);

		$bucket      = $params['bucket'];
		$object_keys = $params['object_keys'];
		$use_cache   = $params['use_cache'];

		if ( empty( $bucket ) ) {
			return new ErrorResponse(
				__( 'Bucket name is required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		if ( empty( $object_keys ) ) {
			return new ErrorResponse(
				__( 'At least one object key is required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		$results    = [];
		$all_exist  = true;
		$none_exist = true;
		$errors     = [];

		foreach ( $object_keys as $object_key ) {
			if ( ! is_string( $object_key ) || empty( $object_key ) ) {
				$errors[] = sprintf( __( 'Invalid object key: %s', 'arraypress' ), $object_key );
				continue;
			}

			$check_result = $this->object_exists( $bucket, $object_key, $use_cache );

			if ( ! $check_result->is_successful() ) {
				$errors[]               = sprintf(
					__( 'Error checking object "%s": %s', 'arraypress' ),
					$object_key,
					$check_result->get_error_message()
				);
				$results[ $object_key ] = [
					'exists'   => null,
					'error'    => $check_result->get_error_message(),
					'metadata' => null
				];
				$all_exist              = false;
			} else {
				$data   = $check_result->get_data();
				$exists = $data['exists'] ?? false;

				$results[ $object_key ] = [
					'exists'   => $exists,
					'error'    => null,
					'metadata' => $exists ? ( $data['metadata'] ?? null ) : null
				];

				if ( $exists ) {
					$none_exist = false;
				} else {
					$all_exist = false;
				}
			}
		}

		// Determine overall status
		$status_code = 200;
		if ( $all_exist && empty( $errors ) ) {
			$message = sprintf( __( 'All objects exist in bucket "%s"', 'arraypress' ), $bucket );
		} elseif ( $none_exist && empty( $errors ) ) {
			$message     = sprintf( __( 'None of the objects exist in bucket "%s"', 'arraypress' ), $bucket );
			$status_code = 404;
		} else {
			$message     = sprintf( __( 'Mixed results for object existence in bucket "%s"', 'arraypress' ), $bucket );
			$status_code = 207; // Multi-Status
		}

		$response = new SuccessResponse(
			$message,
			$status_code,
			[
				'bucket'  => $bucket,
				'objects' => $results,
				'summary' => [
					'total_checked' => count( $object_keys ),
					'all_exist'     => $all_exist,
					'none_exist'    => $none_exist,
					'error_count'   => count( $errors )
				],
				'errors'  => $errors
			]
		);

		// Apply contextual filter to final response
		return $this->apply_contextual_filters(
			'arraypress_s3_objects_exist_response',
			$response,
			$bucket,
			$object_keys
		);
	}

	/**
	 * Get count of accessible objects in a bucket
	 *
	 * @param string $bucket          Bucket name
	 * @param string $prefix          Optional prefix to filter objects
	 * @param bool   $exclude_folders Whether to exclude folder placeholders from count
	 * @param bool   $use_cache       Whether to use cache (default false for real-time results)
	 *
	 * @return ResponseInterface Response with object count
	 */
	public function get_object_count(
		string $bucket,
		string $prefix = '',
		bool $exclude_folders = true,
		bool $use_cache = false
	): ResponseInterface {
		// Apply contextual filter to modify request parameters
		$params = $this->apply_contextual_filters(
			'arraypress_s3_get_object_count_params',
			[
				'bucket'          => $bucket,
				'prefix'          => $prefix,
				'exclude_folders' => $exclude_folders,
				'use_cache'       => $use_cache
			],
			$bucket,
			$prefix
		);

		$bucket          = $params['bucket'];
		$prefix          = $params['prefix'];
		$exclude_folders = $params['exclude_folders'];
		$use_cache       = $params['use_cache'];

		// Check cache if enabled
		if ( $use_cache && $this->is_cache_enabled() ) {
			$cache_key = $this->get_cache_key( 'object_count', [
				'bucket'          => $bucket,
				'prefix'          => $prefix,
				'exclude_folders' => $exclude_folders
			] );
			$cached    = $this->get_from_cache( $cache_key );
			if ( $cached !== false ) {
				return $cached;
			}
		}

		// Get object models (limit high enough to get all objects in reasonable buckets)
		// Use empty delimiter to get all objects without folder separation
		$result = $this->get_object_models( $bucket, 10000, $prefix, '', '', $use_cache );

		if ( ! $result->is_successful() ) {
			return new ErrorResponse(
				sprintf( __( 'Unable to retrieve object count for bucket "%s"', 'arraypress' ), $bucket ),
				'object_count_failed',
				400,
				[ 'original_error' => $result->get_error_message() ]
			);
		}

		$data          = $result->get_data();
		$objects       = $data['objects'] ?? [];
		$total_objects = count( $objects );
		$object_count  = $total_objects;

		// Filter out folder placeholders if requested
		$filtered_objects = [];
		if ( $exclude_folders ) {
			foreach ( $objects as $object ) {
				// Skip folder placeholders and hidden files using the same logic as ObjectsResponse
				if ( $object->should_be_excluded() ) {
					continue;
				}

				$filtered_objects[] = $object;
			}
			$object_count = count( $filtered_objects );
		} else {
			$filtered_objects = $objects;
		}

		// Build response data
		$response_data = [
			'bucket'           => $bucket,
			'prefix'           => $prefix,
			'count'            => $object_count,
			'total_count'      => $total_objects,
			'exclude_folders'  => $exclude_folders,
			'folders_excluded' => $exclude_folders ? ( $total_objects - $object_count ) : 0,
			'truncated'        => $data['truncated'] ?? false
		];

		// Add object keys if the count is reasonable
		if ( $object_count <= 1000 ) {
			$response_data['object_keys'] = array_map( function ( $object ) {
				return $object->get_key();
			}, $filtered_objects );
		}

		// Create appropriate message
		$message_parts = [];
		if ( ! empty( $prefix ) ) {
			$message_parts[] = sprintf( __( 'prefix "%s"', 'arraypress' ), $prefix );
		}
		if ( $exclude_folders && ( $total_objects - $object_count ) > 0 ) {
			$message_parts[] = __( 'excluding folders', 'arraypress' );
		}

		$message_suffix = ! empty( $message_parts ) ? ' (' . implode( ', ', $message_parts ) . ')' : '';

		$message = sprintf(
			_n(
				'Found %d object in bucket "%s"%s',
				'Found %d objects in bucket "%s"%s',
				$object_count,
				'arraypress'
			),
			$object_count,
			$bucket,
			$message_suffix
		);

		$response = new SuccessResponse( $message, 200, $response_data );

		// Cache the result if successful
		if ( $use_cache && $this->is_cache_enabled() ) {
			$this->save_to_cache( $cache_key, $response );
		}

		// Apply contextual filter to final response
		return $this->apply_contextual_filters(
			'arraypress_s3_get_object_count_response',
			$response,
			$bucket,
			$prefix
		);
	}


}