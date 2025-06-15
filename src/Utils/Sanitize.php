<?php
/**
 * Sanitize Utility Class
 *
 * Handles sanitization of S3 credentials and configuration values.
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
 * Class Sanitize
 *
 * Handles sanitization of S3 credentials and configuration values
 */
class Sanitize {

	/**
	 * Validate and normalize minutes value for S3 URLs
	 *
	 * @param int $minutes Minutes to validate
	 * @return int Validated minutes value (1-10080)
	 */
	public static function minutes( int $minutes ): int {
		return max( 1, min( $minutes, 10080 ) );
	}

}