<?php
/**
 * Client Permissions Operations Trait
 *
 * Handles permission checking for the S3 Client.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Client;

use ArrayPress\S3\Responses\SuccessResponse;
use Exception;

/**
 * Trait Permissions
 */
trait Permissions {

	/**
	 * Cached permissions results
	 *
	 * @var array
	 */
	private array $cached_permissions = [];

	/**
	 * Check permissions of the current access key
	 *
	 * @param string $bucket     Test bucket name to use (must exist)
	 * @param bool   $use_cache  Whether to use cached results
	 * @param bool   $force_test Force a new test even if cached results exist
	 *
	 * @return array Permission details with 'read', 'write', 'delete' flags
	 */
	public function check_key_permissions( string $bucket, bool $use_cache = true, bool $force_test = false ): array {
		// Generate a cache key based on provider, bucket, and context
		$cache_key = $this->get_permissions_cache_key( $bucket );

		// Return cached results if available and not forcing a new test
		if ( $use_cache && ! $force_test && isset( $this->cached_permissions[ $cache_key ] ) ) {
			return $this->cached_permissions[ $cache_key ];
		}

		$permissions = [
			'read'      => false,
			'write'     => false,
			'delete'    => false,
			'errors'    => [],
			'tested_at' => current_time( 'timestamp' ),
			'bucket'    => $bucket,
			'context'   => $this->get_context()
		];

		// Allow filtering the initial permissions structure
		$permissions = $this->apply_contextual_filters(
			'arraypress_s3_check_permissions_init',
			$permissions,
			$bucket
		);

		// 1. Test READ permission
		$permissions = $this->test_read_permission( $bucket, $permissions );

		// 2. Test WRITE permission (only if read works)
		if ( $permissions['read'] ) {
			$permissions = $this->test_write_permission( $bucket, $permissions );

			// 3. Test DELETE permission (only if write works)
			if ( $permissions['write'] ) {
				$permissions = $this->test_delete_permission( $bucket, $permissions );
			}
		}

		// Allow filtering the final permissions result
		$permissions = $this->apply_contextual_filters(
			'arraypress_s3_check_permissions_final',
			$permissions,
			$bucket
		);

		// Cache the results
		if ( $use_cache ) {
			$this->cached_permissions[ $cache_key ] = $permissions;
		}

		return $permissions;
	}

	/**
	 * Check if the current key can read from the bucket
	 *
	 * @param string $bucket    Bucket name
	 * @param bool   $use_cache Whether to use cached results
	 *
	 * @return bool
	 */
	public function can_read( string $bucket, bool $use_cache = true ): bool {
		$permissions = $this->check_key_permissions( $bucket, $use_cache );

		return $permissions['read'] ?? false;
	}

	/**
	 * Check if the current key can write to the bucket
	 *
	 * @param string $bucket    Bucket name
	 * @param bool   $use_cache Whether to use cached results
	 *
	 * @return bool
	 */
	public function can_write( string $bucket, bool $use_cache = true ): bool {
		$permissions = $this->check_key_permissions( $bucket, $use_cache );

		return $permissions['write'] ?? false;
	}

	/**
	 * Check if the current key can upload to the bucket (alias for can_write)
	 *
	 * @param string $bucket    Bucket name
	 * @param bool   $use_cache Whether to use cached results
	 *
	 * @return bool
	 */
	public function can_upload( string $bucket, bool $use_cache = true ): bool {
		return $this->can_write( $bucket, $use_cache );
	}

	/**
	 * Check if the current key can delete from the bucket
	 *
	 * @param string $bucket    Bucket name
	 * @param bool   $use_cache Whether to use cached results
	 *
	 * @return bool
	 */
	public function can_delete( string $bucket, bool $use_cache = true ): bool {
		$permissions = $this->check_key_permissions( $bucket, $use_cache );

		return $permissions['delete'] ?? false;
	}

	/**
	 * Check if the current key has full access (read, write, delete)
	 *
	 * @param string $bucket    Bucket name
	 * @param bool   $use_cache Whether to use cached results
	 *
	 * @return bool
	 */
	public function has_full_access( string $bucket, bool $use_cache = true ): bool {
		$permissions = $this->check_key_permissions( $bucket, $use_cache );

		return ( $permissions['read'] ?? false ) &&
		       ( $permissions['write'] ?? false ) &&
		       ( $permissions['delete'] ?? false );
	}

	/**
	 * Clear cached permissions for a specific bucket or all buckets
	 *
	 * @param string|null $bucket Specific bucket or null for all
	 *
	 * @return void
	 */
	public function clear_permissions_cache( ?string $bucket = null ): void {
		if ( $bucket === null ) {
			$this->cached_permissions = [];
		} else {
			$cache_key = $this->get_permissions_cache_key( $bucket );
			unset( $this->cached_permissions[ $cache_key ] );
		}
	}

	/**
	 * Test read permission
	 *
	 * @param string $bucket      Bucket name
	 * @param array  $permissions Current permissions array
	 *
	 * @return array Updated permissions array
	 */
	private function test_read_permission( string $bucket, array $permissions ): array {
		try {
			$list_result         = $this->get_objects( $bucket, 1 );
			$permissions['read'] = $list_result->is_successful();
		} catch ( Exception $e ) {
			$permissions['errors']['read'] = $e->getMessage();
		}

		return $permissions;
	}

