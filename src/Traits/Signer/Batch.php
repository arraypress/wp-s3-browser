<?php
/**
 * Enhanced Signer Batch Trait with Better Error Handling
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Signer;

use ArrayPress\S3\Interfaces\Response as ResponseInterface;
use ArrayPress\S3\Responses\SuccessResponse;
use ArrayPress\S3\Responses\ErrorResponse;

trait Batch {

	/**
	 * Delete multiple objects in batches with enhanced error handling
	 *
	 * @param string $bucket      Bucket name
	 * @param array  $object_keys Array of object keys to delete
	 *
	 * @return ResponseInterface Response with batch delete results
	 */
	public function batch_delete_objects( string $bucket, array $object_keys ): ResponseInterface {
		if ( empty( $bucket ) ) {
			return new ErrorResponse(
				__( 'Bucket name is required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		if ( empty( $object_keys ) ) {
			return new ErrorResponse(
				__( 'At least one object key is required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		// Debug logging
		$this->debug( 'Batch Delete Request', [
			'bucket'       => $bucket,
			'object_count' => count( $object_keys ),
			'object_keys'  => $object_keys
		] );

		// S3 compatibility: Reduce batch size limit
		if ( count( $object_keys ) > 100 ) {
			return new ErrorResponse(
				__( 'Maximum 100 objects can be deleted per batch request for S3 compatibility', 'arraypress' ),
				'too_many_objects',
				400
			);
		}

		// Build the XML for batch delete using XmlParser
		$delete_xml = $this->build_batch_delete_xml( $object_keys );

		$this->debug( 'Batch Delete XML', $delete_xml );

		// Generate authorization headers for POST request
		$headers = $this->generate_auth_headers(
			'POST',
			$bucket,
			'',
			[ 'delete' => '' ]
		);

		// Add required headers for batch delete
		$headers['Content-Type']   = 'application/xml';
		$headers['Content-MD5']    = base64_encode( md5( $delete_xml, true ) );
		$headers['Content-Length'] = strlen( $delete_xml );

		// Build the URL
		$url = $this->provider->format_url( $bucket ) . '?delete';

		// Debug the request
		$this->debug( "Batch Delete Request URL", $url );
		$this->debug( "Batch Delete Request Headers", $headers );

		// Enhanced request with better timeout handling for R2
		$response = wp_remote_request( $url, [
			'method'     => 'POST',
			'headers'    => $headers,
			'body'       => $delete_xml,
			'timeout'    => 60,
			'blocking'   => true,
			'sslverify'  => true,
			'user-agent' => 'ArrayPress-S3-Client/1.0'
		] );

		// Enhanced error handling
		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			$error_code    = $response->get_error_code();

			$this->debug( 'Batch Delete WP_Error', [
				'code'    => $error_code,
				'message' => $error_message,
				'data'    => $response->get_error_data()
			] );

			// Check for specific timeout/network errors
			if ( in_array( $error_code, [ 'http_request_timeout', 'http_request_failed' ] ) ) {
				return new ErrorResponse(
					__( 'Request timeout - try reducing batch size or use individual deletes', 'arraypress' ),
					'batch_delete_timeout',
					408  // Request Timeout
				);
			}

			return ErrorResponse::from_wp_error( $response );
		}

		$status_code      = wp_remote_retrieve_response_code( $response );
		$body             = wp_remote_retrieve_body( $response );
		$response_headers = wp_remote_retrieve_headers( $response );

		// Debug the response
		$this->debug( "Batch Delete Response Status", $status_code );
		$this->debug( "Batch Delete Response Headers", $response_headers );
		$this->debug( "Batch Delete Response Body", $body );

		// Check for error status codes
		if ( $status_code < 200 || $status_code >= 300 ) {
			$this->debug( 'Batch Delete Error Response', [
				'status' => $status_code,
				'body'   => $body
			] );

			// Special handling for R2 specific errors
			if ( $status_code === 400 && strpos( $body, 'MalformedXML' ) !== false ) {
				return new ErrorResponse(
					__( 'Batch delete XML format not supported by provider - falling back to individual deletes', 'arraypress' ),
					'batch_delete_not_supported',
					400
				);
			}

			return $this->handle_error_response( $status_code, $body, 'Failed to batch delete objects' );
		}

		// Parse XML response using XmlParser
		$xml = $this->parse_xml_response( $body );
		if ( $xml instanceof ErrorResponse ) {
			$this->debug( 'Batch Delete XML Parse Error', $xml->get_error_message() );

			return $xml;
		}

		// Parse the batch delete results using XmlParser
		$results = $this->parse_batch_delete_response( $xml );

		$this->debug( 'Batch Delete Results', $results );

		return new SuccessResponse(
			sprintf(
				__( 'Batch delete completed: %d succeeded, %d failed', 'arraypress' ),
				$results['success_count'],
				$results['error_count']
			),
			200,
			[
				'total_requested' => count( $object_keys ),
				'success_count'   => $results['success_count'],
				'error_count'     => $results['error_count'],
				'deleted_objects' => $results['deleted'],
				'failed_objects'  => $results['errors']
			]
		);
	}

}