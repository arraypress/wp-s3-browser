<?php
declare( strict_types=1 );

namespace ArrayPress\S3\Tests;

use ArrayPress\S3\Browser;
use ArrayPress\S3\Provider;
use PHPUnit\Framework\TestCase;

/**
 * Building a browser.
 *
 * Browser is the composition root: it builds the client, the REST controller,
 * the screen tests, the asset loader, the templates and the media tab, and
 * hooks them into WordPress. Nothing exercised that until now, which is how a
 * call to a method that had moved onto the REST controller survived a green
 * suite.
 */
final class BrowserTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wp_test_hooks']      = [];
		$GLOBALS['wp_test_did_action'] = [];
		$GLOBALS['wp_test_options']    = [];
	}

	private function browser( ?string $context = 'edd', array $post_types = [ 'download' ] ): Browser {
		return new Browser(
			Provider::r2( 'account123' ),
			'key',
			'secret',
			$post_types,
			'my-bucket',
			'upload_files',
			$context
		);
	}

	public function test_a_browser_can_be_built(): void {
		$this->assertInstanceOf( Browser::class, $this->browser() );
	}

	/**
	 * Every hook the browser needs to function. A missing one is not an error
	 * anywhere -- the feature simply never appears.
	 */
	public function test_it_hooks_everything_it_needs(): void {
		$this->browser();

		foreach ( [
			'media_upload_tabs',
			'media_upload_s3_r2_edd',
			'media_view_strings',
			'admin_enqueue_scripts',
			'admin_footer',
			'admin_print_footer_scripts',
			'rest_api_init',
		] as $hook ) {
			$this->assertArrayHasKey( $hook, $GLOBALS['wp_test_hooks'], "Nothing hooked {$hook}" );
		}
	}

	/**
	 * Two browsers on one site -- an EDD one and a WooCommerce one -- must not
	 * share a media tab, or one plugin's tab would open the other's browser.
	 */
	public function test_two_contexts_get_distinct_identities(): void {
		$edd = $this->browser( 'edd' );
		$woo = $this->browser( 'woocommerce', [ 'product' ] );

		$this->assertNotSame( $edd->get_tab_id(), $woo->get_tab_id() );
		$this->assertNotSame( $edd->get_hook_suffix(), $woo->get_hook_suffix() );
	}

	public function test_a_browser_without_a_context_falls_back_to_the_provider(): void {
		$browser = $this->browser( null, [] );

		$this->assertSame( 'r2', $browser->get_hook_suffix() );
		$this->assertSame( 's3_r2', $browser->get_tab_id() );
	}

	/**
	 * Registering on rest_api_init after it has fired would never run, which
	 * is a browser whose every endpoint 404s.
	 */
	public function test_routes_register_immediately_if_rest_api_init_already_fired(): void {
		$GLOBALS['wp_test_did_action']['rest_api_init'] = 1;
		$GLOBALS['registered_rest_routes']              = [];

		$this->browser();

		$this->assertNotEmpty( $GLOBALS['registered_rest_routes'] );
	}

	/**
	 * A scoped token cannot list buckets, so the browser hands the client the
	 * names it already knows. Without this a perfectly good configuration
	 * shows a listing error.
	 */
	public function test_the_default_bucket_seeds_the_client_when_no_allow_list_is_set(): void {
		$browser = $this->browser();

		$known = ( new \ReflectionProperty( $browser, 'client' ) )->getValue( $browser );
		$known = ( new \ReflectionProperty( $known, 'known_buckets' ) )->getValue( $known );

		$this->assertSame( [ 'my-bucket' ], $known );
	}

	public function test_an_allow_list_takes_precedence_over_the_default_bucket(): void {
		$browser = $this->browser()->set_allowed_buckets( [ 'one', 'two' ] );

		$this->assertSame( [ 'one', 'two' ], $browser->get_allowed_buckets() );
	}

	public function test_the_rest_namespace_can_be_overridden_after_construction(): void {
		$browser = $this->browser();
		$browser->set_rest_namespace( 'my-plugin/v2' );

		$this->assertSame( 'my-plugin/v2', $browser->get_rest_namespace() );
	}
}
