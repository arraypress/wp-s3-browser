<?php
/**
 * AJAX Handler Trait
 *
 * Provides common AJAX handling functionality for S3 browser operations.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      ArrayPress Team
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Browser;

use WP_Error;

/**
 * Trait AjaxHandler
 */
trait AjaxHandler {
	// Use BaseScreen trait for shared functionality
	use BaseScreen;

	/**
	 * Provider ID
	 *
	 * @var string
	 */
	private string $provider_id;

	/**
	 * Capability required to perform AJAX actions
	 *
	 * @var string
	 */
	private string $capability = 'upload_files';

	/**
	 * Initialize AJAX handlers
	 *
	 * @param string $provider_id Provider ID
	 * @param string $capability  Optional. Capability required. Default 'upload_files'.
	 *
	 * @return void
	 */
	protected function init_ajax_handlers( string $provider_id, string $capability = 'upload_files' ): void {
		$this->provider_id = $provider_id;
		$this->capability  = $capability;

		// Register AJAX handler for loading more objects
		add_action( 'wp_ajax_s3_load_more_' . $this->provider_id, [ $this, 'handle_ajax_load_more' ] );

		// Register AJAX handler for favoriting buckets
		add_action( 'wp_ajax_s3_toggle_favorite_' . $this->provider_id, [ $this, 'handle_ajax_toggle_favorite' ] );

		// Register AJAX handler for clearing cache
		add_action( 'wp_ajax_s3_clear_cache_' . $this->provider_id, [ $this, 'handle_ajax_clear_cache' ] );

		// Register AJAX handler for getting presigned upload URL
		add_action( 'wp_ajax_s3_get_upload_url_' . $this->provider_id, [ $this, 'handle_ajax_get_upload_url' ] );

		// Register AJAX handler for deleting objects
		add_action( 'wp_ajax_s3_delete_object_' . $this->provider_id, [ $this, 'handle_ajax_delete_object' ] );
	}

	/**
	 * Handle AJAX load more request
	 *
	 * Note: This is a placeholder method that should be implemented by the class using this trait
	 *
	 * @return void
	 */
	public function handle_ajax_load_more(): void {
		// Implementation should be provided by the class using this trait
		$this->send_error_response( 'Method not implemented', 'not_implemented' );
	}

	/**
	 * Handle AJAX toggle favorite request
	 *
	 * @return void
	 */
	public function handle_ajax_toggle_favorite(): void {
		// Verify nonce and user capability
		if ( ! $this->verify_ajax_request( 's3_browser_nonce_' . $this->provider_id ) ) {
			return;
		}

		// Get and validate parameters
		$bucket = isset( $_POST['bucket'] ) ? sanitize_text_field( $_POST['bucket'] ) : '';
		if ( empty( $bucket ) ) {
			$this->send_error_response( __( 'Bucket name is required', 'arraypress' ) );

			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$this->send_error_response( __( 'User not logged in', 'arraypress' ) );

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
			$this->send_success_response( [
				'message' => $status === 'added'
					? __( 'Bucket set as default', 'arraypress' )
					: __( 'Default bucket removed', 'arraypress' ),
				'status'  => $status,
				'bucket'  => $bucket
			] );
		} else {
			$this->send_error_response( __( 'Failed to update default bucket', 'arraypress' ) );
		}
	}

	/**
	 * Handle AJAX cache clear request
	 *
	 * @return void
	 */
	public function handle_ajax_clear_cache(): void {
		// Verify nonce
		if ( ! $this->verify_ajax_request( 's3_browser_nonce_' . $this->provider_id ) ) {
			return;
		}

		// For simplicity, always clear all cache regardless of type
		$success = $this->client->clear_all_cache();

		if ( $success ) {
			$this->send_success_response( [
				'message' => __( 'Cache cleared successfully', 'arraypress' ),
				'status'  => 'success'  // Add status for notification styling
			] );
		} else {
			$this->send_error_response(
				__( 'Failed to clear cache', 'arraypress' ),
				'clear_cache_failed',
				[
					'status' => 'error'    // Add status for notification styling
				]
			);
		}
	}

