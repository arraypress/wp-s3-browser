<?php
/**
 * REST Route Handlers Trait
 *
 * The single implementation of every browser operation. The legacy AJAX
 * handlers are thin adapters over these, so authorization and business logic
 * exist in exactly one place.
 *
 * @package     ArrayPress\S3\Traits\Browser\Rest
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Browser\Rest;

use ArrayPress\S3\Tables\Objects;
use ArrayPress\S3\Cors\Origin;
use ArrayPress\S3\Utils\Directory;
use ArrayPress\S3\Utils\Sanitize;
use ArrayPress\S3\Utils\Timestamp;
use ArrayPress\S3\Utils\Validate;
use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Trait Handlers
 */
trait Handlers {

	/**
	 * Build a success response
	 *
	 * @param array $data   Payload.
	 * @param int   $status HTTP status.
	 *
	 * @return WP_REST_Response
	 */
	private function rest_ok( array $data = [], int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response( $data, $status );
	}

	/**
	 * Build an error response from a failed S3 operation
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 *
	 * @return WP_Error
	 */
	private function rest_fail( string $code, string $message, int $status = 400 ): WP_Error {
		return new WP_Error( $code, $message, [ 'status' => $status ] );
	}

	/**
	 * Test the storage connection
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_connection_test() {
		// Account-level ListBuckets works for admin / master tokens.
		$result = $this->client->get_bucket_count();

		if ( $result->is_successful() ) {
			$data = $result->get_data();

			return $this->rest_ok( [
				'status'  => 'ok',
				'message' => __( 'Connection successful.', 'arraypress' ),
				'summary' => sprintf(
					_n( 'Found %d accessible bucket', 'Found %d accessible buckets', $data['count'], 'arraypress' ),
					$data['count']
				),
				'buckets' => $data['buckets'] ?? [],
				'count'   => $data['count'],
			] );
		}

		// A token scoped to specific buckets cannot list them. That is not a
		// failure — Cloudflare recommends scoping R2 tokens — so it must not be
		// reported as one. The only thing missing is the bucket name, which the
		// consumer supplies through this filter.
		$scoped          = 'bucket_listing_forbidden' === $result->get_error_code();
		$fallback_bucket = (string) apply_filters( 'arraypress_s3_connection_test_fallback_bucket', '' );

		if ( '' !== $fallback_bucket ) {
			$exists = $this->client->bucket_exists( $fallback_bucket, false );

			if ( $exists->is_successful() ) {
				return $this->rest_ok( [
					'status'  => 'ok',
					'message' => __( 'Connection successful.', 'arraypress' ),
					'summary' => sprintf(
						/* translators: %s: bucket name */
						__( 'Verified access to "%s". This token is scoped to specific buckets, so it cannot list the others.', 'arraypress' ),
						$fallback_bucket
					),
					'buckets' => [ $fallback_bucket ],
					'count'   => 1,
					'scoped'  => true,
				] );
			}

			// The name is wrong, or the token cannot reach it. Say which
			// bucket was tried — otherwise the admin has no way to tell a typo
			// from a permissions problem.
			return $this->rest_ok( [
				'status'  => 'failed',
				'message' => __( 'Could not reach that bucket.', 'arraypress' ),
				'summary' => sprintf(
					/* translators: 1: bucket name, 2: error from the provider */
					__( 'The credentials work, but "%1$s" could not be reached: %2$s', 'arraypress' ),
					$fallback_bucket,
					$exists->get_error_message()
				),
				'scoped'  => $scoped,
			] );
		}

		if ( $scoped ) {
			// Credentials are fine; the configuration is simply incomplete.
			return $this->rest_ok( [
				'status'  => 'needs_bucket',
				'message' => __( 'Credentials accepted.', 'arraypress' ),
				'summary' => __( 'This token is scoped to specific buckets, so it cannot list them — which is the recommended setup. Enter the bucket name to finish configuring and verify access.', 'arraypress' ),
				'scoped'  => true,
			] );
		}

		// Anything else really is a credential or endpoint problem, and the
		// provider's own message is more useful than a generic one.
		return $this->rest_ok( [
			'status'  => 'failed',
			'message' => __( 'Connection failed.', 'arraypress' ),
			'summary' => $result->get_error_message(),
		] );
	}

	/**
	 * Clear all cached S3 data
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_clear_cache() {
		if ( ! $this->client->clear_all_cache() ) {
			return $this->rest_fail( 'rest_cache_clear_failed', __( 'Failed to clear cache', 'arraypress' ), 500 );
		}

		return $this->rest_ok( [ 'message' => __( 'Cache cleared successfully', 'arraypress' ) ] );
	}

	/**
	 * Get details for a single bucket
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_get_bucket_details( WP_REST_Request $request ) {
		$bucket = (string) $request['bucket'];
		$result = $this->client->get_bucket_details( $bucket, Origin::current(), false );

		if ( ! $result->is_successful() ) {
			return $this->rest_fail( 'rest_bucket_details_failed', $result->get_error_message(), 502 );
		}

		$data = $result->get_data();

		// The debug payload carries raw provider error strings; it is a
		// development aid, not something to ship to every browser.
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			unset( $data['debug'] );
		}

		return $this->rest_ok( $data );
	}

	/**
	 * List a page of objects
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_list_objects( WP_REST_Request $request ) {
		$page = Objects::get_page_data(
			$this->client,
			$this->provider_id,
			(string) $request['bucket'],
			(string) $request['prefix'],
			(string) $request['continuation_token']
		);

		if ( is_wp_error( $page ) ) {
			return $page;
		}

		return $this->rest_ok( $page );
	}

	/**
	 * Delete a single object
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_delete_object( WP_REST_Request $request ) {
		$bucket = (string) $request['bucket'];
		$key    = (string) $request['key'];

		$result = $this->client->delete_object( $bucket, $key );

		if ( ! $result->is_successful() ) {
			return $this->rest_fail( 'rest_delete_failed', $result->get_error_message(), 502 );
		}

		$this->client->clear_bucket_cache( $bucket );

		return $this->rest_ok( [
			'message' => __( 'File deleted successfully', 'arraypress' ),
			'bucket'  => $bucket,
			'key'     => $key,
		] );
	}

	/**
	 * Rename an object
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_rename_object( WP_REST_Request $request ) {
		$bucket       = (string) $request['bucket'];
		$current_key  = (string) $request['current_key'];
		$new_filename = (string) $request['new_filename'];

		$validation = Validate::filename( $new_filename );
		if ( ! $validation['valid'] ) {
			return $this->rest_fail( 'rest_invalid_filename', $validation['message'] );
		}

		if ( Directory::is_rename_same_key( $current_key, $new_filename ) ) {
			return $this->rest_fail(
				'rest_rename_noop',
				__( 'The new filename is the same as the current filename', 'arraypress' )
			);
		}

		$new_key = Directory::build_rename_key( $current_key, $new_filename );

		$exists = $this->client->object_exists( $bucket, $new_key );
		if ( $exists->is_successful() && ( $exists->get_data()['exists'] ?? false ) ) {
			return $this->rest_fail(
				'rest_rename_conflict',
				sprintf( __( 'A file named "%s" already exists in this location', 'arraypress' ), $new_filename ),
				409
			);
		}

		$result = $this->client->rename_object( $bucket, $current_key, $new_key );

		if ( ! $result->is_successful() ) {
			return $this->rest_fail( 'rest_rename_failed', $result->get_error_message(), 502 );
		}

		$this->client->clear_bucket_cache( $bucket );

		return $this->rest_ok( [
			'message'      => sprintf( __( 'File renamed to "%s" successfully', 'arraypress' ), $new_filename ),
			'bucket'       => $bucket,
			'old_key'      => $current_key,
			'new_key'      => $new_key,
			'new_filename' => $new_filename,
		] );
	}

	/**
	 * Mint a presigned download URL
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_get_download_url( WP_REST_Request $request ) {
		$bucket  = (string) $request['bucket'];
		$key     = (string) $request['key'];
		$minutes = Sanitize::minutes( (int) $request['expires_minutes'] );

		$exists = $this->client->object_exists( $bucket, $key );
		if ( ! $exists->is_successful() ) {
			return $this->rest_fail( 'rest_object_check_failed', __( 'Error checking if file exists', 'arraypress' ), 502 );
		}

		if ( ! ( $exists->get_data()['exists'] ?? false ) ) {
			return $this->rest_fail( 'rest_object_not_found', __( 'File does not exist', 'arraypress' ), 404 );
		}

		$result = $this->client->get_presigned_url( $bucket, $key, $minutes );

		if ( ! $result->is_successful() ) {
			return $this->rest_fail( 'rest_presign_failed', $result->get_error_message(), 502 );
		}

		return $this->rest_ok( [
			'url'        => $result->get_url(),
			'expires_at' => Timestamp::in_minutes( $minutes ),
			'expires_in' => $minutes,
			'bucket'     => $bucket,
			'key'        => $key,
			'message'    => sprintf(
				__( 'Link generated successfully (expires in %d minutes)', 'arraypress' ),
				$minutes
			),
		] );
	}

	/**
	 * Mint a presigned upload URL
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_get_upload_url( WP_REST_Request $request ) {
		$bucket = (string) $request['bucket'];
		$key    = (string) $request['key'];

		$result = $this->client->get_presigned_upload_url( $bucket, $key );

		if ( ! $result->is_successful() ) {
			return $this->rest_fail( 'rest_presign_upload_failed', $result->get_error_message(), 502 );
		}

		$this->client->clear_bucket_cache( $bucket );

		return $this->rest_ok( [
			'url'     => $result->get_url(),
			'expires' => Timestamp::in_minutes( 15 ),
		] );
	}

	/**
	 * Create a folder
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_create_folder( WP_REST_Request $request ) {
		$bucket      = (string) $request['bucket'];
		$prefix      = (string) $request['prefix'];
		$folder_name = (string) $request['folder_name'];

		$validation = Validate::folder_name( $folder_name );
		if ( ! $validation['valid'] ) {
			return $this->rest_fail( 'rest_invalid_folder_name', $validation['message'] );
		}

		$folder_key = Directory::build_folder_key( $prefix, $folder_name );
		$result     = $this->client->create_folder( $bucket, $folder_key );

		if ( ! $result->is_successful() ) {
			return $this->rest_fail( 'rest_create_folder_failed', $result->get_error_message(), 502 );
		}

		$this->client->clear_bucket_cache( $bucket );

		return $this->rest_ok( [
			'message'    => sprintf( __( 'Folder "%s" created successfully', 'arraypress' ), $folder_name ),
			'folder_key' => $folder_key,
			'bucket'     => $bucket,
			'prefix'     => $prefix,
		], 201 );
	}

	/**
	 * Delete a folder and everything under it
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_delete_folder( WP_REST_Request $request ) {
		$bucket      = (string) $request['bucket'];
		$folder_path = (string) $request['folder_path'];

		// Refuse to recursively delete the bucket root. After normalization a
		// folder_path of '' or '/' resolves to depth 0, which would wipe the
		// entire bucket, so require at least one path segment.
		if ( Directory::depth( $folder_path ) < 1 ) {
			return $this->rest_fail(
				'rest_refuse_bucket_root',
				__( 'Refusing to delete bucket root. Specify a folder.', 'arraypress' )
			);
		}

		$result = $this->delete_folder_with_fallback( $bucket, $folder_path );

		if ( ! $result->is_successful() ) {
			return $this->rest_fail( 'rest_delete_folder_failed', $result->get_error_message(), 502 );
		}

		$this->client->clear_bucket_cache( $bucket );

		$data = $result->get_data();

		return $this->rest_ok( [
			'message'     => $this->format_folder_deletion_message( $data ),
			'bucket'      => $bucket,
			'folder_path' => $data['folder_path'] ?? Directory::normalize( $folder_path ),
			'data'        => $data,
		] );
	}

	/**
	 * Configure CORS for browser uploads
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_setup_cors( WP_REST_Request $request ) {
		$bucket = (string) $request['bucket'];
		$origin = (string) $request['origin'] ?: Origin::current();

		if ( '' === $origin ) {
			return $this->rest_fail( 'rest_origin_required', __( 'Origin is required for CORS setup', 'arraypress' ) );
		}

		$this->client->clear_bucket_cache( $bucket );

		$result = $this->client->set_cors_scenario( $bucket, 'upload_only', [ $origin ] );

		if ( ! $result->is_successful() ) {
			// AccessDenied on PutBucketCors is not a transient failure and no
			// amount of retrying fixes it: R2 API tokens carry a permission
			// level, and a token with Object Read & Write can upload objects
			// while being refused any change to bucket configuration. That is
			// the recommended way to scope a token, so it is a normal state
			// rather than a misconfiguration — but it does mean CORS has to be
			// set once in the provider's console. Saying "setup failed" sends
			// people to retry something that cannot succeed.
			$denied = in_array(
				$result->get_error_code(),
				[ 'AccessDenied', 'access_denied' ],
				true
			) || 403 === $result->get_status_code();

			return $this->rest_fail(
				$denied ? 'rest_cors_permission_denied' : 'rest_cors_setup_failed',
				$denied
					? __( 'This API token can read and write objects but is not permitted to change bucket settings, so CORS has to be configured once in your provider\'s console. The rule to add is below.', 'arraypress' )
					: $result->get_error_message(),
				502
			);
		}

		$this->client->clear_bucket_cache( $bucket );

		$verification = $this->client->cors_allows_upload( $bucket, $origin, false );
		$verified     = $verification->is_successful()
			&& ( $verification->get_data()['allows_upload'] ?? false );

		return $this->rest_ok( [
			'bucket'              => $bucket,
			'origin'              => $origin,
			'verification_passed' => $verified,
			'message'             => $verified
				? __( 'CORS configured successfully for uploads', 'arraypress' )
				: __( 'CORS was saved but is not active yet — it can take a moment to propagate.', 'arraypress' ),
		] );
	}

	/**
	 * Remove the bucket's CORS configuration
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_delete_cors( WP_REST_Request $request ) {
		$bucket = (string) $request['bucket'];
		$result = $this->client->delete_cors_configuration( $bucket );

		if ( ! $result->is_successful() ) {
			return $this->rest_fail( 'rest_cors_delete_failed', $result->get_error_message(), 502 );
		}

		$this->client->clear_bucket_cache( $bucket );

		return $this->rest_ok( [
			'bucket'  => $bucket,
			'message' => __( 'CORS configuration removed', 'arraypress' ),
		] );
	}

	/**
	 * Delete a folder, falling back to per-object deletion
	 *
	 * Batch deletion is more efficient for large folders, but not every
	 * S3-compatible provider implements it, and it can time out. Fall back only
	 * on error codes that indicate the batch path itself was the problem.
	 *
	 * @param string $bucket      Bucket name.
	 * @param string $folder_path Raw folder path (normalized downstream).
	 *
	 * @return \ArrayPress\S3\Interfaces\Response
	 */
	private function delete_folder_with_fallback( string $bucket, string $folder_path ) {
		$result = $this->client->delete_folder_batch( $bucket, $folder_path );

		if ( ! $result->is_successful() ) {
			$recoverable = [ 'batch_delete_timeout', 'batch_delete_not_supported', 'network_error' ];

			if ( in_array( $result->get_error_code(), $recoverable, true ) ) {
				$result = $this->client->delete_folder( $bucket, $folder_path, true, true );
			}
		}

		return $result;
	}

	/**
	 * Format the folder deletion success message
	 *
	 * @param array $data Deletion result data.
	 *
	 * @return string
	 */
	private function format_folder_deletion_message( array $data ): string {
		if ( ! empty( $data['deleted_count'] ) ) {
			return sprintf( __( 'Folder deleted successfully (%d items removed)', 'arraypress' ), $data['deleted_count'] );
		}

		if ( ! empty( $data['success_count'] ) ) {
			return sprintf( __( 'Folder deleted successfully (%d objects removed)', 'arraypress' ), $data['success_count'] );
		}

		return __( 'Folder deleted successfully', 'arraypress' );
	}

}
