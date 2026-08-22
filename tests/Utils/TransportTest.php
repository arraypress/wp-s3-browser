<?php
/**
 * Transport error translation.
 *
 * @package ArrayPress\S3
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Tests\Utils;

use ArrayPress\S3\Responses\ErrorResponse;
use ArrayPress\S3\Utils\Transport;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Class TransportTest
 */
final class TransportTest extends TestCase {

	/**
	 * What a wrong Cloudflare account id actually produces.
	 */
	private const TLS = 'cURL error 35: LibreSSL/3.3.6: error:1404B410:SSL routines:ST_CONNECT:sslv3 alert handshake failure';

	private const ENDPOINT = 'https://wrongaccountid.r2.cloudflarestorage.com';

	public function test_a_failed_request_is_recognised(): void {
		$this->assertTrue( Transport::is_transport_error( 'http_request_failed' ) );
	}

	/**
	 * An S3 error means the endpoint answered, and its own wording is the
	 * useful one -- so it must not be swept into a connectivity message.
	 */
	public function test_a_provider_error_is_left_alone(): void {
		$this->assertFalse( Transport::is_transport_error( 'AccessDenied' ) );
		$this->assertFalse( Transport::is_transport_error( 'NoSuchBucket' ) );
	}

	public function test_a_tls_failure_names_the_host_and_the_likely_cause(): void {
		$message = Transport::explain( self::TLS, self::ENDPOINT );

		$this->assertStringContainsString( 'wrongaccountid.r2.cloudflarestorage.com', $message );
		$this->assertStringContainsString( 'Account ID', $message );
		$this->assertStringNotContainsString( 'sslv3', $message );
		$this->assertStringNotContainsString( 'cURL', $message );
	}

	public function test_an_unresolvable_host_says_so(): void {
		$message = Transport::explain( 'cURL error 6: Could not resolve host: nope.example.com', self::ENDPOINT );

		$this->assertStringContainsString( 'no server at', $message );
		$this->assertStringNotContainsString( 'cURL', $message );
	}

	public function test_a_timeout_is_not_reported_as_a_typo(): void {
		$message = Transport::explain( 'cURL error 28: Operation timed out after 30001 milliseconds', self::ENDPOINT );

		$this->assertStringContainsString( 'did not respond', $message );
		$this->assertStringNotContainsString( 'typo', $message );
	}

	public function test_an_unrecognised_failure_still_avoids_jargon(): void {
		$message = Transport::explain( 'cURL error 52: Empty reply from server', self::ENDPOINT );

		$this->assertStringContainsString( 'wrongaccountid.r2.cloudflarestorage.com', $message );
		$this->assertStringNotContainsString( 'cURL', $message );
	}

	public function test_without_an_endpoint_it_still_reads_as_a_sentence(): void {
		$message = Transport::explain( self::TLS );

		$this->assertStringContainsString( 'the storage endpoint', $message );
		$this->assertStringNotContainsString( 'sslv3', $message );
	}

	/**
	 * The original is what makes a real network fault diagnosable, so it is
	 * carried rather than replaced.
	 */
	public function test_the_original_text_is_kept_on_the_response(): void {
		$response = ErrorResponse::from_wp_error(
			new WP_Error( 'http_request_failed', self::TLS ),
			400,
			self::ENDPOINT
		);

		$this->assertStringNotContainsString( 'sslv3', $response->get_error_message() );
		$this->assertSame( self::TLS, $response->get_error_data()['transport_error'] ?? '' );
		$this->assertSame( 'http_request_failed', $response->get_error_code() );
	}

	public function test_a_provider_error_response_is_untouched(): void {
		$response = ErrorResponse::from_wp_error(
			new WP_Error( 'AccessDenied', 'Access Denied' ),
			403
		);

		$this->assertSame( 'Access Denied', $response->get_error_message() );
		$this->assertArrayNotHasKey( 'transport_error', $response->get_error_data() );
	}
}
