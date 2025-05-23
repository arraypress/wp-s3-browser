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
		// 1. Get a presigned upload URL
		$upload_url_response = $this->get_presigned_upload_url( $bucket, $target_key, 15 );

		if ( is_wp_error( $upload_url_response ) ) {
			return $upload_url_response;
		}

		if ( ! $upload_url_response->is_successful() ) {
			return new ErrorResponse(
				__( 'Failed to generate upload URL', 'arraypress' ),
				'upload_url_error',
				400
			);
		}

		// Get the presigned upload URL
		$upload_url = $upload_url_response->get_url();

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

		// 3. Read the file contents
		$file_contents = $is_path ? file_get_contents( $file_path ) : $file_path;

		if ( $file_contents === false && $is_path ) {
			return new ErrorResponse(
				__( 'Failed to read file', 'arraypress' ),
				'file_read_error',
				400,
				[ 'file_path' => $file_path ]
			);
		}

		// 4. Upload the file using WordPress HTTP API
		$response = wp_remote_request( $upload_url, [
			'method'  => 'PUT',
			'body'    => $file_contents,
			'headers' => array_merge( [
				'Content-Type' => $content_type
			], $additional_params )
		] );

		// Handle upload errors
		if ( is_wp_error( $response ) ) {
			return new ErrorResponse(
				$response->get_error_message(),
				$response->get_error_code(),
				400,
				$response->get_error_data() ?: []
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			return new ErrorResponse(
				sprintf( __( 'Upload failed with status code: %d', 'arraypress' ), $status_code ),
				'upload_error',
				$status_code,
				[ 'response' => $response ]
			);
		}

		// 5. Clear cache for this bucket/prefix
		if ( $this->is_cache_enabled() ) {
			// Extract the directory prefix from the object key
			$prefix = Directory::prefix( $target_key );

			// Clear cache for this specific prefix
			$cache_key = $this->get_cache_key( 'objects_' . $bucket, [
				'max_keys'  => 1000,
				'prefix'    => $prefix,
				'delimiter' => '/'
			] );
			$this->clear_cache_item( $cache_key );
		}

		// 6. Return success response
		return new SuccessResponse(
			__( 'File uploaded successfully', 'arraypress' ),
			$status_code,
			[
				'bucket' => $bucket,
				'key'    => $target_key,
				'size'   => strlen( $file_contents )
			]
		);
	}

}