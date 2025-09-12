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

	/**
	 * Upload product files with S3 path detection
	 *
	 * @param string|int $identifier Product identifier (ID, slug, or custom folder name)
	 * @param array      $file_paths Array of file paths (local or S3)
	 * @param string     $bucket     Target S3 bucket
	 * @param string     $prefix     S3 prefix for uploaded files
	 * @param bool       $overwrite  Whether to overwrite existing files
	 *
	 * @return ResponseInterface Response with migration results
	 */
	public function upload_product_files(
		$identifier,
		array $file_paths,
		string $bucket,
		string $prefix = 'products/',
		bool $overwrite = false
	): ResponseInterface {

		// Resolve folder name using Post utility
		$folder_name = Post::resolve_folder_name( $identifier );
		$prefix      = rtrim( $prefix, '/' ) . '/' . $folder_name . '/';

		$results = [
			'identifier' => $identifier,
			'folder'     => $folder_name,
			'uploaded'   => [],
			'skipped'    => [],
			'failed'     => [],
			'total_size' => 0
		];

		foreach ( $file_paths as $index => $path ) {
			// Check if already an S3 path
			if ( Detect::is_s3_path( $path ) ) {
				$results['skipped'][] = [
					'path'   => $path,
					'reason' => 'Already an S3 path'
				];
				continue;
			}

			// Convert URL to local path if needed
			$local_path = File::resolve_local_path( $path );

			if ( ! $local_path || ! file_exists( $local_path ) ) {
				$results['failed'][] = [
					'path'  => $path,
					'error' => 'File not found locally'
				];
				continue;
			}

			// Generate S3 key
			$filename   = basename( $local_path );
			$object_key = $prefix . $filename;

			// Check if file exists (unless overwrite is true)
			if ( ! $overwrite ) {
				$exists_response = $this->head_object( $bucket, $object_key );
				if ( $exists_response->is_successful() ) {
					$results['skipped'][] = [
						'path'    => $path,
						'reason'  => 'File already exists in bucket',
						's3_path' => $bucket . '/' . $object_key
					];
					continue;
				}
			}

			// Upload the file
			$response = $this->upload_file( $local_path, $bucket, $object_key );

			if ( $response->is_successful() ) {
				$file_size             = filesize( $local_path );
				$results['uploaded'][] = [
					'original' => $path,
					's3_path'  => $bucket . '/' . $object_key,
					'size'     => $file_size
				];
				$results['total_size'] += $file_size;
			} else {
				$results['failed'][] = [
					'path'  => $path,
					'error' => $response->get_error_message()
				];
			}
		}

		// Store migration metadata if it's a WordPress post
		if ( is_numeric( $identifier ) ) {
			$post_id = intval( $identifier );
			if ( ! empty( $results['uploaded'] ) && get_post( $post_id ) ) {
				update_post_meta( $post_id, '_s3_migration_date', current_time( 'mysql' ) );
				update_post_meta( $post_id, '_s3_migration_bucket', $bucket );
				update_post_meta( $post_id, '_s3_migration_folder', $folder_name );
			}
		}

		$status_code = empty( $results['failed'] ) ? 200 : 207;
		$message     = sprintf(
			__( 'Product files: %d uploaded, %d skipped, %d failed', 'arraypress' ),
			count( $results['uploaded'] ),
			count( $results['skipped'] ),
			count( $results['failed'] )
		);

		return new SuccessResponse( $message, $status_code, $results );
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

	/**
	 * Upload directory contents
	 *
	 * @param string $directory  Local directory path
	 * @param string $bucket     Target S3 bucket
	 * @param string $prefix     S3 prefix for files
	 * @param array  $extensions File extensions to include (empty = all)
	 *
	 * @return ResponseInterface Response with upload results
	 */
	public function upload_directory(
		string $directory,
		string $bucket,
		string $prefix = '',
		array $extensions = []
	): ResponseInterface {

		if ( ! is_dir( $directory ) ) {
			return new ErrorResponse(
				sprintf( __( 'Directory not found: %s', 'arraypress' ), $directory ),
				'directory_not_found',
				404
			);
		}

		$files    = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			// Check extension filter
			if ( ! empty( $extensions ) ) {
				$ext = strtolower( $file->getExtension() );
				if ( ! in_array( $ext, $extensions, true ) ) {
					continue;
				}
			}

			$file_path     = $file->getPathname();
			$relative_path = str_replace( $directory . DIRECTORY_SEPARATOR, '', $file_path );
			$relative_path = str_replace( DIRECTORY_SEPARATOR, '/', $relative_path );

			$files[] = [
				'path' => $file_path,
				'key'  => $prefix . $relative_path
			];
		}

		if ( empty( $files ) ) {
			return new ErrorResponse(
				__( 'No files found to upload', 'arraypress' ),
				'no_files',
				404
			);
		}

		return $this->batch_upload_files( $files, $bucket );
	}

}