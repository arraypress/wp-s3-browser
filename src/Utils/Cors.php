<?php
/**
 * Cors Utility Class - Enhanced
 *
 * Handles CORS analysis and validation operations.
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
	 * Check if CORS configuration supports file uploads
	 *
	 * @param array $cors_rules CORS rules array
	 *
	 * @return bool True if upload methods are allowed
	 */
	public static function supports_upload( array $cors_rules ): bool {
		foreach ( $cors_rules as $rule ) {
			$methods = $rule['AllowedMethods'] ?? [];
			if ( in_array( 'PUT', $methods, true ) || in_array( 'POST', $methods, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extract all allowed origins from CORS rules
	 *
	 * @param array $cors_rules CORS rules array
	 *
	 * @return array Unique allowed origins
	 */
	public static function extract_allowed_origins( array $cors_rules ): array {
		$origins = [];
		foreach ( $cors_rules as $rule ) {
			if ( ! empty( $rule['AllowedOrigins'] ) ) {
				$origins = array_merge( $origins, $rule['AllowedOrigins'] );
			}
		}

		return array_unique( $origins );
	}

	/**
	 * Extract all allowed methods from CORS rules
	 *
	 * @param array $cors_rules CORS rules array
	 *
	 * @return array Unique allowed methods
	 */
	public static function extract_allowed_methods( array $cors_rules ): array {
		$methods = [];
		foreach ( $cors_rules as $rule ) {
			if ( ! empty( $rule['AllowedMethods'] ) ) {
				$methods = array_merge( $methods, $rule['AllowedMethods'] );
			}
		}

		return array_unique( $methods );
	}

}