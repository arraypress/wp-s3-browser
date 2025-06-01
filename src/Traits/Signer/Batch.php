<?php
/**
 * Enhanced Signer Batch Trait - PHP 7.4 Compatible
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

		// S3 compatibility: Reduce batch size limit
		if ( count( $object_keys ) > 100 ) {
			return new ErrorResponse(
				__( 'Maximum 100 objects can be deleted per batch request for S3 compatibility', 'arraypress' ),
				'too_many_objects',
				400
			);
		}

		// Build the XML for batch delete
		$delete_xml = $this->build_batch_delete_xml( $object_keys );

		// Generate authorization headers
		$headers                   = $this->generate_auth_headers( 'POST', $bucket, '', [ 'delete' => '' ] );
		$headers['Content-Type']   = 'application/xml';
		$headers['Content-MD5']    = base64_encode( md5( $delete_xml, true ) );
		$headers['Content-Length'] = strlen( $delete_xml );

		// Build the URL
		$url = $this->provider->format_url( $bucket ) . '?delete';

		// Debug and make request
		$this->debug_request_details( 'batch_delete', $url, $headers );
		$response = wp_remote_request( $url, [
			'method'     => 'POST',
			'headers'    => $headers,
			'body'       => $delete_xml,
			'timeout'    => $this->get_operation_timeout( 'batch_delete' ),
			'blocking'   => true,
			'sslverify'  => true,
			'user-agent' => 'ArrayPress-S3-Client/1.0'
		] );

		// Enhanced error handling
		if ( is_wp_error( $response ) ) {
			$error_code = $response->get_error_code();
			if ( in_array( $error_code, [ 'http_request_timeout', 'http_request_failed' ] ) ) {
				return new ErrorResponse(
					__( 'Request timeout - try reducing batch size or use individual deletes', 'arraypress' ),
					'batch_delete_timeout',
					408
				);
			}

			return ErrorResponse::from_wp_error( $response );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		// Debug response
		$this->debug_response_details( 'batch_delete', $status_code, $body );

		// Check for error status codes
		if ( $status_code < 200 || $status_code >= 300 ) {
			if ( $status_code === 400 && strpos( $body, 'MalformedXML' ) !== false ) {
				return new ErrorResponse(
					__( 'Batch delete XML format not supported by provider', 'arraypress' ),
					'batch_delete_not_supported',
					400
				);
			}

			return $this->handle_error_response( $status_code, $body, 'Failed to batch delete objects' );
		}

		// Parse XML response
		$xml = $this->parse_xml_response( $body );
		if ( $xml instanceof ErrorResponse ) {
			return $xml;
		}

		// Parse results
		$results = $this->parse_batch_delete_response( $xml );

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