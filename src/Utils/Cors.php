<?php
/**
 * Cors Utility Class
 *
 * Handles CORS configuration and origin detection for S3 operations.
 *
 * @package     ArrayPress\S3\Utils
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Utils;

/**
 * Class Cors
 *
 * CORS utilities for S3 operations
 */
class Cors {

	/**
	 * Get current origin for CORS setup
	 *
	 * @return string Current origin (protocol + domain)
	 */
	public static function get_current_origin(): string {
		$protocol = is_ssl() ? 'https://' : 'http://';
		$host     = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';

		return $protocol . $host;
	}

	/**
	 * Generate minimal CORS rules optimized for uploads
	 *
	 * @param string $origin Origin to allow
	 *
	 * @return array CORS rules array
	 */
	public static function generate_upload_rules( string $origin ): array {
		return [
			[
				'ID'             => 'UploadFromBrowser',
				'AllowedOrigins' => [ $origin ],
				'AllowedMethods' => [ 'PUT' ], // Only PUT for presigned uploads
				'AllowedHeaders' => [ 'Content-Type', 'Content-Length' ], // Minimal headers
				'MaxAgeSeconds'  => 3600 // 1 hour cache
			]
		];
	}

	/**
	 * Generate comprehensive CORS rules for browser access
	 *
	 * @param string $origin Origin to allow
	 *
	 * @return array CORS rules array
	 */
	public static function generate_browser_rules( string $origin ): array {
		return [
			[
				'ID'             => 'BrowserAccess',
				'AllowedOrigins' => [ $origin ],
				'AllowedMethods' => [ 'GET', 'PUT', 'POST', 'DELETE', 'HEAD' ],
				'AllowedHeaders' => [ 'Content-Type', 'Content-Length', 'Authorization', 'x-amz-*' ],
				'MaxAgeSeconds'  => 3600
			]
		];
	}

	/**
	 * Generate restrictive CORS rules (uploads only, specific origin)
	 *
	 * @param string $origin Origin to allow
	 *
	 * @return array CORS rules array
	 */
	public static function generate_restrictive_rules( string $origin ): array {
		return [
			[
				'ID'             => 'RestrictiveUpload',
				'AllowedOrigins' => [ $origin ],
				'AllowedMethods' => [ 'PUT' ],
				'AllowedHeaders' => [ 'Content-Type' ],
				'MaxAgeSeconds'  => 1800 // 30 minutes
			]
		];
	}

}