	/**
	 * Test write permission
	 *
	 * @param string $bucket      Bucket name
	 * @param array  $permissions Current permissions array
	 *
	 * @return array Updated permissions array
	 */
	private function test_write_permission( string $bucket, array $permissions ): array {
		$test_key     = $this->get_test_file_key();
		$test_content = $this->get_test_file_content();

		try {
			$upload_url_response = $this->get_presigned_upload_url( $bucket, $test_key, 1 );

			if ( $upload_url_response->is_successful() ) {
				$upload_url = $upload_url_response->get_url();

				// Build headers with user agent using signer's method
				$headers = $this->signer->get_base_request_headers( [
					'Content-Type' => 'text/plain'
				] );

				$response = wp_remote_request( $upload_url, [
					'method'  => 'PUT',
					'body'    => $test_content,
					'headers' => $headers
				] );

				$permissions['write'] = ! is_wp_error( $response ) &&
				                        wp_remote_retrieve_response_code( $response ) >= 200 &&
				                        wp_remote_retrieve_response_code( $response ) < 300;

				// Store test key for deletion test
				$permissions['_test_key'] = $test_key;
			}
		} catch ( Exception $e ) {
			$permissions['errors']['write'] = $e->getMessage();
		}

		return $permissions;
	}

	/**
	 * Test delete permission
	 *
	 * @param string $bucket      Bucket name
	 * @param array  $permissions Current permissions array
	 *
	 * @return array Updated permissions array
	 */
	private function test_delete_permission( string $bucket, array $permissions ): array {
		$test_key = $permissions['_test_key'] ?? null;

		if ( ! $test_key ) {
			return $permissions;
		}

		try {
			$delete_result         = $this->delete_object( $bucket, $test_key );
			$permissions['delete'] = ( $delete_result instanceof SuccessResponse );
		} catch ( Exception $e ) {
			$permissions['errors']['delete'] = $e->getMessage();

			// If we can't delete, leave a cleanup note
			if ( $permissions['write'] ) {
				$cleanup_note = $this->get_cleanup_note_content( $test_key );
				$this->upload_string_to_bucket( $bucket, $test_key . '.note', $cleanup_note );
			}
		}

		// Remove the internal test key from the final result
		unset( $permissions['_test_key'] );

		return $permissions;
	}

	/**
	 * Generate permissions cache key
	 *
	 * @param string $bucket Bucket name
	 *
	 * @return string Cache key
	 */
	private function get_permissions_cache_key( string $bucket ): string {
		$parts = [
			$this->get_provider()->get_id(),
			$this->get_provider()->get_region(),
			$bucket,
			$this->get_context() ?? 'default'
		];

		return md5( implode( '|', $parts ) );
	}

	/**
	 * Generate test file key
	 *
	 * @return string Test file key
	 */
	private function get_test_file_key(): string {
		$prefix = $this->has_context() ? $this->get_context() . '-' : '';

		return $prefix . 'permissions-test-' . wp_generate_password( 16, false ) . '.txt';
	}

	/**
	 * Get test file content
	 *
	 * @return string Test file content
	 */
	private function get_test_file_content(): string {
		$context_info = $this->has_context()
			? sprintf( ' (Context: %s)', $this->get_context() )
			: '';

		return sprintf(
			'S3 permissions test file%s. Safe to delete. Created: %s',
			$context_info,
			current_time( 'mysql' )
		);
	}

	/**
	 * Get cleanup note content
	 *
	 * @param string $test_key The test file key that couldn't be deleted
	 *
	 * @return string Cleanup note content
	 */
	private function get_cleanup_note_content( string $test_key ): string {
		$context_info = $this->has_context()
			? sprintf( ' (Context: %s)', $this->get_context() )
			: '';

		return sprintf(
			"Failed to delete test file%s. Please manually delete '%s' and this note file. Created: %s",
			$context_info,
			$test_key,
			current_time( 'mysql' )
		);
	}

	/**
	 * Helper method to upload a string to S3
	 *
	 * @param string $bucket  Bucket name
	 * @param string $key     Object key
	 * @param string $content String content to upload
	 *
	 * @return bool Success flag
	 */
	private function upload_string_to_bucket( string $bucket, string $key, string $content ): bool {
		$upload_url_response = $this->get_presigned_upload_url( $bucket, $key, 1 );

		if ( ! $upload_url_response->is_successful() ) {
			return false;
		}

		$upload_url = $upload_url_response->get_url();

		// Build headers with user agent using signer's method
		$headers = $this->signer->get_base_request_headers( [
			'Content-Type' => 'text/plain'
		] );

		$response = wp_remote_request( $upload_url, [
			'method'  => 'PUT',
			'body'    => $content,
			'headers' => $headers
		] );

		return ! is_wp_error( $response ) &&
		       wp_remote_retrieve_response_code( $response ) >= 200 &&
		       wp_remote_retrieve_response_code( $response ) < 300;
	}

}