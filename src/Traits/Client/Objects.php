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
use ArrayPress\S3\Responses\SuccessResponse;
use ArrayPress\S3\Responses\ObjectsResponse;
use ArrayPress\S3\Utils\Directory;
use WP_Error;
use Generator;

/**
 * Trait ObjectOperations
 */
trait Objects {

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

	/**
	 * Delete an object from a bucket
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 *
	 * @return ResponseInterface|WP_Error Response or error
	 */
	public function delete_object( string $bucket, string $object_key ) {
		// Use signer to delete object
		$result = $this->signer->delete_object( $bucket, $object_key );

		// Handle errors
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Debug logging if enabled
		if ( $this->debug ) {
			$this->log_debug( 'Client: Raw result from signer for delete operation:', $result );
		}

		// If we're caching, we need to bust the cache for this bucket/prefix
		if ( $this->is_cache_enabled() ) {
			// Extract the directory prefix from the object key
			$prefix = Directory::prefix( $object_key );

			// Clear cache for this specific prefix
			$cache_key = $this->get_cache_key( 'objects_' . $bucket, [
				'max_keys'  => 1000,
				'prefix'    => $prefix,
				'delimiter' => '/'
			] );
			$this->clear_cache_item( $cache_key );
		}

		return $result;
	}

	/**
	 * Copy an object within or between buckets
	 *
	 * @param string $source_bucket Source bucket name
	 * @param string $source_key    Source object key
	 * @param string $target_bucket Target bucket name
	 * @param string $target_key    Target object key
	 *
	 * @return ResponseInterface|WP_Error Response or error
	 */
	public function copy_object( string $source_bucket, string $source_key, string $target_bucket, string $target_key ) {
		// Use signer to copy object
		$result = $this->signer->copy_object( $source_bucket, $source_key, $target_bucket, $target_key );

		// Handle errors
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Debug logging if enabled
		if ( $this->debug ) {
			$this->log_debug( 'Client: Raw result from signer for copy operation:', $result );
		}

		// Clear cache for target bucket/prefix
		if ( $this->is_cache_enabled() ) {
			// Extract the directory prefix from the target object key
			$prefix = Directory::prefix( $target_key );

			// Clear cache for this specific prefix
			$cache_key = $this->get_cache_key( 'objects_' . $target_bucket, [
				'max_keys'  => 1000,
				'prefix'    => $prefix,
				'delimiter' => '/'
			] );
			$this->clear_cache_item( $cache_key );
		}

		return $result;
	}

