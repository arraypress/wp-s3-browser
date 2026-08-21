<?php
/**
 * Cors Utility Class - Enhanced
 *
 * Handles CORS analysis and validation operations.
 *
 * @package     ArrayPress\S3\Cors
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Cors;

/**
 * Class Cors
 *
 * CORS utilities for S3 operations
 */
class Origin {

	/**
	 * Get current origin for CORS setup
	 *
	 * @return string Current origin (protocol + domain)
	 */
	public static function current(): string {
		$protocol = is_ssl() ? 'https://' : 'http://';
		$host     = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';

		return $protocol . $host;
	}

}