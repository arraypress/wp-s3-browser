<?php
/**
 * CORS Operations Trait - Signer Level
 *
 * Handles CORS configuration operations for S3-compatible storage using
 * the raw S3 API endpoints.
 *
 * @package     ArrayPress\S3\Traits\Signer
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Signer;

use ArrayPress\S3\Interfaces\Response as ResponseInterface;
use ArrayPress\S3\Responses\SuccessResponse;
use ArrayPress\S3\Responses\ErrorResponse;
use ArrayPress\S3\Utils\Cors as CorsUtil;
use ArrayPress\S3\Utils\Validate;

/**
 * Trait Cors
 *
 * Provides CORS configuration management for S3-compatible buckets
 */
trait Cors {

	/**
	 * Get CORS configuration for a bucket
	 *
	 * Retrieves the Cross-Origin Resource Sharing (CORS) configuration for a bucket.
	 * CORS defines how web browsers can access bucket resources from different domains.
	 *
	 * The response includes:
	 * - Array of CORS rules with allowed methods, origins, headers
	 * - Analysis of upload capabilities based on rules
	 * - Summary of allowed origins and methods
	 *
	 * Usage Examples:
	 * ```php
	 * // Get CORS configuration
	 * $response = $signer->get_cors_configuration('my-bucket');
	 *
	 * if ($response->is_successful()) {
	 *     $data = $response->get_data();
	 *     $rules = $data['cors_rules'];
	 *     $supports_upload = $data['supports_upload'];
	 *
	 *     foreach ($rules as $rule) {
	 *         echo "Rule ID: " . $rule['ID'] . "\n";
	 *         echo "Allowed Methods: " . implode(', ', $rule['AllowedMethods']) . "\n";
	 *         echo "Allowed Origins: " . implode(', ', $rule['AllowedOrigins']) . "\n";
	 *     }
	 * }
	 * ```
	 *
	 * @param string $bucket Bucket name to get CORS configuration for
	 *
	 * @return ResponseInterface SuccessResponse with CORS configuration or ErrorResponse on failure
	 *
	 * @since 1.0.0
	 *
	 * @see   set_cors_configuration() For setting CORS configuration
	 * @see   delete_cors_configuration() For removing CORS configuration
	 */
	public function get_cors_configuration( string $bucket ): ResponseInterface {
		if ( empty( $bucket ) ) {
			return new ErrorResponse(
				__( 'Bucket name is required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		// Generate authorization headers for CORS GET operation
		$headers = $this->generate_auth_headers( 'GET', $bucket, '', [ 'cors' => '' ] );

		// Add base request headers (including user agent)
		$headers = $this->get_base_request_headers( $headers );

		// Build URL with CORS query parameter
		$url = $this->provider->build_url_with_query( $bucket, '', [ 'cors' => '' ] );

		// Debug the request
		$this->debug_request_details( 'get_cors_configuration', $url, $headers );

		// Make the request
		$response = wp_remote_get( $url, [
			'headers' => $headers,
			'timeout' => $this->get_operation_timeout( 'get_cors' )
		] );

		// Handle errors
		if ( is_wp_error( $response ) ) {
			return ErrorResponse::from_wp_error( $response );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		// Debug the response
		$this->debug_response_details( 'get_cors_configuration', $status_code, $body );

		// Check for error status code
		if ( $status_code < 200 || $status_code >= 300 ) {
			// Special handling for "no CORS configuration" case
			if ( $status_code === 404 ) {
				return new SuccessResponse(
					sprintf( __( 'No CORS configuration found for bucket "%s"', 'arraypress' ), $bucket ),
					200,
					[
						'bucket'          => $bucket,
						'cors_rules'      => [],
						'supports_upload' => false,
						'allowed_origins' => [],
						'allowed_methods' => [],
						'has_cors'        => false
					]
				);
			}

			return $this->handle_error_response( $status_code, $body, 'Failed to get CORS configuration' );
		}

		// Parse XML response
		$xml = $this->parse_xml_response( $body );
		if ( $xml instanceof ErrorResponse ) {
			return $xml;
		}

		// Parse CORS configuration using XmlParser trait
		$cors_rules = $this->parse_cors_configuration( $xml );

		// Analyze the configuration using Cors utility
		$supports_upload = CorsUtil::supports_upload( $cors_rules );
		$allowed_origins = CorsUtil::extract_allowed_origins( $cors_rules );
		$allowed_methods = CorsUtil::extract_allowed_methods( $cors_rules );

		return new SuccessResponse(
			sprintf( __( 'CORS configuration retrieved for bucket "%s"', 'arraypress' ), $bucket ),
			200,
			[
				'bucket'          => $bucket,
				'cors_rules'      => $cors_rules,
				'supports_upload' => $supports_upload,
				'allowed_origins' => $allowed_origins,
				'allowed_methods' => $allowed_methods,
				'has_cors'        => ! empty( $cors_rules ),
				'rules_count'     => count( $cors_rules )
			],
			$xml
		);
	}

	/**
	 * Set CORS configuration for a bucket
	 *
	 * Configures Cross-Origin Resource Sharing (CORS) rules for a bucket.
	 * CORS allows web applications running at different domains to access bucket resources.
	 *
	 * Each CORS rule can specify:
	 * - AllowedMethods: HTTP methods (GET, PUT, POST, DELETE, HEAD)
	 * - AllowedOrigins: Domains that can access the bucket (* for all)
	 * - AllowedHeaders: Headers that can be sent in requests
	 * - ExposeHeaders: Headers exposed to the client
	 * - MaxAgeSeconds: Browser cache time for preflight requests
	 *
	 * Usage Examples:
	 * ```php
	 * // Basic CORS for file uploads
	 * $cors_rules = [
	 *     [
	 *         'ID' => 'AllowUploads',
	 *         'AllowedMethods' => ['PUT', 'POST'],
	 *         'AllowedOrigins' => ['https://mysite.com'],
	 *         'AllowedHeaders' => ['Content-Type', 'Content-Length'],
	 *         'MaxAgeSeconds' => 3600
	 *     ]
	 * ];
	 *
	 * $response = $signer->set_cors_configuration('my-bucket', $cors_rules);
	 *
	 * // Multiple rules for different purposes
	 * $cors_rules = [
	 *     [
	 *         'ID' => 'PublicRead',
	 *         'AllowedMethods' => ['GET', 'HEAD'],
	 *         'AllowedOrigins' => ['*'],
	 *         'MaxAgeSeconds' => 86400
	 *     ],
	 *     [
	 *         'ID' => 'AdminUpload',
	 *         'AllowedMethods' => ['PUT', 'POST', 'DELETE'],
	 *         'AllowedOrigins' => ['https://admin.mysite.com'],
	 *         'AllowedHeaders' => ['*'],
	 *         'MaxAgeSeconds' => 600
	 *     ]
	 * ];
	 * ```
	 *
	 * @param string $bucket     Bucket name to configure CORS for
	 * @param array  $cors_rules Array of CORS rules. Each rule should contain:
	 *                           - AllowedMethods: array of HTTP methods
	 *                           - AllowedOrigins: array of origin domains
	 *                           - AllowedHeaders: array of allowed headers (optional)
	 *                           - ExposeHeaders: array of headers to expose (optional)
	 *                           - MaxAgeSeconds: int cache time for preflight (optional)
	 *                           - ID: string identifier for the rule (optional)
	 *
	 * @return ResponseInterface SuccessResponse on successful configuration or ErrorResponse on failure
	 *
	 * @since 1.0.0
	 *
	 * @see   get_cors_configuration() For retrieving current CORS configuration
	 * @see   delete_cors_configuration() For removing CORS configuration
	 */
	public function set_cors_configuration( string $bucket, array $cors_rules ): ResponseInterface {
		if ( empty( $bucket ) ) {
			return new ErrorResponse(
				__( 'Bucket name is required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		if ( empty( $cors_rules ) ) {
			return new ErrorResponse(
				__( 'At least one CORS rule is required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		// Validate CORS rules using Cors utility
		$validation_result = Validate::cors_rules( $cors_rules );
		if ( ! $validation_result['valid'] ) {
			return new ErrorResponse(
				$validation_result['message'],
				$validation_result['code'] ?? 'validation_error',
				400
			);
		}

		// Build CORS XML using XmlParser trait
		$cors_xml = $this->build_cors_configuration( $cors_rules );

		// Generate authorization headers for CORS PUT operation
		$headers = $this->generate_auth_headers( 'PUT', $bucket, '', [ 'cors' => '' ], $cors_xml );

		$headers['Content-Type']   = 'application/xml';
		$headers['Content-Length'] = (string) strlen( $cors_xml );

		// Add base request headers (including user agent)
		$headers = $this->get_base_request_headers( $headers );

		// Build URL with CORS query parameter
		$url = $this->provider->build_url_with_query( $bucket, '', [ 'cors' => '' ] );

		// Debug the request
		$this->debug_request_details( 'set_cors_configuration', $url, $headers );
		$this->debug( 'CORS XML Payload', $cors_xml );

		// Make the request
		$response = wp_remote_request( $url, [
			'method'  => 'PUT',
			'headers' => $headers,
			'body'    => $cors_xml,
			'timeout' => $this->get_operation_timeout( 'set_cors' )
		] );

		// Handle errors
		if ( is_wp_error( $response ) ) {
			return ErrorResponse::from_wp_error( $response );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		// Debug the response
		$this->debug_response_details( 'set_cors_configuration', $status_code, $body );

		// Check for error status code
		if ( $status_code < 200 || $status_code >= 300 ) {
			return $this->handle_error_response( $status_code, $body, 'Failed to set CORS configuration' );
		}

		return new SuccessResponse(
			sprintf( __( 'CORS configuration updated for bucket "%s"', 'arraypress' ), $bucket ),
			$status_code,
			[
				'bucket'      => $bucket,
				'rules_count' => count( $cors_rules ),
				'xml_size'    => strlen( $cors_xml )
			]
		);
	}

	/**
	 * Delete CORS configuration for a bucket
	 *
	 * Removes all CORS rules from a bucket, disabling cross-origin access.
	 * After deletion, the bucket will only be accessible from the same origin.
	 *
	 * Usage Examples:
	 * ```php
	 * // Remove all CORS rules
	 * $response = $signer->delete_cors_configuration('my-bucket');
	 *
	 * if ($response->is_successful()) {
	 *     echo "CORS configuration removed successfully";
	 * }
	 * ```
	 *
	 * @param string $bucket Bucket name to remove CORS configuration from
	 *
	 * @return ResponseInterface SuccessResponse on successful deletion or ErrorResponse on failure
	 *
	 * @since 1.0.0
	 *
	 * @see   get_cors_configuration() For retrieving current CORS configuration
	 * @see   set_cors_configuration() For setting CORS configuration
	 */
	public function delete_cors_configuration( string $bucket ): ResponseInterface {
		if ( empty( $bucket ) ) {
			return new ErrorResponse(
				__( 'Bucket name is required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		// Generate authorization headers for CORS DELETE operation
		$headers = $this->generate_auth_headers( 'DELETE', $bucket, '', [ 'cors' => '' ] );

		// Add base request headers (including user agent)
		$headers = $this->get_base_request_headers( $headers );

		// Build URL with CORS query parameter
		$url = $this->provider->build_url_with_query( $bucket, '', [ 'cors' => '' ] );

		// Debug the request
		$this->debug_request_details( 'delete_cors_configuration', $url, $headers );

		// Make the request
		$response = wp_remote_request( $url, [
			'method'  => 'DELETE',
			'headers' => $headers,
			'timeout' => $this->get_operation_timeout( 'delete_cors' )
		] );

		// Handle errors
		if ( is_wp_error( $response ) ) {
			return ErrorResponse::from_wp_error( $response );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		// Debug the response
		$this->debug_response_details( 'delete_cors_configuration', $status_code, $body );

		// Check for error status code
		if ( $status_code < 200 || $status_code >= 300 ) {
			// Special handling for "no CORS configuration" case
			if ( $status_code === 404 ) {
				return new SuccessResponse(
					sprintf( __( 'No CORS configuration to delete for bucket "%s"', 'arraypress' ), $bucket ),
					200,
					[
						'bucket'      => $bucket,
						'was_present' => false
					]
				);
			}

			return $this->handle_error_response( $status_code, $body, 'Failed to delete CORS configuration' );
		}

		return new SuccessResponse(
			sprintf( __( 'CORS configuration deleted for bucket "%s"', 'arraypress' ), $bucket ),
			$status_code,
			[
				'bucket'      => $bucket,
				'was_present' => true
			]
		);
	}

}