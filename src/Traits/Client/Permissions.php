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
	 * Check permissions of the current access key
	 *
	 * @param string $bucket Test bucket name to use (must exist)
	 *
	 * @return array Permission details with 'read', 'write', 'delete' flags
	 */
	public function check_key_permissions( string $bucket ): array {
		$permissions = [
			'read'   => false,
			'write'  => false,
			'delete' => false,
			'errors' => []
		];

		// 1. Test READ permission with a list operation
		try {
			$list_result         = $this->get_objects( $bucket, 1 );
			$permissions['read'] = $list_result->is_successful();
		} catch ( Exception $e ) {
			$permissions['errors']['read'] = $e->getMessage();
		}

		// 2. Test WRITE permission with a temporary file
		if ( $permissions['read'] ) {
			$test_key     = 'permissions-test-' . wp_generate_password( 16, false ) . '.txt';
			$test_content = 'This is a test file to check permissions. It can be safely deleted.';

			try {
				// Get a presigned upload URL
				$upload_url_response = $this->get_presigned_upload_url( $bucket, $test_key, 1 );

				if ( $upload_url_response->is_successful() ) {
					$upload_url = $upload_url_response->get_url();

					// Try to upload a small file
					$response = wp_remote_request( $upload_url, [
						'method'  => 'PUT',
						'body'    => $test_content,
						'headers' => [
							'Content-Type' => 'text/plain'
						]
					] );

					$permissions['write'] = ! is_wp_error( $response ) &&
					                        wp_remote_retrieve_response_code( $response ) >= 200 &&
					                        wp_remote_retrieve_response_code( $response ) < 300;

					// 3. Test DELETE permission by trying to delete our test file
					if ( $permissions['write'] ) {
						try {
							$delete_result         = $this->delete_object( $bucket, $test_key );
							$permissions['delete'] = ( $delete_result instanceof SuccessResponse );
						} catch ( Exception $e ) {
							$permissions['errors']['delete'] = $e->getMessage();

							// If we can't delete, leave a note in the test file
							if ( $permissions['write'] ) {
								$this->upload_string_to_bucket(
									$bucket,
									$test_key . '.note',
									"Failed to delete test file. Please delete this and {$test_key} manually."
								);
							}
						}
					}
				}
			} catch ( Exception $e ) {
				$permissions['errors']['write'] = $e->getMessage();
			}
		}

		return $permissions;
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

		$response = wp_remote_request( $upload_url, [
			'method'  => 'PUT',
			'body'    => $content,
			'headers' => [
				'Content-Type' => 'text/plain'
			]
		] );

		return ! is_wp_error( $response ) &&
		       wp_remote_retrieve_response_code( $response ) >= 200 &&
		       wp_remote_retrieve_response_code( $response ) < 300;
	}

}