<?php
/**
 * Client Upload Operations Trait
 *
 * Handles upload-related operations for the S3 Client.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Client;

use ArrayPress\S3\Interfaces\Response as ResponseInterface;
use ArrayPress\S3\Responses\ErrorResponse;
use ArrayPress\S3\Responses\SuccessResponse;
use ArrayPress\S3\Utils\Directory;
use ArrayPress\S3\Utils\File;

/**
 * Trait UploadOperations
 */
trait Upload {

	/**
	 * Upload a file to a bucket
	 *
	 * @param string $bucket            Bucket name
	 * @param string $target_key        Target object key
	 * @param string $file_path         Local file path or file data
	 * @param bool   $is_path           Whether $file_path is a path (true) or file contents (false)
	 * @param string $content_type      Optional content type
	 * @param array  $additional_params Optional additional parameters
	 *
	 * @return ResponseInterface Response or error
	 */
	public function upload_file(
		string $bucket,
		string $target_key,
		string $file_path,
		bool $is_path = true,
		string $content_type = '',
		array $additional_params = []
	): ResponseInterface {
		error_log( "=== upload_file() called ===" );
		error_log( "Bucket: '$bucket', Target key: '$target_key'" );
		error_log( "Is path: " . ( $is_path ? 'TRUE' : 'FALSE' ) );
		error_log( "Content type: '$content_type'" );

		// 1. Get a presigned upload URL
		error_log( "Getting presigned upload URL..." );
		$upload_url_response = $this->get_presigned_upload_url( $bucket, $target_key, 15 );

		if ( is_wp_error( $upload_url_response ) ) {
			error_log( "ERROR: Failed to get presigned URL - " . $upload_url_response->get_error_message() );

			return $upload_url_response;
		}

		if ( ! $upload_url_response->is_successful() ) {
			error_log( "ERROR: Presigned URL response not successful" );

			return new ErrorResponse(
				__( 'Failed to generate upload URL', 'arraypress' ),
				'upload_url_error',
				400
			);
		}

		// Get the presigned upload URL
		$upload_url = $upload_url_response->get_url();
		error_log( "Got presigned URL: " . substr( $upload_url, 0, 100 ) . "..." );

		// 2. Determine the content type if not provided
		if ( empty( $content_type ) ) {
			if ( $is_path ) {
				// If it's a file path, determine from the file
				$content_type = mime_content_type( $file_path ) ?: 'application/octet-stream';
			} else {
				// If it's file data, determine from the target key
				$content_type = File::mime_type( $target_key );
			}
		}
		error_log( "Final content type: '$content_type'" );

		// 3. Read the file contents
		if ( $is_path ) {
			error_log( "Reading file from path: '$file_path'" );
			$file_contents = file_get_contents( $file_path );
			if ( $file_contents === false ) {
				error_log( "ERROR: Failed to read file from path" );

				return new ErrorResponse(
					__( 'Failed to read file', 'arraypress' ),
					'file_read_error',
					400,
					[ 'file_path' => $file_path ]
				);
			}
		} else {
			error_log( "Using provided content directly" );
			$file_contents = $file_path; // When not a path, this contains the actual content
		}

		$content_length = strlen( $file_contents );
		error_log( "Content length: $content_length bytes" );

		// 4. Prepare headers - ALWAYS include Content-Length
		$headers = array_merge( [
			'Content-Type'   => $content_type,
			'Content-Length' => (string) $content_length  // CRITICAL: Always set Content-Length
		], $additional_params );

		error_log( "Headers: " . print_r( $headers, true ) );

		// 5. Upload the file using WordPress HTTP API
		error_log( "Uploading file via HTTP PUT..." );
		$response = wp_remote_request( $upload_url, [
			'method'  => 'PUT',
			'body'    => $file_contents,
			'headers' => $headers,
			'timeout' => 300  // 5 minutes for large files
		] );

		// Handle upload errors
		if ( is_wp_error( $response ) ) {
			error_log( "ERROR: wp_remote_request failed - " . $response->get_error_message() );

			return new ErrorResponse(
				$response->get_error_message(),
				$response->get_error_code(),
				400,
				$response->get_error_data() ?: []
			);
		}

		$status_code      = wp_remote_retrieve_response_code( $response );
		$response_body    = wp_remote_retrieve_body( $response );
		$response_headers = wp_remote_retrieve_headers( $response );

		error_log( "Upload response status: $status_code" );
		error_log( "Response body: " . substr( $response_body, 0, 500 ) );
		error_log( "Response headers: " . print_r( $response_headers, true ) );

		if ( $status_code < 200 || $status_code >= 300 ) {
			error_log( "ERROR: Upload failed with status code $status_code" );

			return new ErrorResponse(
				sprintf( __( 'Upload failed with status code: %d', 'arraypress' ), $status_code ),
				'upload_error',
				$status_code,
				[
					'response'      => $response,
					'response_body' => $response_body,
					'headers_sent'  => $headers
				]
			);
		}

		// 5. Clear cache for this bucket/prefix
		if ( $this->is_cache_enabled() ) {
			// Extract the directory prefix from the object key
			$prefix = Directory::prefix( $target_key );
			error_log( "Clearing cache for prefix: '$prefix'" );

			// Clear cache for this specific prefix
			$cache_key = $this->get_cache_key( 'objects_' . $bucket, [
				'max_keys'  => 1000,
				'prefix'    => $prefix,
				'delimiter' => '/'
			] );
			$this->clear_cache_item( $cache_key );
		}

		// 6. Return success response
		error_log( "SUCCESS: File uploaded successfully" );

		return new SuccessResponse(
			__( 'File uploaded successfully', 'arraypress' ),
			$status_code,
			[
				'bucket' => $bucket,
				'key'    => $target_key,
				'size'   => $content_length
			]
		);
	}

}