<?php
declare( strict_types=1 );

namespace ArrayPress\S3\Tests;

use ArrayPress\S3\Utils\XmlBuilder;
use PHPUnit\Framework\TestCase;

/**
 * XML request bodies.
 *
 * These were trait methods on the API client, reachable only by constructing a
 * client and issuing a request. The output has to match a provider's parser
 * exactly, which is the kind of thing worth asserting directly.
 */
final class XmlBuilderTest extends TestCase {

	public function test_cors_configuration_is_well_formed(): void {
		$xml = XmlBuilder::cors_configuration( [
			[
				'ID'             => 'UploadFromBrowser',
				'AllowedMethods' => [ 'PUT' ],
				'AllowedOrigins' => [ 'https://example.test' ],
				'AllowedHeaders' => [ 'Content-Type' ],
				'MaxAgeSeconds'  => 3600,
			],
		] );

		$parsed = simplexml_load_string( $xml );

		$this->assertNotFalse( $parsed );
		$this->assertSame( 'UploadFromBrowser', (string) $parsed->CORSRule->ID );
		$this->assertSame( 'PUT', (string) $parsed->CORSRule->AllowedMethod );
		$this->assertSame( '3600', (string) $parsed->CORSRule->MaxAgeSeconds );
	}

	public function test_cors_escapes_values(): void {
		$xml = XmlBuilder::cors_configuration( [
			[
				'AllowedMethods' => [ 'PUT' ],
				'AllowedOrigins' => [ 'https://a.test/?x=1&y=2' ],
			],
		] );

		$this->assertNotFalse( simplexml_load_string( $xml ), 'an unescaped ampersand would break parsing' );
		$this->assertStringContainsString( '&amp;', $xml );
	}

	public function test_optional_cors_fields_are_omitted(): void {
		$xml = XmlBuilder::cors_configuration( [
			[ 'AllowedMethods' => [ 'GET' ], 'AllowedOrigins' => [ '*' ] ],
		] );

		$this->assertStringNotContainsString( '<ID>', $xml );
		$this->assertStringNotContainsString( '<MaxAgeSeconds>', $xml );
		$this->assertStringNotContainsString( '<ExposeHeader>', $xml );
	}

	public function test_batch_delete_is_well_formed(): void {
		$xml    = XmlBuilder::batch_delete( [ 'a/b.txt', 'c/d.txt' ] );
		$parsed = simplexml_load_string( $xml );

		$this->assertNotFalse( $parsed );
		$this->assertCount( 2, $parsed->Object );
		$this->assertSame( 'false', (string) $parsed->Quiet );
	}

	/**
	 * Keys arrive percent-encoded from the browser; encoding them again would
	 * ask the provider to delete a key that does not exist.
	 */
	public function test_batch_delete_decodes_keys_once(): void {
		$xml = XmlBuilder::batch_delete( [ 'folder/my%20file.txt' ] );

		$this->assertStringContainsString( 'my file.txt', $xml );
		$this->assertStringNotContainsString( '%20', $xml );
	}

	public function test_batch_delete_escapes_keys(): void {
		$xml = XmlBuilder::batch_delete( [ 'a&b<c>.txt' ] );

		$parsed = simplexml_load_string( $xml );
		$this->assertNotFalse( $parsed );
		$this->assertSame( 'a&b<c>.txt', (string) $parsed->Object->Key );
	}
}
