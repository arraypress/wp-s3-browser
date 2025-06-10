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
	 * Sanitize access key ID
	 *
	 * @param string $access_key Access key ID to sanitize
	 *
	 * @return string Sanitized access key
	 */
	public static function access_key( string $access_key ): string {
		return sanitize_text_field( trim( $access_key ) );
	}

	/**
	 * Sanitize secret access key
	 *
	 * Very conservative - only trim whitespace to preserve key integrity.
	 *
	 * @param string $secret_key Secret access key to sanitize
	 *
	 * @return string Sanitized secret key
	 */
	public static function secret_key( string $secret_key ): string {
		return trim( $secret_key );
	}

	/**
	 * Sanitize account ID
	 *
	 * @param string $account_id Account ID to sanitize
	 *
	 * @return string Sanitized account ID
	 */
	public static function account_id( string $account_id ): string {
		return sanitize_text_field( trim( $account_id ) );
	}

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