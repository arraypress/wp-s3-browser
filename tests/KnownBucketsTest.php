<?php
declare( strict_types=1 );

namespace ArrayPress\S3\Tests;

use ArrayPress\S3\Traits\Client\Buckets;
use PHPUnit\Framework\TestCase;

/**
 * Host exposing the client's known-bucket list.
 */
final class KnownBucketsHost {
	use Buckets;

	public function known(): array {
		return $this->known_buckets;
	}
}

/**
 * Buckets known without listing them.
 *
 * A bucket-scoped token — Cloudflare's recommendation for R2 — cannot call
 * ListBuckets, so the browser can only show a bucket if it is told the name.
 * This list is held per client rather than in a global filter, because two
 * Browser instances on one site would otherwise overwrite each other.
 */
final class KnownBucketsTest extends TestCase {

	public function test_names_are_stored(): void {
		$host = new KnownBucketsHost();
		$host->set_known_buckets( [ 'store-downloads' ] );

		$this->assertSame( [ 'store-downloads' ], $host->known() );
	}

	public function test_blank_and_duplicate_names_are_discarded(): void {
		$host = new KnownBucketsHost();
		$host->set_known_buckets( [ 'a', '', 'a', 'b', '   ' => '' ] );

		$this->assertSame( [ 'a', 'b' ], array_values( $host->known() ) );
	}

	public function test_defaults_to_empty(): void {
		$this->assertSame( [], ( new KnownBucketsHost() )->known() );
	}

	/**
	 * Two clients must not share the list, which is exactly what a global
	 * filter would have caused.
	 */
	public function test_lists_are_per_instance(): void {
		$edd = ( new KnownBucketsHost() );
		$edd->set_known_buckets( [ 'edd-downloads' ] );

		$woo = ( new KnownBucketsHost() );
		$woo->set_known_buckets( [ 'woo-downloads' ] );

		$this->assertSame( [ 'edd-downloads' ], $edd->known() );
		$this->assertSame( [ 'woo-downloads' ], $woo->known() );
	}
}
