<?php
declare( strict_types=1 );

namespace ArrayPress\S3\Tests\Cors;

use ArrayPress\S3\Cors\Origin;
use PHPUnit\Framework\TestCase;

/**
 * Working out the origin to allow.
 *
 * This value is written into the bucket's CORS configuration and stays there,
 * so where it is read from matters more than it looks.
 */
final class OriginTest extends TestCase {

	protected function setUp(): void {
		unset( $GLOBALS['wp_test_home_url'], $GLOBALS['wp_test_is_ssl'] );
		$_SERVER['HTTP_HOST'] = 'attacker.example';
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'] );
	}

	/**
	 * The Host header is client-supplied. It used to decide this, which meant
	 * a forged header on the CORS-setup request would persist someone else's
	 * origin as one the bucket accepts browser uploads from.
	 */
	public function test_the_host_header_is_ignored(): void {
		$GLOBALS['wp_test_home_url'] = 'https://shop.example';

		$origin = Origin::current();

		$this->assertSame( 'https://shop.example', $origin );
		$this->assertStringNotContainsString( 'attacker', $origin );
	}

	public function test_the_scheme_comes_from_the_site_url(): void {
		$GLOBALS['wp_test_home_url'] = 'http://insecure.example';

		$this->assertSame( 'http://insecure.example', Origin::current() );
	}

	/**
	 * A browser treats https://example.com and https://example.com:8443 as
	 * different origins, so a rule naming one does not cover the other.
	 */
	public function test_a_non_default_port_is_part_of_the_origin(): void {
		$GLOBALS['wp_test_home_url'] = 'https://shop.example:8443';

		$this->assertSame( 'https://shop.example:8443', Origin::current() );
	}

	/**
	 * An origin is scheme, host and port only -- a path in it makes the rule
	 * match nothing.
	 */
	public function test_a_site_in_a_subdirectory_yields_no_path(): void {
		$GLOBALS['wp_test_home_url'] = 'https://example.com/shop';

		$this->assertSame( 'https://example.com', Origin::current() );
	}

	public function test_an_unusable_site_url_yields_nothing(): void {
		$GLOBALS['wp_test_home_url'] = '';

		$this->assertSame( '', Origin::current() );
	}
}
