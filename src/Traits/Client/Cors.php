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
		if ( $use_cache && $this->is_cache_enabled() ) {
			$cache_key = $this->get_cache_key( 'cors_config', [ 'bucket' => $bucket ] );
			$cached    = $this->get_from_cache( $cache_key );
			if ( $cached !== false ) {
				return $cached;
			}
		}

		// Use signer to get CORS configuration
		$result = $this->signer->get_cors_configuration( $bucket );

		// Debug logging if enabled
		$this->debug( 'Client: Raw result from signer for CORS get:', $result );

		// Cache the result if successful
		if ( $use_cache && $this->is_cache_enabled() && $result->is_successful() ) {
			$this->save_to_cache( $cache_key, $result );
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
		$result = $this->signer->set_cors_configuration( $bucket, $cors_rules );

		// Debug logging if enabled
		$this->debug( 'Client: Raw result from signer for CORS set:', $result );

		// Clear cache if successful and requested
		if ( $clear_cache && $this->is_cache_enabled() && $result->is_successful() ) {
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
		$result = $this->signer->delete_cors_configuration( $bucket );

		// Debug logging if enabled
		$this->debug( 'Client: Raw result from signer for CORS delete:', $result );

		// Clear cache if successful and requested
		if ( $clear_cache && $this->is_cache_enabled() && $result->is_successful() ) {
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
	 * Check if a bucket has CORS configuration
	 *
	 * @param string $bucket    Bucket name
	 * @param bool   $use_cache Whether to use cache
	 *
	 * @return ResponseInterface Response with CORS status
	 */
	public function has_cors_configuration( string $bucket, bool $use_cache = true ): ResponseInterface {
		$cors_result = $this->get_cors_configuration( $bucket, $use_cache );

		if ( ! $cors_result->is_successful() ) {
			return $cors_result;
		}

		$data     = $cors_result->get_data();
		$has_cors = $data['has_cors'] ?? false;

		return new SuccessResponse(
			$has_cors ?
				sprintf( __( 'Bucket "%s" has CORS configuration', 'arraypress' ), $bucket ) :
				sprintf( __( 'Bucket "%s" has no CORS configuration', 'arraypress' ), $bucket ),
			200,
			[
				'bucket'      => $bucket,
				'has_cors'    => $has_cors,
				'rules_count' => $data['rules_count'] ?? 0
			]
		);
	}

	/**
	 * Check if CORS allows uploads from specific origin
	 *
	 * @param string $bucket    Bucket name
	 * @param string $origin    Origin to check (e.g., 'https://example.com')
	 * @param bool   $use_cache Whether to use cache
	 *
	 * @return ResponseInterface Response with upload capability info
	 */
	public function cors_allows_upload( string $bucket, string $origin = '*', bool $use_cache = true ): ResponseInterface {
		$cors_result = $this->get_cors_configuration( $bucket, $use_cache );

		if ( ! $cors_result->is_successful() ) {
			return $cors_result;
		}

		$data       = $cors_result->get_data();
		$cors_rules = $data['cors_rules'] ?? [];

		$allows_upload   = false;
		$allowed_methods = [];
		$matching_rules  = [];
		$upload_methods  = [ 'PUT', 'POST' ];

		foreach ( $cors_rules as $rule ) {
			$rule_origins = $rule['AllowedOrigins'] ?? [];
			$rule_methods = $rule['AllowedMethods'] ?? [];

			// Check if origin matches
			$origin_matches = in_array( '*', $rule_origins, true ) || in_array( $origin, $rule_origins, true );

			if ( $origin_matches ) {
				// Check if any upload methods are allowed
				$upload_allowed_in_rule = ! empty( array_intersect( $upload_methods, $rule_methods ) );

				if ( $upload_allowed_in_rule ) {
					$allows_upload    = true;
					$allowed_methods  = array_unique( array_merge( $allowed_methods, array_intersect( $upload_methods, $rule_methods ) ) );
					$matching_rules[] = $rule;
				}
			}
		}

		$response_data = [
			'bucket'          => $bucket,
			'origin'          => $origin,
			'allows_upload'   => $allows_upload,
			'allowed_methods' => $allowed_methods,
			'matching_rules'  => $matching_rules,
			'rules_checked'   => count( $cors_rules )
		];

		// Apply contextual filter to the upload check result
		$response_data = $this->apply_contextual_filters(
			'arraypress_s3_cors_upload_check_result',
			$response_data,
			$bucket,
			$origin
		);

		return new SuccessResponse(
			$allows_upload ?
				sprintf( __( 'CORS allows uploads from "%s" to bucket "%s"', 'arraypress' ), $origin, $bucket ) :
				sprintf( __( 'CORS does not allow uploads from "%s" to bucket "%s"', 'arraypress' ), $origin, $bucket ),
			200,
			$response_data
		);
	}

	/**
	 * Generate basic CORS rules for common scenarios
	 *
	 * @param string $scenario     Scenario type: 'public_read', 'upload_only', 'full_access', 'custom'
	 * @param array  $origins      Array of allowed origins (default: ['*'])
	 * @param array  $extra_config Additional configuration options
	 *
	 * @return array CORS rules array
	 */
	public function generate_cors_rules( string $scenario = 'public_read', array $origins = [ '*' ], array $extra_config = [] ): array {
		// Apply contextual filter to modify generation parameters
		$params = $this->apply_contextual_filters(
			'arraypress_s3_generate_cors_rules_params',
			[
				'scenario'     => $scenario,
				'origins'      => $origins,
				'extra_config' => $extra_config
			]
		);

		$scenario     = $params['scenario'];
		$origins      = $params['origins'];
		$extra_config = $params['extra_config'];

		$rules = [];

		switch ( $scenario ) {
			case 'public_read':
				$rules[] = [
					'ID'             => 'PublicRead',
					'AllowedMethods' => [ 'GET', 'HEAD' ],
					'AllowedOrigins' => $origins,
					'AllowedHeaders' => [ 'Range' ],
					'ExposeHeaders'  => [ 'Content-Length', 'Content-Type', 'ETag', 'Last-Modified' ],
					'MaxAgeSeconds'  => $extra_config['max_age'] ?? 86400
				];
				break;

			case 'upload_only':
				$rules[] = [
					'ID'             => 'UploadOnly',
					'AllowedMethods' => [ 'PUT', 'POST' ],
					'AllowedOrigins' => $origins,
					'AllowedHeaders' => [ 'Content-Type', 'Content-Length', 'Content-MD5', 'x-amz-*' ],
					'MaxAgeSeconds'  => $extra_config['max_age'] ?? 3600
				];
				break;

			case 'full_access':
				$rules[] = [
					'ID'             => 'FullAccess',
					'AllowedMethods' => [ 'GET', 'PUT', 'POST', 'DELETE', 'HEAD' ],
					'AllowedOrigins' => $origins,
					'AllowedHeaders' => [ '*' ],
					'ExposeHeaders'  => [ 'Content-Length', 'Content-Type', 'ETag', 'Last-Modified' ],
					'MaxAgeSeconds'  => $extra_config['max_age'] ?? 3600
				];
				break;

			case 'presigned_upload':
				$rules[] = [
					'ID'             => 'PresignedUpload',
					'AllowedMethods' => [ 'PUT' ],
					'AllowedOrigins' => $origins,
					'AllowedHeaders' => [ 'Content-Type', 'Content-Length' ],
					'MaxAgeSeconds'  => $extra_config['max_age'] ?? 600
				];
				break;

			case 'mixed':
				// Public read + restricted upload
				$rules[] = [
					'ID'             => 'PublicRead',
					'AllowedMethods' => [ 'GET', 'HEAD' ],
					'AllowedOrigins' => [ '*' ],
					'ExposeHeaders'  => [ 'Content-Length', 'Content-Type', 'ETag' ],
					'MaxAgeSeconds'  => 86400
				];
				$rules[] = [
					'ID'             => 'RestrictedUpload',
					'AllowedMethods' => [ 'PUT', 'POST' ],
					'AllowedOrigins' => $origins,
					'AllowedHeaders' => [ 'Content-Type', 'Content-Length', 'x-amz-*' ],
					'MaxAgeSeconds'  => $extra_config['max_age'] ?? 3600
				];
				break;

			default:
				// Custom or fallback
				$rules[] = array_merge( [
					'ID'             => 'Custom',
					'AllowedMethods' => [ 'GET' ],
					'AllowedOrigins' => $origins,
					'MaxAgeSeconds'  => 3600
				], $extra_config );
		}

		// Apply contextual filter to final rules
		return $this->apply_contextual_filters(
			'arraypress_s3_generate_cors_rules_result',
			$rules,
			$scenario,
			$origins
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
	 * Get CORS analysis for a bucket
	 *
	 * Provides detailed analysis of CORS configuration including capabilities
	 * and potential security considerations.
	 *
	 * @param string $bucket    Bucket name
	 * @param bool   $use_cache Whether to use cache
	 *
	 * @return ResponseInterface Response with detailed CORS analysis
	 */
	public function analyze_cors_configuration( string $bucket, bool $use_cache = true ): ResponseInterface {
		$cors_result = $this->get_cors_configuration( $bucket, $use_cache );

		if ( ! $cors_result->is_successful() ) {
			return $cors_result;
		}

		$data       = $cors_result->get_data();
		$cors_rules = $data['cors_rules'] ?? [];

		$analysis = [
			'bucket'               => $bucket,
			'has_cors'             => ! empty( $cors_rules ),
			'rules_count'          => count( $cors_rules ),
			'supports_public_read' => false,
			'supports_upload'      => false,
			'supports_delete'      => false,
			'allows_all_origins'   => false,
			'allows_credentials'   => false,
			'max_cache_time'       => 0,
			'security_warnings'    => [],
			'capabilities'         => [],
			'origins_summary'      => [],
			'methods_summary'      => []
		];

		foreach ( $cors_rules as $rule ) {
			$methods = $rule['AllowedMethods'] ?? [];
			$origins = $rule['AllowedOrigins'] ?? [];
			$headers = $rule['AllowedHeaders'] ?? [];

			// Check capabilities
			if ( in_array( 'GET', $methods, true ) ) {
				$analysis['supports_public_read'] = true;
				$analysis['capabilities'][]       = 'read';
			}

			if ( array_intersect( [ 'PUT', 'POST' ], $methods ) ) {
				$analysis['supports_upload'] = true;
				$analysis['capabilities'][]  = 'upload';
			}

			if ( in_array( 'DELETE', $methods, true ) ) {
				$analysis['supports_delete'] = true;
				$analysis['capabilities'][]  = 'delete';
			}

			// Check security aspects
			if ( in_array( '*', $origins, true ) ) {
				$analysis['allows_all_origins'] = true;
				if ( array_intersect( [ 'PUT', 'POST', 'DELETE' ], $methods ) ) {
					$analysis['security_warnings'][] = 'Allows write operations from any origin (*)';
				}
			}

			if ( in_array( '*', $headers, true ) ) {
				$analysis['security_warnings'][] = 'Allows all headers (*)';
			}

			// Track max cache time
			$max_age = $rule['MaxAgeSeconds'] ?? 0;
			if ( $max_age > $analysis['max_cache_time'] ) {
				$analysis['max_cache_time'] = $max_age;
			}

			// Collect origins and methods
			$analysis['origins_summary'] = array_unique( array_merge( $analysis['origins_summary'], $origins ) );
			$analysis['methods_summary'] = array_unique( array_merge( $analysis['methods_summary'], $methods ) );
		}

		// Remove duplicates and clean up
		$analysis['capabilities']      = array_unique( $analysis['capabilities'] );
		$analysis['security_warnings'] = array_unique( $analysis['security_warnings'] );

		// Add recommendations
		$analysis['recommendations'] = $this->generate_cors_recommendations( $analysis );

		// Apply contextual filter to the analysis
		$analysis = $this->apply_contextual_filters(
			'arraypress_s3_cors_analysis_result',
			$analysis,
			$bucket
		);

		return new SuccessResponse(
			sprintf( __( 'CORS analysis completed for bucket "%s"', 'arraypress' ), $bucket ),
			200,
			$analysis
		);
	}

	/**
	 * Generate CORS recommendations based on analysis
	 *
	 * @param array $analysis CORS analysis data
	 *
	 * @return array Array of recommendations
	 */
	private function generate_cors_recommendations( array $analysis ): array {
		$recommendations = [];

		if ( $analysis['allows_all_origins'] && $analysis['supports_upload'] ) {
			$recommendations[] = 'Consider restricting allowed origins instead of using "*" for upload operations';
		}

		if ( $analysis['supports_delete'] && $analysis['allows_all_origins'] ) {
			$recommendations[] = 'DELETE operations with wildcard origins pose security risks';
		}

		if ( $analysis['max_cache_time'] > 86400 ) {
			$recommendations[] = 'Very long cache times may cause issues when updating CORS configuration';
		}

		if ( ! $analysis['has_cors'] && $analysis['bucket'] ) {
			$recommendations[] = 'Consider adding CORS configuration if cross-origin access is needed';
		}

		if ( count( $analysis['security_warnings'] ) === 0 && $analysis['has_cors'] ) {
			$recommendations[] = 'CORS configuration appears secure';
		}

		return $recommendations;
	}

	/**
	 * Clear CORS-related cache for a bucket
	 *
	 * @param string $bucket Bucket name
	 *
	 * @return bool Whether cache was cleared
	 */
	private function clear_cors_cache( string $bucket ): bool {
		if ( ! $this->is_cache_enabled() ) {
			return false;
		}

		$cache_key = $this->get_cache_key( 'cors_config', [ 'bucket' => $bucket ] );

		return $this->clear_cache_item( $cache_key );
	}

}