<?php
/**
 * Bucket Operations Trait - PHP 7.4 Compatible
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
use ArrayPress\S3\Traits\Common\RequestTimeouts;

/**
 * Trait Buckets
 */
trait Buckets {

	use RequestTimeouts;

	/**
	 * List all buckets
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

		// Build the URL
		$endpoint = $this->provider->get_endpoint();
		$url      = 'https://' . $endpoint;
		if ( ! empty( $query_params ) ) {
			$url .= '?' . http_build_query( $query_params );
		}

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

		// Extract data
		$owner           = $this->extract_owner_from_xml( $xml );
		$buckets         = $this->extract_buckets_from_xml( $xml );
		$truncation_info = $this->extract_truncation_info_from_xml( $xml );

		return new BucketsResponse(
			$buckets,
			$status_code,
			$owner,
			$truncation_info['truncated'],
			$truncation_info['next_marker'],
			$xml
		);
	}

}