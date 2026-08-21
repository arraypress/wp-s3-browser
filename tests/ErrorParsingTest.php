<?php
declare( strict_types=1 );

namespace ArrayPress\S3\Tests;

use ArrayPress\S3\Responses\ErrorResponse;
use ArrayPress\S3\Traits\Api\ErrorHandling;
use ArrayPress\S3\Traits\XML\Core;
use ArrayPress\S3\Traits\XML\Extract;
use ArrayPress\S3\Traits\XML\Parser;
use PHPUnit\Framework\TestCase;

/**
 * Host exposing the provider error parser.
 */
final class ErrorParserHost {
	use ErrorHandling;
	use Core;
	use Extract;
	use Parser;

	public function parse( int $status, string $body, string $default ): ErrorResponse {
		return $this->handle_error_response( $status, $body, $default );
	}

	protected function debug( string $title, $data = null ): void {}

	protected function debug_log_errors( array $errors, string $xml ): void {}
}

/**
 * Parsing the provider's error document.
 *
 * The error code is what separates a bucket-scoped token — a normal, in fact
 * recommended, R2 configuration — from credentials that are genuinely wrong.
 * Losing it means telling the admin to re-enter working keys.
 */
final class ErrorParsingTest extends TestCase {

	/**
	 * Cloudflare R2 omits the XML declaration. Requiring '<?xml' meant every
	 * R2 error collapsed to the caller's generic default.
	 */
	public function test_error_without_an_xml_declaration_is_parsed(): void {
		$body = '<Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>';

		$result = ( new ErrorParserHost() )->parse( 403, $body, 'Failed to list buckets' );

		$this->assertSame( 'AccessDenied', $result->get_error_code() );
		$this->assertNotSame( 'Failed to list buckets', $result->get_error_message() );
	}

	public function test_error_with_an_xml_declaration_is_parsed(): void {
		$body = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<Error><Code>NoSuchBucket</Code><Message>The specified bucket does not exist</Message></Error>';

		$result = ( new ErrorParserHost() )->parse( 404, $body, 'Failed' );

		$this->assertSame( 'NoSuchBucket', $result->get_error_code() );
		$this->assertSame( 'The specified bucket does not exist', $result->get_error_message() );
	}

	public function test_status_code_is_preserved(): void {
		$body = '<Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>';

		$this->assertSame( 403, ( new ErrorParserHost() )->parse( 403, $body, 'x' )->get_status_code() );
	}

	public function test_non_xml_body_falls_back_to_the_default_message(): void {
		$result = ( new ErrorParserHost() )->parse( 500, 'upstream connect error', 'Failed to list buckets' );

		$this->assertSame( 'Failed to list buckets', $result->get_error_message() );
		$this->assertSame( 'request_failed', $result->get_error_code() );
		$this->assertSame( 500, $result->get_status_code() );
	}

	public function test_empty_body_falls_back_to_the_default_message(): void {
		$result = ( new ErrorParserHost() )->parse( 403, '', 'Failed to list buckets' );

		$this->assertSame( 'Failed to list buckets', $result->get_error_message() );
	}
}
