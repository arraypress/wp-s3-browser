<?php
/**
 * Bucket Operations Trait - Simplified Version
 *
 * Handles bucket-related operations for S3-compatible storage using
 * centralized XML parsing methods.
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
use ArrayPress\S3\Responses\BucketsResponse;
use ArrayPress\S3\Responses\ErrorResponse;
use ArrayPress\S3\Responses\SuccessResponse;
use InvalidArgumentException;

/**
 * Trait Buckets
 */
trait Buckets {

	/**
	 * List all buckets accessible to the authenticated user
	 *
	 * Retrieves a list of all buckets owned by the authenticated sender of the request.
	 * This is a service-level operation that provides bucket metadata including names,
	 * creation dates, and owner information.
	 *
	 * The response includes:
	 * - Array of bucket objects with name and creation date
	 * - Owner information (ID and display name)
	 * - Pagination details if results are truncated
	 *
	 * Usage Examples:
	 * ```php
	 * // List all buckets
	 * $response = $signer->list_buckets();
	 *
	 * // List with pagination
	 * $response = $signer->list_buckets( 50, '', 'some-marker' );
	 *
	 * // List buckets with name filter
	 * $response = $signer->list_buckets( 100, 'backup-' );
	 *
	 * // Process results
	 * if ( $response->is_successful() ) {
	 *     $buckets = $response->get_buckets();
	 *     foreach ( $buckets as $bucket ) {
	 *         echo "Bucket: " . $bucket['Name'] . "\n";
	 *         echo "Created: " . $bucket['CreationDate'] . "\n";
	 *     }
	 *
	 *     // Handle pagination
	 *     if ( $response->is_truncated() ) {
	 *         $next_marker = $response->get_next_marker();
	 *         // Make next request with marker...
	 *     }
	 * }
	 * ```
	 *
	 * Error Handling:
	 * ```php
	 * $response = $signer->list_buckets();
	 * if ( ! $response->is_successful() ) {
	 *     if ( $response instanceof ErrorResponse ) {
	 *         echo "Error: " . $response->get_error_message();
	 *         echo "Code: " . $response->get_error_code();
	 *     }
	 * }
	 * ```
	 *
	 * Provider Notes:
	 * - AWS S3: Standard behavior, supports all parameters
	 * - Cloudflare R2: Supports basic listing, pagination may vary
	 * - Some providers may not support prefix filtering
	 *
	 * @param int    $max_keys Maximum number of buckets to return in the response.
	 *                         Range: 1-1000. Default: 1000.
	 *                         Note: Some providers may have lower limits.
	 * @param string $prefix   Optional prefix to filter bucket names.
	 *                         Only buckets whose names begin with this prefix will be returned.
	 *                         Default: '' (no filtering).
	 * @param string $marker   Optional pagination marker.
	 *                         Specifies the bucket name to start listing from.
	 *                         Used for pagination when results are truncated.
	 *                         Default: '' (start from beginning).
	 *
	 * @return ResponseInterface Returns BucketsResponse on success or ErrorResponse on failure.
	 *                          BucketsResponse provides:
	 *                          - get_buckets(): array of bucket data
	 *                          - get_owner(): owner information array
	 *                          - is_truncated(): boolean indicating if more results exist
	 *                          - get_next_marker(): string for next page request
	 *                          - get_count(): integer count of returned buckets
	 *
	 * @throws InvalidArgumentException If max_keys is outside valid range (handled internally).
	 *
	 * @since 1.0.0
	 *
	 * @see   BucketsResponse For detailed response structure and methods
	 * @see   ErrorResponse For error response structure and methods
	 */
	public function list_buckets( int $max_keys = 1000, string $prefix = '', string $marker = '' ): ResponseInterface {
		// Prepare query parameters
		$query_params = [];
		if ( $max_keys !== 1000 ) {
			$query_params['max-keys'] = $max_keys;
		}
		if ( ! empty( $prefix ) ) {
			$query_params['prefix'] = $prefix;
		}
		if ( ! empty( $marker ) ) {
			$query_params['marker'] = $marker;
		}

		// Generate authorization headers
		$headers = $this->generate_auth_headers( 'GET', '', '', $query_params );

		// Add base request headers (including user agent)
		$headers = $this->get_base_request_headers( $headers );

		// Use provider method for service-level URL building
		$url = $this->provider->build_url_with_query( '', '', $query_params );

		// Debug and make request
		$this->debug_request_details( 'list_buckets', $url, $headers );
		$response = wp_remote_get( $url, [
			'headers' => $headers,
			'timeout' => $this->get_operation_timeout( 'list_buckets' )
		] );

		// Handle errors
		if ( is_wp_error( $response ) ) {
			return ErrorResponse::from_wp_error( $response );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		// Debug response
		$this->debug_response_details( 'list_buckets', $status_code, $body );

		// Check for error status code
		if ( $status_code < 200 || $status_code >= 300 ) {
			return $this->handle_error_response( $status_code, $body, 'Failed to list buckets' );
		}

		// Parse XML response
		$xml = $this->parse_xml_response( $body );
		if ( $xml instanceof ErrorResponse ) {
			return $xml;
		}

		// Use XML trait method to parse buckets list
		$parsed = $this->parse_buckets_list( $xml );

		return new BucketsResponse(
			$parsed['buckets'],
			$status_code,
			$parsed['owner'],
			$parsed['truncated'],
			$parsed['next_marker'],
			$xml
		);
	}

}