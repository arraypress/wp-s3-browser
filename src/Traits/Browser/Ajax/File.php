<?php
/**
 * File AJAX Trait (legacy)
 *
 * Thin adapters over the REST routes, retained so front-end code written
 * against the admin-ajax actions keeps working. All logic lives in
 * ArrayPress\S3\Traits\Browser\Rest\Handlers.
 *
 * @package     ArrayPress\S3\Traits\Browser\Ajax
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 * @deprecated  1.2.0 Use the REST API routes instead.
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Browser\Ajax;

/**
 * Trait File
 */
trait File {

	/**
	 * Handle AJAX delete object request
	 *
	 * @deprecated 1.2.0 Use DELETE /<namespace>/<base>/buckets/<bucket>/objects
	 */
	public function handle_ajax_delete_object(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		$this->dispatch_to_rest( 'rest_delete_object', [
			'bucket' => $this->get_sanitized_post( 'bucket' ),
			'key'    => $this->get_sanitized_post( 'key', true ),
		] );
	}

	/**
	 * Handle AJAX rename object request
	 *
	 * @deprecated 1.2.0 Use PATCH /<namespace>/<base>/buckets/<bucket>/objects
	 */
	public function handle_ajax_rename_object(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		$this->dispatch_to_rest( 'rest_rename_object', [
			'bucket'       => $this->get_sanitized_post( 'bucket' ),
			'current_key'  => $this->get_sanitized_post( 'current_key', true ),
			'new_filename' => $this->get_sanitized_post( 'new_filename', true ),
		] );
	}

	/**
	 * Handle AJAX presigned download URL request
	 *
	 * @deprecated 1.2.0 Use POST /<namespace>/<base>/buckets/<bucket>/objects/download-url
	 */
	public function handle_ajax_get_presigned_url(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		$this->dispatch_to_rest( 'rest_get_download_url', [
			'bucket'          => $this->get_sanitized_post( 'bucket' ),
			'key'             => $this->get_sanitized_post( 'object_key', true ),
			'expires_minutes' => isset( $_POST['expires_minutes'] ) ? absint( $_POST['expires_minutes'] ) : 60,
		] );
	}

}
