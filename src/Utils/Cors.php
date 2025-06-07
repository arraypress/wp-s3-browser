<?php
/**
 * Cors Utility Class - Simplified
 *
 * Handles only pure utility functions for CORS operations.
 * Rule generation is handled by the Client CORS trait.
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

}