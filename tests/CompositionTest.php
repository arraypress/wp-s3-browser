<?php
declare( strict_types=1 );

namespace ArrayPress\S3\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class composition.
 *
 * A `use SomeTrait;` inside a class body resolves against the current
 * namespace unless a matching import exists at the top of the file. Get that
 * wrong and the file still passes `php -l`, and an autoloader findFile() check
 * still reports the class as resolvable — the failure only appears when PHP
 * actually links the class, which is to say, in production.
 *
 * Loading each class here forces that linkage.
 */
final class CompositionTest extends TestCase {

	#[DataProvider( 'classes' )]
	public function test_class_links( string $class ): void {
		$this->assertTrue(
			class_exists( $class ),
			$class . ' failed to load — usually a trait or parent that is used but not imported.'
		);
	}

	public static function classes(): array {
		$classes = [];

		$base = dirname( __DIR__ ) . '/src';
		$iter = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base ) );

		foreach ( $iter as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$src = file_get_contents( $file->getPathname() );

			if ( ! preg_match( '/^\s*namespace\s+([^;{\s]+)/m', $src, $ns ) ) {
				continue;
			}

			// Concrete classes only: abstracts, interfaces and traits are
			// linked transitively by the classes that use them.
			if ( ! preg_match( '/^\s*(?:final\s+)?class\s+(\w+)/m', $src, $c ) ) {
				continue;
			}

			$fqn             = $ns[1] . '\\' . $c[1];
			$classes[ $fqn ] = [ $fqn ];
		}

		ksort( $classes );

		return $classes;
	}
}
