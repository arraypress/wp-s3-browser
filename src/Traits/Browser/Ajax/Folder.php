<?php
/**
 * Browser Folder Operations AJAX Handlers Trait
 *
 * Handles AJAX operations specifically for folder management in the S3 Browser.
 *
 * @package     ArrayPress\S3\Traits\Browser\Ajax
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Browser\Ajax;

use ArrayPress\S3\Utils\Directory;
use ArrayPress\S3\Utils\Validate;

/**
 * Trait FolderOperations
 *
 * Provides AJAX endpoint handlers for folder-specific operations including
 * create and delete folder functionality.
 */
trait Folder {

	/**
	 * Handle AJAX create folder request
	 *
	 * Processes requests to create new folders in S3 buckets. Validates the
	 * folder name according to S3 naming conventions and creates the folder
	 * marker object in the specified bucket and prefix location.
	 *
	 * Expected POST parameters:
	 * - bucket: Target S3 bucket name
	 * - prefix: Current folder prefix/path (optional)
	 * - folder_name: Name of the new folder to create
	 * - nonce: Security nonce
	 *
	 * @since 1.0.0
	 */
	public function handle_ajax_create_folder(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		$bucket         = $this->get_sanitized_post( 'bucket' );
		$current_prefix = $this->get_sanitized_post( 'prefix', true );
		$folder_name    = $this->get_sanitized_post( 'folder_name', true );

		if ( empty( $bucket ) ) {
			wp_send_json_error( [ 'message' => __( 'Bucket name is required', 'arraypress' ) ] );

			return;
		}

		if ( empty( $folder_name ) ) {
			wp_send_json_error( [ 'message' => __( 'Folder name is required', 'arraypress' ) ] );

			return;
		}

		$validation_result = Validate::folder_name( $folder_name );
		if ( ! $validation_result['valid'] ) {
			wp_send_json_error( [ 'message' => $validation_result['message'] ] );

			return;
		}

		$folder_key = Directory::build_folder_key( $current_prefix, $folder_name );
		$result     = $this->client->create_folder( $bucket, $folder_key );

		if ( ! $result->is_successful() ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );

			return;
		}

		$this->client->clear_all_cache();

		wp_send_json_success( [
			'message'    => sprintf( __( 'Folder "%s" created successfully', 'arraypress' ), $folder_name ),
			'folder_key' => $folder_key,
			'bucket'     => $bucket,
			'prefix'     => $current_prefix
		] );
	}

	/**
	 * Handle AJAX delete folder request
	 *
	 * Processes requests to delete entire folders from S3 buckets, including all
	 * contained objects. Uses batch deletion with fallback to individual deletion
	 * for improved reliability across different S3 providers.
	 *
	 * Expected POST parameters:
	 * - bucket: S3 bucket name
	 * - folder_path: Folder path to delete (will be normalized)
	 * - nonce: Security nonce
	 *
	 * @since 1.0.0
	 */
	public function handle_ajax_delete_folder(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		$bucket      = $this->get_sanitized_post( 'bucket' );
		$folder_path = $this->get_sanitized_post( 'folder_path', true );

		if ( empty( $bucket ) || empty( $folder_path ) ) {
			wp_send_json_error( [ 'message' => __( 'Bucket and folder path are required', 'arraypress' ) ] );

			return;
		}

		// Use the enhanced batch deletion method (handles normalization internally)
		$result = $this->delete_folder_with_fallback( $bucket, $folder_path );

		if ( ! $result->is_successful() ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );

			return;
		}

		// Clear cache after successful deletion
		$this->client->clear_all_cache();

		$data = $result->get_data();

		// Create appropriate success message based on what was deleted
		$message = $this->format_folder_deletion_message( $data );

		wp_send_json_success( [
			'message'     => $message,
			'bucket'      => $bucket,
			'folder_path' => $data['folder_path'] ?? Directory::normalize( $folder_path ),
			'data'        => $data
		] );
	}

	/**
	 * Delete folder with intelligent fallback strategy
	 *
	 * The batch trait now handles path normalization internally, so we just
	 * need to pass the raw folder path and let it handle the details.
	 *
	 * @param string $bucket      Bucket name
	 * @param string $folder_path Raw folder path (will be normalized by batch method)
	 *
	 * @return \ArrayPress\S3\Interfaces\Response
	 */
	private function delete_folder_with_fallback( string $bucket, string $folder_path ) {
		// Try batch deletion first (more efficient for larger folders)
		// The delete_folder_batch method now handles normalization internally
		$result = $this->client->delete_folder_batch( $bucket, $folder_path );

		// If batch deletion failed due to network/timeout issues, try regular deletion
		if ( ! $result->is_successful() ) {
			$error_code = $result->get_error_code();

			// Check for network/timeout related errors that warrant fallback
			if ( in_array( $error_code, [ 'batch_delete_timeout', 'batch_delete_not_supported', 'network_error' ] ) ) {
				// Fallback to regular folder deletion
				$result = $this->client->delete_folder( $bucket, $folder_path, true, true );
			}
		}

		return $result;
	}

	/**
	 * Format folder deletion success message
	 *
	 * @param array $data Deletion result data
	 *
	 * @return string Formatted message
	 */
	private function format_folder_deletion_message( array $data ): string {
		if ( isset( $data['deleted_count'] ) && $data['deleted_count'] > 0 ) {
			return sprintf( __( 'Folder deleted successfully (%d items removed)', 'arraypress' ), $data['deleted_count'] );
		}

		if ( isset( $data['success_count'] ) && $data['success_count'] > 0 ) {
			return sprintf( __( 'Folder deleted successfully (%d objects removed)', 'arraypress' ), $data['success_count'] );
		}

		return __( 'Folder deleted successfully', 'arraypress' );
	}

}