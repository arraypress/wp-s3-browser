<?php
/**
 * Browser AJAX Handlers Trait
 *
 * Handles AJAX operations for the S3 Browser.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Browser;

use ArrayPress\S3\Tables\ObjectsTable;

/**
 * Trait AjaxHandlers
 */
trait AjaxHandlers {

	/**
	 * Handle AJAX delete object request
	 *
	 * @return void
	 */
	public function handle_ajax_delete_object(): void {
		// Verify nonce
		if ( ! check_ajax_referer( 's3_browser_nonce_' . $this->provider_id, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed', 'arraypress' ) ] );

			return;
		}

		// Check user capability
		if ( ! current_user_can( $this->capability ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action', 'arraypress' ) ] );

			return;
		}

		// Get parameters
		$bucket     = isset( $_POST['bucket'] ) ? sanitize_text_field( $_POST['bucket'] ) : '';
		$object_key = isset( $_POST['key'] ) ? sanitize_text_field( $_POST['key'] ) : '';

		if ( empty( $bucket ) || empty( $object_key ) ) {
			wp_send_json_error( [ 'message' => __( 'Bucket and object key are required', 'arraypress' ) ] );

			return;
		}

		// Delete the object
		$result = $this->client->delete_object( $bucket, $object_key );

		// Handle WP_Error case
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );

			return;
		}

		// Check if operation was successful using is_successful() method
		// Should always return true for SuccessResponse objects
		if ( $result instanceof \ArrayPress\S3\Interfaces\Response ) {
			if ( ! $result->is_successful() ) {
				wp_send_json_error( [ 'message' => __( 'Failed to delete object', 'arraypress' ) ] );

				return;
			}
		} else {
			// Not a Response object - this should never happen but adding as a fallback
			wp_send_json_error( [ 'message' => __( 'Invalid response from S3 client', 'arraypress' ) ] );

			return;
		}

		// Send successful response
		wp_send_json_success( [
			'message' => __( 'File successfully deleted', 'arraypress' ),
			'bucket'  => $bucket,
			'key'     => $object_key
		] );
	}

	/**
	 * Handle AJAX load more request
	 *
	 * @return void
	 */
	public function handle_ajax_load_more(): void {
		if ( ! current_user_can( $this->capability ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action', 'arraypress' ) ] );

			return;
		}

		// Delegate to the table static method with the client
		ObjectsTable::ajax_load_more( $this->client, $this->provider_id );
	}

	/**
	 * Handle AJAX request for presigned upload URL
	 *
	 * @return void
	 */
	public function handle_ajax_get_upload_url() {
		// Verify nonce and user capability
		if ( ! check_ajax_referer( 's3_browser_nonce_' . $this->provider_id, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed', 'arraypress' ) ] );

			return;
		}

		if ( ! current_user_can( $this->capability ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action', 'arraypress' ) ] );

			return;
		}

		// Get parameters
		$bucket     = isset( $_POST['bucket'] ) ? sanitize_text_field( $_POST['bucket'] ) : '';
		$object_key = isset( $_POST['object_key'] ) ? sanitize_text_field( $_POST['object_key'] ) : '';

		if ( empty( $bucket ) || empty( $object_key ) ) {
			wp_send_json_error( [ 'message' => __( 'Bucket and object key are required', 'arraypress' ) ] );

			return;
		}

		// Generate a pre-signed PUT URL for uploading
		$response = $this->client->get_presigned_upload_url( $bucket, $object_key, 15 ); // 15 minute expiry

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => $response->get_error_message() ] );

			return;
		}

		// Send back the URL
		wp_send_json_success( [
			'url'     => $response->get_url(),
			'expires' => time() + ( 15 * 60 ) // Expiry timestamp
		] );
	}

