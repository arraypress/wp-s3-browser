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
	 * Reduce a string to a lowercase, hyphen-separated slug.
	 *
	 * Deliberately not sanitize_title(): that runs through a filter of the
	 * same name, so a third-party plugin can change what it returns. This
	 * derives a REST namespace, and a namespace that shifts under a filter
	 * moves the browser's routes out from under its own JavaScript.
	 *
	 * @param string $value Value to slug.
	 *
	 * @return string Slug, or '' if nothing usable remained.
	 */
	public static function slug( string $value ): string {
		return trim( strtolower( (string) preg_replace( '/[^A-Za-z0-9]+/', '-', $value ) ), '-' );
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