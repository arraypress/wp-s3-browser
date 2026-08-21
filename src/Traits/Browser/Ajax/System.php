<?php
/**
 * System AJAX Trait (legacy)
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

use ArrayPress\S3\Tables\Objects;

/**
 * Trait System
 */
trait System {

	/**
	 * Handle AJAX load-more request
	 *
	 * @deprecated 1.2.0 Use GET /<namespace>/<base>/buckets/<bucket>/objects
	 */
	public function handle_ajax_load_more(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		// Objects::ajax_load_more() reads the bucket from $_POST itself, so gate
		// it here before delegating — otherwise this endpoint enumerates any
		// bucket the credentials can reach.
		if ( ! $this->verify_bucket( $this->get_sanitized_post( 'bucket' ) ) ) {
			return;
		}

		Objects::ajax_load_more( $this->client, $this->provider_id );
	}

	/**
	 * Handle AJAX cache clear request
	 *
	 * @deprecated 1.2.0 Use DELETE /<namespace>/<base>/cache
	 */
	public function handle_ajax_clear_cache(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		$this->dispatch_to_rest( 'rest_clear_cache' );
	}

}