	/**
	 * Handle AJAX request for presigned upload URL
	 *
	 * @return void
	 */
	public function handle_ajax_get_upload_url(): void {
		// Verify nonce and user capability
		if ( ! $this->verify_ajax_request( 's3_browser_nonce_' . $this->provider_id ) ) {
			return;
		}

		// Get parameters
		$bucket     = isset( $_POST['bucket'] ) ? sanitize_text_field( $_POST['bucket'] ) : '';
		$object_key = isset( $_POST['object_key'] ) ? sanitize_text_field( $_POST['object_key'] ) : '';

		if ( empty( $bucket ) || empty( $object_key ) ) {
			$this->send_error_response( __( 'Bucket and object key are required', 'arraypress' ) );

			return;
		}

		// Generate a pre-signed PUT URL for uploading
		$response = $this->client->get_presigned_upload_url( $bucket, $object_key, 15 ); // 15 minute expiry

		if ( is_wp_error( $response ) ) {
			$this->send_error_response( $response->get_error_message() );

			return;
		}

		// Send back the URL
		$this->send_success_response( [
			'url'     => $response->get_url(),
			'expires' => $response->get_expiration()
		] );
	}

	/**
	 * Handle AJAX delete object request
	 *
	 * @return void
	 */
	public function handle_ajax_delete_object(): void {
		// Verify nonce
		if ( ! $this->verify_ajax_request( 's3_browser_nonce_' . $this->provider_id ) ) {
			return;
		}

		// Get parameters
		$bucket     = isset( $_POST['bucket'] ) ? sanitize_text_field( $_POST['bucket'] ) : '';
		$object_key = isset( $_POST['key'] ) ? sanitize_text_field( $_POST['key'] ) : '';

		if ( empty( $bucket ) || empty( $object_key ) ) {
			$this->send_error_response( __( 'Bucket and object key are required', 'arraypress' ) );

			return;
		}

		// Delete the object
		$result = $this->client->delete_object( $bucket, $object_key );

		// Handle WP_Error case
		if ( is_wp_error( $result ) ) {
			$this->send_error_response( $result->get_error_message() );

			return;
		}

		// Check if operation was successful using is_successful() method
		if ( $result instanceof \ArrayPress\S3\Interfaces\Response ) {
			if ( ! $result->is_successful() ) {
				$this->send_error_response( __( 'Failed to delete object', 'arraypress' ) );

				return;
			}
		} else {
			// Not a Response object - this should never happen but adding as a fallback
			$this->send_error_response( __( 'Invalid response from S3 client', 'arraypress' ) );

			return;
		}

		// Send successful response
		$this->send_success_response( [
			'message' => __( 'File successfully deleted', 'arraypress' ),
			'bucket'  => $bucket,
			'key'     => $object_key
		] );
	}

	/**
	 * Verify AJAX request nonce and capability
	 *
	 * @param string $nonce_action Nonce action name
	 *
	 * @return bool True if verified, false otherwise
	 */
	protected function verify_ajax_request( string $nonce_action ): bool {
		// Verify nonce
		if ( ! check_ajax_referer( $nonce_action, 'nonce', false ) ) {
			$this->send_error_response( __( 'Security check failed', 'arraypress' ), 'security_check_failed' );

			return false;
		}

		// Check user capability
		if ( ! $this->user_has_capability( $this->capability ) ) {
			$this->send_error_response( __( 'You do not have permission to perform this action', 'arraypress' ), 'permission_denied' );

			return false;
		}

		return true;
	}

	/**
	 * Send JSON error response and die
	 *
	 * @param string $message Error message
	 * @param string $code    Optional. Error code. Default 'error'.
	 * @param array  $data    Optional. Additional data. Default empty array.
	 *
	 * @return void
	 */
	protected function send_error_response( string $message, string $code = 'error', array $data = [] ): void {
		$response = array_merge( [
			'message' => $message,
			'code'    => $code
		], $data );

		wp_send_json_error( $response );
	}

	/**
	 * Send JSON success response and die
	 *
	 * @param array $data Response data
	 *
	 * @return void
	 */
	protected function send_success_response( array $data ): void {
		wp_send_json_success( $data );
	}
}