	/**
	 * Check if an object exists in a bucket
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 * @param bool   $use_cache  Whether to use cache
	 *
	 * @return ResponseInterface Response with existence info or error
	 */
	public function object_exists( string $bucket, string $object_key, bool $use_cache = true ): ResponseInterface {
		if ( empty( $bucket ) || empty( $object_key ) ) {
			return new ErrorResponse(
				__( 'Bucket and object key are required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		// Check cache if enabled
		if ( $use_cache && $this->is_cache_enabled() ) {
			$cache_key = $this->get_cache_key( 'object_exists', [
				'bucket' => $bucket,
				'key'    => $object_key
			] );
			$cached    = $this->get_from_cache( $cache_key );
			if ( $cached !== false ) {
				return $cached;
			}
		}

		// Use HEAD request to check object existence - more efficient than GET
		$head_result = $this->signer->head_object( $bucket, $object_key );

		if ( ! is_wp_error( $head_result ) && $head_result->is_successful() ) {
			// Object exists - get metadata from the response
			$metadata = $head_result->get_metadata();

			$response = new SuccessResponse(
				sprintf( __( 'Object "%s" exists in bucket "%s"', 'arraypress' ), $object_key, $bucket ),
				200,
				[
					'bucket'     => $bucket,
					'object_key' => $object_key,
					'exists'     => true,
					'metadata'   => $metadata,
					'method'     => 'head_object'
				]
			);

			// Cache the positive result
			if ( $use_cache && $this->is_cache_enabled() ) {
				$this->save_to_cache( $cache_key, $response );
			}

			return $response;
		}

		// Handle WP_Error
		if ( is_wp_error( $head_result ) ) {
			$error_code    = $head_result->get_error_code();
			$error_message = $head_result->get_error_message();

			// Common error codes that indicate object doesn't exist
			$not_found_codes = [ 'NoSuchKey', 'object_not_found', 'not_found', '404' ];

			if ( in_array( $error_code, $not_found_codes, true ) ||
			     strpos( $error_message, 'does not exist' ) !== false ||
			     strpos( $error_message, 'not found' ) !== false ||
			     strpos( $error_message, 'NoSuchKey' ) !== false ) {

				$response = new SuccessResponse(
					sprintf( __( 'Object "%s" does not exist in bucket "%s"', 'arraypress' ), $object_key, $bucket ),
					404,
					[
						'bucket'     => $bucket,
						'object_key' => $object_key,
						'exists'     => false,
						'error_code' => $error_code,
						'method'     => 'head_object'
					]
				);

				// Cache the negative result (with shorter TTL)
				if ( $use_cache && $this->is_cache_enabled() ) {
					$this->save_to_cache( $cache_key, $response );
				}

				return $response;
			}

			// For other errors (like access denied), we can't determine existence
			return new ErrorResponse(
				sprintf(
					__( 'Unable to determine if object "%s" exists in bucket "%s": %s', 'arraypress' ),
					$object_key,
					$bucket,
					$error_message
				),
				'object_check_failed',
				400,
				[
					'bucket'           => $bucket,
					'object_key'       => $object_key,
					'original_error'   => $error_code,
					'original_message' => $error_message
				]
			);
		}

		// If result is not successful but not a WP_Error
		if ( method_exists( $head_result, 'get_error_code' ) ) {
			$error_code  = $head_result->get_error_code();
			$status_code = $head_result->get_status_code();

			// Check for 404 or NoSuchKey errors
			if ( $status_code === 404 || in_array( $error_code, [ 'NoSuchKey', 'object_not_found' ], true ) ) {
				$response = new SuccessResponse(
					sprintf( __( 'Object "%s" does not exist in bucket "%s"', 'arraypress' ), $object_key, $bucket ),
					404,
					[
						'bucket'     => $bucket,
						'object_key' => $object_key,
						'exists'     => false,
						'error_code' => $error_code,
						'method'     => 'head_object'
					]
				);

				if ( $use_cache && $this->is_cache_enabled() ) {
					$this->save_to_cache( $cache_key, $response );
				}

				return $response;
			}
		}

		// Fallback - unable to determine
		return new ErrorResponse(
			sprintf( __( 'Unable to determine if object "%s" exists in bucket "%s"', 'arraypress' ), $object_key, $bucket ),
			'object_check_failed',
			400,
			[
				'bucket'     => $bucket,
				'object_key' => $object_key
			]
		);
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

			if ( is_wp_error( $check_result ) ) {
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

		return new SuccessResponse(
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
	}

	/**
	 * Check if an object exists and get basic info without downloading content
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 * @param bool   $use_cache  Whether to use cache
	 *
	 * @return ResponseInterface Response with object info or error
	 */
	public function get_object_info( string $bucket, string $object_key, bool $use_cache = true ): ResponseInterface {
		if ( empty( $bucket ) || empty( $object_key ) ) {
			return new ErrorResponse(
				__( 'Bucket and object key are required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		// Use the object_exists method which already uses HEAD and includes metadata
		$exists_result = $this->object_exists( $bucket, $object_key, $use_cache );

		if ( is_wp_error( $exists_result ) ) {
			return $exists_result;
		}

		$data = $exists_result->get_data();

		if ( ! $data['exists'] ) {
			return new ErrorResponse(
				sprintf( __( 'Object "%s" does not exist in bucket "%s"', 'arraypress' ), $object_key, $bucket ),
				'object_not_found',
				404,
				[
					'bucket'     => $bucket,
					'object_key' => $object_key
				]
			);
		}

		// Enhance the response with additional computed information
		$metadata = $data['metadata'] ?? [];

		// Add computed fields
		$enhanced_data = [
			'bucket'                => $bucket,
			'object_key'            => $object_key,
			'exists'                => true,
			'filename'              => basename( $object_key ),
			'directory'             => dirname( $object_key ),
			'extension'             => pathinfo( $object_key, PATHINFO_EXTENSION ),
			'metadata'              => $metadata,
			'formatted_size'        => isset( $metadata['content_length'] ) ? size_format( $metadata['content_length'] ) : '',
			'is_folder_placeholder' => ( $object_key === rtrim( $object_key, '/' ) . '/' &&
			                             ( $metadata['content_type'] ?? '' ) === 'application/x-directory' ),
			'method'                => 'head_object'
		];

		// Parse last modified date if available
		if ( ! empty( $metadata['last_modified'] ) ) {
			$enhanced_data['last_modified_timestamp'] = strtotime( $metadata['last_modified'] );
			$enhanced_data['last_modified_formatted'] = date( 'Y-m-d H:i:s', $enhanced_data['last_modified_timestamp'] );
		}

		return new SuccessResponse(
			sprintf( __( 'Object information for "%s" in bucket "%s"', 'arraypress' ), $object_key, $bucket ),
			200,
			$enhanced_data
		);
	}

}