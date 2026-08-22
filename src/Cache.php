<?php
/**
 * Client Cache
 *
 * Transient-backed cache for S3 responses, keyed so that a whole bucket -- or
 * everything -- can be invalidated in one write.
 *
 * @package     ArrayPress\S3
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3;

/**
 * Class Cache
 */
class Cache {

	/**
	 * Build a cache.
	 *
	 * @param bool   $enabled Whether to read or write anything at all.
	 * @param int    $ttl     Lifetime of an entry, in seconds.
	 * @param string $prefix  Prefix for every key and counter this owns.
	 */
	public function __construct(
		private bool $enabled = true,
		private int $ttl = 3600,
		private string $prefix = 's3_'
	) {
	}

	/**
	 * Whether this cache reads or writes anything.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->enabled;
	}

	/**
	 * Build a key for an operation.
	 *
	 * The key folds in two monotonic generation counters -- one global, one
	 * per bucket. Bumping a counter makes every key derived from it
	 * unreachable at once, which is how invalidation works here. That matters
	 * because deleting matching rows from wp_options is a no-op on any site
	 * running a persistent object cache, where transients never reach the
	 * options table at all.
	 *
	 * @param string $base   Operation name.
	 * @param array  $params Parameters that distinguish this call.
	 * @param string $bucket Bucket this entry belongs to, so it can be
	 *                       invalidated independently of the rest.
	 *
	 * @return string Cache key.
	 */
	public function key( string $base, array $params = [], string $bucket = '' ): string {
		// Most call sites already name the bucket inside $params; honour that
		// so they get per-bucket invalidation without repeating it.
		if ( '' === $bucket && is_string( $params['bucket'] ?? null ) ) {
			$bucket = $params['bucket'];
		}

		$generation = $this->generation() . ':' . $this->generation( $bucket );

		return $this->prefix . md5( $generation . '|' . $base . '|' . serialize( $params ) );
	}

	/**
	 * Read an entry.
	 *
	 * @param string $key Cache key.
	 *
	 * @return mixed|false Entry, or false when absent or caching is off.
	 */
	public function get( string $key ) {
		if ( ! $this->enabled ) {
			return false;
		}

		$value = get_transient( $key );

		// Two plugins can each ship their own Strauss-prefixed copy of this
		// library and run side by side. Should they ever share a key, one copy
		// reads back an object built from the other's classes -- which
		// satisfies no type declaration here and takes the request down with a
		// TypeError, from what looks like a plain cache hit. Prefixing keys per
		// build (see Client) keeps them apart; this makes a collision degrade
		// into a miss rather than a fatal.
		if ( is_object( $value ) && ! self::is_own_class( $value ) ) {
			return false;
		}

		return $value;
	}

	/**
	 * Whether an object comes from this build of the library.
	 *
	 * @param object $value Cached object.
	 *
	 * @return bool
	 */
	private static function is_own_class( object $value ): bool {
		return strtok( get_class( $value ), '\\' ) === strtok( __NAMESPACE__, '\\' );
	}

	/**
	 * Write an entry.
	 *
	 * @param string   $key   Cache key.
	 * @param mixed    $value Value to store.
	 * @param int|null $ttl   Lifetime override, in seconds.
	 *
	 * @return bool Whether it was stored.
	 */
	public function set( string $key, $value, ?int $ttl = null ): bool {
		return $this->enabled && set_transient( $key, $value, $ttl ?? $this->ttl );
	}

	/**
	 * Drop one entry.
	 *
	 * @param string $key Cache key.
	 *
	 * @return bool Whether it was removed.
	 */
	public function forget( string $key ): bool {
		return delete_transient( $key );
	}

	/**
	 * Invalidate everything this cache owns.
	 *
	 * @return bool Whether the generation was bumped.
	 */
	public function flush(): bool {
		// The authoritative step: every existing key derives from the old
		// generation, so bumping it invalidates the lot -- including on sites
		// with a persistent object cache, where the row sweep is a no-op.
		$bumped = $this->bump();

		// Best-effort tidy-up so orphaned rows do not sit in wp_options until
		// their TTL lapses. Failure is not fatal; the bump did the real work.
		$this->sweep_rows();

		return $bumped;
	}

	/**
	 * Invalidate everything cached for one bucket.
	 *
	 * @param string $bucket Bucket name.
	 *
	 * @return bool Whether the generation was bumped.
	 */
	public function flush_bucket( string $bucket ): bool {
		return '' !== $bucket && $this->bump( $bucket );
	}

	/**
	 * Name of the option holding a generation counter.
	 *
	 * @param string $bucket Bucket name, or '' for the global counter.
	 *
	 * @return string
	 */
	private function generation_option( string $bucket = '' ): string {
		return $this->prefix . 'cache_gen_' . ( '' === $bucket ? 'global' : md5( $bucket ) );
	}

	/**
	 * Read a generation counter.
	 *
	 * @param string $bucket Bucket name, or '' for the global counter.
	 *
	 * @return int
	 */
	private function generation( string $bucket = '' ): int {
		return (int) get_option( $this->generation_option( $bucket ), 0 );
	}

	/**
	 * Bump a generation counter, orphaning every key derived from it.
	 *
	 * @param string $bucket Bucket name, or '' for the global counter.
	 *
	 * @return bool
	 */
	private function bump( string $bucket = '' ): bool {
		// autoload=false: these are read on demand, not on every page load.
		return update_option( $this->generation_option( $bucket ), $this->generation( $bucket ) + 1, false );
	}

	/**
	 * Remove this cache's transient rows from the options table.
	 *
	 * Only meaningful when transients live there. Reports false rather than
	 * pretending to succeed when an external object cache is in play, since
	 * nothing was removed.
	 *
	 * @return bool Whether rows were swept.
	 */
	private function sweep_rows(): bool {
		global $wpdb;

		if ( empty( $wpdb ) || wp_using_ext_object_cache() ) {
			return false;
		}

		foreach ( [ '_transient_', '_transient_timeout_' ] as $row_prefix ) {
			// A direct query is the point of this method: it removes orphaned
			// transient rows, which no cache API can do by prefix. The guard
			// above already returns early when an object cache is in play, so
			// this only runs where the rows genuinely exist. The query is
			// prepared and the LIKE value escaped.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM $wpdb->options WHERE option_name LIKE %s",
				$wpdb->esc_like( $row_prefix . $this->prefix ) . '%'
			) );
		}

		return true;
	}
}
