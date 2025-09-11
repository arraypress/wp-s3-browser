<?php
/**
 * Shortcodes Utility Class
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
 * Class Shortcodes
 */
class Shortcodes {

	/**
	 * Check if a path contains shortcodes
	 *
	 * @param string $path Path to check
	 *
	 * @return bool
	 */
	public static function has( string $path ): bool {
		return str_contains( $path, '[' ) && str_contains( $path, ']' );
	}

}