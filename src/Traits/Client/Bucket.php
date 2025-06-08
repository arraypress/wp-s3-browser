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
use ArrayPress\S3\utils\Cors;
use Exception;

/**
 * Trait Bucket
 */
trait Bucket {

	/**
	 * Check if a bucket exists
	 *
	 * @param string $bucket    Bucket name to check
	 * @param bool   $use_cache Whether to use cache
	 *
	 * @return ResponseInterface Response with existence info
	 */
	public function bucket_exists( string $bucket, bool $use_cache = true ): ResponseInterface {
		// Apply contextual filter to modify request parameters
		$params = $this->apply_contextual_filters(
			'arraypress_s3_bucket_exists_params',
			[
				'bucket'    => $bucket,
				'use_cache' => $use_cache
			],
			$bucket
		);

		$bucket    = $params['bucket'];
		$use_cache = $params['use_cache'];

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

		// Try to list objects in the bucket with limit 1
		$result = $this->get_objects( $bucket, 1, '', '', '', false );

		// If we get a successful response, bucket exists
		if ( $result->is_successful() ) {
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

			// Apply contextual filter to final response
			return $this->apply_contextual_filters(
				'arraypress_s3_bucket_exists_response',
				$response,
				$bucket
			);
		}

		// Check error response
		if ( $result instanceof ErrorResponse ) {
			$error_code    = $result->get_error_code();
			$error_message = $result->get_error_message();

			// Common error codes that indicate bucket doesn't exist
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

				// Cache the negative result
				if ( $use_cache && $this->is_cache_enabled() ) {
					$this->save_to_cache( $cache_key, $response );
				}

				// Apply contextual filter to final response
				return $this->apply_contextual_filters(
					'arraypress_s3_bucket_exists_response',
					$response,
					$bucket
				);
			}

			// For other errors, we can't determine existence
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

		// Fallback - unable to determine
		return new ErrorResponse(
			sprintf( __( 'Unable to determine if bucket "%s" exists', 'arraypress' ), $bucket ),
			'bucket_check_failed',
			400,
			[ 'bucket' => $bucket ]
		);
	}

