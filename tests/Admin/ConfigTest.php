<?php
declare( strict_types=1 );

namespace ArrayPress\S3\Tests\Admin;

use ArrayPress\S3\Admin\Config;
use PHPUnit\Framework\TestCase;

/**
 * What one browser instance is.
 *
 * The identifiers here separate two browsers on the same site. Getting them
 * wrong does not error -- it makes one plugin's media tab open the other
 * plugin's browser, against the other plugin's credentials.
 */
final class ConfigTest extends TestCase {

	private function config( ?string $context = 'edd' ): Config {
		return new Config( 'r2', 'Cloudflare R2', 'upload_files', 'my-bucket', [ 'download' ], $context );
	}

	public function test_context_qualifies_the_hook_suffix(): void {
		$this->assertSame( 'r2_edd', $this->config()->hook_suffix() );
		$this->assertSame( 's3_r2_edd', $this->config()->tab_id() );
	}

	public function test_without_a_context_the_provider_stands_alone(): void {
		$config = $this->config( null );

		$this->assertFalse( $config->has_context() );
		$this->assertSame( 'r2', $config->hook_suffix() );
		$this->assertSame( 's3_r2', $config->tab_id() );
	}

	public function test_two_contexts_never_collide(): void {
		$this->assertNotSame( $this->config( 'edd' )->tab_id(), $this->config( 'woocommerce' )->tab_id() );
	}

	/**
	 * A filter runs under its base name and under a context-suffixed one, so
	 * an integration can change its own browser without changing every
	 * browser on the site.
	 */
	public function test_a_filter_runs_under_both_names(): void {
		$seen = [];

		$GLOBALS['wp_test_filters']['s3_thing']         = function ( $value ) use ( &$seen ) {
			$seen[] = 'base';

			return $value;
		};
		$GLOBALS['wp_test_filters']['s3_thing_edd']     = function ( $value ) use ( &$seen ) {
			$seen[] = 'contextual';

			return $value;
		};

		$this->config()->filter( 's3_thing', 'value' );

		$this->assertSame( [ 'base', 'contextual' ], $seen );
	}

	public function test_without_a_context_only_the_base_filter_runs(): void {
		$seen = [];

		$GLOBALS['wp_test_filters']['s3_thing'] = function ( $value ) use ( &$seen ) {
			$seen[] = 'base';

			return $value;
		};

		$this->config( null )->filter( 's3_thing', 'value' );

		$this->assertSame( [ 'base' ], $seen );
	}

	protected function tearDown(): void {
		$GLOBALS['wp_test_filters'] = [];
	}
}
