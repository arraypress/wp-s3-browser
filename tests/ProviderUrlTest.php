<?php
declare( strict_types=1 );

namespace ArrayPress\S3\Tests;

use ArrayPress\S3\Providers\CloudflareR2;
use ArrayPress\S3\Providers\DigitalOceanSpaces;
use PHPUnit\Framework\TestCase;

/**
 * URL provenance and canonical URI construction.
 *
 * is_provider_url() is documented as a security check on URLs from external
 * sources, so a look-alike host must not pass it.
 */
final class ProviderUrlTest extends TestCase {

	private function r2(): CloudflareR2 {
		$provider = new CloudflareR2( 'default', [ 'account_id' => 'abc123' ] );
		$provider->set_custom_domain( 'my-bucket', 'cdn.example.com' );

		return $provider;
	}

	public function test_accepts_path_style_endpoint_url(): void {
		$this->assertTrue( $this->r2()->is_provider_url( 'https://abc123.r2.cloudflarestorage.com/b/f.zip' ) );
	}

	public function test_accepts_virtual_hosted_url(): void {
		$this->assertTrue( $this->r2()->is_provider_url( 'https://b.abc123.r2.cloudflarestorage.com/f.zip' ) );
	}

	public function test_accepts_configured_custom_domain(): void {
		$this->assertTrue( $this->r2()->is_provider_url( 'https://cdn.example.com/f.zip' ) );
	}

	/**
	 * A prefix match accepted these. The match must land on a label boundary.
	 */
	public function test_rejects_endpoint_used_as_a_domain_prefix(): void {
		$this->assertFalse(
			$this->r2()->is_provider_url( 'https://abc123.r2.cloudflarestorage.com.attacker.example/f.zip' )
		);
	}

	public function test_rejects_custom_domain_used_as_a_domain_prefix(): void {
		$this->assertFalse( $this->r2()->is_provider_url( 'https://cdn.example.com.attacker.example/f.zip' ) );
	}

	public function test_rejects_endpoint_appearing_in_path_or_query(): void {
		$r2 = $this->r2();
		$this->assertFalse( $r2->is_provider_url( 'https://attacker.example/abc123.r2.cloudflarestorage.com/f.zip' ) );
		$this->assertFalse( $r2->is_provider_url( 'https://attacker.example/?x=abc123.r2.cloudflarestorage.com' ) );
	}

	public function test_rejects_userinfo_spoofing(): void {
		$this->assertFalse(
			$this->r2()->is_provider_url( 'https://abc123.r2.cloudflarestorage.com@attacker.example/f.zip' )
		);
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

	public function test_request_host_matches_addressing_style(): void {
		$this->assertSame( 'abc123.r2.cloudflarestorage.com', $this->r2()->get_request_host( 'my-bucket' ) );

		$spaces = new DigitalOceanSpaces( 'ams3' );
		$this->assertSame( 'my-bucket.ams3.digitaloceanspaces.com', $spaces->get_request_host( 'my-bucket' ) );
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
}