	/**
	 * Get bucket location
	 *
	 * @param string $bucket    Bucket name
	 * @param bool   $use_cache Whether to use cache
	 *
	 * @return ResponseInterface Response with location information
	 */
	public function get_bucket_location( string $bucket, bool $use_cache = true ): ResponseInterface {
		// Apply contextual filter to modify request parameters
		$params = $this->apply_contextual_filters(
			'arraypress_s3_get_bucket_location_params',
			[
				'bucket'    => $bucket,
				'use_cache' => $use_cache
			],
			$bucket
		);

		$bucket    = $params['bucket'];
		$use_cache = $params['use_cache'];

		if ( empty( $bucket ) ) {
			return new ErrorResponse(
				__( 'Bucket name is required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		// Check cache if enabled
		if ( $use_cache && $this->is_cache_enabled() ) {
			$cache_key = $this->get_cache_key( 'bucket_location', [ 'bucket' => $bucket ] );
			$cached    = $this->get_from_cache( $cache_key );
			if ( $cached !== false ) {
				return $cached;
			}
		}

		// Use signer to get bucket location
		$result = $this->signer->get_bucket_location( $bucket );

		// Debug logging if enabled
		$this->debug( 'Client: Raw result from signer for bucket location:', $result );

		// Cache the result if successful
		if ( $use_cache && $this->is_cache_enabled() && $result->is_successful() ) {
			$this->save_to_cache( $cache_key, $result );
		}

		// Apply contextual filter to final response
		return $this->apply_contextual_filters(
			'arraypress_s3_get_bucket_location_response',
			$result,
			$bucket
		);
	}

	/**
	 * Get bucket versioning configuration
	 *
	 * @param string $bucket    Bucket name
	 * @param bool   $use_cache Whether to use cache
	 *
	 * @return ResponseInterface Response with versioning information
	 */
	public function get_bucket_versioning( string $bucket, bool $use_cache = true ): ResponseInterface {
		// Apply contextual filter to modify request parameters
		$params = $this->apply_contextual_filters(
			'arraypress_s3_get_bucket_versioning_params',
			[
				'bucket'    => $bucket,
				'use_cache' => $use_cache
			],
			$bucket
		);

		$bucket    = $params['bucket'];
		$use_cache = $params['use_cache'];

		if ( empty( $bucket ) ) {
			return new ErrorResponse(
				__( 'Bucket name is required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		// Check cache if enabled
		if ( $use_cache && $this->is_cache_enabled() ) {
			$cache_key = $this->get_cache_key( 'bucket_versioning', [ 'bucket' => $bucket ] );
			$cached    = $this->get_from_cache( $cache_key );
			if ( $cached !== false ) {
				return $cached;
			}
		}

		// Use signer to get bucket versioning
		$result = $this->signer->get_bucket_versioning( $bucket );

		// Debug logging if enabled
		$this->debug( 'Client: Raw result from signer for bucket versioning:', $result );

		// Cache the result if successful
		if ( $use_cache && $this->is_cache_enabled() && $result->is_successful() ) {
			$this->save_to_cache( $cache_key, $result );
		}

		// Apply contextual filter to final response
		return $this->apply_contextual_filters(
			'arraypress_s3_get_bucket_versioning_response',
			$result,
			$bucket
		);
	}

	/**
	 * Get bucket policy
	 *
	 * @param string $bucket    Bucket name
	 * @param bool   $use_cache Whether to use cache
	 *
	 * @return ResponseInterface Response with policy information
	 */
	public function get_bucket_policy( string $bucket, bool $use_cache = true ): ResponseInterface {
		// Apply contextual filter to modify request parameters
		$params = $this->apply_contextual_filters(
			'arraypress_s3_get_bucket_policy_params',
			[
				'bucket'    => $bucket,
				'use_cache' => $use_cache
			],
			$bucket
		);

		$bucket    = $params['bucket'];
		$use_cache = $params['use_cache'];

		if ( empty( $bucket ) ) {
			return new ErrorResponse(
				__( 'Bucket name is required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		// Check cache if enabled
		if ( $use_cache && $this->is_cache_enabled() ) {
			$cache_key = $this->get_cache_key( 'bucket_policy', [ 'bucket' => $bucket ] );
			$cached    = $this->get_from_cache( $cache_key );
			if ( $cached !== false ) {
				return $cached;
			}
		}

		// Use signer to get bucket policy
		$result = $this->signer->get_bucket_policy( $bucket );

		// Debug logging if enabled
		$this->debug( 'Client: Raw result from signer for bucket policy:', $result );

		// Cache the result if successful
		if ( $use_cache && $this->is_cache_enabled() && $result->is_successful() ) {
			$this->save_to_cache( $cache_key, $result );
		}

		// Apply contextual filter to final response
		return $this->apply_contextual_filters(
			'arraypress_s3_get_bucket_policy_response',
			$result,
			$bucket
		);
	}

	/**
	 * Get bucket lifecycle configuration
	 *
	 * @param string $bucket    Bucket name
	 * @param bool   $use_cache Whether to use cache
	 *
	 * @return ResponseInterface Response with lifecycle information
	 */
	public function get_bucket_lifecycle( string $bucket, bool $use_cache = true ): ResponseInterface {
		// Apply contextual filter to modify request parameters
		$params = $this->apply_contextual_filters(
			'arraypress_s3_get_bucket_lifecycle_params',
			[
				'bucket'    => $bucket,
				'use_cache' => $use_cache
			],
			$bucket
		);

		$bucket    = $params['bucket'];
		$use_cache = $params['use_cache'];

		if ( empty( $bucket ) ) {
			return new ErrorResponse(
				__( 'Bucket name is required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		// Check cache if enabled
		if ( $use_cache && $this->is_cache_enabled() ) {
			$cache_key = $this->get_cache_key( 'bucket_lifecycle', [ 'bucket' => $bucket ] );
			$cached    = $this->get_from_cache( $cache_key );
			if ( $cached !== false ) {
				return $cached;
			}
		}

		// Use signer to get bucket lifecycle
		$result = $this->signer->get_bucket_lifecycle( $bucket );

		// Debug logging if enabled
		$this->debug( 'Client: Raw result from signer for bucket lifecycle:', $result );

		// Cache the result if successful
		if ( $use_cache && $this->is_cache_enabled() && $result->is_successful() ) {
			$this->save_to_cache( $cache_key, $result );
		}

		// Apply contextual filter to final response
		return $this->apply_contextual_filters(
			'arraypress_s3_get_bucket_lifecycle_response',
			$result,
			$bucket
		);
	}

	/**
	 * Get comprehensive bucket details
	 *
	 * Combines basic info, CORS analysis, and permissions into a single response.
	 * This replaces the old Bucket::get_details() utility method.
	 *
	 * @param string      $bucket         Bucket name
	 * @param string|null $current_origin Current origin for CORS checking (auto-detected if null)
	 * @param bool        $use_cache      Whether to use cache
	 *
	 * @return ResponseInterface Response with complete bucket details
	 */
	public function get_bucket_details( string $bucket, ?string $current_origin = null, bool $use_cache = true ): ResponseInterface {
		if ( empty( $bucket ) ) {
			return new ErrorResponse(
				__( 'Bucket name is required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		$current_origin = $current_origin ?? Cors::get_current_origin();

		$details = [
			'bucket'      => $bucket,
			'basic'       => [
				'name'    => $bucket,
				'region'  => null,
				'created' => null,
			],
			'cors'        => [
				'analysis'       => null,
				'upload_ready'   => false,
				'current_origin' => $current_origin,
				'details'        => __( 'CORS not configured', 'arraypress' )
			],
			'permissions' => null,
		];

		// Get bucket location
		$location_result = $this->get_bucket_location( $bucket, $use_cache );
		if ( $location_result->is_successful() ) {
			$location_data              = $location_result->get_data();
			$details['basic']['region'] = $location_data['location'] ?? null;
		}

		// Get creation date from bucket list
		$buckets_result = $this->get_bucket_models( 1000, '', '', $use_cache );
		if ( $buckets_result->is_successful() ) {
			$buckets_data = $buckets_result->get_data();
			$buckets      = $buckets_data['buckets'] ?? [];

			foreach ( $buckets as $bucket_model ) {
				if ( $bucket_model->get_name() === $bucket ) {
					$details['basic']['created'] = $bucket_model->get_creation_date( true );
					break;
				}
			}
		}

		// Get CORS analysis
		$cors_result = $this->analyze_cors_configuration( $bucket, $use_cache );
		if ( $cors_result->is_successful() ) {
			$details['cors']['analysis'] = $cors_result->get_data();

			// Check upload capability
			$upload_check = $this->cors_allows_upload( $bucket, $current_origin, $use_cache );
			if ( $upload_check->is_successful() ) {
				$upload_data                        = $upload_check->get_data();
				$details['cors']['upload_ready']    = $upload_data['allows_upload'];
				$details['cors']['allowed_methods'] = $upload_data['allowed_methods'] ?? [];
				$details['cors']['details']         = $upload_data['allows_upload']
					? __( 'Upload allowed from current domain', 'arraypress' )
					: __( 'Upload not allowed from current domain', 'arraypress' );
			}
		}

		// Get permissions
		try {
			$permissions = $this->check_key_permissions( $bucket );
			if ( is_array( $permissions ) ) {
				$details['permissions'] = [
					'read'   => $permissions['read'] ?? false,
					'write'  => $permissions['write'] ?? false,
					'delete' => $permissions['delete'] ?? false,
				];
			}
		} catch ( Exception $e ) {
			// Permissions check failed, leave as null
		}

		return new SuccessResponse(
			sprintf( __( 'Complete details retrieved for bucket "%s"', 'arraypress' ), $bucket ),
			200,
			$details
		);
	}

}