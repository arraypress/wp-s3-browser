<?php
/**
 * Caching Trait - Simplified
 *
 * Provides essential caching functionality for S3 operations.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      ArrayPress Team
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Client;

/**
 * Trait Caching
 */
trait Caching {

	/**
	 * Cache enabled flag
	 *
	 * @var bool
	 */
	private bool $cache_enabled = true;

	/**
	 * Cache TTL in seconds
	 *
	 * @var int
	 */
	private int $cache_ttl = 3600; // One-hour default

	/**
	 * Cache prefix
	 *
	 * @var string
	 */
	private string $cache_prefix = 's3_';

	/**
	 * Initialize cache settings
	 *
	 * @param bool $enabled Whether cache is enabled
	 * @param int  $ttl     Cache TTL in seconds
	 */
	protected function init_cache( bool $enabled = true, int $ttl = 3600 ): void {
		$this->cache_enabled = $enabled;
		$this->cache_ttl     = $ttl;
	}

	/**
	 * Check if cache is enabled
	 *
	 * @return bool
	 */
	public function is_cache_enabled(): bool {
		return $this->cache_enabled;
	}

	/**
	 * Set cache enabled/disabled
	 *
	 * @param bool $enabled Whether to enable cache
	 *
	 * @return self
	 */
	public function set_cache_enabled( bool $enabled ): self {
		$this->cache_enabled = $enabled;

		return $this;
	}

	/**
	 * Generate cache key
	 *
	 * @param string $base   Base key
	 * @param array  $params Additional parameters to include in key
	 *
	 * @return string Cache key
	 */
	public function get_cache_key( string $base, array $params = [] ): string {
		return $this->cache_prefix . md5( $base . '_' . serialize( $params ) );
	}

	/**
	 * Get data from cache
	 *
	 * @param string $key Cache key
	 *
	 * @return mixed|false Cached data or false if not found
	 */
	protected function get_from_cache( string $key ) {
		if ( ! $this->cache_enabled ) {
			return false;
		}

		return get_transient( $key );
	}

	/**
	 * Save data to cache
	 *
	 * @param string $key  Cache key
	 * @param mixed  $data Data to cache
	 *
	 * @return bool Whether the data was saved
	 */
	protected function save_to_cache( string $key, $data ): bool {
		if ( ! $this->cache_enabled ) {
			return false;
		}

		return set_transient( $key, $data, $this->cache_ttl );
	}

	/**
	 * Clear specific cache item
	 *
	 * @param string $key Cache key
	 *
	 * @return bool Whether the cache key was deleted
	 */
	public function clear_cache_item( string $key ): bool {
		return delete_transient( $key );
	}

	/**
	 * Clear all cache for this S3 client
	 *
	 * @return bool Whether the operation was successful
	 */
	public function clear_all_cache(): bool {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! $wpdb ) {
			return false;
		}

		$pattern = $wpdb->esc_like( '_transient_' . $this->cache_prefix ) . '%';

		$sql = $wpdb->prepare(
			"DELETE FROM $wpdb->options WHERE option_name LIKE %s",
			$pattern
		);

		$result = $wpdb->query( $sql );

		// Also clear timeout entries
		$timeout_pattern = $wpdb->esc_like( '_transient_timeout_' . $this->cache_prefix ) . '%';

		$wpdb->query( $wpdb->prepare(
			"DELETE FROM $wpdb->options WHERE option_name LIKE %s",
			$timeout_pattern
		) );

		return $result !== false;
	}

	/**
	 * Clear cache for a specific bucket
	 *
	 * @param string $bucket Bucket name
	 *
	 * @return bool Whether the operation was successful
	 */
	public function clear_bucket_cache( string $bucket ): bool {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! $wpdb ) {
			return false;
		}

		$prefix  = $this->cache_prefix . md5( 'objects_' . $bucket );
		$pattern = $wpdb->esc_like( '_transient_' . $prefix ) . '%';

		$sql = $wpdb->prepare(
			"DELETE FROM $wpdb->options WHERE option_name LIKE %s",
			$pattern
		);

		$result = $wpdb->query( $sql );

		// Also clear timeout entries
		$timeout_pattern = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';

		$wpdb->query( $wpdb->prepare(
			"DELETE FROM $wpdb->options WHERE option_name LIKE %s",
			$timeout_pattern
		) );

		return $result !== false;
	}

}