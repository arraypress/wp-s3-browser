<?php
/**
 * Browser Upload Operations AJAX Handlers Trait
 *
 * Handles AJAX operations specifically for upload functionality in the S3 Browser.
 *
 * @package     ArrayPress\S3\Traits\Browser\Ajax
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Browser\Ajax;

use ArrayPress\S3\Utils\Duration;

/**
 * Trait Upload
 *
 * Provides AJAX endpoint handlers for upload-specific operations including
 * presigned upload URL generation.
 */
trait Upload {

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

	 */
	public function handle_ajax_get_upload_url(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		$bucket     = $this->get_sanitized_post( 'bucket' );
		$object_key = $this->get_sanitized_post( 'object_key', true );

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

		// Use Duration utility for consistent expiration handling
		$expires_at = Duration::add_minutes_to_timestamp( time(), 15 );

		wp_send_json_success( [
			'url'     => $response->get_url(),
			'expires' => $expires_at
		] );
	}

}