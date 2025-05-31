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
use ArrayPress\S3\Responses\SuccessResponse;
use ArrayPress\S3\Responses\ErrorResponse;
use ArrayPress\S3\Utils\Xml;

/**
 * Add this to your Objects trait in Signer
 */
trait Batch {

	/**
	 * Delete multiple objects in batches (up to 1000 per request)
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

		// S3 batch delete supports max 1000 objects per request
		if ( count( $object_keys ) > 1000 ) {
			return new ErrorResponse(
				__( 'Maximum 1000 objects can be deleted per batch request', 'arraypress' ),
				'too_many_objects',
				400
			);
		}

		// Build the XML for batch delete using utility
		$delete_xml = $this->build_batch_delete_xml( $object_keys );

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
		$this->debug( "Batch Delete XML Body", $delete_xml );

		// Make the request
		$response = wp_remote_request( $url, [
			'method'  => 'POST',
			'headers' => $headers,
			'body'    => $delete_xml,
			'timeout' => 60
		] );

		// Handle WP_Error responses
		if ( is_wp_error( $response ) ) {
			return ErrorResponse::from_wp_error( $response );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		// Debug the response
		$this->debug( "Batch Delete Response Status", $status_code );
		$this->debug( "Batch Delete Response Body", $body );

		// Check for error status codes
		if ( $status_code < 200 || $status_code >= 300 ) {
			return $this->handle_error_response( $status_code, $body, 'Failed to batch delete objects' );
		}

		// Parse XML response using existing parser
		$xml = $this->parse_xml_response( $body );
		if ( $xml instanceof ErrorResponse ) {
			return $xml;
		}

		// Parse the batch delete results using XML utilities
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

	/**
	 * Build XML for batch delete request
	 * Uses proper XML escaping and structure
	 *
	 * @param array $object_keys Array of object keys
	 *
	 * @return string XML string
	 */
	private function build_batch_delete_xml( array $object_keys ): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<Delete xmlns="http://s3.amazonaws.com/doc/2006-03-01/">' . "\n";
		$xml .= '  <Quiet>false</Quiet>' . "\n";

		foreach ( $object_keys as $key ) {
			$xml .= '  <Object>' . "\n";
			$xml .= '    <Key>' . htmlspecialchars( $key, ENT_XML1, 'UTF-8' ) . '</Key>' . "\n";
			$xml .= '  </Object>' . "\n";
		}

		$xml .= '</Delete>';

		return $xml;
	}

	/**
	 * Parse batch delete response XML using existing XML utilities
	 *
	 * @param array $xml Parsed XML response from parse_xml_response()
	 *
	 * @return array Results summary
	 */
	private function parse_batch_delete_response( array $xml ): array {
		$deleted = [];
		$errors  = [];

		// Handle DeleteResult format - search for the root element
		$result_path = $xml['DeleteResult'] ?? $xml;

		// Parse deleted objects using XML utility
		$deleted_items = Xml::find_value( $result_path, 'Deleted' );
		if ( $deleted_items !== null ) {
			// Single deleted object
			if ( isset( $deleted_items['Key'] ) ) {
				$deleted[] = [
					'key'        => $this->extract_text_value( $deleted_items['Key'] ),
					'version_id' => $this->extract_text_value( $deleted_items['VersionId'] ?? null )
				];
			} // Multiple deleted objects (array format)
			elseif ( is_array( $deleted_items ) ) {
				foreach ( $deleted_items as $item ) {
					if ( isset( $item['Key'] ) ) {
						$deleted[] = [
							'key'        => $this->extract_text_value( $item['Key'] ),
							'version_id' => $this->extract_text_value( $item['VersionId'] ?? null )
						];
					}
				}
			}
		}

		// Parse error objects using XML utility
		$error_items = Xml::find_value( $result_path, 'Error' );
		if ( $error_items !== null ) {
			// Single error
			if ( isset( $error_items['Key'] ) ) {
				$errors[] = [
					'key'     => $this->extract_text_value( $error_items['Key'] ),
					'code'    => $this->extract_text_value( $error_items['Code'] ?? 'Unknown' ),
					'message' => $this->extract_text_value( $error_items['Message'] ?? 'Unknown error' )
				];
			} // Multiple errors (array format)
			elseif ( is_array( $error_items ) ) {
				foreach ( $error_items as $item ) {
					if ( isset( $item['Key'] ) ) {
						$errors[] = [
							'key'     => $this->extract_text_value( $item['Key'] ),
							'code'    => $this->extract_text_value( $item['Code'] ?? 'Unknown' ),
							'message' => $this->extract_text_value( $item['Message'] ?? 'Unknown error' )
						];
					}
				}
			}
		}

		return [
			'success_count' => count( $deleted ),
			'error_count'   => count( $errors ),
			'deleted'       => $deleted,
			'errors'        => $errors
		];
	}

}