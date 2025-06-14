<?php
/**
 * Browser File Operations AJAX Handlers Trait
 *
 * Handles AJAX operations specifically for file management in the S3 Browser.
 *
 * @package     ArrayPress\S3\Traits\Browser\Ajax
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Browser\Ajax;

use ArrayPress\S3\Responses\ErrorResponse;
use ArrayPress\S3\Utils\Directory;
use ArrayPress\S3\Utils\Validate;
use ArrayPress\S3\Utils\Sanitize;
use ArrayPress\S3\Utils\Timestamp;

/**
 * Trait File
 *
 * Provides AJAX endpoint handlers for file-specific operations including
 * delete, rename, and presigned URL generation.
 */
trait File {

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
	 */
	public function handle_ajax_delete_object(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		$bucket     = $this->get_sanitized_post( 'bucket' );
		$object_key = $this->get_sanitized_post( 'key', true ); // Preserve special chars

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
	 */
	public function handle_ajax_rename_object(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		$params = $this->validate_required_params( [ 'bucket', 'current_key', 'new_filename' ], true );
		if ( $params === false ) {
			return;
		}

		// Validate the new filename
		$validation_result = Validate::filename( $params['new_filename'] );
		if ( ! $validation_result['valid'] ) {
			wp_send_json_error( [ 'message' => $validation_result['message'] ] );

			return;
		}

		// Build new object key using Directory utility
		$new_key = Directory::build_rename_key( $params['current_key'], $params['new_filename'] );

		// Check if the new key would be the same as current key
		if ( Directory::is_rename_same_key( $params['current_key'], $params['new_filename'] ) ) {
			wp_send_json_error( [ 'message' => __( 'The new filename is the same as the current filename', 'arraypress' ) ] );

			return;
		}

		// Check if an object with the new key already exists
		$exists_result = $this->client->object_exists( $params['bucket'], $new_key );
		if ( $exists_result->is_successful() ) {
			$data = $exists_result->get_data();
			if ( $data['exists'] ) {
				wp_send_json_error( [ 'message' => sprintf( __( 'A file named "%s" already exists in this location', 'arraypress' ), $params['new_filename'] ) ] );

				return;
			}
		}

		// Perform the rename operation
		$rename_result = $this->client->rename_object( $params['bucket'], $params['current_key'], $new_key );

		if ( ! $rename_result->is_successful() ) {
			wp_send_json_error( [ 'message' => $rename_result->get_error_message() ] );

			return;
		}

		$this->client->clear_all_cache();

		wp_send_json_success( [
			'message'      => sprintf( __( 'File renamed to "%s" successfully', 'arraypress' ), $params['new_filename'] ),
			'bucket'       => $params['bucket'],
			'old_key'      => $params['current_key'],
			'new_key'      => $new_key,
			'new_filename' => $params['new_filename']
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
	 */
	public function handle_ajax_get_presigned_url(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		$bucket          = $this->get_sanitized_post( 'bucket' );
		$object_key      = $this->get_sanitized_post( 'object_key', true );
		$expires_minutes = isset( $_POST['expires_minutes'] ) ? absint( $_POST['expires_minutes'] ) : 60;

		if ( empty( $bucket ) || empty( $object_key ) ) {
			wp_send_json_error( [ 'message' => __( 'Bucket and object key are required', 'arraypress' ) ] );

			return;
		}

		// Sanitize expiration time to S3 limits
		$expires_minutes = Sanitize::minutes( $expires_minutes );

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

		$expires_at = Timestamp::in_minutes( $expires_minutes );

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

}