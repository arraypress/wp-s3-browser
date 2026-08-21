<?php
/**
 * Bucket AJAX Trait (legacy)
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
 * Trait Bucket
 */
trait Bucket {

	/**
	 * Handle AJAX bucket details request
	 *
	 * @deprecated 1.2.0 Use GET /<namespace>/<base>/buckets/<bucket>
	 */
	public function handle_ajax_get_bucket_details(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		$this->dispatch_to_rest( 'rest_get_bucket_details', [
			'bucket' => $this->get_sanitized_post( 'bucket' ),
		] );
	}

	/**
	 * Handle AJAX CORS setup request
	 *
	 * @deprecated 1.2.0 Use PUT /<namespace>/<base>/buckets/<bucket>/cors
	 */
	public function handle_ajax_setup_cors_upload(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		$this->dispatch_to_rest( 'rest_setup_cors', [
			'bucket' => $this->get_sanitized_post( 'bucket' ),
			'origin' => $this->get_sanitized_post( 'origin' ),
		] );
	}

	/**
	 * Handle AJAX CORS deletion request
	 *
	 * @deprecated 1.2.0 Use DELETE /<namespace>/<base>/buckets/<bucket>/cors
	 */
	public function handle_ajax_delete_cors_configuration(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		$this->dispatch_to_rest( 'rest_delete_cors', [
			'bucket' => $this->get_sanitized_post( 'bucket' ),
		] );
	}

}
