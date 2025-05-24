<?php
/**
 * Object Operations Trait
 *
 * Handles object-related operations for S3-compatible storage.
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
use ArrayPress\S3\Utils\File;

/**
 * Trait ObjectOperations
 */
trait Items {

	/**
	 * List objects in a bucket
	 *
	 * @param string $bucket             Bucket name
	 * @param int    $max_keys           Maximum number of objects to return
	 * @param string $prefix             Optional prefix to filter objects
	 * @param string $delimiter          Optional delimiter for hierarchical listing
	 * @param string $continuation_token Optional continuation token for pagination
	 *
	 * @return ResponseInterface Operation result
	 */
	public function list_objects(
		string $bucket,
		int $max_keys = 1000,
		string $prefix = '',
		string $delimiter = '/',
		string $continuation_token = ''
	): ResponseInterface {
		// Prepare query parameters
		$query_params = [
			'list-type' => '2' // Use ListObjectsV2
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

		// Build the URL
		$url = $this->provider->format_url( $bucket );

		if ( ! empty( $query_params ) ) {
			$url .= '?' . http_build_query( $query_params );
		}

		// Debug the request if callback is set
		$this->debug( "List Objects Request URL", $url );
		$this->debug( "List Objects Request Headers", $headers );

		// Make the request
		$response = wp_remote_get( $url, [
			'headers' => $headers,
			'timeout' => 30
		] );

		// Handle errors
		if ( is_wp_error( $response ) ) {
			return ErrorResponse::from_wp_error( $response );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		// Debug the response if callback is set
		$this->debug( "List Objects Response Status", $status_code );
		$this->debug( "List Objects Response Body", $body );

		// Check for error status code
		if ( $status_code < 200 || $status_code >= 300 ) {
			return $this->handle_error_response( $status_code, $body, 'Failed to list objects' );
		}

		// Parse XML response
		$xml = $this->parse_xml_response( $body );

		if ( is_wp_error( $xml ) ) {
			return ErrorResponse::from_wp_error( $xml, $status_code );
		}

		$objects            = [];
		$prefixes           = [];
		$truncated          = false;
		$continuation_token = '';

		// Extract from ListObjectsV2Result format
		$result_path = $xml['ListObjectsV2Result'] ?? $xml;

		// Check for truncation
		if ( isset( $result_path['IsTruncated'] ) ) {
			$is_truncated = $result_path['IsTruncated'];
			$truncated    = ( $is_truncated === 'true' || $is_truncated === true );
		}

		// Get continuation token if available
		if ( $truncated && isset( $result_path['NextContinuationToken'] ) ) {
			$continuation_token = $result_path['NextContinuationToken']['value'] ?? '';
		}

		// Extract objects
		if ( isset( $result_path['Contents'] ) ) {
			$contents = $result_path['Contents'];

			// Single object case
			if ( isset( $contents['Key'] ) ) {
				$this->add_formatted_object( $objects, $contents );
			} // Multiple objects case
			elseif ( is_array( $contents ) ) {
				foreach ( $contents as $object ) {
					$this->add_formatted_object( $objects, $object );
				}
			}
		}

		// Extract prefixes (folders)
		if ( isset( $result_path['CommonPrefixes'] ) ) {
			$common_prefixes = $result_path['CommonPrefixes'];

			// Single prefix case
			if ( isset( $common_prefixes['Prefix'] ) ) {
				$prefix_value = $common_prefixes['Prefix']['value'] ?? '';
				if ( ! empty( $prefix_value ) ) {
					$prefixes[] = $prefix_value;
				}
			} // Multiple prefixes case
			elseif ( is_array( $common_prefixes ) ) {
				foreach ( $common_prefixes as $prefix_data ) {
					if ( isset( $prefix_data['Prefix']['value'] ) ) {
						$prefixes[] = $prefix_data['Prefix']['value'];
					} elseif ( isset( $prefix_data['Prefix'] ) ) {
						$prefixes[] = $prefix_data['Prefix'];
					}
				}
			}
		}

		// Pass the current prefix to ObjectsResponse for filtering
		return new ObjectsResponse(
			$objects,
			$prefixes,
			$status_code,
			$truncated,
			$continuation_token,
			$xml,
			$prefix
		);
	}

}