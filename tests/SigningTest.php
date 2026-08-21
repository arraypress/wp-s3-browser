<?php
declare( strict_types=1 );

namespace ArrayPress\S3\Tests;

use ArrayPress\S3\Providers\CloudflareR2;
use ArrayPress\S3\Providers\DigitalOceanSpaces;
use ArrayPress\S3\Signer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AWS Signature Version 4 correctness.
 *
 * Rather than assert golden strings — which only ever prove the implementation
 * still agrees with itself — each test recomputes the expected signature from
 * the SigV4 specification independently, then compares. That is an oracle: it
 * catches the class of bug where the canonical request stops describing the
 * request actually sent, which is exactly how presigned URLs came to fail on
 * every virtual-hosted provider.
 */
final class SigningTest extends TestCase {

	private const ACCESS_KEY = 'AKIAIOSFODNN7EXAMPLE';
	private const SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

	/**
	 * Derive a SigV4 signature straight from the spec.
	 *
	 * @param string $canonical_request Canonical request.
	 * @param string $amz_date          ISO basic timestamp.
	 * @param string $region            Region.
	 *
	 * @return string
	 */
	private function expected_signature( string $canonical_request, string $amz_date, string $region ): string {
		$date  = substr( $amz_date, 0, 8 );
		$scope = $date . '/' . $region . '/s3/aws4_request';

		$string_to_sign = "AWS4-HMAC-SHA256\n" . $amz_date . "\n" . $scope . "\n"
		                  . hash( 'sha256', $canonical_request );

		$k = hash_hmac( 'sha256', $date, 'AWS4' . self::SECRET_KEY, true );
		$k = hash_hmac( 'sha256', $region, $k, true );
		$k = hash_hmac( 'sha256', 's3', $k, true );
		$k = hash_hmac( 'sha256', 'aws4_request', $k, true );

		return hash_hmac( 'sha256', $string_to_sign, $k );
	}

