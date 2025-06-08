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
use ArrayPress\S3\Utils\File as FileUtil;
use Generator;

/**
 * Trait Objects
 */
trait File {

	/**
	 * Delete an object from a bucket
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 *
	 * @return ResponseInterface Response
	 */
	public function delete_object( string $bucket, string $object_key ): ResponseInterface {
		// Apply contextual filter to modify parameters and allow preventing deletion
		$delete_params = $this->apply_contextual_filters(
			'arraypress_s3_delete_object_params',
			[
				'bucket'     => $bucket,
				'object_key' => $object_key,
				'proceed'    => true // Allow preventing deletion
			],
			$bucket,
			$object_key
		);

		// Check if deletion should proceed
		if ( ! $delete_params['proceed'] ) {
			return new ErrorResponse(
				__( 'Object deletion was prevented by filter', 'arraypress' ),
				'deletion_prevented',
				403,
				[
					'bucket'     => $bucket,
					'object_key' => $object_key
				]
			);
		}

		// Use potentially modified values
		$bucket     = $delete_params['bucket'];
		$object_key = $delete_params['object_key'];

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

		// Apply contextual filter to final response
		return $this->apply_contextual_filters(
			'arraypress_s3_delete_object_response',
			$result,
			$bucket,
			$object_key
		);
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
		// Apply contextual filter to modify parameters
		$params = $this->apply_contextual_filters(
			'arraypress_s3_copy_object_params',
			[
				'source_bucket' => $source_bucket,
				'source_key'    => $source_key,
				'target_bucket' => $target_bucket,
				'target_key'    => $target_key
			],
			$source_bucket,
			$target_bucket
		);

		// Use signer to copy an object
		$result = $this->signer->copy_object(
			$params['source_bucket'],
			$params['source_key'],
			$params['target_bucket'],
			$params['target_key']
		);

		// Debug logging if enabled
		$this->debug( 'Client: Raw result from signer for copy operation:', $result );

		// Clear cache for target bucket/prefix
		if ( $this->is_cache_enabled() ) {
			// Extract the directory prefix from the target object key
			$prefix = Directory::prefix( $params['target_key'] );

			// Clear cache for this specific prefix
			$cache_key = $this->get_cache_key( 'objects_' . $params['target_bucket'], [
				'max_keys'  => 1000,
				'prefix'    => $prefix,
				'delimiter' => '/'
			] );
			$this->clear_cache_item( $cache_key );
		}

		// Apply contextual filter to final response
		return $this->apply_contextual_filters(
			'arraypress_s3_copy_object_response',
			$result,
			$params['source_bucket'],
			$params['target_bucket']
		);
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
		// Apply contextual filter to modify parameters
		$params = $this->apply_contextual_filters(
			'arraypress_s3_get_object_info_params',
			[
				'bucket'     => $bucket,
				'object_key' => $object_key,
				'use_cache'  => $use_cache
			],
			$bucket,
			$object_key
		);

		$bucket     = $params['bucket'];
		$object_key = $params['object_key'];
		$use_cache  = $params['use_cache'];

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

		$response = new SuccessResponse(
			sprintf( __( 'Object information for "%s" in bucket "%s"', 'arraypress' ), $object_key, $bucket ),
			200,
			$enhanced_data
		);

		// Apply contextual filter to final response
		return $this->apply_contextual_filters(
			'arraypress_s3_get_object_info_response',
			$response,
			$bucket,
			$object_key
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
		// Apply contextual filter to modify parameters
		$params = $this->apply_contextual_filters(
			'arraypress_s3_rename_object_params',
			[
				'bucket'     => $bucket,
				'source_key' => $source_key,
				'target_key' => $target_key
			],
			$bucket,
			$source_key,
			$target_key
		);

		$bucket     = $params['bucket'];
		$source_key = $params['source_key'];
		$target_key = $params['target_key'];

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
			$response = new SuccessResponse(
				__( 'Object renamed, but failed to delete the original', 'arraypress' ),
				207, // 207 Multi-Status
				[
					'warning'    => __( 'The object was copied but the original could not be deleted', 'arraypress' ),
					'source_key' => $source_key,
					'target_key' => $target_key
				]
			);
		} else {
			// Both operations succeeded
			$response = new SuccessResponse(
				__( 'Object renamed successfully', 'arraypress' ),
				200,
				[
					'source_key' => $source_key,
					'target_key' => $target_key
				]
			);
		}

		// Apply contextual filter to final response
		return $this->apply_contextual_filters(
			'arraypress_s3_rename_object_response',
			$response,
			$bucket,
			$source_key,
			$target_key
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
		// Apply contextual filter to modify upload parameters before processing
		$upload_params = $this->apply_contextual_filters(
			'arraypress_s3_put_object_params',
			[
				'bucket'            => $bucket,
				'target_key'        => $target_key,
				'file_path'         => $file_path,
				'is_path'           => $is_path,
				'content_type'      => $content_type,
				'additional_params' => $additional_params
			],
			$bucket,
			$target_key
		);

		// Extract potentially modified values
		$bucket            = $upload_params['bucket'];
		$target_key        = $upload_params['target_key'];
		$file_path         = $upload_params['file_path'];
		$is_path           = $upload_params['is_path'];
		$content_type      = $upload_params['content_type'];
		$additional_params = $upload_params['additional_params'];

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
				$content_type = FileUtil::mime_type( $target_key );
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
		$success_response = new SuccessResponse(
			__( 'File uploaded successfully', 'arraypress' ),
			$status_code,
			[
				'bucket' => $bucket,
				'key'    => $target_key,
				'size'   => $content_length
			]
		);

		// Apply contextual filter to final response
		return $this->apply_contextual_filters(
			'arraypress_s3_put_object_response',
			$success_response,
			$bucket,
			$target_key,
			$status_code
		);
	}

}