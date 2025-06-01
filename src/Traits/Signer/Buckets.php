<?php
/**
 * Bucket Operations Trait
 *
 * Handles bucket-related operations for S3-compatible storage.
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
use ArrayPress\S3\Utils\Request;

/**
 * Trait Buckets
 */
trait Buckets {

	/**
	 * List all buckets
	 *
	 * @param int    $max_keys Maximum number of buckets to return
	 * @param string $prefix   Optional prefix to filter buckets
	 * @param string $marker   Optional marker for pagination
	 *
	 * @return ResponseInterface Operation result
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
		$headers = $this->generate_auth_headers(
			'GET',
			'',  // Empty bucket for list_buckets operation
			'',
			$query_params
		);

		// Build the URL using provider utility
		$url = $this->provider->build_service_url( '', '', $query_params );

		// Debug the request
		$this->debug( "List Buckets Request URL", $url );
		$this->debug( "List Buckets Request Headers", $headers );

		// Make the request using Request convenience method
		$response = Request::get( $url, $headers );

		// Handle errors
		if ( is_wp_error( $response ) ) {
			return ErrorResponse::from_wp_error( $response );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		// Debug the response
		$this->debug( "List Buckets Response Status", $status_code );
		$this->debug( "List Buckets Response Body", $body );

		// Check for error status code
		if ( $status_code < 200 || $status_code >= 300 ) {
			return $this->handle_error_response( $status_code, $body, 'Failed to list buckets' );
		}

		// Parse XML response
		$xml = $this->parse_xml_response( $body );
		if ( $xml instanceof ErrorResponse ) {
			return $xml;
		}

		// Debug the parsed XML structure
		$this->debug( "Parsed XML Structure", $xml );

		// Extract owner - search recursively through the XML structure
		$owner = $this->extract_owner_from_xml( $xml );

		// Extract buckets - search recursively through the XML structure
		$buckets = $this->extract_buckets_from_xml( $xml );

		// Extract truncation info - search recursively
		$truncation_info = $this->extract_truncation_info_from_xml( $xml );
		$truncated       = $truncation_info['truncated'];
		$next_marker     = $truncation_info['next_marker'];

		return new BucketsResponse(
			$buckets,
			$status_code,
			$owner,
			$truncated,
			$next_marker,
			$xml
		);
	}

}