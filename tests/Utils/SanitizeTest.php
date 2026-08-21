<?php
declare( strict_types=1 );

namespace ArrayPress\S3\Tests\Utils;

use ArrayPress\S3\Utils\Sanitize;
use PHPUnit\Framework\TestCase;

/**
 * Slugging a Strauss prefix into a REST namespace.
 *
 * The result is a public URL the browser's JavaScript calls. If it changes,
 * the routes move out from under the interface, so the mapping has to be
 * stable and has to stay distinct between two plugins bundling this library.
 */
final class SanitizeTest extends TestCase {

	public function test_prefix_becomes_a_lowercase_slug(): void {
		$this->assertSame( 'eddr2', Sanitize::slug( 'EDDR2' ) );
		$this->assertSame( 'wcr2', Sanitize::slug( 'WCR2' ) );
	}

	public function test_separators_collapse_to_one_hyphen(): void {
		$this->assertSame( 'my-plugin', Sanitize::slug( 'My_Plugin' ) );
		$this->assertSame( 'my-plugin', Sanitize::slug( 'My   Plugin' ) );
		$this->assertSame( 'my-plugin', Sanitize::slug( 'My\\\\Plugin' ) );
	}

	public function test_leading_and_trailing_separators_are_dropped(): void {
		$this->assertSame( 'plugin', Sanitize::slug( '__Plugin__' ) );
		$this->assertSame( 'plugin', Sanitize::slug( '-Plugin-' ) );
	}

	public function test_nothing_usable_yields_an_empty_slug(): void {
		$this->assertSame( '', Sanitize::slug( '___' ) );
		$this->assertSame( '', Sanitize::slug( '' ) );
	}

	/**
	 * Two prefixes that differ only in punctuation must not collapse onto the
	 * same namespace -- that is exactly the collision the derivation exists to
	 * prevent.
	 */
	public function test_distinct_prefixes_stay_distinct(): void {
		$this->assertNotSame( Sanitize::slug( 'EDDR2' ), Sanitize::slug( 'WCR2' ) );
	}

	public function test_minutes_are_clamped_to_the_presign_window(): void {
		$this->assertSame( 1, Sanitize::minutes( 0 ) );
		$this->assertSame( 1, Sanitize::minutes( -30 ) );
		$this->assertSame( 10080, Sanitize::minutes( 99999 ) );
		$this->assertSame( 60, Sanitize::minutes( 60 ) );
	}
}
