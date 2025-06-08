<?php
/**
 * Browser Bucket Operations AJAX Handlers Trait
 *
 * Handles AJAX operations specifically for bucket management in the S3 Browser.
 *
 * @package     ArrayPress\S3\Traits\Browser\Ajax
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Browser\Ajax;

use ArrayPress\S3\Utils\Cors;

/**
 * Trait BucketOperations
 *
 * Provides AJAX endpoint handlers for bucket-specific operations including
 * details retrieval and CORS configuration management.
 */
trait Bucket {

	/**
	 * Handle AJAX get bucket details request
	 *
	 * Uses the client method to gather comprehensive bucket information including
	 * basic details, CORS configuration, and upload capabilities.
	 *
	 * Expected POST parameters:
	 * - bucket: S3 bucket name
	 * - current_origin: Origin for CORS checking (optional)
	 * - nonce: Security nonce
	 */
	/**
	 * Debug version - Handle AJAX get bucket details request
	 */
	public function handle_ajax_get_bucket_details(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		$bucket = $this->get_sanitized_post( 'bucket' );
		$current_origin = $this->get_sanitized_post( 'current_origin' );

		// Debug logging
		error_log( "=== BUCKET DETAILS AJAX DEBUG ===" );
		error_log( "Bucket: " . $bucket );
		error_log( "Current Origin: " . $current_origin );
		error_log( "POST data: " . print_r( $_POST, true ) );

		if ( empty( $bucket ) ) {
			wp_send_json_error( [ 'message' => __( 'Bucket name is required', 'arraypress' ) ] );
			return;
		}

		// Force fresh data by bypassing cache
		$details_result = $this->client->get_bucket_details( $bucket, $current_origin, false );

		error_log( "Details result successful: " . ( $details_result->is_successful() ? 'YES' : 'NO' ) );

		if ( ! $details_result->is_successful() ) {
			error_log( "Details error: " . $details_result->get_error_message() );
			wp_send_json_error( [ 'message' => $details_result->get_error_message() ] );
			return;
		}

		$data = $details_result->get_data();

		// Debug the response data
		error_log( "Response data structure:" );
		error_log( "- bucket: " . ( $data['bucket'] ?? 'NULL' ) );
		error_log( "- cors.upload_ready: " . ( $data['cors']['upload_ready'] ?? 'NULL' ) );
		error_log( "- cors.analysis.has_cors: " . ( $data['cors']['analysis']['has_cors'] ?? 'NULL' ) );
		error_log( "- cors.current_origin: " . ( $data['cors']['current_origin'] ?? 'NULL' ) );
		error_log( "Full CORS data: " . print_r( $data['cors'] ?? [], true ) );
		error_log( "=== END BUCKET DEBUG ===" );

		wp_send_json_success( $data );
	}

	/**
	 * Handle AJAX setup CORS for uploads request
	 *
	 * Sets up minimal CORS configuration optimized for browser uploads
	 * from the current domain using the client's CORS methods.
	 *
	 * Expected POST parameters:
	 * - bucket: S3 bucket name
	 * - origin: Origin to allow (optional, defaults to current)
	 * - nonce: Security nonce
	 */
	public function handle_ajax_setup_cors_upload(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		$bucket = $this->get_sanitized_post( 'bucket' );
		$origin = $this->get_sanitized_post( 'origin' ) ?: Cors::get_current_origin();

		error_log( "=== CORS SETUP DEBUG ===" );
		error_log( "Bucket: " . $bucket );
		error_log( "Origin: " . $origin );

		if ( empty( $bucket ) ) {
			wp_send_json_error( [ 'message' => __( 'Bucket name is required', 'arraypress' ) ] );
			return;
		}

		if ( empty( $origin ) ) {
			wp_send_json_error( [ 'message' => __( 'Origin is required for CORS setup', 'arraypress' ) ] );
			return;
		}

		// Clear ALL cache before setup
		$this->client->clear_all_cache();
		error_log( "Cache cleared before CORS setup" );

		// Use the client method to set CORS for uploads
		$set_result = $this->client->set_cors_scenario( $bucket, 'upload_only', [ $origin ] );

		error_log( "CORS setup result: " . ( $set_result->is_successful() ? 'SUCCESS' : 'FAILED' ) );

		if ( ! $set_result->is_successful() ) {
			error_log( "CORS setup error: " . $set_result->get_error_message() );
			wp_send_json_error( [ 'message' => $set_result->get_error_message() ] );
			return;
		}

		// Clear cache again after setup
		$this->client->clear_all_cache();
		error_log( "Cache cleared after CORS setup" );

		// Wait a moment for eventual consistency
		sleep( 1 );

		// Verify the setup worked (without cache)
		$verification_result = $this->client->cors_allows_upload( $bucket, $origin, false );
		$verification_success = false;

		error_log( "Verification result: " . ( $verification_result->is_successful() ? 'SUCCESS' : 'FAILED' ) );

		if ( $verification_result->is_successful() ) {
			$verification_data = $verification_result->get_data();
			$verification_success = $verification_data['allows_upload'] ?? false;
			error_log( "Verification data: " . print_r( $verification_data, true ) );
			error_log( "Upload allowed: " . ( $verification_success ? 'YES' : 'NO' ) );
		} else {
			error_log( "Verification error: " . $verification_result->get_error_message() );
		}

		error_log( "=== END CORS SETUP DEBUG ===" );

		wp_send_json_success( [
			'bucket'              => $bucket,
			'origin'              => $origin,
			'verification_passed' => $verification_success,
			'message'             => sprintf(
				__( 'CORS configured for uploads from %s to bucket "%s"', 'arraypress' ),
				$origin,
				$bucket
			)
		] );
	}

	/**
	 * Handle AJAX delete CORS configuration request
	 *
	 * Removes all CORS rules from a bucket, disabling cross-origin access.
	 * Used by the revoke CORS functionality in the bucket management interface.
	 *
	 * Expected POST parameters:
	 * - bucket: S3 bucket name
	 * - nonce: Security nonce
	 */
	public function handle_ajax_delete_cors_configuration(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		$bucket = $this->get_sanitized_post( 'bucket' );

		if ( empty( $bucket ) ) {
			wp_send_json_error( [ 'message' => __( 'Bucket name is required', 'arraypress' ) ] );

			return;
		}

		// Delete CORS configuration using client method
		$result = $this->client->delete_cors_configuration( $bucket );

		if ( ! $result->is_successful() ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );

			return;
		}

		// Clear cache after successful deletion
		$this->client->clear_all_cache();

		wp_send_json_success( [
			'bucket'  => $bucket,
			'message' => sprintf( __( 'CORS configuration deleted for bucket "%s"', 'arraypress' ), $bucket ),
			'status'  => 'revoked'
		] );
	}

}