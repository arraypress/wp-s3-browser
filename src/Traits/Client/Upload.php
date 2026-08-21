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
use ArrayPress\S3\Utils\File;
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

}