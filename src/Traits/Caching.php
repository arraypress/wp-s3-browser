<?php
/**
 * Caching Trait
 *
 * Provides caching functionality for S3 operations.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      ArrayPress Team
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits;

/**
 * Trait CachingTrait
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
	private int $cache_ttl = 86400; // DAY_IN_SECONDS

	/**
	 * Cache prefix
	 *
	 * @var string
	 */
	private string $cache_prefix = 's3_';

	/**
	 * Cache backend handler
	 *
	 * @var callable|null
	 */
	private $cache_handler = null;

	/**
	 * Initialize cache settings
	 *
	 * @param bool $enabled Whether cache is enabled
	 * @param int  $ttl     Cache TTL in seconds
	 */
	protected function init_cache( bool $enabled = true, int $ttl = 86400 ): void {
		$this->cache_enabled = $enabled;
		$this->cache_ttl     = $ttl;
	}

	/**
	 * Set custom cache handler
	 *
	 * @param callable $handler Custom cache handler with get/set/delete methods
	 *
	 * @return self
	 */
	public function set_cache_handler( callable $handler ): self {
		$this->cache_handler = $handler;

		return $this;
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
	 * Get cache TTL
	 *
	 * @return int
	 */
	public function get_cache_ttl(): int {
		return $this->cache_ttl;
	}

	/**
	 * Set cache TTL
	 *
	 * @param int $ttl TTL in seconds
	 *
	 * @return self
	 */
	public function set_cache_ttl( int $ttl ): self {
		$this->cache_ttl = max( 0, $ttl );

		return $this;
	}

	/**
	 * Set cache prefix
	 *
	 * @param string $prefix Cache key prefix
	 *
	 * @return self
	 */
	public function set_cache_prefix( string $prefix ): self {
		$this->cache_prefix = $prefix;

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
	protected function get_cache_key( string $base, array $params = [] ): string {
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
		// Use custom cache handler if set
		if ( is_callable( $this->cache_handler ) ) {
			return call_user_func( $this->cache_handler, 'get', $key );
		}

		// Default to WordPress transients if available
		if ( function_exists( 'get_transient' ) ) {
			return get_transient( $key );
		}

		// No caching available
		return false;
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
		// Use custom cache handler if set
		if ( is_callable( $this->cache_handler ) ) {
			return (bool) call_user_func( $this->cache_handler, 'set', $key, $data, $this->cache_ttl );
		}

		// Default to WordPress transients if available
		if ( function_exists( 'set_transient' ) ) {
			return set_transient( $key, $data, $this->cache_ttl );
		}

		// No caching available
		return false;
	}

	/**
	 * Clear specific cache item
	 *
	 * @param string $key Cache key
	 *
	 * @return bool Whether the cache key was deleted
	 */
	public function clear_cache_item( string $key ): bool {
		// Use custom cache handler if set
		if ( is_callable( $this->cache_handler ) ) {
			return (bool) call_user_func( $this->cache_handler, 'delete', $key );
		}

		// Default to WordPress transients if available
		if ( function_exists( 'delete_transient' ) ) {
			return delete_transient( $key );
		}

		// No caching available
		return false;
	}

	/**
	 * Clear all cache for the client
	 *
	 * @return bool Whether the operation was successful
	 */
	public function clear_all_cache(): bool {
		// Use custom cache handler if set
		if ( is_callable( $this->cache_handler ) ) {
			return (bool) call_user_func( $this->cache_handler, 'flush', $this->cache_prefix );
		}

		// Default to WordPress transients if available
		if ( function_exists( 'delete_transient' ) && function_exists( '_get_using_prefix_where' ) ) {
			global $wpdb;

			if ( isset( $wpdb ) && $wpdb ) {
				$sql = $wpdb->prepare(
					"DELETE FROM $wpdb->options WHERE option_name LIKE %s",
					_get_using_prefix_where( $wpdb->esc_like( '_transient_' . $this->cache_prefix ) )
				);

				$result = $wpdb->query( $sql );

				// Also clear timeout entries
				$sql = $wpdb->prepare(
					"DELETE FROM $wpdb->options WHERE option_name LIKE %s",
					_get_using_prefix_where( $wpdb->esc_like( '_transient_timeout_' . $this->cache_prefix ) )
				);

				$wpdb->query( $sql );

				return $result !== false;
			}
		}

		// No caching available
		return false;
	}

	/**
	 * Clear cache for a specific bucket
	 *
	 * @param string $bucket Bucket name
	 *
	 * @return bool Whether the operation was successful
	 */
	public function clear_bucket_cache( string $bucket ): bool {
		// Use custom cache handler if set
		if ( is_callable( $this->cache_handler ) ) {
			$prefix = $this->cache_prefix . md5( 'objects_' . $bucket );

			return (bool) call_user_func( $this->cache_handler, 'flush', $prefix );
		}

		// Default to WordPress transients if available
		if ( function_exists( 'delete_transient' ) && function_exists( '_get_using_prefix_where' ) ) {
			global $wpdb;

			if ( isset( $wpdb ) && $wpdb ) {
				$bucket_prefix = $wpdb->esc_like( '_transient_' . $this->cache_prefix . md5( 'objects_' . $bucket ) );

				$sql = $wpdb->prepare(
					"DELETE FROM $wpdb->options WHERE option_name LIKE %s",
					$bucket_prefix . '%'
				);

				$result = $wpdb->query( $sql );

				// Also clear timeout entries
				$sql = $wpdb->prepare(
					"DELETE FROM $wpdb->options WHERE option_name LIKE %s",
					$wpdb->esc_like( '_transient_timeout_' . $this->cache_prefix . md5( 'objects_' . $bucket ) ) . '%'
				);

				$wpdb->query( $sql );

				return $result !== false;
			}
		}

		// No caching available
		return false;
	}

	/**
	 * Check if the item exists in cache
	 *
	 * @param string $key Cache key
	 *
	 * @return bool Whether the cache key exists
	 */
	protected function cache_has( string $key ): bool {
		// Use custom cache handler if set
		if ( is_callable( $this->cache_handler ) ) {
			return (bool) call_user_func( $this->cache_handler, 'has', $key );
		}

		// For WordPress, get_transient returns false if not found
		// so we need to do a direct check in the database
		if ( function_exists( 'get_transient' ) && function_exists( '_get_using_prefix_where' ) ) {
			global $wpdb;

			if ( isset( $wpdb ) && $wpdb ) {
				$option_name = '_transient_' . $key;

				$result = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT option_name FROM $wpdb->options WHERE option_name = %s LIMIT 1",
						$option_name
					)
				);

				return ! empty( $result );
			}
		}

		// No caching available or no way to check existence
		return false;
	}

}