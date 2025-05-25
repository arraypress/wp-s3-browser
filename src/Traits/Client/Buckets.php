<?php
/**
 * Client Bucket Operations Trait
 *
 * Handles bucket-related operations for the S3 Client.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Client;

use ArrayPress\S3\Interfaces\Response as ResponseInterface;
use ArrayPress\S3\Responses\BucketsResponse;
use ArrayPress\S3\Responses\SuccessResponse;
use ArrayPress\S3\Responses\ErrorResponse;
use WP_Error;

/**
 * Trait Buckets
 */
trait Buckets {

	/**
	 * Get buckets list
	 *
	 * @param int    $max_keys  Maximum number of buckets to return
	 * @param string $prefix    Prefix to filter buckets
	 * @param string $marker    Marker for pagination
	 * @param bool   $use_cache Whether to use cache
	 *
	 * @return ResponseInterface|WP_Error Response or error
	 */
	public function get_buckets(
		int $max_keys = 1000,
		string $prefix = '',
		string $marker = '',
		bool $use_cache = true
	) {
		// Check cache if enabled
		if ( $use_cache && $this->is_cache_enabled() ) {
			$cache_key = $this->get_cache_key( 'buckets', [
				'max_keys' => $max_keys,
				'prefix'   => $prefix,
				'marker'   => $marker
			] );

			$cached = $this->get_from_cache( $cache_key );
			if ( $cached !== false ) {
				return $cached;
			}
		}

		// Use signer to list buckets
		$result = $this->signer->list_buckets( $max_keys, $prefix, $marker );

		// Handle errors
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Debug logging if enabled
		if ( $this->debug ) {
			$this->log_debug( 'Client: Raw result from signer:', $result );
		}

		// Cache the result if successful
		if ( $use_cache && $this->is_cache_enabled() && $result->is_successful() ) {
			$this->save_to_cache( $cache_key, $result );
		}

		return $result;
	}

	/**
	 * Get buckets as models
	 *
	 * @param int    $max_keys  Maximum number of buckets to return
	 * @param string $prefix    Prefix to filter buckets
	 * @param string $marker    Marker for pagination
	 * @param bool   $use_cache Whether to use cache
	 *
	 * @return array|WP_Error Array of bucket models or WP_Error
	 */
	public function get_bucket_models(
		int $max_keys = 1000,
		string $prefix = '',
		string $marker = '',
		bool $use_cache = true
	) {
		// Get buckets response
		$response = $this->get_buckets(
			$max_keys,
			$prefix,
			$marker,
			$use_cache
		);

		// Handle errors
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! ( $response instanceof BucketsResponse ) ) {
			return new WP_Error(
				'invalid_response',
				'Expected BucketsResponse but got ' . get_class( $response )
			);
		}

