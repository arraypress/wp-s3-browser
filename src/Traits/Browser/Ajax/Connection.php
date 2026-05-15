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

		try {
			// Try the account-level ListBuckets path first — works for
			// admin / master tokens.
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
						'count'   => $data['count'],
					]
				);

				return;
			}

			// ListBuckets failed. Cloudflare R2 best practice is to scope API
			// tokens to a single bucket — those tokens can read/write within
			// their scope but cannot list account buckets (403 AccessDenied).
			// Fall back to a HeadBucket against a consumer-supplied bucket
			// name.
			$fallback_bucket = (string) apply_filters( 'arraypress_s3_connection_test_fallback_bucket', '' );

			if ( '' !== $fallback_bucket ) {
				$exists = $this->client->bucket_exists( $fallback_bucket, false );

				if ( $exists->is_successful() ) {
					$this->send_success_response(
						__( 'Connection successful (bucket-scoped token).', 'arraypress' ),
						[
							'summary' => sprintf(
								__( 'Token has access to "%s".', 'arraypress' ),
								$fallback_bucket
							),
							'buckets' => [ $fallback_bucket ],
							'count'   => 1,
							'scoped'  => true,
						]
					);

					return;
				}

				// Both calls failed — surface both errors.
				$this->send_error_response(
					__( 'Connection failed', 'arraypress' ),
					[
						'details' => sprintf(
							/* translators: 1: ListBuckets error, 2: bucket name, 3: HeadBucket error */
							__( 'ListBuckets: %1$s. HeadBucket on "%2$s": %3$s', 'arraypress' ),
							$result->get_error_message(),
							$fallback_bucket,
							$exists->get_error_message()
						),
					]
				);

				return;
			}

			// No fallback bucket configured — return the original ListBuckets
			// error with a hint about scoped tokens.
			$this->send_error_response(
				__( 'Connection failed', 'arraypress' ),
				[
					'details' => sprintf(
						/* translators: %s: ListBuckets error message */
						__( '%s — if your API token is scoped to a specific bucket, supply a fallback bucket name via the arraypress_s3_connection_test_fallback_bucket filter and re-test.', 'arraypress' ),
						$result->get_error_message()
					),
				]
			);
		} catch ( Exception $e ) {
			$this->send_error_response(
				__( 'Connection test failed', 'arraypress' ),
				[ 'details' => $e->getMessage() ]
			);
		}
	}

}