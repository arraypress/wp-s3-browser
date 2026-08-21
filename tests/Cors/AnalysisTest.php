<?php
declare( strict_types=1 );

namespace ArrayPress\S3\Tests\Cors;

use ArrayPress\S3\Cors\Analysis;
use PHPUnit\Framework\TestCase;

/**
 * Reading a bucket's CORS rules.
 *
 * This decides whether the browser uploader is offered at all, so a wrong
 * answer either hides a working feature or offers one that fails in the
 * browser with an opaque network error.
 */
final class AnalysisTest extends TestCase {

	private const UPLOAD_RULE = [
		'AllowedOrigins' => [ 'https://shop.example' ],
		'AllowedMethods' => [ 'PUT', 'POST' ],
	];

	// -- Upload capability -------------------------------------------------

	public function test_named_origin_is_allowed(): void {
		$result = Analysis::allows_upload( [ self::UPLOAD_RULE ], 'https://shop.example' );

		$this->assertTrue( $result['allows_upload'] );
		$this->assertSame( [ 'PUT', 'POST' ], $result['allowed_methods'] );
	}

	public function test_other_origin_is_refused(): void {
		$this->assertFalse( Analysis::allows_upload( [ self::UPLOAD_RULE ], 'https://other.example' )['allows_upload'] );
	}

	public function test_wildcard_origin_allows_anyone(): void {
		$rules = [ [ 'AllowedOrigins' => [ '*' ], 'AllowedMethods' => [ 'PUT' ] ] ];

		$this->assertTrue( Analysis::allows_upload( $rules, 'https://anything.example' )['allows_upload'] );
	}

	/**
	 * S3 matches origins literally. A rule for the apex domain does not cover
	 * a subdomain, and reporting otherwise sends the admin hunting for a
	 * problem in the wrong place.
	 */
	public function test_subdomains_do_not_inherit_a_rule(): void {
		$rules = [ [ 'AllowedOrigins' => [ 'https://example.com' ], 'AllowedMethods' => [ 'PUT' ] ] ];

		$this->assertFalse( Analysis::allows_upload( $rules, 'https://cdn.example.com' )['allows_upload'] );
	}

	/**
	 * Scheme and port are part of an origin. http and https are distinct.
	 */
	public function test_scheme_is_part_of_the_origin(): void {
		$rules = [ [ 'AllowedOrigins' => [ 'https://example.com' ], 'AllowedMethods' => [ 'PUT' ] ] ];

		$this->assertFalse( Analysis::allows_upload( $rules, 'http://example.com' )['allows_upload'] );
	}

	public function test_read_only_rule_does_not_permit_upload(): void {
		$rules = [ [ 'AllowedOrigins' => [ '*' ], 'AllowedMethods' => [ 'GET', 'HEAD' ] ] ];

		$this->assertFalse( Analysis::allows_upload( $rules, 'https://shop.example' )['allows_upload'] );
	}

	/**
	 * Permission can be split across rules -- one naming the origin, another
	 * granting the method. Only a rule doing both actually permits an upload.
	 */
	public function test_permission_split_across_rules_is_not_combined(): void {
		$rules = [
			[ 'AllowedOrigins' => [ 'https://shop.example' ], 'AllowedMethods' => [ 'GET' ] ],
			[ 'AllowedOrigins' => [ 'https://other.example' ], 'AllowedMethods' => [ 'PUT' ] ],
		];

		$this->assertFalse( Analysis::allows_upload( $rules, 'https://shop.example' )['allows_upload'] );
	}

	public function test_matching_rules_are_returned_for_inspection(): void {
		$result = Analysis::allows_upload( [ self::UPLOAD_RULE ], 'https://shop.example' );

		$this->assertSame( [ self::UPLOAD_RULE ], $result['matching_rules'] );
		$this->assertSame( 1, $result['rules_checked'] );
	}

