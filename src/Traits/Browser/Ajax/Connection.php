<?php
/**
 * Connection AJAX Trait (legacy)
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
 * Trait Connection
 */
trait Connection {

	/**
	 * Handle AJAX connection test request
	 *
	 * @deprecated 1.2.0 Use GET /<namespace>/<base>/connection
	 */
	public function handle_ajax_connection_test(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		$this->dispatch_to_rest( 'rest_connection_test' );
	}

}
