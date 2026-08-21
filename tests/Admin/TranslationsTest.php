<?php
declare( strict_types=1 );

namespace ArrayPress\S3\Tests\Admin;

use ArrayPress\S3\Admin\Translations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The translation table.
 *
 * The browser's JavaScript reads these as i18n.<group>.<key> with no fallback,
 * so a missing key renders the literal word "undefined" in the interface. PHP
 * gives no warning for that -- the mismatch only shows up on screen.
 */
final class TranslationsTest extends TestCase {

	/**
	 * Every translation key the shipped JavaScript reads.
	 *
	 * Two forms appear. Most references are fully qualified --
	 * s3BrowserConfig.i18n.buckets.detailsTitle -- but a function that uses
	 * one group repeatedly aliases it first:
	 *
	 *     var i18n = s3BrowserConfig.i18n.buckets;
	 *     i18n.corsSetupSuccess.replace( ... )
	 *
	 * so a bare i18n.<key> has to be resolved against the alias in scope, or
	 * the key reads as a group name and the group name is lost.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function keys_used_by_javascript(): array {
		$refs = [];

		foreach ( glob( __DIR__ . '/../../assets/js/*/*.js' ) as $file ) {
			$alias = null;

			foreach ( explode( "\n", (string) file_get_contents( $file ) ) as $line ) {
				// An alias holds until the next one; scoping is per function,
				// but no file here aliases two different groups in one scope.
				if ( preg_match( '/\bi18n\s*=\s*\w+\.i18n\.(\w+)/', $line, $found ) ) {
					$alias = $found[1];
				}

				preg_match_all( '/\w+\.i18n\.(\w+)\.(\w+)/', $line, $qualified, PREG_SET_ORDER );

				foreach ( $qualified as [ , $group, $key ] ) {
					$refs[ "{$group}.{$key}" ] = [ $group, $key ];
				}

				if ( null === $alias ) {
					continue;
				}

				// Bare i18n.<key>, excluding the qualified form already taken.
				preg_match_all( '/(?<!\.)\bi18n\.(\w+)/', $line, $bare, PREG_SET_ORDER );

				foreach ( $bare as [ , $key ] ) {
					$refs[ "{$alias}.{$key}" ] = [ $alias, $key ];
				}
			}
		}

		ksort( $refs );

		return $refs;
	}

	#[DataProvider( 'keys_used_by_javascript' )]
	public function test_key_the_javascript_reads_is_defined( string $group, string $key ): void {
		$strings = Translations::all();

		$this->assertArrayHasKey( $group, $strings, "JavaScript reads i18n.{$group} but no such group exists" );
		$this->assertArrayHasKey( $key, $strings[ $group ], "JavaScript reads i18n.{$group}.{$key} but it is not defined" );
		$this->assertNotSame( '', $strings[ $group ][ $key ] );
	}

	public function test_every_string_is_a_string(): void {
		foreach ( Translations::all() as $group => $strings ) {
			$this->assertIsArray( $strings, "Group {$group} is not an array" );

			foreach ( $strings as $key => $value ) {
				$this->assertIsString( $value, "{$group}.{$key} is not a string" );
			}
		}
	}

	/**
	 * The table is sent to the browser as JSON. A key that is not valid UTF-8
	 * takes the whole payload down with it, not just its own entry.
	 */
	public function test_table_survives_json_encoding(): void {
		$this->assertIsString( json_encode( Translations::all(), JSON_THROW_ON_ERROR ) );
	}
}
