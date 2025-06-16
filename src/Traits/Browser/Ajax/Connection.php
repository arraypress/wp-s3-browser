<?php
/**
 * Browser Connection Test AJAX Handler Trait
 *
 * Handles AJAX operations for connection testing in the S3 Browser.
 *
 * @package     ArrayPress\S3\Traits\Browser\Ajax
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Browser\Ajax;

use Exception;

/**
 * Trait ConnectionTest
 *
 * Provides AJAX endpoint handler for connection testing functionality.
 */
trait Connection {

	/**
	 * Handle AJAX connection test request
	 *
	 * Tests the S3 connection by attempting to list buckets and returns
	 * information about accessible buckets and connection status.
	 *
	 * Expected POST parameters:
	 * - nonce: Security nonce
	 *
	 * Returns:
	 * - message: Success/failure message
	 * - summary: Bucket count summary (on success)
	 * - buckets: Array of accessible bucket names (on success)
	 * - details: Error details (on failure)
	 */
	public function handle_ajax_connection_test(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		// Test connection
		try {
			$result = $this->client->get_bucket_count();

			if ( $result->is_successful() ) {
				$data = $result->get_data();

				$this->send_success_response(
					__( 'Connection successful!', 'arraypress' ),
					[
						'summary' => sprintf(
							_n( 'Found %d accessible bucket', 'Found %d accessible buckets', $data['count'], 'arraypress' ),
							$data['count']
						),
						'buckets' => $data['buckets'] ?? [],
						'count' => $data['count']
					]
				);
			} else {
				$this->send_error_response(
					__( 'Connection failed', 'arraypress' ),
					[ 'details' => $result->get_error_message() ]
				);
			}
		} catch ( Exception $e ) {
			$this->send_error_response(
				__( 'Connection test failed', 'arraypress' ),
				[ 'details' => $e->getMessage() ]
			);
		}
	}

}