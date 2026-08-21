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
 * @author      David Sherlock
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
	 * The key folds in two monotonic "generation" counters — one global, one
	 * per bucket. Bumping a counter makes every key derived from it
	 * unreachable at once, which is how invalidation works here. That matters
	 * because the previous approach (deleting matching rows from wp_options)
	 * is a no-op on any site running a persistent object cache, where
	 * transients never touch the options table at all.
	 *
	 * @param string $base   Base key
	 * @param array  $params Additional parameters to include in key
	 * @param string $bucket Optional bucket this entry belongs to, so it can be
	 *                       invalidated independently of the rest
	 *
	 * @return string Cache key
	 */
	public function get_cache_key( string $base, array $params = [], string $bucket = '' ): string {
		// Most call sites already identify the bucket inside $params; honour that
		// so they get per-bucket invalidation without having to repeat it.
		if ( '' === $bucket && isset( $params['bucket'] ) && is_string( $params['bucket'] ) ) {
			$bucket = $params['bucket'];
		}

		$generation = $this->get_cache_generation( '' ) . ':' . $this->get_cache_generation( $bucket );

		return $this->cache_prefix . md5( $generation . '|' . $base . '|' . serialize( $params ) );
	}

	/**
	 * Get the option name holding a cache generation counter
	 *
	 * @param string $bucket Bucket name, or '' for the global counter
	 *
	 * @return string
	 */
	private function get_cache_generation_option( string $bucket = '' ): string {
		return $this->cache_prefix . 'cache_gen_' . ( '' === $bucket ? 'global' : md5( $bucket ) );
	}

	/**
	 * Read a cache generation counter
	 *
	 * @param string $bucket Bucket name, or '' for the global counter
	 *
	 * @return int
	 */
	private function get_cache_generation( string $bucket = '' ): int {
		return (int) get_option( $this->get_cache_generation_option( $bucket ), 0 );
	}

	/**
	 * Bump a cache generation counter, orphaning every key derived from it
	 *
	 * @param string $bucket Bucket name, or '' for the global counter
	 *
	 * @return bool
	 */
	private function bump_cache_generation( string $bucket = '' ): bool {
		$option = $this->get_cache_generation_option( $bucket );

		// autoload=false: these are read on demand, not on every page load.
		return update_option( $option, $this->get_cache_generation( $bucket ) + 1, false );
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
		// Authoritative step: every existing key is derived from the old
		// generation, so bumping it invalidates the lot — including on sites
		// with a persistent object cache, where the row sweep below is a no-op.
		$bumped = $this->bump_cache_generation();

		// Best-effort tidy-up so orphaned rows don't sit in wp_options until
		// their TTL lapses. Failure here is not fatal; the bump already did
		// the real work.
		$this->delete_transient_rows( $this->cache_prefix );

		return $bumped;
	}

	/**
	 * Delete transient rows matching a key prefix
	 *
	 * Only meaningful when transients live in the options table. Returns false
	 * (rather than pretending to succeed) when an external object cache is in
	 * play, since nothing was actually removed there.
	 *
	 * @param string $key_prefix Cache key prefix to match
	 *
	 * @return bool Whether rows were swept
	 */
	private function delete_transient_rows( string $key_prefix ): bool {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! $wpdb || wp_using_ext_object_cache() ) {
			return false;
		}

		foreach ( [ '_transient_', '_transient_timeout_' ] as $row_prefix ) {
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM $wpdb->options WHERE option_name LIKE %s",
				$wpdb->esc_like( $row_prefix . $key_prefix ) . '%'
			) );
		}

		return true;
	}

	/**
	 * Clear cache for a specific bucket
	 *
	 * @param string $bucket Bucket name
	 *
	 * @return bool Whether the operation was successful
	 */
	public function clear_bucket_cache( string $bucket ): bool {
		if ( '' === $bucket ) {
			return false;
		}

		return $this->bump_cache_generation( $bucket );
	}

}
