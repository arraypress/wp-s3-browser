<?php
/**
 * Browser System Operations AJAX Handlers Trait
 *
 * Handles AJAX operations for system-level functionality in the S3 Browser.
 *
 * @package     ArrayPress\S3\Traits\Browser\Ajax
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Browser\Ajax;

use ArrayPress\S3\Tables\Objects;

/**
 * Trait System
 *
 * Provides AJAX endpoint handlers for system-level operations including
 * cache management, pagination, and user preferences.
 */
trait System {

	/**
	 * Handle AJAX load more request
	 *
	 * Processes pagination requests for object listings. Delegates to the
	 * Objects table class for handling pagination logic and returning
	 * additional object data.
	 *
	 * Expected POST parameters:
	 * - Various pagination parameters handled by Objects::ajax_load_more()
	 * - nonce: Security nonce
	 */
	public function handle_ajax_load_more(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		Objects::ajax_load_more( $this->client, $this->provider_id );
	}

	/**
	 * Handle AJAX cache clear request
	 *
	 * Processes requests to clear all cached S3 data. This includes object
	 * listings, bucket information, and any other cached S3 responses.
	 * Useful for forcing fresh data retrieval from S3.
	 *
	 * Expected POST parameters:
	 * - nonce: Security nonce
	 */
	public function handle_ajax_clear_cache(): void {
		if ( ! $this->verify_ajax_request() ) {
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

}