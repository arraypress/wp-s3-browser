<?php
/**
 * Cache Utility Functions
 *
 * Cache-related utility functions for S3 operations.
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
 * Class CacheUtils
 */
class Cache {

	/**
	 * Create a unique cache key
	 *
	 * @param string $prefix Prefix for the key
	 * @param mixed  $data   Data to include in the key
	 *
	 * @return string Unique cache key
	 */
	public static function create_cache_key( string $prefix, $data ): string {
		return $prefix . md5( serialize( $data ) );
	}

	/**
	 * Get WordPress transient with error handling
	 *
	 * @param string $key     Cache key
	 * @param mixed  $default Default value if transient doesn't exist
	 *
	 * @return mixed Cached value or default
	 */
	public static function get_transient( string $key, $default = false ) {
		if ( ! function_exists( 'get_transient' ) ) {
			return $default;
		}

		$value = get_transient( $key );

		return $value !== false ? $value : $default;
	}

	/**
	 * Set WordPress transient with error handling
	 *
	 * @param string $key        Cache key
	 * @param mixed  $value      Value to cache
	 * @param int    $expiration Expiration time in seconds
	 *
	 * @return bool True if the value was set, false otherwise
	 */
	public static function set_transient( string $key, $value, int $expiration = 3600 ): bool {
		if ( ! function_exists( 'set_transient' ) ) {
			return false;
		}

		return set_transient( $key, $value, $expiration );
	}

	/**
	 * Delete WordPress transient with error handling
	 *
	 * @param string $key Cache key
	 *
	 * @return bool True if the transient was deleted, false otherwise
	 */
	public static function delete_transient( string $key ): bool {
		if ( ! function_exists( 'delete_transient' ) ) {
			return false;
		}

		return delete_transient( $key );
	}

	/**
	 * Delete all transients matching a prefix
	 *
	 * @param string $prefix Prefix to match
	 *
	 * @return bool True if transients were deleted, false otherwise
	 */
	public static function delete_transients_by_prefix( string $prefix ): bool {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! function_exists( '_get_using_prefix_where' ) ) {
			return false;
		}

		$sql = $wpdb->prepare(
			"DELETE FROM $wpdb->options WHERE option_name LIKE %s",
			$wpdb->esc_like( '_transient_' . $prefix ) . '%'
		);

		$result = $wpdb->query( $sql );

		// Also clear timeout entries
		$sql = $wpdb->prepare(
			"DELETE FROM $wpdb->options WHERE option_name LIKE %s",
			$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
		);

		$wpdb->query( $sql );

		return $result !== false;
	}

}