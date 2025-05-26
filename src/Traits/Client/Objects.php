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
 * Trait Objects
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
		// Get regular object response
		$response = $this->get_objects(
			$bucket,
			$max_keys,
			$prefix,
			$delimiter,
			$continuation_token,
			$use_cache
		);

		if ( ! ( $response instanceof ObjectsResponse ) ) {
			return new ErrorResponse(
				'Expected ObjectsResponse but got ' . get_class( $response ),
				'invalid_response',
				400
			);
		}

		// Return success response with transformed data
		return new SuccessResponse(
			'Object models retrieved successfully',
			200,
			[
				'objects'            => $response->to_object_models(),
				'prefixes'           => $response->to_prefix_models(),
				'truncated'          => $response->is_truncated(),
				'continuation_token' => $response->get_continuation_token(),
				'response_object'    => $response
			]
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
	 * Check if an object exists in a bucket
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 * @param bool   $use_cache  Whether to use cache
	 *
	 * @return ResponseInterface Response with existence info
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

		// Use HEAD request to check object existence
		$head_result = $this->signer->head_object( $bucket, $object_key );

		if ( $head_result->is_successful() ) {
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

		// Handle error response
		if ( $head_result instanceof ErrorResponse ) {
			$error_code    = $head_result->get_error_code();
			$error_message = $head_result->get_error_message();
			$status_code   = $head_result->get_status_code();

			// Common error codes that indicate object doesn't exist
			$not_found_codes = [ 'NoSuchKey', 'object_not_found', 'not_found', '404' ];

			if ( $status_code === 404 ||
			     in_array( $error_code, $not_found_codes, true ) ||
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

				// Cache the negative result
				if ( $use_cache && $this->is_cache_enabled() ) {
					$this->save_to_cache( $cache_key, $response );
				}

				return $response;
			}

			// For other errors, we can't determine existence
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
	 * Delete an object from a bucket
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 *
	 * @return ResponseInterface Response
	 */
	public function delete_object( string $bucket, string $object_key ): ResponseInterface {
		// Use signer to delete object
		$result = $this->signer->delete_object( $bucket, $object_key );

		// Debug logging if enabled
		$this->debug( 'Client: Raw result from signer for delete operation:', $result );

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
	 * @return ResponseInterface Response
	 */
	public function copy_object( string $source_bucket, string $source_key, string $target_bucket, string $target_key ): ResponseInterface {
		// Use signer to copy object
		$result = $this->signer->copy_object( $source_bucket, $source_key, $target_bucket, $target_key );

		// Debug logging if enabled
		$this->debug( 'Client: Raw result from signer for copy operation:', $result );

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
	 * Check if an object exists and get basic info without downloading content
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 * @param bool   $use_cache  Whether to use cache
	 *
	 * @return ResponseInterface Response with object info
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

		if ( ! $exists_result->is_successful() ) {
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

	/**
	 * Rename an object in a bucket
	 *
	 * @param string $bucket     Bucket name
	 * @param string $source_key Current object key
	 * @param string $target_key New object key
	 *
	 * @return ResponseInterface Response
	 */
	public function rename_object( string $bucket, string $source_key, string $target_key ): ResponseInterface {
		// 1. Copy the object to the new key
		$copy_result = $this->copy_object( $bucket, $source_key, $bucket, $target_key );

		// If copy failed, return the error
		if ( ! $copy_result->is_successful() ) {
			return new ErrorResponse(
				__( 'Failed to copy object during rename operation', 'arraypress' ),
				'rename_error',
				400,
				[ 'copy_error' => $copy_result ]
			);
		}

		// 2. Delete the original object
		$delete_result = $this->delete_object( $bucket, $source_key );

		// If delete failed, return a warning but still consider the operation successful
		if ( ! $delete_result->is_successful() ) {
			return new SuccessResponse(
				__( 'Object renamed, but failed to delete the original', 'arraypress' ),
				207, // 207 Multi-Status
				[
					'warning'    => __( 'The object was copied but the original could not be deleted', 'arraypress' ),
					'source_key' => $source_key,
					'target_key' => $target_key
				]
			);
		}

		// Both operations succeeded
		return new SuccessResponse(
			__( 'Object renamed successfully', 'arraypress' ),
			200,
			[
				'source_key' => $source_key,
				'target_key' => $target_key
			]
		);
	}

	/**
	 * Upload a file to a bucket
	 *
	 * @param string $bucket            Bucket name
	 * @param string $target_key        Target object key
	 * @param string $file_path         Local file path or file data
	 * @param bool   $is_path           Whether $file_path is a path (true) or file contents (false)
	 * @param string $content_type      Optional content type
	 * @param array  $additional_params Optional additional parameters
	 *
	 * @return ResponseInterface Response
	 */
	public function put_object(
		string $bucket,
		string $target_key,
		string $file_path,
		bool $is_path = true,
		string $content_type = '',
		array $additional_params = []
	): ResponseInterface {
		// 1. Get a presigned upload URL
		$upload_url_response = $this->get_presigned_upload_url( $bucket, $target_key, 15 );

		if ( ! $upload_url_response->is_successful() ) {
			return new ErrorResponse(
				__( 'Failed to generate upload URL', 'arraypress' ),
				'upload_url_error',
				400
			);
		}

		// Get the presigned upload URL
		$upload_url = $upload_url_response->get_url();

		// 2. Determine the content type if not provided
		if ( empty( $content_type ) ) {
			if ( $is_path ) {
				// If it's a file path, determine from the file
				$content_type = mime_content_type( $file_path ) ?: 'application/octet-stream';
			} else {
				// If it's file data, determine from the target key
				$content_type = File::mime_type( $target_key );
			}
		}

		// 3. Read the file contents
		if ( $is_path ) {
			$file_contents = file_get_contents( $file_path );
			if ( $file_contents === false ) {
				return new ErrorResponse(
					__( 'Failed to read file', 'arraypress' ),
					'file_read_error',
					400,
					[ 'file_path' => $file_path ]
				);
			}
		} else {
			$file_contents = $file_path; // When not a path, this contains the actual content
		}

		$content_length = strlen( $file_contents );

		// 4. Prepare headers - ALWAYS include Content-Length
		$headers = array_merge( [
			'Content-Type'   => $content_type,
			'Content-Length' => (string) $content_length
		], $additional_params );

		// 5. Upload the file using WordPress HTTP API
		$response = wp_remote_request( $upload_url, [
			'method'  => 'PUT',
			'body'    => $file_contents,
			'headers' => $headers,
			'timeout' => 300  // 5 minutes for large files
		] );

		// Handle upload errors
		if ( is_wp_error( $response ) ) {
			return new ErrorResponse(
				$response->get_error_message(),
				$response->get_error_code(),
				400,
				$response->get_error_data() ?: []
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			return new ErrorResponse(
				sprintf( __( 'Upload failed with status code: %d', 'arraypress' ), $status_code ),
				'upload_error',
				$status_code,
				[ 'response' => $response ]
			);
		}

		// 6. Clear cache for this bucket/prefix
		if ( $this->is_cache_enabled() ) {
			// Extract the directory prefix from the object key
			$prefix = Directory::prefix( $target_key );

			// Clear cache for this specific prefix
			$cache_key = $this->get_cache_key( 'objects_' . $bucket, [
				'max_keys'  => 1000,
				'prefix'    => $prefix,
				'delimiter' => '/'
			] );
			$this->clear_cache_item( $cache_key );
		}

		// 7. Return success response
		return new SuccessResponse(
			__( 'File uploaded successfully', 'arraypress' ),
			$status_code,
			[
				'bucket' => $bucket,
				'key'    => $target_key,
				'size'   => $content_length
			]
		);
	}

}