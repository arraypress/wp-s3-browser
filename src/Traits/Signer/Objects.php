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
use ArrayPress\S3\Utils\Request;

/**
 * Trait Objects
 */
trait Objects {

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

		// Build the URL using provider utility
		$url = $this->provider->build_service_url( $bucket, '', $query_params );

		// Debug the request
		$this->debug( "List Objects Request URL", $url );
		$this->debug( "List Objects Request Headers", $headers );

		// Make the request using Request convenience method
		$response = Request::get( $url, $headers );

		// Handle errors
		if ( is_wp_error( $response ) ) {
			return ErrorResponse::from_wp_error( $response );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		// Debug the response
		$this->debug( "List Objects Response Status", $status_code );
		$this->debug( "List Objects Response Body", $body );

		// Check for error status code
		if ( $status_code < 200 || $status_code >= 300 ) {
			return $this->handle_error_response( $status_code, $body, 'Failed to list objects' );
		}

		// Parse XML response
		$xml = $this->parse_xml_response( $body );
		if ( $xml instanceof ErrorResponse ) {
			return $xml;
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

	/**
	 * Download an object
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
		$headers = $this->generate_auth_headers(
			'GET',
			$bucket,
			$object_key
		);

		// Build the URL using provider utility
		$url = $this->provider->build_request_url( $bucket, $object_key );

		// Debug the request
		$this->debug( "Get Object Request URL", $url );
		$this->debug( "Get Object Request Headers", $headers );

		// Make the request using Request convenience method
		$response = Request::get( $url, $headers );

		// Handle errors
		if ( is_wp_error( $response ) ) {
			return ErrorResponse::from_wp_error( $response );
		}

		$status_code      = wp_remote_retrieve_response_code( $response );
		$body             = wp_remote_retrieve_body( $response );
		$response_headers = wp_remote_retrieve_headers( $response );

		// Debug the response
		$this->debug( "Get Object Response Status", $status_code );
		$this->debug( "Get Object Response Headers", $response_headers );

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
		$headers = $this->generate_auth_headers(
			'HEAD',
			$bucket,
			$object_key
		);

		// Build the URL using provider utility
		$url = $this->provider->build_request_url( $bucket, $object_key );

		// Debug the request
		$this->debug( "Head Object Request URL", $url );
		$this->debug( "Head Object Request Headers", $headers );

		// Make the request using Request convenience method (HEAD requests don't need user agent)
		$response = Request::head( $url, $headers );

		// Handle errors
		if ( is_wp_error( $response ) ) {
			return ErrorResponse::from_wp_error( $response );
		}

		$status_code      = wp_remote_retrieve_response_code( $response );
		$response_headers = wp_remote_retrieve_headers( $response );

		// Debug the response
		$this->debug( "Head Object Response Status", $status_code );
		$this->debug( "Head Object Response Headers", $response_headers );

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

		// Generate authorization headers
		$headers = $this->generate_auth_headers(
			'DELETE',
			$bucket,
			$object_key
		);

		// Build the URL using provider utility (encoding handled automatically)
		$url = $this->provider->build_request_url( $bucket, $object_key );

		// Debug the request
		$this->debug( "Delete Object Request URL", $url );
		$this->debug( "Delete Object Request Headers", $headers );

		// Make the request using Request convenience method
		$response = Request::delete( $url, $headers, $this->get_user_agent() );

		// Handle WP_Error responses
		if ( is_wp_error( $response ) ) {
			return ErrorResponse::from_wp_error( $response );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		// Debug the response
		$this->debug( "Delete Object Response Status", $status_code );
		$this->debug( "Delete Object Response Body", $body );

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

		// Generate authorization headers for PUT request to the target
		$headers = $this->generate_auth_headers(
			'PUT',
			$target_bucket,
			$target_key
		);

		// Create the source path for x-amz-copy-source header
		// Use the provider's URL building to ensure proper encoding
		$encoded_source_key = \ArrayPress\S3\Utils\Encode::object_key( $source_key );
		$source_path        = $source_bucket . '/' . $encoded_source_key;

		// Add the source header - this tells S3 to copy from the source object
		$headers['x-amz-copy-source'] = $source_path;

		// Build the URL using provider utility
		$url = $this->provider->build_request_url( $target_bucket, $target_key );

		// Debug the request
		$this->debug( "Copy Object Request URL", $url );
		$this->debug( "Copy Object Request Headers", $headers );
		$this->debug( "Copy Object Source Key", $source_key );
		$this->debug( "Copy Object Encoded Source Key", $encoded_source_key );
		$this->debug( "Copy Object Source Path", $source_path );

		// Make the request using Request convenience method
		$response = Request::put( $url, $headers, '', $this->get_user_agent() );

		// Handle WP_Error responses
		if ( is_wp_error( $response ) ) {
			return ErrorResponse::from_wp_error( $response );
		}

		// Get response data
		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		// Debug the response
		$this->debug( "Copy Object Response Status", $status_code );
		$this->debug( "Copy Object Response Body", $body );

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

		// Parse XML response for metadata
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

		// Extract ETag if available
		$etag = '';
		if ( isset( $xml_data['CopyObjectResult']['ETag']['value'] ) ) {
			$etag = trim( $xml_data['CopyObjectResult']['ETag']['value'], '"' );
		}

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
				'etag'          => $etag,
				'last_modified' => $xml_data['CopyObjectResult']['LastModified']['value'] ?? ''
			],
			$xml_data
		);
	}

}