	/**
	 * Pull the query parameters out of a presigned URL, preserving encoding.
	 *
	 * @param string $url Presigned URL.
	 *
	 * @return array{host: string, path: string, params: array<string,string>}
	 */
	private function dissect( string $url ): array {
		$parts  = parse_url( $url );
		$params = [];

		foreach ( explode( '&', $parts['query'] ?? '' ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			[ $k, $v ] = array_pad( explode( '=', $pair, 2 ), 2, '' );
			$params[ rawurldecode( $k ) ] = $v;
		}

		return [
			'host'   => $parts['host'] ?? '',
			'path'   => $parts['path'] ?? '',
			'params' => $params,
		];
	}

	/**
	 * Rebuild the canonical query string from a presigned URL, minus the
	 * signature itself.
	 *
	 * @param array<string,string> $params Raw (still-encoded) parameters.
	 *
	 * @return string
	 */
	private function canonical_query( array $params ): string {
		unset( $params['X-Amz-Signature'] );
		ksort( $params );

		$pairs = [];
		foreach ( $params as $key => $value ) {
			$pairs[] = rawurlencode( $key ) . '=' . $value;
		}

		return implode( '&', $pairs );
	}

	#[DataProvider( 'presign_cases' )]
	public function test_presigned_url_signature_matches_spec(
		string $provider_class,
		array $provider_args,
		string $region,
		string $bucket,
		string $key,
		string $expected_host,
		string $expected_path
	): void {
		$provider = new $provider_class( ...$provider_args );
		$signer   = new Signer( $provider, self::ACCESS_KEY, self::SECRET_KEY );

		$url = $signer->get_presigned_url( $bucket, $key, 60 )->get_url();
		$bits = $this->dissect( $url );

		// The host and path must reflect the provider's addressing style.
		$this->assertSame( $expected_host, $bits['host'], 'presigned URL host' );
		$this->assertSame( $expected_path, $bits['path'], 'presigned URL path' );

		$canonical_request = "GET\n"
		                     . $bits['path'] . "\n"
		                     . $this->canonical_query( $bits['params'] ) . "\n"
		                     . 'host:' . $bits['host'] . "\n"
		                     . "\n"
		                     . "host\n"
		                     . 'UNSIGNED-PAYLOAD';

		$this->assertSame(
			$this->expected_signature( $canonical_request, rawurldecode( $bits['params']['X-Amz-Date'] ), $region ),
			$bits['params']['X-Amz-Signature'],
			'signature must cover the URL actually produced'
		);
	}

	public static function presign_cases(): array {
		return [
			'R2 path-style' => [
				CloudflareR2::class,
				[ 'default', [ 'account_id' => 'abc123' ] ],
				'auto',
				'my-bucket',
				'files/song.wav',
				'abc123.r2.cloudflarestorage.com',
				'/my-bucket/files/song.wav',
			],
			'R2 path-style, key needing encoding' => [
				CloudflareR2::class,
				[ 'default', [ 'account_id' => 'abc123' ] ],
				'auto',
				'my-bucket',
				'Drum Kits/Vol 2.zip',
				'abc123.r2.cloudflarestorage.com',
				'/my-bucket/Drum%20Kits/Vol%202.zip',
			],
			// Bucket belongs in the host, NOT repeated in the path.
			'Spaces virtual-hosted' => [
				DigitalOceanSpaces::class,
				[ 'ams3' ],
				'ams3',
				'my-bucket',
				'files/song.wav',
				'my-bucket.ams3.digitaloceanspaces.com',
				'/files/song.wav',
			],
		];
	}

	/**
	 * Header-authenticated requests must sign the host they are sent to.
	 */
	public function test_auth_headers_sign_the_addressed_host(): void {
		$spaces  = new DigitalOceanSpaces( 'ams3' );
		$signer  = new Signer( $spaces, self::ACCESS_KEY, self::SECRET_KEY );
		$headers = $signer->generate_auth_headers( 'DELETE', 'my-bucket', 'files/song.wav' );

		$this->assertSame( 'my-bucket.ams3.digitaloceanspaces.com', $headers['Host'] );

		$canonical_request = "DELETE\n"
		                     . "/files/song.wav\n"
		                     . "\n"
		                     . 'host:' . $headers['Host'] . "\n"
		                     . 'x-amz-content-sha256:' . $headers['X-Amz-Content-SHA256'] . "\n"
		                     . 'x-amz-date:' . $headers['X-Amz-Date'] . "\n"
		                     . "\n"
		                     . "host;x-amz-content-sha256;x-amz-date\n"
		                     . $headers['X-Amz-Content-SHA256'];

		$this->assertStringContainsString(
			'Signature=' . $this->expected_signature( $canonical_request, $headers['X-Amz-Date'], 'ams3' ),
			$headers['Authorization']
		);
	}

	public function test_empty_payload_hash_is_sha256_of_empty_string(): void {
		$provider = new CloudflareR2( 'default', [ 'account_id' => 'abc123' ] );
		$headers  = ( new Signer( $provider, self::ACCESS_KEY, self::SECRET_KEY ) )
			->generate_auth_headers( 'GET', 'my-bucket' );

		$this->assertSame( hash( 'sha256', '' ), $headers['X-Amz-Content-SHA256'] );
	}

	public function test_presign_expiry_is_clamped_to_the_sigv4_maximum(): void {
		$provider = new CloudflareR2( 'default', [ 'account_id' => 'abc123' ] );
		$signer   = new Signer( $provider, self::ACCESS_KEY, self::SECRET_KEY );

		$url = $signer->get_presigned_url( 'b', 'k.txt', 99999999 )->get_url();
		$this->assertStringContainsString( 'X-Amz-Expires=604800', $url );

		$url = $signer->get_presigned_url( 'b', 'k.txt', -5 )->get_url();
		$this->assertStringContainsString( 'X-Amz-Expires=60', $url );
	}
}
