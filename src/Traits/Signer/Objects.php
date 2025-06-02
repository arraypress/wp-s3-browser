<?php
/**
 * Object Operations Trait - Refactored Version
 *
 * Handles object-related operations for S3-compatible storage using centralized
 * Provider URL building methods, Headers trait, and XML parsing methods.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Signer;

use ArrayPress\S3\Interfaces\Response as ResponseInterface;
use ArrayPress\S3\Responses\ObjectsResponse;
use ArrayPress\S3\Responses\ObjectResponse;
use ArrayPress\S3\Responses\ErrorResponse;
use ArrayPress\S3\Responses\SuccessResponse;
use ArrayPress\S3\Utils\Encode;

/**
 * Trait Objects
 *
 * Provides comprehensive object management operations for S3-compatible storage
 * including listing, retrieval, upload, deletion, and copying of objects.
 */
trait Objects {

	/**
	 * List objects in a bucket
	 *
	 * Retrieves a list of objects from the specified S3 bucket with support for
	 * pagination, filtering, and hierarchical listing. Uses ListObjectsV2 API
	 * for improved performance and consistency across providers.
	 *
	 * The response includes:
	 * - Array of object metadata (key, size, last modified, ETag, etc.)
	 * - Array of common prefixes (folders) when using delimiter
	 * - Pagination information for large result sets
	 * - Formatted object data with additional metadata
	 *
	 * Usage Examples:
	 * ```php
	 * // List all objects in bucket
	 * $response = $signer->list_objects( 'my-bucket' );
	 *
	 * // List objects with prefix filter
	 * $response = $signer->list_objects( 'my-bucket', 1000, 'photos/' );
	 *
	 * // List with pagination
	 * $response = $signer->list_objects( 'my-bucket', 50, '', '/', $continuation_token );
	 *
	 * // Process results
	 * if ( $response->is_successful() ) {
	 *     $objects = $response->get_objects();
	 *     $folders = $response->get_prefixes();
	 *
	 *     foreach ( $objects as $object ) {
	 *         echo "File: " . $object['Filename'] . " (" . $object['FormattedSize'] . ")\n";
	 *     }
	 *
	 *     // Handle pagination
	 *     if ( $response->is_truncated() ) {
	 *         $next_token = $response->get_continuation_token();
	 *         // Make next request...
	 *     }
	 * }
	 * ```
	 *
	 * @param string $bucket             Bucket name to list objects from
	 * @param int    $max_keys           Maximum number of objects to return (1-1000, default: 1000)
	 * @param string $prefix             Optional prefix to filter objects (e.g., 'photos/' for photos folder)
	 * @param string $delimiter          Optional delimiter for hierarchical listing (default: '/')
	 * @param string $continuation_token Optional pagination token from previous response
	 *
	 * @return ResponseInterface ObjectsResponse on success with object/folder data, or ErrorResponse on failure
	 *
	 * @since 1.0.0
	 *
	 * @see   ObjectsResponse For detailed response structure and methods
	 * @see   ErrorResponse For error response structure and methods
	 */
	public function list_objects(
		string $bucket,
		int $max_keys = 1000,
		string $prefix = '',
		string $delimiter = '/',
		string $continuation_token = ''
	): ResponseInterface {
		// Prepare query parameters for ListObjectsV2
		$query_params = [
			'list-type' => '2' // Use ListObjectsV2 API
		];

		if ( $max_keys !== 1000 ) {
			$query_params['max-keys'] = $max_keys;
		}

		if ( ! empty( $prefix ) ) {
			$query_params['prefix'] = $prefix;
		}

		if ( ! empty( $delimiter ) ) {
			$query_params['delimiter'] = $delimiter;
		}

		if ( ! empty( $continuation_token ) ) {
			$query_params['continuation-token'] = $continuation_token;
		}

		// Generate authorization headers
		$headers = $this->generate_auth_headers(
			'GET',
			$bucket,
			'',
			$query_params
		);

		// Use provider method for URL building with query parameters
		$url = $this->provider->build_url_with_query( $bucket, '', $query_params );

		// Debug the request
		$this->debug_request_details( 'list_objects', $url, $headers );

		// Make the request with appropriate timeout
		$response = wp_remote_get( $url, [
			'headers' => $headers,
			'timeout' => $this->get_operation_timeout( 'list_objects' )
		] );

		// Handle errors
		if ( is_wp_error( $response ) ) {
			return ErrorResponse::from_wp_error( $response );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		// Debug the response
		$this->debug_response_details( 'list_objects', $status_code, $body );

		// Check for error status code
		if ( $status_code < 200 || $status_code >= 300 ) {
			return $this->handle_error_response( $status_code, $body, 'Failed to list objects' );
		}

		// Parse XML response
		$xml = $this->parse_xml_response( $body );
		if ( $xml instanceof ErrorResponse ) {
			return $xml;
		}

		// Use the new XML parsing method
		$parsed = $this->parse_objects_list( $xml );

		// Pass the current prefix to ObjectsResponse for filtering
		return new ObjectsResponse(
			$parsed['objects'],
			$parsed['prefixes'],
			$status_code,
			$parsed['truncated'],
			$parsed['continuation_token'],
			$xml,
			$prefix
		);
	}

	/**
	 * Download an object
	 *
	 * Retrieves the complete content and metadata of an object from S3.
	 * This method downloads the entire object content into memory, so it
	 * should be used carefully with large files.
	 *
	 * Usage Examples:
	 * ```php
	 * // Download a file
	 * $response = $signer->get_object( 'my-bucket', 'documents/file.pdf' );
	 *
	 * if ( $response->is_successful() ) {
	 *     $content = $response->get_content();
	 *     $metadata = $response->get_metadata();
	 *
	 *     // Save to local file
	 *     file_put_contents( 'local-file.pdf', $content );
	 *
	 *     // Access metadata
	 *     echo "Content Type: " . $metadata['content_type'] . "\n";
	 *     echo "File Size: " . $metadata['content_length'] . " bytes\n";
	 *     echo "Last Modified: " . $metadata['last_modified'] . "\n";
	 * }
	 * ```
	 *
	 * @param string $bucket     Bucket name containing the object
	 * @param string $object_key Object key (path) to retrieve
	 *
	 * @return ResponseInterface ObjectResponse with content and metadata on success, or ErrorResponse on failure
	 *
	 * @since 1.0.0
	 *
	 * @see   ObjectResponse For detailed response structure and methods
	 * @see   head_object() For retrieving only metadata without content
	 */
	public function get_object( string $bucket, string $object_key ): ResponseInterface {
		if ( empty( $bucket ) || empty( $object_key ) ) {
			return new ErrorResponse( 'Bucket and object key are required', 'invalid_parameters', 400 );
		}

		// Generate authorization headers using provider method
		$headers = $this->generate_auth_headers( 'GET', $bucket, $object_key );

		// Use provider method for standard URL building
		$url = $this->provider->format_url( $bucket, $object_key );

		// Debug the request
		$this->debug_request_details( 'get_object', $url, $headers );

		// Make the request with appropriate timeout
		$response = wp_remote_get( $url, [
			'headers' => $headers,
			'timeout' => $this->get_operation_timeout( 'get_object' )
		] );

		// Handle errors
		if ( is_wp_error( $response ) ) {
			return ErrorResponse::from_wp_error( $response );
		}

		$status_code      = wp_remote_retrieve_response_code( $response );
		$body             = wp_remote_retrieve_body( $response );
		$response_headers = wp_remote_retrieve_headers( $response );

		// Debug the response
		$this->debug_response_details( 'get_object', $status_code, null, $response_headers );

		// Check for error status code
		if ( $status_code < 200 || $status_code >= 300 ) {
			return $this->handle_error_response( $status_code, $body, 'Failed to retrieve object' );
		}

		// Extract metadata
		$metadata = [
			'content_type'   => wp_remote_retrieve_header( $response, 'content-type' ),
			'content_length' => (int) wp_remote_retrieve_header( $response, 'content-length' ),
			'etag'           => trim( wp_remote_retrieve_header( $response, 'etag' ), '"' ),
			'last_modified'  => wp_remote_retrieve_header( $response, 'last-modified' )
		];

		// Extract custom metadata
		foreach ( $response_headers as $key => $value ) {
			if ( strpos( $key, 'x-amz-meta-' ) === 0 ) {
				$metadata['user_metadata'][ substr( $key, 11 ) ] = $value;
			}
		}

		return new ObjectResponse( $body, $metadata, $status_code, [
			'headers' => $response_headers,
			'bucket'  => $bucket,
			'key'     => $object_key
		] );
	}

	/**
	 * Get object metadata (HEAD request)
	 *
	 * Retrieves only the metadata of an object without downloading its content.
	 * This is more efficient than get_object() when you only need to check
	 * if an object exists or get its metadata.
	 *
	 * Usage Examples:
	 * ```php
	 * // Check if object exists and get metadata
	 * $response = $signer->head_object( 'my-bucket', 'documents/file.pdf' );
	 *
	 * if ( $response->is_successful() ) {
	 *     $metadata = $response->get_metadata();
	 *
	 *     echo "File exists!\n";
	 *     echo "Size: " . $metadata['content_length'] . " bytes\n";
	 *     echo "Type: " . $metadata['content_type'] . "\n";
	 *     echo "ETag: " . $metadata['etag'] . "\n";
	 * } else {
	 *     echo "File does not exist or access denied\n";
	 * }
	 * ```
	 *
	 * @param string $bucket     Bucket name containing the object
	 * @param string $object_key Object key (path) to check
	 *
	 * @return ResponseInterface ObjectResponse with metadata on success, or ErrorResponse on failure
	 *
	 * @since 1.0.0
	 *
	 * @see   ObjectResponse For detailed response structure and methods
	 * @see   get_object() For retrieving both content and metadata
	 */
	public function head_object( string $bucket, string $object_key ): ResponseInterface {
		if ( empty( $bucket ) || empty( $object_key ) ) {
			return new ErrorResponse( 'Bucket and object key are required', 'invalid_parameters', 400 );
		}

		// Use headers trait method
		$headers = $this->build_head_headers( $bucket, $object_key );

		// Use provider method for standard URL building
		$url = $this->provider->format_url( $bucket, $object_key );

		// Debug the request
		$this->debug_request_details( 'head_object', $url, $headers );

		// Make the request with appropriate timeout
		$response = wp_remote_head( $url, [
			'headers' => $headers,
			'timeout' => $this->get_operation_timeout( 'head_object' )
		] );

		// Handle errors
		if ( is_wp_error( $response ) ) {
			return ErrorResponse::from_wp_error( $response );
		}

		$status_code      = wp_remote_retrieve_response_code( $response );
		$response_headers = wp_remote_retrieve_headers( $response );

		// Debug the response
		$this->debug_response_details( 'head_object', $status_code, null, $response_headers );

		// Check for error status code
		if ( $status_code < 200 || $status_code >= 300 ) {
			return new ErrorResponse( 'Failed to retrieve object metadata', 'request_failed', $status_code );
		}

		// Extract metadata
		$metadata = [
			'content_type'   => wp_remote_retrieve_header( $response, 'content-type' ),
			'content_length' => (int) wp_remote_retrieve_header( $response, 'content-length' ),
			'etag'           => trim( wp_remote_retrieve_header( $response, 'etag' ), '"' ),
			'last_modified'  => wp_remote_retrieve_header( $response, 'last-modified' )
		];

		// Empty content for HEAD request
		return new ObjectResponse( '', $metadata, $status_code, [
			'headers' => $response_headers,
			'bucket'  => $bucket,
			'key'     => $object_key
		] );
	}

	/**
	 * Delete an object from a bucket
	 *
	 * Permanently removes an object from the specified S3 bucket.
	 * This operation cannot be undone, so use with caution.
	 *
	 * Usage Examples:
	 * ```php
	 * // Delete a single file
	 * $response = $signer->delete_object( 'my-bucket', 'old-file.txt' );
	 *
	 * if ( $response->is_successful() ) {
	 *     echo $response->get_message(); // "File deleted successfully"
	 *     $data = $response->get_data();
	 *     echo "Deleted: " . $data['filename'] . "\n";
	 * } else {
	 *     echo "Error: " . $response->get_error_message() . "\n";
	 * }
	 * ```
	 *
	 * @param string $bucket     Bucket name containing the object
	 * @param string $object_key Object key (path) to delete
	 *
	 * @return ResponseInterface SuccessResponse on successful deletion, or ErrorResponse on failure
	 *
	 * @since 1.0.0
	 *
	 * @see   SuccessResponse For successful operation response structure
	 * @see   ErrorResponse For error response structure and methods
	 */
	public function delete_object( string $bucket, string $object_key ): ResponseInterface {
		if ( empty( $bucket ) || empty( $object_key ) ) {
			return new ErrorResponse(
				__( 'Bucket and object key are required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		// Use headers trait method for delete headers
		$headers = $this->build_delete_headers( $bucket, $object_key );

		// Use provider method for URL building with encoded key
		$encoded_key = Encode::object_key( $object_key );
		$url         = $this->provider->build_url_with_encoded_key( $bucket, $encoded_key );

		// Debug the request
		$this->debug_request_details( 'delete_object', $url, $headers );

		// Make the request with appropriate timeout
		$response = wp_remote_request( $url, [
			'method'  => 'DELETE',
			'headers' => $headers,
			'timeout' => $this->get_operation_timeout( 'delete_object' ),
			'body'    => ''
		] );

		// Handle WP_Error responses
		if ( is_wp_error( $response ) ) {
			return ErrorResponse::from_wp_error( $response );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		// Debug the response
		$this->debug_response_details( 'delete_object', $status_code );

		// Check for error status codes
		if ( $status_code < 200 || $status_code >= 300 ) {
			return $this->handle_error_response( $status_code, $body, __( 'Failed to delete object', 'arraypress' ) );
		}

		// Success! Return a meaningful response
		$filename = basename( $object_key );

		return new SuccessResponse(
			sprintf( __( 'File "%s" deleted successfully', 'arraypress' ), $filename ),
			$status_code,
			[
				'bucket'   => $bucket,
				'key'      => $object_key,
				'filename' => $filename
			]
		);
	}

	/**
	 * Copy an object within or between buckets
	 *
	 * Creates a copy of an object in S3, either within the same bucket or to a different bucket.
	 * The copy operation preserves the original object's metadata unless overridden.
	 * This is also used internally for rename operations.
	 *
	 * Usage Examples:
	 * ```php
	 * // Copy within same bucket
	 * $response = $signer->copy_object(
	 *     'my-bucket', 'original-file.txt',
	 *     'my-bucket', 'backup/original-file.txt'
	 * );
	 *
	 * // Copy to different bucket
	 * $response = $signer->copy_object(
	 *     'source-bucket', 'documents/file.pdf',
	 *     'backup-bucket', 'documents/file.pdf'
	 * );
	 *
	 * if ( $response->is_successful() ) {
	 *     $data = $response->get_data();
	 *     echo "Copied successfully!\n";
	 *     echo "ETag: " . $data['etag'] . "\n";
	 *     echo "Modified: " . $data['last_modified'] . "\n";
	 * }
	 * ```
	 *
	 * @param string $source_bucket Source bucket name
	 * @param string $source_key    Source object key
	 * @param string $target_bucket Target bucket name
	 * @param string $target_key    Target object key
	 *
	 * @return ResponseInterface SuccessResponse with copy metadata on success, or ErrorResponse on failure
	 *
	 * @since 1.0.0
	 *
	 * @see   SuccessResponse For successful operation response structure
	 * @see   ErrorResponse For error response structure and methods
	 */
	public function copy_object(
		string $source_bucket,
		string $source_key,
		string $target_bucket,
		string $target_key
	): ResponseInterface {
		if ( empty( $source_bucket ) || empty( $source_key ) || empty( $target_bucket ) || empty( $target_key ) ) {
			return new ErrorResponse(
				__( 'Source bucket, source key, target bucket, and target key are required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		// Use headers trait method for copy headers
		$headers = $this->build_copy_headers( $source_bucket, $source_key, $target_bucket, $target_key );

		// Use provider method for standard URL building
		$url = $this->provider->format_url( $target_bucket, $target_key );

		// Debug the request
		$this->debug_request_details( 'copy_object', $url, $headers );

		// Make the request with appropriate timeout
		$response = wp_remote_request( $url, [
			'method'  => 'PUT',
			'headers' => $headers,
			'timeout' => $this->get_operation_timeout( 'copy_object' ),
			'body'    => '' // Empty body for copy operation
		] );

		// Handle WP_Error responses
		if ( is_wp_error( $response ) ) {
			return ErrorResponse::from_wp_error( $response );
		}

		// Get response data
		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		// Debug the response
		$this->debug_response_details( 'copy_object', $status_code, $body );

		// Check for error status codes
		if ( $status_code < 200 || $status_code >= 300 ) {
			return $this->handle_error_response(
				$status_code,
				$body,
				sprintf(
					__( 'Failed to copy object from %s to %s', 'arraypress' ),
					"{$source_bucket}/{$source_key}",
					"{$target_bucket}/{$target_key}"
				)
			);
		}

		// Parse XML response for metadata using XML trait method
		$xml_data = $this->parse_xml_response( $body );
		if ( $xml_data instanceof ErrorResponse ) {
			// Even if we can't parse the XML, the operation was successful
			return new SuccessResponse(
				sprintf(
					__( 'Object copied from %s to %s', 'arraypress' ),
					"{$source_bucket}/{$source_key}",
					"{$target_bucket}/{$target_key}"
				),
				$status_code,
				[
					'source_bucket' => $source_bucket,
					'source_key'    => $source_key,
					'target_bucket' => $target_bucket,
					'target_key'    => $target_key
				]
			);
		}

		// Use XML trait method to parse copy result
		$copy_data = $this->parse_copy_result( $xml_data );

		// Return success response with metadata
		return new SuccessResponse(
			sprintf(
				__( 'Object copied from %s to %s', 'arraypress' ),
				"{$source_bucket}/{$source_key}",
				"{$target_bucket}/{$target_key}"
			),
			$status_code,
			[
				'source_bucket' => $source_bucket,
				'source_key'    => $source_key,
				'target_bucket' => $target_bucket,
				'target_key'    => $target_key,
				'etag'          => $copy_data['etag'],
				'last_modified' => $copy_data['last_modified']
			],
			$xml_data
		);
	}

}