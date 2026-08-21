<?php
declare( strict_types=1 );

namespace ArrayPress\S3\Tests;

use ArrayPress\S3\Cache;
use PHPUnit\Framework\TestCase;

/**
 * The response cache.
 *
 * Invalidation works by bumping a generation counter folded into every key,
 * rather than by deleting rows. That is deliberate: on a site with a
 * persistent object cache, transients never reach wp_options, so a row sweep
 * clears nothing and the browser keeps serving a listing that no longer
 * matches the bucket. None of that was reachable from a test while this lived
 * in a trait on the client.
 */
final class CacheTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wp_test_options']          = [];
		$GLOBALS['wp_test_ext_object_cache'] = false;
	}

	public function test_stores_and_reads_back(): void {
		$cache = new Cache();
		$key   = $cache->key( 'objects', [ 'bucket' => 'b' ] );

		$cache->set( $key, [ 'a.txt' ] );

		$this->assertSame( [ 'a.txt' ], $cache->get( $key ) );
	}

	public function test_missing_entry_reads_false(): void {
		$this->assertFalse( ( new Cache() )->get( 'nothing-here' ) );
	}

	public function test_disabled_cache_neither_reads_nor_writes(): void {
		$cache = new Cache( false );
		$key   = $cache->key( 'objects' );

		$this->assertFalse( $cache->set( $key, 'value' ) );
		$this->assertFalse( $cache->get( $key ) );
		$this->assertFalse( $cache->is_enabled() );
	}

	public function test_different_parameters_are_different_entries(): void {
		$cache = new Cache();

		$this->assertNotSame(
			$cache->key( 'objects', [ 'prefix' => 'a/' ] ),
			$cache->key( 'objects', [ 'prefix' => 'b/' ] )
		);
	}

	public function test_same_call_yields_the_same_key(): void {
		$cache = new Cache();

		$this->assertSame(
			$cache->key( 'objects', [ 'prefix' => 'a/', 'max' => 100 ] ),
			$cache->key( 'objects', [ 'prefix' => 'a/', 'max' => 100 ] )
		);
	}

	public function test_forget_removes_one_entry(): void {
		$cache = new Cache();
		$key   = $cache->key( 'objects' );

		$cache->set( $key, 'value' );
		$cache->forget( $key );

		$this->assertFalse( $cache->get( $key ) );
	}

	// -- Invalidation ------------------------------------------------------

	public function test_flush_orphans_every_entry(): void {
		$cache = new Cache();
		$key   = $cache->key( 'objects', [ 'bucket' => 'b' ] );
		$cache->set( $key, 'value' );

		$cache->flush();

		// The old key is unreachable, and the new key for the same call is
		// a different string entirely.
		$this->assertNotSame( $key, $cache->key( 'objects', [ 'bucket' => 'b' ] ) );
		$this->assertFalse( $cache->get( $cache->key( 'objects', [ 'bucket' => 'b' ] ) ) );
	}

	/**
	 * The point of the per-bucket counter: deleting a file from one bucket
	 * must not throw away the listing of every other bucket.
	 */
	public function test_flushing_one_bucket_leaves_the_others(): void {
		$cache = new Cache();

		$kept    = $cache->key( 'objects', [ 'bucket' => 'untouched' ] );
		$dropped = $cache->key( 'objects', [ 'bucket' => 'changed' ] );

		$cache->set( $kept, 'still here' );
		$cache->set( $dropped, 'stale' );

		$cache->flush_bucket( 'changed' );

		$this->assertSame( 'still here', $cache->get( $cache->key( 'objects', [ 'bucket' => 'untouched' ] ) ) );
		$this->assertFalse( $cache->get( $cache->key( 'objects', [ 'bucket' => 'changed' ] ) ) );
	}

	public function test_bucket_is_taken_from_the_parameters_when_not_given(): void {
		$cache = new Cache();

		$this->assertSame(
			$cache->key( 'objects', [ 'bucket' => 'b' ] ),
			$cache->key( 'objects', [ 'bucket' => 'b' ], 'b' )
		);
	}

	public function test_flushing_an_unnamed_bucket_does_nothing(): void {
		$this->assertFalse( ( new Cache() )->flush_bucket( '' ) );
	}

	/**
	 * Invalidation must not depend on the row sweep. With a persistent object
	 * cache the sweep clears nothing, so the generation bump has to carry it
	 * on its own.
	 */
	public function test_flush_works_with_a_persistent_object_cache(): void {
		$GLOBALS['wp_test_ext_object_cache'] = true;

		$cache = new Cache();
		$key   = $cache->key( 'objects', [ 'bucket' => 'b' ] );
		$cache->set( $key, 'stale' );

		$cache->flush();

		$this->assertFalse( $cache->get( $cache->key( 'objects', [ 'bucket' => 'b' ] ) ) );
	}

	/**
	 * Two caches sharing a prefix share their counters, which is what lets a
	 * browser and a client built separately invalidate each other's entries.
	 */
	public function test_generations_are_shared_across_instances(): void {
		$writer = new Cache();
		$key    = $writer->key( 'objects', [ 'bucket' => 'b' ] );
		$writer->set( $key, 'value' );

		( new Cache() )->flush_bucket( 'b' );

		$this->assertFalse( $writer->get( $writer->key( 'objects', [ 'bucket' => 'b' ] ) ) );
	}

	public function test_distinct_prefixes_do_not_collide(): void {
		$a = new Cache( true, 3600, 's3_a_' );
		$b = new Cache( true, 3600, 's3_b_' );

		$a->set( $a->key( 'objects' ), 'from a' );
		$b->set( $b->key( 'objects' ), 'from b' );

		$this->assertSame( 'from a', $a->get( $a->key( 'objects' ) ) );
		$this->assertSame( 'from b', $b->get( $b->key( 'objects' ) ) );
	}
}