	public function test_no_rules_means_no_upload(): void {
		$result = Analysis::allows_upload( [], 'https://shop.example' );

		$this->assertFalse( $result['allows_upload'] );
		$this->assertSame( 0, $result['rules_checked'] );
	}

	// -- Summaries ---------------------------------------------------------

	/**
	 * These two were calls to Utils\Cors methods that did not exist, so
	 * reading back a bucket that had CORS configured was a fatal error.
	 */
	public function test_origins_are_collected_across_rules(): void {
		$rules = [
			[ 'AllowedOrigins' => [ 'https://a.example', '*' ] ],
			[ 'AllowedOrigins' => [ 'https://b.example', '*' ] ],
		];

		$this->assertSame( [ 'https://a.example', '*', 'https://b.example' ], Analysis::origins( $rules ) );
	}

	public function test_methods_are_collected_across_rules(): void {
		$rules = [
			[ 'AllowedMethods' => [ 'GET', 'HEAD' ] ],
			[ 'AllowedMethods' => [ 'PUT', 'GET' ] ],
		];

		$this->assertSame( [ 'GET', 'HEAD', 'PUT' ], Analysis::methods( $rules ) );
	}

	public function test_summaries_tolerate_rules_missing_the_field(): void {
		$this->assertSame( [], Analysis::origins( [ [ 'AllowedMethods' => [ 'GET' ] ] ] ) );
		$this->assertSame( [], Analysis::methods( [] ) );
	}

	// -- Description -------------------------------------------------------

	public function test_unconfigured_bucket_is_described_as_such(): void {
		$analysis = Analysis::describe( [] );

		$this->assertFalse( $analysis['has_cors'] );
		$this->assertSame( 0, $analysis['rules_count'] );
		$this->assertSame( [], $analysis['capabilities'] );
	}

	public function test_capabilities_are_reported(): void {
		$analysis = Analysis::describe( [
			[ 'AllowedOrigins' => [ '*' ], 'AllowedMethods' => [ 'GET', 'PUT', 'DELETE' ] ],
		] );

		$this->assertTrue( $analysis['supports_public_read'] );
		$this->assertTrue( $analysis['supports_upload'] );
		$this->assertTrue( $analysis['supports_delete'] );
		$this->assertSame( [ 'read', 'upload', 'delete' ], $analysis['capabilities'] );
	}

	public function test_wildcard_write_raises_a_warning(): void {
		$analysis = Analysis::describe( [
			[ 'AllowedOrigins' => [ '*' ], 'AllowedMethods' => [ 'PUT' ] ],
		] );

		$this->assertTrue( $analysis['allows_all_origins'] );
		$this->assertNotEmpty( $analysis['security_warnings'] );
	}

	public function test_wildcard_read_alone_raises_no_warning(): void {
		$analysis = Analysis::describe( [
			[ 'AllowedOrigins' => [ '*' ], 'AllowedMethods' => [ 'GET', 'HEAD' ] ],
		] );

		$this->assertTrue( $analysis['allows_all_origins'] );
		$this->assertSame( [], $analysis['security_warnings'] );
	}

	public function test_max_cache_time_is_the_highest_across_rules(): void {
		$analysis = Analysis::describe( [
			[ 'MaxAgeSeconds' => 600 ],
			[ 'MaxAgeSeconds' => 86400 ],
			[],
		] );

		$this->assertSame( 86400, $analysis['max_cache_time'] );
	}

	public function test_identical_warnings_are_not_repeated(): void {
		$rule     = [ 'AllowedOrigins' => [ '*' ], 'AllowedMethods' => [ 'PUT' ] ];
		$analysis = Analysis::describe( [ $rule, $rule, $rule ] );

		$this->assertCount( 1, $analysis['security_warnings'] );
	}

	public function test_a_sound_configuration_is_reported_as_such(): void {
		$analysis = Analysis::describe( [ self::UPLOAD_RULE ] );

		$this->assertSame( [], $analysis['security_warnings'] );
		$this->assertNotEmpty( $analysis['recommendations'] );
	}
}
