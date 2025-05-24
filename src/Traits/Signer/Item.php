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
trait Item {

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

		// Build the URL
		$url = $this->provider->format_url( $bucket, $object_key );

		// Debug the request if callback is set
		$this->debug( "Get Object Request URL", $url );
		$this->debug( "Get Object Request Headers", $headers );

		// Make the request
		$response = wp_remote_get( $url, [
			'headers' => $headers,
			'timeout' => 30
		] );

		// Handle errors
		if ( is_wp_error( $response ) ) {
			return ErrorResponse::from_wp_error( $response );
		}

		$status_code      = wp_remote_retrieve_response_code( $response );
		$body             = wp_remote_retrieve_body( $response );
		$response_headers = wp_remote_retrieve_headers( $response );

		// Debug the response status if callback is set
		$this->debug( "Get Object Response Status", $status_code );
		$this->debug( "Get Object Response Headers", $response_headers );

		// Check for error status code
		if ( $status_code < 200 || $status_code >= 300 ) {
			// Try to parse an error message from XML if available
			if ( strpos( $body, '<?xml' ) !== false ) {
				$error_xml = $this->parse_xml_response( $body, false );
				if ( ! is_wp_error( $error_xml ) && isset( $error_xml['Error'] ) ) {
					$error_info    = $error_xml['Error'];
					$error_message = $error_info['Message']['value'] ?? 'Unknown error';
					$error_code    = $error_info['Code']['value'] ?? 'unknown_error';

					return new ErrorResponse( $error_message, $error_code, $status_code );
				}
			}

			return new ErrorResponse( 'Failed to retrieve object', 'request_failed', $status_code );
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

		// Build the URL
		$url = $this->provider->format_url( $bucket, $object_key );

		// Debug the request if callback is set
		$this->debug( "Head Object Request URL", $url );
		$this->debug( "Head Object Request Headers", $headers );

		// Make the request
		$response = wp_remote_head( $url, [
			'headers' => $headers,
			'timeout' => 15
		] );

		// Handle errors
		if ( is_wp_error( $response ) ) {
			return ErrorResponse::from_wp_error( $response );
		}

		$status_code      = wp_remote_retrieve_response_code( $response );
		$response_headers = wp_remote_retrieve_headers( $response );

		// Debug the response status if callback is set
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

		// Use our special encoding method to properly handle special characters
		$encoded_key = Encode::object_key( $object_key );

		// Generate authorization headers with the encoded key
		$headers = $this->generate_auth_headers(
			'DELETE',
			$bucket,
			$encoded_key
		);

		// Add Content-Length header which is required for DELETE operations
		$headers['Content-Length'] = '0';

		// Build the URL based on path style setting
		$host = $this->provider->get_endpoint();

		if ( $this->provider->uses_path_style() ) {
			$url = 'https://' . $host . '/' . $bucket . '/' . $encoded_key;
		} else {
			$url = 'https://' . $bucket . '.' . $host . '/' . $encoded_key;
		}

		// Make the request
		$response = wp_remote_request( $url, [
			'method'  => 'DELETE',
			'headers' => $headers,
			'timeout' => 15,
			'body'    => ''
		] );

		// Handle WP_Error responses
		if ( is_wp_error( $response ) ) {
			return ErrorResponse::from_wp_error( $response );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

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

		// Encode the source key and target key for URL
		$encoded_source_key = Encode::object_key( $source_key );
		$encoded_target_key = Encode::object_key( $target_key );

		// Create the source path in the format required by the S3 CopyObject operation
		// Note: The x-amz-copy-source header must be URL-encoded
		$source_path = rawurlencode( "{$source_bucket}/{$encoded_source_key}" );

		// Generate authorization headers for PUT request to the target
		$headers = $this->generate_auth_headers(
			'PUT',
			$target_bucket,
			$encoded_target_key
		);

		// Add the source header - this tells S3 to copy from the source object
		$headers['x-amz-copy-source'] = $source_path;

		// Build the URL
		$url = $this->provider->format_url( $target_bucket, $encoded_target_key );

		// Debug the request if a callback is set
		$this->debug( "Copy Object Request URL", $url );
		$this->debug( "Copy Object Request Headers", $headers );

		// Make the request
		$response = wp_remote_request( $url, [
			'method'  => 'PUT',
			'headers' => $headers,
			'timeout' => 30,
			'body'    => '' // Empty body for copy operation
		] );

		// Handle WP_Error responses
		if ( is_wp_error( $response ) ) {
			return ErrorResponse::from_wp_error( $response );
		}

		// Get response data
		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		// Debug the response if callback is set
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
		if ( is_wp_error( $xml_data ) ) {
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