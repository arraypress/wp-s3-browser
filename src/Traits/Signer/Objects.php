<?php
/**
 * Object Operations Trait - Refactored
 *
 * Handles object-related operations using consolidated utilities.
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
 * Trait Objects - Refactored
 */
trait Objects {

	// Include the utility traits
	use HttpResponseHandler;
	use RequestTimeouts;
	use ObjectDataExtractor;

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
		$query_params = [ 'list-type' => '2' ];

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
		$headers = $this->generate_auth_headers( 'GET', $bucket, '', $query_params );

		// Build the URL
		$url = $this->provider->format_url( $bucket );
		if ( ! empty( $query_params ) ) {
			$url .= '?' . http_build_query( $query_params );
		}

		// Debug and make request
		$this->debug_request( 'list_objects', $url, $headers );
		$response = $this->make_get_request( $url, $headers, 'list_objects' );

		// Handle HTTP errors
		$error = $this->handle_http_response( $response, 'list objects' );
		if ( $error ) {
			return $error;
		}

		// Extract response data
		$response_data = $this->extract_response_data( $response );

		// Parse XML response
		$xml = $this->parse_xml_response( $response_data['body'] );
		if ( $xml instanceof ErrorResponse ) {
			return $xml;
		}

		return $this->process_list_objects_response( $xml, $response_data['status_code'], $prefix );
	}

	/**
	 * Get object content
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 *
	 * @return ResponseInterface Object data or error result
	 */
	public function get_object( string $bucket, string $object_key ): ResponseInterface {
		if ( empty( $bucket ) || empty( $object_key ) ) {
			return new ErrorResponse( 'Bucket and object key are required', 'invalid_parameters', 400 );
		}

		// Generate authorization headers
		$headers = $this->generate_auth_headers( 'GET', $bucket, $object_key );

		// Build the URL
		$url = $this->provider->format_url( $bucket, $object_key );

		// Debug and make request
		$this->debug_request( 'get_object', $url, $headers );
		$response = $this->make_get_request( $url, $headers, 'get_object' );

		// Handle HTTP errors
		$error = $this->handle_http_response( $response, 'retrieve object' );
		if ( $error ) {
			return $error;
		}

		// Extract response data
		$response_data = $this->extract_response_data( $response );

		// Extract metadata
		$metadata = $this->extract_object_metadata( $response_data['headers'] );

		return new ObjectResponse(
			$response_data['body'],
			$metadata,
			$response_data['status_code'],
			[
				'headers' => $response_data['headers'],
				'bucket'  => $bucket,
				'key'     => $object_key
			]
		);
	}

	/**
	 * Get object metadata (HEAD request)
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 *
	 * @return ResponseInterface Object metadata or error result
	 */
	public function head_object( string $bucket, string $object_key ): ResponseInterface {
		if ( empty( $bucket ) || empty( $object_key ) ) {
			return new ErrorResponse( 'Bucket and object key are required', 'invalid_parameters', 400 );
		}

		// Generate authorization headers
		$headers = $this->generate_auth_headers( 'HEAD', $bucket, $object_key );

		// Build the URL
		$url = $this->provider->format_url( $bucket, $object_key );

		// Debug and make request
		$this->debug_request( 'head_object', $url, $headers );
		$response = $this->make_head_request( $url, $headers, 'head_object' );

		// Handle HTTP errors
		$error = $this->handle_http_response( $response, 'retrieve object metadata' );
		if ( $error ) {
			return $error;
		}

		// Extract response data
		$response_data = $this->extract_response_data( $response );

		// Extract metadata
		$metadata = $this->extract_object_metadata( $response_data['headers'] );

		return new ObjectResponse(
			'',
			$metadata,
			$response_data['status_code'],
			[
				'headers' => $response_data['headers'],
				'bucket'  => $bucket,
				'key'     => $object_key
			]
		);
	}

	/**
	 * Delete an object from a bucket
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 *
	 * @return ResponseInterface Operation result
	 */
	public function delete_object( string $bucket, string $object_key ): ResponseInterface {
		if ( empty( $bucket ) || empty( $object_key ) ) {
			return new ErrorResponse(
				__( 'Bucket and object key are required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		// Use our special encoding method to properly handle special characters
		$encoded_key = Encode::object_key( $object_key );

		// Generate authorization headers with the encoded key
		$headers = $this->generate_auth_headers( 'DELETE', $bucket, $encoded_key );

		// Build the URL
		$url = $this->provider->format_url( $bucket, $object_key );

		// Debug and make request
		$this->debug_request( 'delete_object', $url, $headers );
		$response = $this->make_delete_request( $url, $headers, 'delete_object' );

		// Handle HTTP errors
		$error = $this->handle_http_response( $response, 'delete object' );
		if ( $error ) {
			return $error;
		}

		// Extract response data
		$response_data = $this->extract_response_data( $response );
		$filename = basename( $object_key );

		return new SuccessResponse(
			sprintf( __( 'File "%s" deleted successfully', 'arraypress' ), $filename ),
			$response_data['status_code'],
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
	 * @param string $source_bucket Source bucket name
	 * @param string $source_key    Source object key
	 * @param string $target_bucket Target bucket name
	 * @param string $target_key    Target object key
	 *
	 * @return ResponseInterface Response or error
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

		// Encode the target key for URL usage
		$encoded_target_key = Encode::object_key( $target_key );

		// Generate authorization headers for PUT request to the target
		$headers = $this->generate_auth_headers( 'PUT', $target_bucket, $encoded_target_key );

		// Create the source path for x-amz-copy-source header
		$encoded_source_key = Encode::object_key( $source_key );
		$headers['x-amz-copy-source'] = $source_bucket . '/' . $encoded_source_key;

		// Build the URL
		$url = $this->provider->format_url( $target_bucket, $target_key );

		// Debug and make request
		$this->debug_request( 'copy_object', $url, $headers );
		$response = $this->make_put_request( $url, $headers, '', 'copy_object' );

		// Handle HTTP errors
		$error = $this->handle_http_response( $response, 'copy object' );
		if ( $error ) {
			return $error;
		}

		// Extract response data
		$response_data = $this->extract_response_data( $response );

		// Parse XML response for metadata
		$xml_data = $this->parse_xml_response( $response_data['body'] );
		if ( $xml_data instanceof ErrorResponse ) {
			return $this->create_copy_success_response(
				$source_bucket,
				$source_key,
				$target_bucket,
				$target_key,
				$response_data['status_code']
			);
		}

		// Extract copy metadata
		$copy_metadata = $this->extract_copy_metadata( $xml_data );

		return $this->create_copy_success_response(
			$source_bucket,
			$source_key,
			$target_bucket,
			$target_key,
			$response_data['status_code'],
			$copy_metadata,
			$xml_data
		);
	}

	/**
	 * Process list objects XML response
	 *
	 * @param array  $xml         Parsed XML response
	 * @param int    $status_code HTTP status code
	 * @param string $prefix      Current prefix for filtering
	 *
	 * @return ObjectsResponse
	 */
	private function process_list_objects_response( array $xml, int $status_code, string $prefix ): ObjectsResponse {
		$objects  = [];
		$prefixes = [];

		// Extract from ListObjectsV2Result format
		$result_path = $xml['ListObjectsV2Result'] ?? $xml;

		// Extract pagination info
		$pagination = $this->extract_pagination_data( $result_path );

		// Extract objects
		if ( isset( $result_path['Contents'] ) ) {
			$contents = $result_path['Contents'];

			// Single object case
			if ( isset( $contents['Key'] ) ) {
				$objects[] = $this->extract_and_format_object( $contents );
			}
			// Multiple objects case
			elseif ( is_array( $contents ) ) {
				foreach ( $contents as $object ) {
					$objects[] = $this->extract_and_format_object( $object );
				}
			}
		}

		// Extract prefixes (folders)
		if ( isset( $result_path['CommonPrefixes'] ) ) {
			$common_prefixes = $result_path['CommonPrefixes'];

			// Single prefix case
			if ( isset( $common_prefixes['Prefix'] ) ) {
				$prefix_value = $this->extract_prefix_data( $common_prefixes );
				if ( ! empty( $prefix_value ) ) {
					$prefixes[] = $prefix_value;
				}
			}
			// Multiple prefixes case
			elseif ( is_array( $common_prefixes ) ) {
				foreach ( $common_prefixes as $prefix_data ) {
					$prefix_value = $this->extract_prefix_data( $prefix_data );
					if ( ! empty( $prefix_value ) ) {
						$prefixes[] = $prefix_value;
					}
				}
			}
		}

		return new ObjectsResponse(
			$objects,
			$prefixes,
			$status_code,
			$pagination['is_truncated'],
			$pagination['continuation_token'],
			$xml,
			$prefix
		);
	}

	/**
	 * Extract object metadata from HTTP headers
	 *
	 * @param array $headers HTTP response headers
	 *
	 * @return array Object metadata
	 */
	private function extract_object_metadata( array $headers ): array {
		$metadata = [
			'content_type'   => $headers['content-type'] ?? '',
			'content_length' => (int) ( $headers['content-length'] ?? 0 ),
			'etag'           => trim( $headers['etag'] ?? '', '"' ),
			'last_modified'  => $headers['last-modified'] ?? ''
		];

		// Extract custom metadata
		foreach ( $headers as $key => $value ) {
			if ( strpos( $key, 'x-amz-meta-' ) === 0 ) {
				$metadata['user_metadata'][ substr( $key, 11 ) ] = $value;
			}
		}

		return $metadata;
	}

	/**
	 * Create copy operation success response
	 *
	 * @param string     $source_bucket Source bucket
	 * @param string     $source_key    Source key
	 * @param string     $target_bucket Target bucket
	 * @param string     $target_key    Target key
	 * @param int        $status_code   HTTP status code
	 * @param array|null $metadata      Optional copy metadata
	 * @param array|null $xml_data      Optional XML data
	 *
	 * @return SuccessResponse
	 */
	private function create_copy_success_response(
		string $source_bucket,
		string $source_key,
		string $target_bucket,
		string $target_key,
		int $status_code,
		array $metadata = null,
		array $xml_data = null
	): SuccessResponse {
		$response_data = [
			'source_bucket' => $source_bucket,
			'source_key'    => $source_key,
			'target_bucket' => $target_bucket,
			'target_key'    => $target_key
		];

		if ( $metadata ) {
			$response_data = array_merge( $response_data, $metadata );
		}

		return new SuccessResponse(
			sprintf(
				__( 'Object copied from %s to %s', 'arraypress' ),
				"{$source_bucket}/{$source_key}",
				"{$target_bucket}/{$target_key}"
			),
			$status_code,
			$response_data,
			$xml_data
		);
	}

}