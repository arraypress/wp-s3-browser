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
	 * @return ResponseInterface Response
	 */
	public function get_buckets(
		int $max_keys = 1000,
		string $prefix = '',
		string $marker = '',
		bool $use_cache = true
	): ResponseInterface {
		// Apply contextual filter to modify request parameters
		$params = $this->apply_contextual_filters(
			'arraypress_s3_get_buckets_params',
			[
				'max_keys'  => $max_keys,
				'prefix'    => $prefix,
				'marker'    => $marker,
				'use_cache' => $use_cache
			]
		);

		// Extract potentially modified values
		$max_keys  = $params['max_keys'];
		$prefix    = $params['prefix'];
		$marker    = $params['marker'];
		$use_cache = $params['use_cache'];

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

		// Debug logging if enabled
		$this->debug( 'Client: Raw result from signer:', $result );

		// Cache the result if successful
		if ( $use_cache && $this->is_cache_enabled() && $result->is_successful() ) {
			$this->save_to_cache( $cache_key, $result );
		}

		// Apply contextual filter to final response
		return $this->apply_contextual_filters(
			'arraypress_s3_get_buckets_response',
			$result,
			$max_keys,
			$prefix
		);
	}

	/**
	 * Get buckets as models
	 *
	 * @param int    $max_keys  Maximum number of buckets to return
	 * @param string $prefix    Prefix to filter buckets
	 * @param string $marker    Marker for pagination
	 * @param bool   $use_cache Whether to use cache
	 *
	 * @return ResponseInterface Response with bucket models
	 */
	public function get_bucket_models(
		int $max_keys = 1000,
		string $prefix = '',
		string $marker = '',
		bool $use_cache = true
	): ResponseInterface {
		// Apply contextual filter to modify request parameters
		$params = $this->apply_contextual_filters(
			'arraypress_s3_get_bucket_models_params',
			[
				'max_keys'  => $max_keys,
				'prefix'    => $prefix,
				'marker'    => $marker,
				'use_cache' => $use_cache
			]
		);

		// Get buckets response
		$response = $this->get_buckets(
			$params['max_keys'],
			$params['prefix'],
			$params['marker'],
			$params['use_cache']
		);

		if ( ! ( $response instanceof BucketsResponse ) ) {
			return new ErrorResponse(
				__( 'Unable to retrieve buckets. Please verify your access key, secret key, and region settings are correct.', 'arraypress' ),
				'bucket_retrieval_failed',
				400
			);
		}

		// Return success response with transformed data
		$success_response = new SuccessResponse(
			__( 'Bucket models retrieved successfully', 'arraypress' ),
			200,
			[
				'buckets'         => $response->to_bucket_models(),
				'truncated'       => $response->is_truncated(),
				'next_marker'     => $response->get_next_marker(),
				'owner'           => $response->get_owner(),
				'response_object' => $response
			]
		);

		// Apply contextual filter to final response
		return $this->apply_contextual_filters(
			'arraypress_s3_get_bucket_models_response',
			$success_response,
			$params['max_keys'],
			$params['prefix']
		);
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
		// Apply contextual filter to modify request parameters
		$params = $this->apply_contextual_filters(
			'arraypress_s3_buckets_exist_params',
			[
				'buckets'   => $buckets,
				'use_cache' => $use_cache
			]
		);

		$buckets   = $params['buckets'];
		$use_cache = $params['use_cache'];

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

			if ( ! $check_result->is_successful() ) {
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

		$response = new SuccessResponse(
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

		// Apply contextual filter to final response
		return $this->apply_contextual_filters(
			'arraypress_s3_buckets_exist_response',
			$response,
			$buckets
		);
	}

	/**
	 * Get count of accessible buckets
	 *
	 * @param bool $use_cache Whether to use cache (default false for real-time results)
	 *
	 * @return ResponseInterface Response with bucket count
	 */
	public function get_bucket_count( bool $use_cache = false ): ResponseInterface {
		// Apply contextual filter to modify request parameters
		$params = $this->apply_contextual_filters(
			'arraypress_s3_get_bucket_count_params',
			[
				'use_cache' => $use_cache
			]
		);

		$use_cache = $params['use_cache'];

		// Check cache if enabled
		if ( $use_cache && $this->is_cache_enabled() ) {
			$cache_key = $this->get_cache_key( 'bucket_count', [] );
			$cached    = $this->get_from_cache( $cache_key );
			if ( $cached !== false ) {
				return $cached;
			}
		}

		// Get bucket models (limit high enough to get all buckets)
		$result = $this->get_bucket_models( 1000, '', '', $use_cache );

		if ( ! $result->is_successful() ) {
			return new ErrorResponse(
				__( 'Unable to retrieve bucket count', 'arraypress' ),
				'bucket_count_failed',
				400,
				[ 'original_error' => $result->get_error_message() ]
			);
		}

		$data         = $result->get_data();
		$buckets      = $data['buckets'] ?? [];
		$bucket_count = count( $buckets );

		$response = new SuccessResponse(
			sprintf(
				_n(
					'Found %d bucket',
					'Found %d buckets',
					$bucket_count,
					'arraypress'
				),
				$bucket_count
			),
			200,
			[
				'count'   => $bucket_count,
				'buckets' => array_map( function ( $bucket ) {
					return $bucket->get_name();
				}, $buckets )
			]
		);

		// Cache the result if successful
		if ( $use_cache && $this->is_cache_enabled() ) {
			$this->save_to_cache( $cache_key, $response );
		}

		// Apply contextual filter to final response
		return $this->apply_contextual_filters(
			'arraypress_s3_get_bucket_count_response',
			$response
		);
	}

}