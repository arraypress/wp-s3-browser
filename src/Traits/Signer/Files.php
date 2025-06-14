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
use ArrayPress\S3\Responses\ErrorResponse;
/**
 * Trait Files
 *
 * Provides comprehensive object management operations for S3-compatible storage
 * including listing, retrieval, upload, deletion, and copying of objects.
 */
trait Files {

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

		// Add base request headers (including user agent)
		$headers = $this->get_base_request_headers( $headers );

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

}