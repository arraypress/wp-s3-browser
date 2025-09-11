<?php
/**
 * Browser Buckets Operations AJAX Handlers Trait
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

trait Buckets {

	/**
	 * Handle AJAX refresh buckets request for select fields
	 */
	public function handle_ajax_refresh_buckets(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		// Get fresh buckets (no cache)
		$result = $this->client->get_bucket_count();

		if ( $result->is_successful() ) {
			$data    = $result->get_data();
			$buckets = $data['buckets'] ?? [];

			// Update cache
			$this->cache_buckets( $buckets );

			wp_send_json_success( [
				'buckets' => $buckets,
				'count'   => count( $buckets ),
				'message' => sprintf(
					_n( 'Found %d bucket', 'Found %d buckets', count( $buckets ), 'arraypress' ),
					count( $buckets )
				)
			] );
		} else {
			wp_send_json_error( [
				'message' => $result->get_error_message()
			] );
		}
	}

	/**
	 * Get cached buckets for select fields
	 *
	 * @return array Array of bucket names
	 */
	public function get_cached_buckets(): array {
		$cache_key = $this->get_bucket_cache_key();
		$buckets   = get_transient( $cache_key );

		if ( false === $buckets ) {
			$result = $this->client->get_bucket_count();
			if ( $result->is_successful() ) {
				$data    = $result->get_data();
				$buckets = $data['buckets'] ?? [];
				$this->cache_buckets( $buckets );
			} else {
				$buckets = [];
			}
		}

		return $buckets;
	}

	/**
	 * Cache buckets list
	 *
	 * @param array $buckets Bucket names to cache
	 */
	private function cache_buckets( array $buckets ): void {
		$cache_key = $this->get_bucket_cache_key();
		set_transient( $cache_key, $buckets, HOUR_IN_SECONDS );
	}

	/**
	 * Get bucket cache key
	 *
	 * @return string
	 */
	private function get_bucket_cache_key(): string {
		$context = $this->get_context() ?? 'default';

		return 'arraypress_s3_buckets_' . $this->provider_id . '_' . md5( $context );
	}

}