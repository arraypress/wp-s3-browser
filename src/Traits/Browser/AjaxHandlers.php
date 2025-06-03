<?php
/**
 * Browser AJAX Handlers Trait - Complete Fixed Version
 *
 * Handles AJAX operations for the S3 Browser including proper character handling
 * and comprehensive error management for all S3 operations.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Browser;

use ArrayPress\S3\Tables\Objects;
use ArrayPress\S3\Utils\Directory;
use ArrayPress\S3\Utils\Validate;

/**
 * Trait AjaxHandlers
 *
 * Provides AJAX endpoint handlers for S3 Browser operations including file management,
 * folder operations, uploads, and cache management. All handlers include proper
 * character encoding handling to prevent issues with special characters like apostrophes.
 */
trait AjaxHandlers {

	/**
	 * Handle AJAX delete object request
	 *
	 * Processes requests to delete individual objects from S3 buckets.
	 * Includes proper character handling for filenames with special characters.
	 *
	 * Expected POST parameters:
	 * - bucket: S3 bucket name
	 * - key: Object key to delete
	 * - nonce: Security nonce
	 *
	 * @since 1.0.0
	 */
	public function handle_ajax_delete_object(): void {
		if ( ! check_ajax_referer( 's3_browser_nonce_' . $this->provider_id, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed', 'arraypress' ) ] );

			return;
		}

		if ( ! current_user_can( $this->capability ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action', 'arraypress' ) ] );

			return;
		}

		$bucket     = isset( $_POST['bucket'] ) ? sanitize_text_field( $_POST['bucket'] ) : '';
		$object_key = isset( $_POST['key'] ) ? wp_unslash( sanitize_text_field( $_POST['key'] ) ) : '';

		if ( empty( $bucket ) || empty( $object_key ) ) {
			wp_send_json_error( [ 'message' => __( 'Bucket and object key are required', 'arraypress' ) ] );

			return;
		}

		$result = $this->client->delete_object( $bucket, $object_key );

		if ( ! $result->is_successful() ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );

			return;
		}

		$this->client->clear_all_cache();

		wp_send_json_success( [
			'message' => __( 'File successfully deleted', 'arraypress' ),
			'bucket'  => $bucket,
			'key'     => $object_key
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
		if ( ! check_ajax_referer( 's3_browser_nonce_' . $this->provider_id, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed', 'arraypress' ) ] );

			return;
		}

		if ( ! current_user_can( $this->capability ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action', 'arraypress' ) ] );

			return;
		}

		$bucket      = isset( $_POST['bucket'] ) ? sanitize_text_field( $_POST['bucket'] ) : '';
		$folder_path = isset( $_POST['folder_path'] ) ? wp_unslash( sanitize_text_field( $_POST['folder_path'] ) ) : '';

		if ( empty( $bucket ) || empty( $folder_path ) ) {
			wp_send_json_error( [ 'message' => __( 'Bucket and folder path are required', 'arraypress' ) ] );

			return;
		}

		// Normalize folder path to ensure it ends with /
		$normalized_folder_path = Directory::normalize( $folder_path );

		// Use batch deletion with fallback to regular deletion
		$result = $this->client->delete_folder_batch( $bucket, $normalized_folder_path, true, true );

		// If batch deletion failed due to network/timeout issues, try regular deletion
		if ( ! $result->is_successful() ) {
			$error_code = $result->get_error_code();

			// Check for network/timeout related errors
			if ( in_array( $error_code, [ 'batch_delete_timeout', 'batch_delete_not_supported', 'network_error' ] ) ) {
				// Fallback to regular folder deletion
				$result = $this->client->delete_folder( $bucket, $normalized_folder_path, true, true );
			}
		}

		if ( ! $result->is_successful() ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );

			return;
		}

		// Clear cache after successful deletion
		$this->client->clear_all_cache();

		$data = $result->get_data();

		// Create appropriate success message based on what was deleted
		if ( isset( $data['deleted_count'] ) && $data['deleted_count'] > 0 ) {
			$message = sprintf( __( 'Folder deleted successfully (%d items removed)', 'arraypress' ), $data['deleted_count'] );
		} elseif ( isset( $data['success_count'] ) && $data['success_count'] > 0 ) {
			$message = sprintf( __( 'Folder deleted successfully (%d objects removed)', 'arraypress' ), $data['success_count'] );
		} else {
			$message = __( 'Folder deleted successfully', 'arraypress' );
		}

		wp_send_json_success( [
			'message'     => $message,
			'bucket'      => $bucket,
			'folder_path' => $normalized_folder_path,
			'data'        => $data
		] );
	}

	/**
	 * Handle AJAX rename object request
	 *
	 * Processes requests to rename objects in S3 buckets. Performs validation
	 * on the new filename and checks for conflicts before executing the rename
	 * operation (which is implemented as copy + delete).
	 *
	 * Expected POST parameters:
	 * - bucket: S3 bucket name
	 * - current_key: Current object key
	 * - new_filename: New filename (without path)
	 * - nonce: Security nonce
	 *
	 * @since 1.0.0
	 */
	public function handle_ajax_rename_object(): void {
		if ( ! check_ajax_referer( 's3_browser_nonce_' . $this->provider_id, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed', 'arraypress' ) ] );

			return;
		}

		if ( ! current_user_can( $this->capability ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action', 'arraypress' ) ] );

			return;
		}

		$bucket       = isset( $_POST['bucket'] ) ? sanitize_text_field( $_POST['bucket'] ) : '';
		$current_key  = isset( $_POST['current_key'] ) ? wp_unslash( sanitize_text_field( $_POST['current_key'] ) ) : '';
		$new_filename = isset( $_POST['new_filename'] ) ? wp_unslash( sanitize_text_field( $_POST['new_filename'] ) ) : '';

		if ( empty( $bucket ) || empty( $current_key ) || empty( $new_filename ) ) {
			wp_send_json_error( [ 'message' => __( 'Bucket, current key, and new filename are required', 'arraypress' ) ] );

			return;
		}

		// Validate the new filename
		$validation_result = Validate::filename_comprehensive( $new_filename );
		if ( ! $validation_result['valid'] ) {
			wp_send_json_error( [ 'message' => $validation_result['message'] ] );

			return;
		}

		// Extract directory path from current key
		$directory_path = dirname( $current_key );
		$directory_path = ( $directory_path === '.' ) ? '' : $directory_path . '/';

		// Build new object key
		$new_key = $directory_path . $new_filename;

		// Check if the new key would be the same as current key
		if ( $new_key === $current_key ) {
			wp_send_json_error( [ 'message' => __( 'The new filename is the same as the current filename', 'arraypress' ) ] );

			return;
		}

		// Check if an object with the new key already exists
		$exists_result = $this->client->object_exists( $bucket, $new_key );
		if ( $exists_result->is_successful() ) {
			$data = $exists_result->get_data();
			if ( $data['exists'] ) {
				wp_send_json_error( [ 'message' => sprintf( __( 'A file named "%s" already exists in this location', 'arraypress' ), $new_filename ) ] );

				return;
			}
		}

		// Perform the rename operation
		$rename_result = $this->client->rename_object( $bucket, $current_key, $new_key );

		if ( ! $rename_result->is_successful() ) {
			wp_send_json_error( [ 'message' => $rename_result->get_error_message() ] );

			return;
		}

		$this->client->clear_all_cache();

		wp_send_json_success( [
			'message'      => sprintf( __( 'File renamed to "%s" successfully', 'arraypress' ), $new_filename ),
			'bucket'       => $bucket,
			'old_key'      => $current_key,
			'new_key'      => $new_key,
			'new_filename' => $new_filename
		] );
	}

	/**
	 * Handle AJAX get presigned URL request
	 *
	 * Generates presigned URLs for file sharing with customizable expiration.
	 * Used by the "Copy Link" functionality to create temporary access URLs.
	 *
	 * Expected POST parameters:
	 * - bucket: S3 bucket name
	 * - object_key: Object key for which to generate URL
	 * - expires_minutes: Expiration time in minutes (optional, defaults to 60)
	 * - nonce: Security nonce
	 *
	 * @since 1.0.0
	 */
	public function handle_ajax_get_presigned_url(): void {
		if ( ! check_ajax_referer( 's3_browser_nonce_' . $this->provider_id, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed', 'arraypress' ) ] );

			return;
		}

		if ( ! current_user_can( $this->capability ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action', 'arraypress' ) ] );

			return;
		}

		$bucket          = isset( $_POST['bucket'] ) ? sanitize_text_field( $_POST['bucket'] ) : '';
		$object_key      = isset( $_POST['object_key'] ) ? wp_unslash( sanitize_text_field( $_POST['object_key'] ) ) : '';
		$expires_minutes = isset( $_POST['expires_minutes'] ) ? absint( $_POST['expires_minutes'] ) : 60;

		if ( empty( $bucket ) || empty( $object_key ) ) {
			wp_send_json_error( [ 'message' => __( 'Bucket and object key are required', 'arraypress' ) ] );

			return;
		}

		// Validate expiration time (between 1 minute and 7 days)
		if ( $expires_minutes < 1 ) {
			$expires_minutes = 1;
		} elseif ( $expires_minutes > 10080 ) { // 7 days in minutes
			$expires_minutes = 10080;
		}

		// First check if the object exists
		$exists_result = $this->client->object_exists( $bucket, $object_key );
		if ( ! $exists_result->is_successful() ) {
			wp_send_json_error( [ 'message' => __( 'Error checking if file exists', 'arraypress' ) ] );

			return;
		}

		$data = $exists_result->get_data();
		if ( ! $data['exists'] ) {
			wp_send_json_error( [ 'message' => __( 'File does not exist', 'arraypress' ) ] );

			return;
		}

		// Generate the presigned URL
		$presigned_result = $this->client->get_presigned_url( $bucket, $object_key, $expires_minutes );

		if ( ! $presigned_result->is_successful() ) {
			wp_send_json_error( [ 'message' => $presigned_result->get_error_message() ] );

			return;
		}

		$expires_at = time() + ( $expires_minutes * 60 );

		wp_send_json_success( [
			'url'        => $presigned_result->get_url(),
			'expires_at' => $expires_at,
			'expires_in' => $expires_minutes,
			'object_key' => $object_key,
			'bucket'     => $bucket,
			'message'    => sprintf(
				__( 'Link generated successfully (expires in %d minutes)', 'arraypress' ),
				$expires_minutes
			)
		] );
	}

	/**
	 * Handle AJAX load more request
	 *
	 * Processes pagination requests for object listings. Delegates to the
	 * Objects table class for handling pagination logic and returning
	 * additional object data.
	 *
	 * Expected POST parameters:
	 * - Various pagination parameters handled by Objects::ajax_load_more()
	 * - nonce: Security nonce
	 *
	 * @since 1.0.0
	 */
	public function handle_ajax_load_more(): void {
		if ( ! check_ajax_referer( 's3_browser_nonce_' . $this->provider_id, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed', 'arraypress' ) ] );

			return;
		}

		if ( ! current_user_can( $this->capability ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action', 'arraypress' ) ] );

			return;
		}

		Objects::ajax_load_more( $this->client, $this->provider_id );
	}

	/**
	 * Handle AJAX request for presigned upload URL
	 *
	 * Generates presigned URLs for direct browser uploads to S3. The presigned
	 * URL allows the browser to upload files directly to S3 without passing
	 * through the server, improving performance and reducing server load.
	 *
	 * Expected POST parameters:
	 * - bucket: Target S3 bucket name
	 * - object_key: Target object key (including path and filename)
	 * - nonce: Security nonce
	 *
	 * Returns:
	 * - url: Presigned upload URL
	 * - expires: URL expiration timestamp
	 *
	 * @since 1.0.0
	 */
	public function handle_ajax_get_upload_url() {
		if ( ! check_ajax_referer( 's3_browser_nonce_' . $this->provider_id, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed', 'arraypress' ) ] );

			return;
		}

		if ( ! current_user_can( $this->capability ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action', 'arraypress' ) ] );

			return;
		}

		$bucket     = isset( $_POST['bucket'] ) ? sanitize_text_field( $_POST['bucket'] ) : '';
		$object_key = isset( $_POST['object_key'] ) ? wp_unslash( sanitize_text_field( $_POST['object_key'] ) ) : '';

		if ( empty( $bucket ) || empty( $object_key ) ) {
			wp_send_json_error( [ 'message' => __( 'Bucket and object key are required', 'arraypress' ) ] );

			return;
		}

		$response = $this->client->get_presigned_upload_url( $bucket, $object_key );

		if ( ! $response->is_successful() ) {
			wp_send_json_error( [ 'message' => $response->get_error_message() ] );

			return;
		}

		$this->client->clear_all_cache();

		wp_send_json_success( [
			'url'     => $response->get_url(),
			'expires' => time() + ( 15 * 60 )
		] );
	}

	/**
	 * Handle AJAX cache clear request
	 *
	 * Processes requests to clear all cached S3 data. This includes object
	 * listings, bucket information, and any other cached S3 responses.
	 * Useful for forcing fresh data retrieval from S3.
	 *
	 * Expected POST parameters:
	 * - nonce: Security nonce
	 *
	 * @since 1.0.0
	 */
	public function handle_ajax_clear_cache(): void {
		if ( ! check_ajax_referer( 's3_browser_nonce_' . $this->provider_id, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed', 'arraypress' ) ] );

			return;
		}

		if ( ! current_user_can( $this->capability ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied', 'arraypress' ) ] );

			return;
		}

		$success = $this->client->clear_all_cache();

		if ( $success ) {
			wp_send_json_success( [
				'message' => __( 'Cache cleared successfully', 'arraypress' ),
				'status'  => 'success'
			] );
		} else {
			wp_send_json_error( [
				'message' => __( 'Failed to clear cache', 'arraypress' ),
				'status'  => 'error'
			] );
		}
	}

	/**
	 * Handle AJAX toggle favorite request
	 *
	 * Processes requests to set or remove a bucket as the user's default/favorite
	 * bucket for a specific post type context. This allows users to have different
	 * default buckets for different contexts (e.g., media library vs. custom post types).
	 *
	 * Expected POST parameters:
	 * - bucket: Bucket name to set as favorite
	 * - favorite_action: 'add' or 'remove' (optional, toggles if not specified)
	 * - post_type: Context for the favorite (defaults to 'default')
	 * - nonce: Security nonce
	 *
	 * @since 1.0.0
	 */
	public function handle_ajax_toggle_favorite(): void {
		if ( ! check_ajax_referer( 's3_browser_nonce_' . $this->provider_id, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed', 'arraypress' ) ] );

			return;
		}

		if ( ! current_user_can( $this->capability ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action', 'arraypress' ) ] );

			return;
		}

		$bucket = isset( $_POST['bucket'] ) ? sanitize_text_field( $_POST['bucket'] ) : '';
		if ( empty( $bucket ) ) {
			wp_send_json_error( [ 'message' => __( 'Bucket name is required', 'arraypress' ) ] );

			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( [ 'message' => __( 'User not logged in', 'arraypress' ) ] );

			return;
		}

		$action           = isset( $_POST['favorite_action'] ) ? sanitize_text_field( $_POST['favorite_action'] ) : '';
		$post_type        = isset( $_POST['post_type'] ) ? sanitize_text_field( $_POST['post_type'] ) : 'default';
		$meta_key         = "s3_favorite_{$this->provider_id}_{$post_type}";
		$current_favorite = get_user_meta( $user_id, $meta_key, true );

		$should_add = $action === 'add' || ( $action !== 'remove' && $current_favorite !== $bucket );

		delete_user_meta( $user_id, $meta_key );

		$result = true;
		if ( $should_add ) {
			$result = update_user_meta( $user_id, $meta_key, $bucket );
			$status = 'added';
		} else {
			$status = 'removed';
		}

		if ( $result ) {
			wp_send_json_success( [
				'message' => $status === 'added'
					? __( 'Bucket set as default', 'arraypress' )
					: __( 'Default bucket removed', 'arraypress' ),
				'status'  => $status,
				'bucket'  => $bucket
			] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Failed to update default bucket', 'arraypress' ) ] );
		}
	}

	/**
	 * AJAX Handler for Folder Creation
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
		if ( ! check_ajax_referer( 's3_browser_nonce_' . $this->provider_id, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed', 'arraypress' ) ] );

			return;
		}

		if ( ! current_user_can( $this->capability ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action', 'arraypress' ) ] );

			return;
		}

		$bucket         = isset( $_POST['bucket'] ) ? sanitize_text_field( $_POST['bucket'] ) : '';
		$current_prefix = isset( $_POST['prefix'] ) ? wp_unslash( sanitize_text_field( $_POST['prefix'] ) ) : '';
		$folder_name    = isset( $_POST['folder_name'] ) ? wp_unslash( sanitize_text_field( $_POST['folder_name'] ) ) : '';

		if ( empty( $bucket ) ) {
			wp_send_json_error( [ 'message' => __( 'Bucket name is required', 'arraypress' ) ] );

			return;
		}

		if ( empty( $folder_name ) ) {
			wp_send_json_error( [ 'message' => __( 'Folder name is required', 'arraypress' ) ] );

			return;
		}

		$validation_result = Validate::folder_comprehensive( $folder_name );
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

}