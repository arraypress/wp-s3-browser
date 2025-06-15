<?php
/**
 * Client WordPress Upload Operations Trait
 *
 * Simple WordPress integration for S3 uploads.
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
use ArrayPress\S3\Utils\Directory;

/**
 * Trait Upload
 *
 * Simple WordPress-specific upload functionality for S3 integration.
 */
trait Upload {

	/**
	 * Upload a WordPress attachment to S3
	 *
	 * @param int    $attachment_id WordPress attachment ID
	 * @param string $bucket        Target S3 bucket
	 * @param string $prefix        Optional prefix for the S3 key (e.g., 'uploads/')
	 *
	 * @return ResponseInterface Response with upload details
	 */
	public function upload_attachment( int $attachment_id, string $bucket, string $prefix = 'uploads/' ): ResponseInterface {
		// Get attachment file path
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new ErrorResponse(
				sprintf( __( 'Attachment file not found for ID: %d', 'arraypress' ), $attachment_id ),
				'file_not_found',
				404
			);
		}

		// Get attachment info
		$attachment = get_post( $attachment_id );
		if ( ! $attachment ) {
			return new ErrorResponse(
				sprintf( __( 'Attachment not found for ID: %d', 'arraypress' ), $attachment_id ),
				'attachment_not_found',
				404
			);
		}

		// Build S3 key using utility
		$object_key = Directory::build_wp_object_key( $file_path, $prefix );

		// Get MIME type
		$mime_type = get_post_mime_type( $attachment_id ) ?: 'application/octet-stream';

		// Upload the file
		$upload_response = $this->put_object( $bucket, $object_key, $file_path, true, $mime_type );

		if ( $upload_response->is_successful() ) {
			// Store S3 info in attachment meta
			update_post_meta( $attachment_id, '_s3_bucket', $bucket );
			update_post_meta( $attachment_id, '_s3_object_key', $object_key );
		}

		return $upload_response;
	}

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