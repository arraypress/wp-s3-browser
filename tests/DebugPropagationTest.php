<?php
declare( strict_types=1 );

namespace ArrayPress\S3\Tests;

use ArrayPress\S3\Client;
use ArrayPress\S3\Provider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Debug state across the Client and the Signer it owns.
 *
 * Both compose the Debug trait, so each holds its own flag. The Client was
 * setting only its own, which left every debug call in the request path — the
 * canonical request, the string to sign, the response body — silently
 * disabled even when the caller had explicitly asked for debug. Those are
 * exactly the things needed to diagnose a SignatureDoesNotMatch.
 */
final class DebugPropagationTest extends TestCase {

	/**
	 * Read the debug flag from a Client and from its Signer.
	 *
	 * @param Client $client Client.
	 *
	 * @return array{0: bool, 1: bool}
	 */
	private function flags( Client $client ): array {
		$rc     = new ReflectionClass( $client );
		$signer = $rc->getProperty( 'signer' )->getValue( $client );

		return [
			(bool) $rc->getProperty( 'debug' )->getValue( $client ),
			(bool) ( new ReflectionClass( $signer ) )->getProperty( 'debug' )->getValue( $signer ),
		];
	}

	private function client( bool $debug = false ): Client {
		return new Client( Provider::r2( 'account' ), 'key', 'secret', true, 3600, $debug );
	}

	public function test_debug_reaches_the_signer_at_construction(): void {
		$this->assertSame( [ true, true ], $this->flags( $this->client( true ) ) );
	}

	public function test_debug_off_reaches_the_signer_too(): void {
		$this->assertSame( [ false, false ], $this->flags( $this->client() ) );
	}

	public function test_set_debug_cascades_after_construction(): void {
		$client = $this->client();
		$client->set_debug( true );

		$this->assertSame( [ true, true ], $this->flags( $client ) );
	}

	public function test_debug_can_be_turned_back_off(): void {
		$client = $this->client( true );
		$client->set_debug( false );

		$this->assertSame( [ false, false ], $this->flags( $client ) );
	}
}
