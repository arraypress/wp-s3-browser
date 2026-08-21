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
use ArrayPress\S3\Utils\Cors;
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
	/**
	 * Buckets known to exist without listing them.
	 *
	 * A bucket-scoped token cannot call ListBuckets, so the only way to show a
	 * bucket is to be told its name and confirm it with HeadBucket.
	 *
	 * @var string[]
	 */
	private array $known_buckets = [];

	/**
	 * Tell the client which buckets to fall back to when listing is refused.
	 *
	 * @param string[] $names Bucket names.
	 *
	 * @return self
	 */
	public function set_known_buckets( array $names ): self {
		$this->known_buckets = array_values( array_unique( array_filter( array_map( 'strval', $names ) ) ) );

		return $this;
	}

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
			// ListBuckets failed (commonly: 403 AccessDenied on R2 with
			// bucket-scoped API tokens). Fall back to a consumer-supplied
			// bucket list — typically populated from a "Default Bucket"
			// setting — and verify each via HeadBucket.
			// Buckets this client already knows about, set by whatever configured
			// it. A global filter cannot serve this: two Browser instances on
			// one site — an EDD one and a WooCommerce one, say — would overwrite
			// each other's answer. The filter is still applied afterwards for
			// consumers that prefer it.
			$fallback_names = $this->known_buckets;
			$fallback_names = (array) apply_filters(
				'arraypress_s3_known_buckets_fallback',
				$fallback_names,
				$params['prefix']
			);
			$fallback_names = array_values( array_unique( array_filter( array_map( 'strval', $fallback_names ) ) ) );

			if ( ! empty( $fallback_names ) ) {
				$models = [];

				foreach ( $fallback_names as $bucket_name ) {
					if ( '' !== $params['prefix'] && 0 !== strpos( $bucket_name, $params['prefix'] ) ) {
						continue;
					}

					$exists = $this->bucket_exists( $bucket_name, $params['use_cache'] );
					if ( $exists->is_successful() ) {
						$models[] = new \ArrayPress\S3\Models\S3Bucket( [
							'Name'         => $bucket_name,
							'CreationDate' => '',
						] );
					}
				}

				if ( ! empty( $models ) ) {
					return new SuccessResponse(
						__( 'Bucket models retrieved (scoped token).', 'arraypress' ),
						200,
						[
							'buckets'         => $models,
							'truncated'       => false,
							'next_marker'     => '',
							'owner'           => null,
							'response_object' => null,
							'scoped'          => true,
						]
					);
				}
			}

			$code   = $response instanceof ErrorResponse ? $response->get_error_code() : '';
			$status = $response instanceof ErrorResponse ? $response->get_status_code() : 0;

			// A 403 AccessDenied here is not a credential problem: the request
			// was signed and accepted, the token simply lacks
			// s3:ListAllMyBuckets. That is what a bucket-scoped token looks
			// like, and Cloudflare recommends scoping tokens. Bad credentials
			// present differently — InvalidAccessKeyId or SignatureDoesNotMatch
			// — so the two must not share a message.
			// Key off the status as well as the code. The code is only present
			// when the provider returned a parseable error document, and a 403
			// on a service-level listing already means the request was signed
			// and accepted but the token lacks ListAllMyBuckets.
			if ( 403 === $status || in_array( $code, [ 'AccessDenied', 'access_denied' ], true ) ) {
				return new ErrorResponse(
					__( 'This API token cannot list buckets. That is expected for a bucket-scoped token — specify the bucket name directly.', 'arraypress' ),
					'bucket_listing_forbidden',
					403,
					[ 'scoped_token' => true ]
				);
			}

			return new ErrorResponse(
				$response instanceof ErrorResponse
					? $response->get_error_message()
					: __( 'Unable to retrieve buckets.', 'arraypress' ),
				'' !== $code ? $code : 'bucket_retrieval_failed',
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
			// Pass the underlying reason straight through. Wrapping it in
			// "Unable to retrieve bucket count" hid the one detail a caller
			// needs: whether the token is scoped or the credentials are wrong.
			return $result;
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