<?php
/**
 * Browser AJAX Handlers Trait - Fixed Folder Delete (Original Approach)
 *
 * Handles AJAX operations for the S3 Browser including proper folder deletion.
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
 */
trait AjaxHandlers {

	/**
	 * Handle AJAX delete object request
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
		$object_key = isset( $_POST['key'] ) ? sanitize_text_field( $_POST['key'] ) : '';

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
	 * Handle AJAX delete folder request - Fixed to properly handle empty folders
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
		$folder_path = isset( $_POST['folder_path'] ) ? sanitize_text_field( $_POST['folder_path'] ) : '';

		if ( empty( $bucket ) || empty( $folder_path ) ) {
			wp_send_json_error( [ 'message' => __( 'Bucket and folder path are required', 'arraypress' ) ] );

			return;
		}

		// Ensure folder path ends with / for proper S3 folder handling
		$normalized_folder_path = rtrim( $folder_path, '/' ) . '/';

		// Always use recursive deletion and force=true for user-initiated folder deletion
		// This ensures both the placeholder object and any contents are removed
		$result = method_exists( $this->client, 'delete_folder_batch' )
			? $this->client->delete_folder_batch( $bucket, $normalized_folder_path, true, true )
			: $this->client->delete_folder( $bucket, $normalized_folder_path, true, true );

		if ( ! $result->is_successful() ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );

			return;
		}

		$this->client->clear_all_cache();

		$data    = $result->get_data();
		$message = isset( $data['deleted_count'] ) && $data['deleted_count'] > 0
			? sprintf( __( 'Folder deleted successfully (%d items removed)', 'arraypress' ), $data['deleted_count'] )
			: __( 'Folder deleted successfully', 'arraypress' );

		wp_send_json_success( [
			'message'     => $message,
			'bucket'      => $bucket,
			'folder_path' => $normalized_folder_path,
			'data'        => $data
		] );
	}

	/**
	 * Handle AJAX rename object request
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
		$current_key  = isset( $_POST['current_key'] ) ? sanitize_text_field( $_POST['current_key'] ) : '';
		$new_filename = isset( $_POST['new_filename'] ) ? sanitize_text_field( $_POST['new_filename'] ) : '';

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
	 * Handle AJAX load more request
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
		$object_key = isset( $_POST['object_key'] ) ? sanitize_text_field( $_POST['object_key'] ) : '';

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
		$current_prefix = isset( $_POST['prefix'] ) ? sanitize_text_field( $_POST['prefix'] ) : '';
		$folder_name    = isset( $_POST['folder_name'] ) ? sanitize_text_field( $_POST['folder_name'] ) : '';

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