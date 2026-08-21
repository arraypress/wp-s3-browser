<?php
declare( strict_types=1 );

namespace ArrayPress\S3\Tests\Cors;

use ArrayPress\S3\Cors\Rules;
use ArrayPress\S3\Xml\Builder;
use PHPUnit\Framework\TestCase;

/**
 * Generating CORS rules.
 *
 * What these produce is sent to the provider verbatim, so a wrong method list
 * or a missing origin surfaces later as a browser error with no useful detail.
 */
final class RulesTest extends TestCase {

	public function test_upload_scenario_permits_writes_from_the_given_origin(): void {
		$rules = Rules::generate( 'upload_only', [ 'https://shop.example' ] );

		$this->assertCount( 1, $rules );
		$this->assertSame( [ 'PUT', 'POST' ], $rules[0]['AllowedMethods'] );
		$this->assertSame( [ 'https://shop.example' ], $rules[0]['AllowedOrigins'] );
	}

	public function test_public_read_scenario_is_read_only(): void {
		$rules = Rules::generate( 'public_read' );

		$this->assertSame( [ 'GET', 'HEAD' ], $rules[0]['AllowedMethods'] );
		$this->assertSame( [ '*' ], $rules[0]['AllowedOrigins'] );
	}

	/**
	 * A browser hides response headers from fetch() unless the rule exposes
	 * them, so a read rule that omits these breaks range requests and any
	 * client-side size or type check.
	 */
	public function test_read_scenarios_expose_the_headers_javascript_needs(): void {
		$exposed = Rules::generate( 'public_read' )[0]['ExposeHeaders'];

		$this->assertContains( 'Content-Length', $exposed );
		$this->assertContains( 'ETag', $exposed );
	}

	public function test_unknown_scenario_falls_back_to_a_read_rule(): void {
		$rules = Rules::generate( 'something-else', [ 'https://a.example' ] );

		$this->assertSame( 'Custom', $rules[0]['ID'] );
		$this->assertSame( [ 'https://a.example' ], $rules[0]['AllowedOrigins'] );
	}

	public function test_max_age_can_be_overridden(): void {
		$rules = Rules::generate( 'upload_only', [ '*' ], [ 'max_age' => 120 ] );

		$this->assertSame( 120, $rules[0]['MaxAgeSeconds'] );
		$this->assertArrayNotHasKey( 'max_age', $rules[0] );
	}

	/**
	 * The mixed scenario keeps reads open to everyone while pinning writes to
	 * the caller's origins -- the distinction is the whole point of it.
	 */
	public function test_mixed_scenario_opens_reads_but_pins_writes(): void {
		$rules = Rules::generate( 'mixed', [ 'https://shop.example' ] );

		$this->assertCount( 2, $rules );

		$read  = $rules[0];
		$write = $rules[1];

		$this->assertSame( [ '*' ], $read['AllowedOrigins'] );
		$this->assertSame( [ 'GET', 'HEAD' ], $read['AllowedMethods'] );
		$this->assertSame( [ 'https://shop.example' ], $write['AllowedOrigins'] );
		$this->assertSame( [ 'PUT', 'POST' ], $write['AllowedMethods'] );
	}

	public function test_every_scenario_produces_rules_the_builder_accepts(): void {
		foreach ( Rules::scenarios() as $scenario ) {
			$xml = Builder::cors_configuration( Rules::generate( $scenario, [ 'https://shop.example' ] ) );

			$this->assertNotFalse(
				simplexml_load_string( $xml ),
				"Scenario {$scenario} produced XML a provider could not parse"
			);
			$this->assertStringContainsString( '<CORSRule>', $xml );
		}
	}
}
