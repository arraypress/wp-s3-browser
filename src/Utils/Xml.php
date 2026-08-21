<?php
/**
 * Xml Utility Class
 *
 * Handles general XML operations that could be useful anywhere.
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
 * Class Xml
 *
 * Handles general XML operations
 */
class Xml {

	/**
	 * Search recursively for a key in XML array
	 *
	 * @param array $array XML array
	 * @param string $search_key Key to search for
	 * @return mixed|null First matching value or null
	 */
	public static function find_value( array $array, string $search_key ) {
		foreach ( $array as $key => $value ) {
			if ( $key === $search_key ) {
				return $value;
			}

			if ( is_array( $value ) ) {
				$result = self::find_value( $value, $search_key );
				if ( $result !== null ) {
					return $result;
				}
			}
		}

		return null;
	}

}