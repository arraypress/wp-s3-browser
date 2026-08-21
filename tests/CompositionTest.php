<?php
declare( strict_types=1 );

namespace ArrayPress\S3\Tests;

use ArrayPress\S3\Admin\Templates;
use ArrayPress\S3\Api;
use ArrayPress\S3\Browser;
use ArrayPress\S3\Cache;
use ArrayPress\S3\Client;
use ArrayPress\S3\Permissions;
use ArrayPress\S3\Provider;
use ArrayPress\S3\Rest\Controller;
use ArrayPress\S3\Tables\Buckets;
use ArrayPress\S3\Tables\Objects;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * That the composed classes actually hold together.
 *
 * This library assembles its behaviour from traits, and PHP resolves a trait
 * method against whatever class ends up composing it -- at call time, not at
 * load time. So a trait can call $this->something() that no composing class
 * defines, and nothing complains until a user reaches that line. php -l does
 * not see it, and neither does an autoloader check.
 *
 * It has happened repeatedly: a Browser composing traits that called into a
 * RestApi trait it had stopped importing, and later an asset config still
 * calling get_rest_route_base() after that moved onto the REST controller --
 * in a code path no test covers, so the suite stayed green while the browser
 * would have fataled on load.
 */
final class CompositionTest extends TestCase {

	/**
	 * Every class this library asks PHP to assemble.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function composed_classes(): array {
		$classes = [
			Api::class,
			Browser::class,
			Buckets::class,
			Cache::class,
			Client::class,
			Controller::class,
			Objects::class,
			Permissions::class,
			Provider::class,
			Templates::class,
		];

		return array_combine(
			array_map( static fn( $class ) => $class, $classes ),
			array_map( static fn( $class ) => [ $class ], $classes )
		);
	}

	/**
	 * Loading the class forces PHP to flatten its traits, which is what
	 * catches a trait that is used but no longer imported.
	 */
	#[DataProvider( 'composed_classes' )]
	public function test_class_composes( string $class ): void {
		$this->assertTrue( class_exists( $class ), "{$class} does not load" );
	}

	/**
	 * Every $this->method() reachable in a class or its traits must resolve
	 * somewhere on the composed class.
	 */
	#[DataProvider( 'composed_classes' )]
	public function test_every_internal_call_resolves( string $class ): void {
		$reflection = new ReflectionClass( $class );

		$defined = [];
		foreach ( $reflection->getMethods() as $method ) {
			$defined[ strtolower( $method->getName() ) ] = true;
		}

		$unresolved = [];
		foreach ( $this->internal_calls( $reflection ) as $lower => $name ) {
			if ( ! isset( $defined[ $lower ] ) ) {
				$unresolved[] = $name . '()';
			}
		}

		$this->assertSame(
			[],
			$unresolved,
			$reflection->getShortName() . ' calls methods nothing defines: ' . implode( ', ', $unresolved )
		);
	}

	/**
	 * Every $this->property reachable in a class or its traits must be
	 * declared somewhere on the composed class.
	 *
	 * The same hazard as the method check, and it has already happened twice:
	 * Shared\Config gave Api an admin-hook list it never used, and
	 * Shared\Context read $this->provider_id while Client, which composed it,
	 * had no such property. Both were latent -- fatal only once someone called
	 * the method that touched them.
	 */
	#[DataProvider( 'composed_classes' )]
	public function test_every_internal_property_resolves( string $class ): void {
		$reflection = new ReflectionClass( $class );

		$defined = [];
		foreach ( $reflection->getProperties() as $property ) {
			$defined[ $property->getName() ] = true;
		}

		$unresolved = [];
		foreach ( $this->internal_properties( $reflection ) as $name ) {
			if ( ! isset( $defined[ $name ] ) ) {
				$unresolved[] = '$' . $name;
			}
		}

		$this->assertSame(
			[],
			$unresolved,
			$reflection->getShortName() . ' reads properties nothing declares: ' . implode( ', ', $unresolved )
		);
	}

	/**
	 * Collect $this->property from a class file and every trait it composes.
	 *
	 * @param ReflectionClass $reflection Class to scan.
	 *
	 * @return array<int, string> Property names.
	 */
	private function internal_properties( ReflectionClass $reflection ): array {
		$names = [];

		foreach ( $this->source_files( $reflection ) as $file ) {
			// $this->foo not followed by '(' -- a property read rather than a
			// method call. The \b matters: without it the name part backtracks
			// so the lookahead can succeed, and every name loses its last
			// character.
			preg_match_all( '/\$this->([a-z_][a-z0-9_]*)\b(?!\s*\()/i', (string) file_get_contents( $file ), $found );

			foreach ( $found[1] as $name ) {
				$names[ $name ] = $name;
			}
		}

		return array_values( $names );
	}

	/**
	 * Collect $this->foo( from a class file and every trait it composes.
	 *
	 * @param ReflectionClass $reflection Class to scan.
	 *
	 * @return array<string, string> Lowercased name => name as written.
	 */
	private function internal_calls( ReflectionClass $reflection ): array {
		$calls = [];

		foreach ( $this->source_files( $reflection ) as $file ) {
			preg_match_all( '/\$this->([a-z_][a-z0-9_]*)\s*\(/i', (string) file_get_contents( $file ), $found );

			foreach ( $found[1] as $name ) {
				$calls[ strtolower( $name ) ] = $name;
			}
		}

		return $calls;
	}

	/**
	 * Every file contributing code to a composed class.
	 *
	 * @param ReflectionClass $reflection Class to scan.
	 *
	 * @return array<int, string> File paths.
	 */
	private function source_files( ReflectionClass $reflection ): array {
		$files = [ $reflection->getFileName() ];

		foreach ( $reflection->getTraits() as $trait ) {
			$files[] = $trait->getFileName();

			foreach ( $trait->getTraits() as $nested ) {
				$files[] = $nested->getFileName();
			}
		}

		for ( $parent = $reflection->getParentClass(); $parent; $parent = $parent->getParentClass() ) {
			$files[] = $parent->getFileName();
		}

		return array_values( array_unique( array_filter( $files ) ) );
	}
}
