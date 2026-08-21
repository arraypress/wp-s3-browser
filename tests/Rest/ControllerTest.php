<?php
declare( strict_types=1 );

namespace ArrayPress\S3\Tests\Rest;

use ArrayPress\S3\Client;
use ArrayPress\S3\Provider;
use ArrayPress\S3\Rest\Controller;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use WP_Error;
use WP_REST_Request;

/**
 * REST route registration and authorization.
 *
 * The bucket arrives as a request parameter, so the permission checks here are
 * the only thing between a user holding the configured capability and every
 * bucket the credentials can reach.
 */
final class ControllerTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['registered_rest_routes'] = [];
		$GLOBALS['doing_it_wrong']         = [];
		$GLOBALS['test_user_can']          = true;

		// Registration is refused once a route base is claimed, and the claim
		// list spans the process by design.
		( new ReflectionProperty( Controller::class, 'claimed_routes' ) )->setValue( null, [] );
	}

	protected function tearDown(): void {
		$GLOBALS['test_user_can'] = true;
	}

	private function controller( string $route_base = 'cloudflare-r2', array $allowed = [] ): Controller {
		return new Controller(
			new Client( Provider::r2( 'account123' ), 'key', 'secret', false ),
			'cloudflare-r2',
			'upload_files',
			$route_base,
			static fn(): array => $allowed
		);
	}

	private function register( string $route_base = 'cloudflare-r2' ): Controller {
		$controller = $this->controller( $route_base );
		$controller->register_rest_routes();

		return $controller;
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
	 * route paths, since a REST route is a public URL Strauss cannot isolate.
	 */
	public function test_namespace_is_derived_from_the_strauss_prefix(): void {
		// Unprefixed checkout: the library's own vendor name is at the front.
		$this->assertSame( 's3-browser/v1', $this->controller()->get_rest_namespace() );
	}

	public function test_namespace_can_be_overridden(): void {
		$controller = $this->controller();
		$controller->set_rest_namespace( 'my-plugin/v2' );

		$this->assertSame( 'my-plugin/v2', $controller->get_rest_namespace() );
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
		$controller = $this->controller( 'cloudflare-r2', [ 'permitted-bucket' ] );

		$request = new WP_REST_Request();
		$request->set_param( 'bucket', 'someone-elses-bucket' );

		$this->assertInstanceOf( WP_Error::class, $controller->rest_bucket_permission_check( $request ) );

		$request->set_param( 'bucket', 'permitted-bucket' );
		$this->assertTrue( $controller->rest_bucket_permission_check( $request ) );
	}

	/**
	 * The allow-list is resolved per request, not captured at construction,
	 * because it runs through a filter and set_allowed_buckets() may be called
	 * after the browser is built.
	 */
	public function test_allow_list_is_resolved_at_call_time(): void {
		$allowed    = [];
		$controller = new Controller(
			new Client( Provider::r2( 'account123' ), 'key', 'secret', false ),
			'cloudflare-r2',
			'upload_files',
			'cloudflare-r2',
			static function () use ( &$allowed ): array {
				return $allowed;
			}
		);

		$request = new WP_REST_Request();
		$request->set_param( 'bucket', 'later-bucket' );

		// Empty allow-list means no restriction.
		$this->assertTrue( $controller->rest_bucket_permission_check( $request ) );

		$allowed = [ 'only-this-one' ];
		$this->assertInstanceOf( WP_Error::class, $controller->rest_bucket_permission_check( $request ) );
	}

	public function test_bucket_permission_check_rejects_a_malformed_bucket_name(): void {
		$controller = $this->controller();
		$request    = new WP_REST_Request();

		foreach ( [ '', '../etc', 'has space', 'a', str_repeat( 'x', 70 ) ] as $bad ) {
			$request->set_param( 'bucket', $bad );
			$this->assertInstanceOf(
				WP_Error::class,
				$controller->rest_bucket_permission_check( $request ),
				'Should reject bucket: ' . var_export( $bad, true )
			);
		}
	}

	public function test_capability_failure_blocks_every_route(): void {
		$GLOBALS['test_user_can'] = false;

		$controller = $this->controller();
		$request    = new WP_REST_Request();
		$request->set_param( 'bucket', 'fine-bucket' );

		$this->assertInstanceOf( WP_Error::class, $controller->rest_permission_check() );
		$this->assertInstanceOf( WP_Error::class, $controller->rest_bucket_permission_check( $request ) );
	}
}