		// Return result using response object's transformation method
		return [
			'buckets'         => $response->to_bucket_models(),
			'truncated'       => $response->is_truncated(),
			'next_marker'     => $response->get_next_marker(),
			'owner'           => $response->get_owner(),
			'response_object' => $response
		];
	}

	/**
	 * Check if multiple buckets exist
	 *
	 * @param array $buckets   Array of bucket names to check
	 * @param bool  $use_cache Whether to use cache
	 *
	 * @return ResponseInterface Response with existence info for all buckets
	 */
	public function buckets_exist( array $buckets, bool $use_cache = true ): ResponseInterface {
		if ( empty( $buckets ) ) {
			return new ErrorResponse(
				__( 'At least one bucket name is required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		$results    = [];
		$all_exist  = true;
		$none_exist = true;
		$errors     = [];

		foreach ( $buckets as $bucket ) {
			if ( ! is_string( $bucket ) || empty( $bucket ) ) {
				$errors[] = sprintf( __( 'Invalid bucket name: %s', 'arraypress' ), $bucket );
				continue;
			}

			$check_result = $this->bucket_exists( $bucket, $use_cache );

			if ( is_wp_error( $check_result ) ) {
				$errors[]           = sprintf(
					__( 'Error checking bucket "%s": %s', 'arraypress' ),
					$bucket,
					$check_result->get_error_message()
				);
				$results[ $bucket ] = [
					'exists' => null,
					'error'  => $check_result->get_error_message()
				];
				$all_exist          = false;
			} else {
				$data   = $check_result->get_data();
				$exists = $data['exists'] ?? false;

				$results[ $bucket ] = [
					'exists' => $exists,
					'error'  => null
				];

				if ( $exists ) {
					$none_exist = false;
				} else {
					$all_exist = false;
				}
			}
		}

		// Determine overall status
		$status_code = 200;
		if ( $all_exist && empty( $errors ) ) {
			$message = __( 'All buckets exist', 'arraypress' );
		} elseif ( $none_exist && empty( $errors ) ) {
			$message     = __( 'None of the buckets exist', 'arraypress' );
			$status_code = 404;
		} else {
			$message     = __( 'Mixed results for bucket existence', 'arraypress' );
			$status_code = 207; // Multi-Status
		}

		return new SuccessResponse(
			$message,
			$status_code,
			[
				'buckets' => $results,
				'summary' => [
					'total_checked' => count( $buckets ),
					'all_exist'     => $all_exist,
					'none_exist'    => $none_exist,
					'error_count'   => count( $errors )
				],
				'errors'  => $errors
			]
		);
	}

	/**
	 * Check if a bucket exists
	 *
	 * @param string $bucket    Bucket name to check
	 * @param bool   $use_cache Whether to use cache
	 *
	 * @return ResponseInterface Response with existence info or error
	 */
	public function bucket_exists( string $bucket, bool $use_cache = true ): ResponseInterface {
		if ( empty( $bucket ) ) {
			return new ErrorResponse(
				__( 'Bucket name is required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		// Check cache if enabled
		if ( $use_cache && $this->is_cache_enabled() ) {
			$cache_key = $this->get_cache_key( 'bucket_exists', [ 'bucket' => $bucket ] );
			$cached    = $this->get_from_cache( $cache_key );
			if ( $cached !== false ) {
				return $cached;
			}
		}

		// Try to list objects in the bucket with limit 1 - this is more reliable than HEAD bucket
		// because some S3 providers don't support HEAD bucket properly
		$result = $this->get_objects( $bucket, 1, '', '', '', false );

		// If we get a successful response, bucket exists
		if ( ! is_wp_error( $result ) && $result->is_successful() ) {
			$response = new SuccessResponse(
				sprintf( __( 'Bucket "%s" exists', 'arraypress' ), $bucket ),
				200,
				[
					'bucket' => $bucket,
					'exists' => true,
					'method' => 'list_objects'
				]
			);

			// Cache the positive result
			if ( $use_cache && $this->is_cache_enabled() ) {
				$this->save_to_cache( $cache_key, $response );
			}

			return $response;
		}

		// If we get a WP_Error, check the error type
		if ( is_wp_error( $result ) ) {
			$error_code    = $result->get_error_code();
			$error_message = $result->get_error_message();

			// Common error codes that indicate bucket don't exist
			$not_found_codes = [ 'NoSuchBucket', 'bucket_not_found', 'not_found' ];

			if ( in_array( $error_code, $not_found_codes, true ) ||
			     strpos( $error_message, 'does not exist' ) !== false ||
			     strpos( $error_message, 'not found' ) !== false ) {

				$response = new SuccessResponse(
					sprintf( __( 'Bucket "%s" does not exist', 'arraypress' ), $bucket ),
					404,
					[
						'bucket'     => $bucket,
						'exists'     => false,
						'error_code' => $error_code,
						'method'     => 'list_objects'
					]
				);

				// Cache the negative result (with shorter TTL)
				if ( $use_cache && $this->is_cache_enabled() ) {
					$this->save_to_cache( $cache_key, $response );
				}

				return $response;
			}

			// For other errors (like access denied), we can't determine existence
			return new ErrorResponse(
				sprintf( __( 'Unable to determine if bucket "%s" exists: %s', 'arraypress' ), $bucket, $error_message ),
				'bucket_check_failed',
				400,
				[
					'bucket'           => $bucket,
					'original_error'   => $error_code,
					'original_message' => $error_message
				]
			);
		}

		// If result is not successful but not a WP_Error
		if ( method_exists( $result, 'get_error_code' ) ) {
			$error_code    = $result->get_error_code();
			$error_message = $result->get_error_message();

			// Check for bucket not found errors
			if ( in_array( $error_code, [ 'NoSuchBucket', 'bucket_not_found' ], true ) ) {
				$response = new SuccessResponse(
					sprintf( __( 'Bucket "%s" does not exist', 'arraypress' ), $bucket ),
					404,
					[
						'bucket'     => $bucket,
						'exists'     => false,
						'error_code' => $error_code,
						'method'     => 'list_objects'
					]
				);

				if ( $use_cache && $this->is_cache_enabled() ) {
					$this->save_to_cache( $cache_key, $response );
				}

				return $response;
			}
		}

		// Fallback - unable to determine
		return new ErrorResponse(
			sprintf( __( 'Unable to determine if bucket "%s" exists', 'arraypress' ), $bucket ),
			'bucket_check_failed',
			400,
			[ 'bucket' => $bucket ]
		);
	}

}