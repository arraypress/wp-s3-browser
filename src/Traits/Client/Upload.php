<?php
/**
 * Client WordPress Upload Operations Trait
 *
 * WordPress integration for S3 uploads and product file migrations.
 *
 * @package     ArrayPress\S3\Traits\Client
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
use ArrayPress\S3\Utils\Detect;
use ArrayPress\S3\Utils\File;
use ArrayPress\S3\Utils\Post;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Trait Upload
 *
 * WordPress-specific upload functionality for S3 integration and product file migrations.
 */
trait Upload {

	/**
	 * Upload any local file to S3
	 *
	 * @param string $file_path  Local file path
	 * @param string $bucket     Target S3 bucket
	 * @param string $object_key Target S3 object key (full path)
	 *
	 * @return ResponseInterface Response with upload details
	 */
	public function upload_file( string $file_path, string $bucket, string $object_key ): ResponseInterface {
		if ( ! file_exists( $file_path ) ) {
			return new ErrorResponse(
				sprintf( __( 'File not found: %s', 'arraypress' ), $file_path ),
				'file_not_found',
				404
			);
		}

		// Get MIME type
		$mime_type = mime_content_type( $file_path ) ?: 'application/octet-stream';

		// Upload the file
		return $this->put_object( $bucket, $object_key, $file_path, true, $mime_type );
	}

	/**
	 * Batch upload files
	 *
	 * @param array  $files  Array of ['path' => local_path, 'key' => s3_key]
	 * @param string $bucket Target S3 bucket
	 *
	 * @return ResponseInterface Response with batch results
	 */
	public function batch_upload_files( array $files, string $bucket ): ResponseInterface {
		$results = [
			'uploaded'   => [],
			'failed'     => [],
			'total_size' => 0
		];

		foreach ( $files as $file ) {
			$local_path = $file['path'] ?? '';
			$object_key = $file['key'] ?? '';

			if ( empty( $local_path ) || empty( $object_key ) ) {
				$results['failed'][] = [
					'file'  => $local_path,
					'error' => 'Missing path or key'
				];
				continue;
			}

			// Skip if already S3 path
			if ( Detect::is_s3_path( $local_path ) ) {
				continue;
			}

			$response = $this->upload_file( $local_path, $bucket, $object_key );

			if ( $response->is_successful() ) {
				$file_size             = filesize( $local_path );
				$results['uploaded'][] = [
					'path' => $local_path,
					'key'  => $object_key,
					'size' => $file_size
				];
				$results['total_size'] += $file_size;
			} else {
				$results['failed'][] = [
					'path'  => $local_path,
					'error' => $response->get_error_message()
				];
			}
		}

		$status_code = empty( $results['failed'] ) ? 200 : 207;
		$message     = sprintf(
			__( 'Batch upload: %d uploaded, %d failed (%s total)', 'arraypress' ),
			count( $results['uploaded'] ),
			count( $results['failed'] ),
			size_format( $results['total_size'] )
		);

		return new SuccessResponse( $message, $status_code, $results );
	}

}