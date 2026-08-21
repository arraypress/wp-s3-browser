<?php
/**
 * Client CORS Operations Trait
 *
 * Handles CORS configuration operations for the S3 Client with caching,
 * contextual filters, and convenience methods.
 *
 * @package     ArrayPress\S3\Traits\Client
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Client;

use ArrayPress\S3\Interfaces\Response as ResponseInterface;
use ArrayPress\S3\Responses\SuccessResponse;
use ArrayPress\S3\Responses\ErrorResponse;
use ArrayPress\S3\Cors\Analysis;
use ArrayPress\S3\Cors\Rules;

/**
 * Trait Cors
 *
 * Provides high-level CORS configuration management with caching and filtering
 */
trait Cors {

	/**
	 * Get CORS configuration for a bucket
	 *
	 * @param string $bucket    Bucket name
	 * @param bool   $use_cache Whether to use cache
	 *
	 * @return ResponseInterface Response with CORS configuration
	 */
	public function get_cors_configuration( string $bucket, bool $use_cache = true ): ResponseInterface {
		// Apply contextual filter to modify request parameters
		$params = $this->apply_contextual_filters(
			'arraypress_s3_get_cors_configuration_params',
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
		if ( $use_cache && $this->cache->is_enabled() ) {
			$cache_key = $this->cache->key( 'cors_config', [ 'bucket' => $bucket ] );
			$cached    = $this->cache->get( $cache_key );
			if ( $cached !== false ) {
				return $cached;
			}
		}

		// Use signer to get CORS configuration
		$result = $this->api->get_cors_configuration( $bucket );

		// Debug logging if enabled
		$this->debug( 'Client: Raw result from signer for CORS get:', $result );

		// Cache the result if successful
		if ( $use_cache && $this->cache->is_enabled() && $result->is_successful() ) {
			$this->cache->set( $cache_key, $result );
		}

		// Apply contextual filter to final response
		return $this->apply_contextual_filters(
			'arraypress_s3_get_cors_configuration_response',
			$result,
			$bucket
		);
	}

	/**
	 * Set CORS configuration for a bucket
	 *
	 * @param string $bucket      Bucket name
	 * @param array  $cors_rules  Array of CORS rules
	 * @param bool   $clear_cache Whether to clear cache after setting
	 *
	 * @return ResponseInterface Response
	 */
	public function set_cors_configuration( string $bucket, array $cors_rules, bool $clear_cache = true ): ResponseInterface {
		// Apply contextual filter to modify parameters and allow preventing changes
		$params = $this->apply_contextual_filters(
			'arraypress_s3_set_cors_configuration_params',
			[
				'bucket'      => $bucket,
				'cors_rules'  => $cors_rules,
				'clear_cache' => $clear_cache,
				'proceed'     => true
			],
			$bucket
		);

		// Check if operation should proceed
		if ( ! $params['proceed'] ) {
			return new ErrorResponse(
				__( 'CORS configuration update was prevented by filter', 'arraypress' ),
				'update_prevented',
				403,
				[
					'bucket' => $bucket
				]
			);
		}

		$bucket      = $params['bucket'];
		$cors_rules  = $params['cors_rules'];
		$clear_cache = $params['clear_cache'];

		// Use signer to set CORS configuration
		$result = $this->api->set_cors_configuration( $bucket, $cors_rules );

		// Debug logging if enabled
		$this->debug( 'Client: Raw result from signer for CORS set:', $result );

		// Clear cache if successful and requested
		if ( $clear_cache && $this->cache->is_enabled() && $result->is_successful() ) {
			$this->clear_cors_cache( $bucket );
		}

		// Apply contextual filter to final response
		return $this->apply_contextual_filters(
			'arraypress_s3_set_cors_configuration_response',
			$result,
			$bucket,
			$cors_rules
		);
	}

	/**
	 * Delete CORS configuration for a bucket
	 *
	 * @param string $bucket      Bucket name
	 * @param bool   $clear_cache Whether to clear cache after deletion
	 *
	 * @return ResponseInterface Response
	 */
	public function delete_cors_configuration( string $bucket, bool $clear_cache = true ): ResponseInterface {
		// Apply contextual filter to modify parameters and allow preventing deletion
		$params = $this->apply_contextual_filters(
			'arraypress_s3_delete_cors_configuration_params',
			[
				'bucket'      => $bucket,
				'clear_cache' => $clear_cache,
				'proceed'     => true
			],
			$bucket
		);

		// Check if deletion should proceed
		if ( ! $params['proceed'] ) {
			return new ErrorResponse(
				__( 'CORS configuration deletion was prevented by filter', 'arraypress' ),
				'deletion_prevented',
				403,
				[
					'bucket' => $bucket
				]
			);
		}

		$bucket      = $params['bucket'];
		$clear_cache = $params['clear_cache'];

		// Use signer to delete CORS configuration
		$result = $this->api->delete_cors_configuration( $bucket );

		// Debug logging if enabled
		$this->debug( 'Client: Raw result from signer for CORS delete:', $result );

		// Clear cache if successful and requested
		if ( $clear_cache && $this->cache->is_enabled() && $result->is_successful() ) {
			$this->clear_cors_cache( $bucket );
		}

		// Apply contextual filter to final response
		return $this->apply_contextual_filters(
			'arraypress_s3_delete_cors_configuration_response',
			$result,
			$bucket
		);
	}

	/**
	 * Check whether CORS lets a browser upload from an origin.
	 *
	 * @param string $bucket    Bucket name.
	 * @param string $origin    Origin to test.
	 * @param bool   $use_cache Whether to use cache.
	 *
	 * @return ResponseInterface Upload capability.
	 */
	public function cors_allows_upload( string $bucket, string $origin = '*', bool $use_cache = true ): ResponseInterface {
		$cors = $this->get_cors_configuration( $bucket, $use_cache );

		if ( ! $cors->is_successful() ) {
			return $cors;
		}

		$result = $this->apply_contextual_filters(
			'arraypress_s3_cors_upload_check_result',
			[ 'bucket' => $bucket ] + Analysis::allows_upload( $cors->get_data()['cors_rules'] ?? [], $origin ),
			$bucket,
			$origin
		);

		return new SuccessResponse(
			$result['allows_upload']
				/* translators: %1$s: origin, %2$s: bucket name */
				? sprintf( __( 'CORS allows uploads from "%1$s" to bucket "%2$s"', 'arraypress' ), $origin, $bucket )
				/* translators: %1$s: origin, %2$s: bucket name */
				: sprintf( __( 'CORS does not allow uploads from "%1$s" to bucket "%2$s"', 'arraypress' ), $origin, $bucket ),
			200,
			$result
		);
	}

	/**
	 * Build CORS rules for a scenario.
	 *
	 * @param string $scenario Scenario name.
	 * @param array  $origins  Allowed origins.
	 * @param array  $extra    Overrides merged over the scenario template.
	 *
	 * @return array CORS rules.
	 */
	public function generate_cors_rules( string $scenario = 'public_read', array $origins = [ '*' ], array $extra = [] ): array {
		$params = $this->apply_contextual_filters(
			'arraypress_s3_generate_cors_rules_params',
			[ 'scenario' => $scenario, 'origins' => $origins, 'extra_config' => $extra ]
		);

		return $this->apply_contextual_filters(
			'arraypress_s3_generate_cors_rules_result',
			Rules::generate( $params['scenario'], $params['origins'], $params['extra_config'] ),
			$params['scenario'],
			$params['origins']
		);
	}

	/**
	 * Set CORS rules using a predefined scenario
	 *
	 * @param string $bucket       Bucket name
	 * @param string $scenario     Scenario type
	 * @param array  $origins      Allowed origins
	 * @param array  $extra_config Extra configuration
	 * @param bool   $clear_cache  Whether to clear cache
	 *
	 * @return ResponseInterface Response
	 */
	public function set_cors_scenario(
		string $bucket,
		string $scenario = 'public_read',
		array $origins = [ '*' ],
		array $extra_config = [],
		bool $clear_cache = true
	): ResponseInterface {

		$cors_rules = $this->generate_cors_rules( $scenario, $origins, $extra_config );

		return $this->set_cors_configuration( $bucket, $cors_rules, $clear_cache );
	}

	/**
	 * Report what a bucket's CORS configuration permits.
	 *
	 * @param string $bucket    Bucket name.
	 * @param bool   $use_cache Whether to use cache.
	 *
	 * @return ResponseInterface Analysis.
	 */
	public function analyze_cors_configuration( string $bucket, bool $use_cache = true ): ResponseInterface {
		$cors = $this->get_cors_configuration( $bucket, $use_cache );

		if ( ! $cors->is_successful() ) {
			return $cors;
		}

		$analysis = $this->apply_contextual_filters(
			'arraypress_s3_cors_analysis_result',
			[ 'bucket' => $bucket ] + Analysis::describe( $cors->get_data()['cors_rules'] ?? [] ),
			$bucket
		);

		return new SuccessResponse(
			/* translators: %1$s: bucket name */
			sprintf( __( 'CORS analysis completed for bucket "%s"', 'arraypress' ), $bucket ),
			200,
			$analysis
		);
	}


	/**
	 * Clear CORS-related cache for a bucket
	 *
	 * @param string $bucket Bucket name
	 *
	 * @return bool Whether cache was cleared
	 */
	private function clear_cors_cache( string $bucket ): bool {
		if ( ! $this->cache->is_enabled() ) {
			return false;
		}

		$cache_key = $this->cache->key( 'cors_config', [ 'bucket' => $bucket ] );

		return $this->cache->forget( $cache_key );
	}

}