<?php
declare( strict_types=1 );

namespace ArrayPress\S3\Tests;

use ArrayPress\S3\Providers\CloudflareR2;
use ArrayPress\S3\Providers\DigitalOceanSpaces;
use PHPUnit\Framework\TestCase;

/**
 * Canonical URI and request URL construction.
 *
 * Note: the domain-provenance tests that used to live here covered
 * is_provider_url() and parse_provider_url(), which have been removed —
 * nothing in this library or any consumer called them.
 */
final class ProviderUrlTest extends TestCase {

	private function r2(): CloudflareR2 {
		return new CloudflareR2( 'default', [ 'account_id' => 'abc123' ] );
	}

	/**
	 * Path-style keeps the bucket in the path; virtual-hosted must not repeat
	 * it there, because it already lives in the Host header.
	 */
	public function test_canonical_uri_path_style_includes_bucket(): void {
		$this->assertSame(
			'/my-bucket/a%20b.txt',
			$this->r2()->format_canonical_uri( 'my-bucket', 'a b.txt' )
		);
	}

	public function test_canonical_uri_virtual_hosted_omits_bucket(): void {
		$spaces = new DigitalOceanSpaces( 'ams3' );

		$this->assertSame( '/a%20b.txt', $spaces->format_canonical_uri( 'my-bucket', 'a b.txt' ) );
	}

	public function test_canonical_uri_for_a_bucket_without_a_key(): void {
		$this->assertSame( '/my-bucket', $this->r2()->format_canonical_uri( 'my-bucket', '' ) );
	}

	public function test_canonical_uri_for_service_level_operations(): void {
		$this->assertSame( '/', $this->r2()->format_canonical_uri( '', '' ) );
	}

	public function test_request_host_matches_addressing_style(): void {
		$this->assertSame( 'abc123.r2.cloudflarestorage.com', $this->r2()->get_request_host( 'my-bucket' ) );

		$spaces = new DigitalOceanSpaces( 'ams3' );
		$this->assertSame( 'my-bucket.ams3.digitaloceanspaces.com', $spaces->get_request_host( 'my-bucket' ) );
	}

	public function test_service_level_request_host_omits_the_bucket(): void {
		$spaces = new DigitalOceanSpaces( 'ams3' );

		$this->assertSame( 'ams3.digitaloceanspaces.com', $spaces->get_request_host( '' ) );
	}

	/**
	 * The signed canonical query is rawurlencode()'d, so the URL must be too.
	 * http_build_query()'s default renders a space as '+' and a tilde as '%7E'.
	 */
	public function test_query_string_uses_rfc3986_encoding(): void {
		$url = $this->r2()->build_url_with_query( 'b', '', [ 'prefix' => 'my photos/~2024/' ] );

		$this->assertStringContainsString( 'prefix=my%20photos%2F~2024%2F', $url );
		$this->assertStringNotContainsString( '+', $url );
		$this->assertStringNotContainsString( '%7E', $url );
	}

	public function test_format_url_encodes_the_object_key(): void {
		$this->assertSame(
			'https://abc123.r2.cloudflarestorage.com/b/a%20b.txt',
			$this->r2()->format_url( 'b', 'a b.txt' )
		);
	}
}
