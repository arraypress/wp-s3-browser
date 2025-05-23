<?php
/**
 * Xml Utility Class
 *
 * Handles XML parsing and data extraction.
 *
 * @package     ArrayPress\S3\Utils
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      ArrayPress Team
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Utils;

/**
 * Class Xml
 *
 * Handles XML parsing and data extraction
 */
class Xml {

	/**
	 * Get value from XML array using dot notation path
	 *
	 * @param array  $array XML array
	 * @param string $path  Dot notation path (e.g., 'Owner.ID.value')
	 *
	 * @return mixed|null Value if found, null otherwise
	 */
	public static function get_value( array $array, string $path ) {
		$keys    = explode( '.', $path );
		$current = $array;

		foreach ( $keys as $key ) {
			if ( ! is_array( $current ) || ! isset( $current[ $key ] ) ) {
				return null;
			}
			$current = $current[ $key ];
		}

		return $current;
	}

	/**
	 * Search recursively for a key in XML array
	 *
	 * @param array  $array      XML array
	 * @param string $search_key Key to search for
	 *
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

	/**
	 * Extract text value from XML node (handles both 'value' key and direct values)
	 *
	 * @param mixed $node XML node data
	 *
	 * @return string Extracted text value
	 */
	public static function extract_text( $node ): string {
		if ( is_array( $node ) ) {
			return (string) ( $node['value'] ?? $node['@text'] ?? '' );
		}

		return (string) $node;
	}

	/**
	 * Check if XML array represents truncated results
	 *
	 * @param array $xml XML array
	 *
	 * @return bool True if truncated
	 */
	public static function is_truncated( array $xml ): bool {
		$truncated_value = self::find_value( $xml, 'IsTruncated' );
		if ( $truncated_value !== null ) {
			$text_value = self::extract_text( $truncated_value );

			return $text_value === 'true' || $text_value === '1';
		}

		return false;
	}

	/**
	 * Get next continuation token from XML
	 *
	 * @param array $xml XML array
	 *
	 * @return string|null Next continuation token or null
	 */
	public static function get_continuation_token( array $xml ): ?string {
		$token = self::find_value( $xml, 'NextContinuationToken' );
		if ( $token !== null ) {
			return self::extract_text( $token );
		}

		return null;
	}

	/**
	 * Get next marker from XML
	 *
	 * @param array $xml XML array
	 *
	 * @return string|null Next marker or null
	 */
	public static function get_next_marker( array $xml ): ?string {
		$marker = self::find_value( $xml, 'NextMarker' );
		if ( $marker !== null ) {
			return self::extract_text( $marker );
		}

		return null;
	}

}