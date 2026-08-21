<?php
declare( strict_types=1 );

namespace ArrayPress\S3\Tests;

use ArrayPress\S3\Traits\Browser\Rest\Routes;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * A minimal host for the Routes trait.
 *
 * Browser itself pulls in the whole browser stack; the routing contract can be
 * exercised on its own.
 */
final class RouteHost {
	use Routes;

	protected string $capability = 'upload_files';
	protected array $allowed_buckets = [];
	private string $hook_suffix;

	public function __construct( string $hook_suffix = 'cloudflare-r2' ) {
		$this->hook_suffix = $hook_suffix;
	}

	private function get_hook_suffix(): string {
		return $this->hook_suffix;
	}

	public function get_allowed_buckets(): array {
		return $this->allowed_buckets;
	}

	public function set_allowed( array $buckets ): void {
		$this->allowed_buckets = $buckets;
	}

	public static function reset_claims(): void {
		self::$claimed_routes = [];
	}
}

/**
 * REST route registration.
 */
final class RestRoutesTest extends TestCase {

	protected function tearDown(): void {
		$GLOBALS['test_user_can'] = true;
	}

	protected function setUp(): void {
		$GLOBALS['registered_rest_routes'] = [];
		$GLOBALS['doing_it_wrong']         = [];
		$GLOBALS['test_user_can']          = true;
		RouteHost::reset_claims();
	}

	private function register( string $suffix = 'cloudflare-r2' ): RouteHost {
		$host = new RouteHost( $suffix );
		$host->register_rest_routes();

		return $host;
	}

	public function test_routes_are_registered(): void {
		$this->register();

		$this->assertNotEmpty( $GLOBALS['registered_rest_routes'] );
	}

	/**
	 * The structural reason for moving off admin-ajax: a route cannot exist
	 * without an explicit authorization decision.
	 */
	public function test_every_endpoint_declares_a_permission_callback(): void {
		$this->register();

		foreach ( $GLOBALS['registered_rest_routes'] as $registered ) {
			foreach ( $registered['args'] as $endpoint ) {
				$this->assertArrayHasKey(
					'permission_callback',
					$endpoint,
					'Missing permission_callback on ' . $registered['route']
				);
				$this->assertIsCallable( $endpoint['permission_callback'] );
			}
		}
	}

	public function test_bucket_routes_are_gated_on_the_bucket(): void {
		$this->register();

		foreach ( $GLOBALS['registered_rest_routes'] as $registered ) {
			if ( ! str_contains( $registered['route'], '(?P<bucket>' ) ) {
				continue;
			}

			foreach ( $registered['args'] as $endpoint ) {
				$this->assertSame(
					'rest_bucket_permission_check',
					$endpoint['permission_callback'][1],
					$registered['route'] . ' must gate on the bucket, not just the capability'
				);
			}
		}
	}

	/**
	 * Two plugins each bundling a Strauss-prefixed copy must not collide on
	 * route paths, since a REST route is a public URL that Strauss cannot
	 * isolate.
	 */
	public function test_namespace_is_derived_from_the_strauss_prefix(): void {
		$namespace = ( new RouteHost() )->get_rest_namespace();

		// Unprefixed checkout: the library's own vendor name is at the front.
		$this->assertSame( 's3-browser/v1', $namespace );
	}

	public function test_namespace_can_be_overridden(): void {
		$host = new RouteHost();
		$host->set_rest_namespace( 'my-plugin/v2' );

		$this->assertSame( 'my-plugin/v2', $host->get_rest_namespace() );
	}

	public function test_route_base_separates_instances_by_context(): void {
		$this->register( 'cloudflare-r2_edd' );
		$edd = $GLOBALS['registered_rest_routes'][0]['route'];

		$GLOBALS['registered_rest_routes'] = [];
		$this->register( 'cloudflare-r2_woo' );
		$woo = $GLOBALS['registered_rest_routes'][0]['route'];

		$this->assertNotSame( $edd, $woo );
	}

	/**
	 * WP_REST_Server merges same-path registrations rather than replacing them,
	 * so a duplicate must be refused rather than left to serve another
	 * instance's requests.
	 */
	public function test_duplicate_registration_is_refused(): void {
		$this->register();
		$first = count( $GLOBALS['registered_rest_routes'] );

		$this->register();

		$this->assertCount( $first, $GLOBALS['registered_rest_routes'] );
		$this->assertNotEmpty( $GLOBALS['doing_it_wrong'] );
	}

	public function test_bucket_permission_check_rejects_a_disallowed_bucket(): void {
		$host = new RouteHost();
		$host->set_allowed( [ 'permitted-bucket' ] );

		$request = new WP_REST_Request();
		$request->set_param( 'bucket', 'someone-elses-bucket' );

		$this->assertInstanceOf( \WP_Error::class, $host->rest_bucket_permission_check( $request ) );

		$request->set_param( 'bucket', 'permitted-bucket' );
		$this->assertTrue( $host->rest_bucket_permission_check( $request ) );
	}

	public function test_bucket_permission_check_rejects_a_malformed_bucket_name(): void {
		$host    = new RouteHost();
		$request = new WP_REST_Request();

		foreach ( [ '', '../etc', 'has space', 'a', str_repeat( 'x', 70 ) ] as $bad ) {
			$request->set_param( 'bucket', $bad );
			$this->assertInstanceOf(
				\WP_Error::class,
				$host->rest_bucket_permission_check( $request ),
				'Should reject bucket: ' . var_export( $bad, true )
			);
		}
	}

	public function test_capability_failure_blocks_every_route(): void {
		$GLOBALS['test_user_can'] = false;

		$host    = new RouteHost();
		$request = new WP_REST_Request();
		$request->set_param( 'bucket', 'fine-bucket' );

		$this->assertInstanceOf( \WP_Error::class, $host->rest_permission_check() );
		$this->assertInstanceOf( \WP_Error::class, $host->rest_bucket_permission_check( $request ) );
	}
}