	/**
	 * Handle AJAX cache clear request
	 *
	 * @return void
	 */
	public function handle_ajax_clear_cache(): void {
		// Verify nonce
		if ( ! check_ajax_referer( 's3_browser_nonce_' . $this->provider_id, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed', 'arraypress' ) ] );

			return;
		}

		// Check user capability
		if ( ! current_user_can( $this->capability ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied', 'arraypress' ) ] );

			return;
		}

		// For simplicity, always clear all cache regardless of type
		$success = $this->client->clear_all_cache();

		if ( $success ) {
			wp_send_json_success( [
				'message' => __( 'Cache cleared successfully', 'arraypress' ),
				'status'  => 'success'  // Add status for notification styling
			] );
		} else {
			wp_send_json_error( [
				'message' => __( 'Failed to clear cache', 'arraypress' ),
				'status'  => 'error'    // Add status for notification styling
			] );
		}
	}

	/**
	 * Handle AJAX toggle favorite request
	 *
	 * @return void
	 */
	public function handle_ajax_toggle_favorite(): void {
		// Verify nonce and user capability
		if ( ! check_ajax_referer( 's3_browser_nonce_' . $this->provider_id, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed', 'arraypress' ) ] );

			return;
		}

		if ( ! current_user_can( $this->capability ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action', 'arraypress' ) ] );

			return;
		}

		// Get and validate parameters
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

		// Get action and post-type
		$action           = isset( $_POST['favorite_action'] ) ? sanitize_text_field( $_POST['favorite_action'] ) : '';
		$post_type        = isset( $_POST['post_type'] ) ? sanitize_text_field( $_POST['post_type'] ) : 'default';
		$meta_key         = "s3_favorite_{$this->provider_id}_{$post_type}";
		$current_favorite = get_user_meta( $user_id, $meta_key, true );

		// Determine if we're adding or removing
		$should_add = $action === 'add' ||
		              ( $action !== 'remove' && $current_favorite !== $bucket );

		// Always clear existing favorite first
		delete_user_meta( $user_id, $meta_key );

		// Add new favorite if needed
		$result = true;
		if ( $should_add ) {
			$result = update_user_meta( $user_id, $meta_key, $bucket );
			$status = 'added';
		} else {
			$status = 'removed';
		}

		// Send response
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
	 * Handle AJAX create folder request
	 *
	 * @return void
	 */
	public function handle_ajax_create_folder(): void {
		// ADD THIS DEBUG LINE:
		error_log( '=== S3 Create Folder AJAX Handler Called ===' );
		error_log( 'POST data: ' . print_r( $_POST, true ) );

		// Verify nonce and capability
		if ( ! check_ajax_referer( 's3_browser_nonce_' . $this->provider_id, 'nonce', false ) ) {
			error_log( 'S3 Create Folder: Nonce check failed' );
			wp_send_json_error( [ 'message' => __( 'Security check failed', 'arraypress' ) ] );

			return;
		}

		if ( ! current_user_can( $this->capability ) ) {
			error_log( 'S3 Create Folder: Capability check failed' );
			wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action', 'arraypress' ) ] );

			return;
		}

		// Get and validate parameters
		$bucket         = isset( $_POST['bucket'] ) ? sanitize_text_field( $_POST['bucket'] ) : '';
		$current_prefix = isset( $_POST['prefix'] ) ? sanitize_text_field( $_POST['prefix'] ) : '';
		$folder_name    = isset( $_POST['folder_name'] ) ? sanitize_text_field( $_POST['folder_name'] ) : '';

		error_log( "Bucket: '$bucket', Prefix: '$current_prefix', Folder: '$folder_name'" );

		if ( empty( $bucket ) ) {
			error_log( 'S3 Create Folder: Bucket name is empty' );
			wp_send_json_error( [ 'message' => __( 'Bucket name is required', 'arraypress' ) ] );

			return;
		}

		if ( empty( $folder_name ) ) {
			error_log( 'S3 Create Folder: Folder name is empty' );
			wp_send_json_error( [ 'message' => __( 'Folder name is required', 'arraypress' ) ] );

			return;
		}

		// Validate folder name
		$validation_result = $this->validate_folder_name( $folder_name );
		if ( ! $validation_result['valid'] ) {
			error_log( 'S3 Create Folder: Validation failed - ' . $validation_result['message'] );
			wp_send_json_error( [ 'message' => $validation_result['message'] ] );

			return;
		}

		// Build the full folder key
		$folder_key = rtrim( $current_prefix, '/' );
		if ( ! empty( $folder_key ) ) {
			$folder_key .= '/';
		}
		$folder_key .= $folder_name . '/';

		error_log( "Final folder key: '$folder_key'" );

		// Create the folder using the client's create_folder method
		error_log( 'Calling client->create_folder()...' );
		$result = $this->client->create_folder( $bucket, $folder_key );

		// ADD DETAILED LOGGING:
		error_log( 'create_folder() result type: ' . gettype( $result ) );
		if ( is_wp_error( $result ) ) {
			error_log( 'Result is WP_Error: ' . $result->get_error_message() );
			error_log( 'Error code: ' . $result->get_error_code() );
			error_log( 'Error data: ' . print_r( $result->get_error_data(), true ) );
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );

			return;
		}

		if ( is_object( $result ) ) {
			error_log( 'Result is object of class: ' . get_class( $result ) );
			if ( method_exists( $result, 'is_successful' ) ) {
				$is_successful = $result->is_successful();
				error_log( 'is_successful(): ' . ( $is_successful ? 'TRUE' : 'FALSE' ) );

				if ( method_exists( $result, 'get_data' ) ) {
					error_log( 'Result data: ' . print_r( $result->get_data(), true ) );
				}

				if ( ! $is_successful ) {
					$error_message = 'Failed to create folder';
					if ( method_exists( $result, 'get_error_message' ) ) {
						$error_message = $result->get_error_message();
					} elseif ( method_exists( $result, 'get_data' ) ) {
						$data = $result->get_data();
						if ( isset( $data['message'] ) ) {
							$error_message = $data['message'];
						}
					}
					error_log( 'Error message: ' . $error_message );
					wp_send_json_error( [ 'message' => $error_message ] );

					return;
				}
			}
		} else {
			error_log( 'Result is not an object: ' . print_r( $result, true ) );
		}

		// Clear cache to ensure the new folder appears
//		$this->client->clear_cache( 'objects', $bucket, $current_prefix );

		$this->client->clear_all_cache();

		error_log( 'Sending success response' );
		wp_send_json_success( [
			'message'    => sprintf( __( 'Folder "%s" created successfully', 'arraypress' ), $folder_name ),
			'folder_key' => $folder_key,
			'bucket'     => $bucket,
			'prefix'     => $current_prefix
		] );
	}

}