<?php
/**
 * REST Controller
 *
 * The browser's REST surface: route registration, permission checks, argument
 * schemas and the request handlers behind them.
 *
 * This was two traits mixed into Browser, which meant the routes could only be
 * reasoned about with the whole browser -- its admin hooks, its asset
 * enqueueing, its media-library tab -- standing behind them. Nothing here
 * needs any of that. It needs a client, who is allowed to call it, and which
 * buckets it may address.
 *
 * @package     ArrayPress\S3\Rest
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Rest;

use ArrayPress\S3\Client;
use ArrayPress\S3\Interfaces\Response as ResponseInterface;
use ArrayPress\S3\Responses\ErrorResponse;
use ArrayPress\S3\Cors\Origin;
use ArrayPress\S3\Tables\Objects;
use ArrayPress\S3\Utils\Directory;
use ArrayPress\S3\Utils\Sanitize;
use ArrayPress\S3\Utils\Timestamp;
use ArrayPress\S3\Utils\Validate;
use Closure;
use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class Controller
 */
class Controller {

	/**
	 * Build a controller for one browser instance.
	 *
	 * @param Client  $client          Client the handlers act through.
	 * @param string  $provider_id     Provider identifier, used in filter names.
	 * @param string  $capability      Capability required to call any route.
	 * @param string  $route_base      Segment identifying this instance's routes.
	 * @param Closure $allowed_buckets Resolver for the bucket allow-list.
	 */
	public function __construct(
		private Client $client,
		private string $provider_id,
		private string $capability,
		private string $route_base,
		// Resolved per request rather than captured once: the allow-list runs
		// through a filter, so an integration can scope it by user or context
		// and that has to be honoured at call time, not at registration time.
		private Closure $allowed_buckets
	) {
	}

	/**
	 * Explicit REST namespace override, if the consumer set one.
	 *
	 * @var string
	 */
	protected string $rest_namespace = '';

	/**
	 * Route bases already claimed in this request, as "namespace|base".
	 *
	 * Static so it spans every Browser instance in the process, including
	 * instances belonging to entirely different plugins that each bundle their
	 * own prefixed copy of this library.
	 *
	 * @var array<string, string>
	 */
	private static array $claimed_routes = [];

	/**
	 * Set an explicit REST namespace for this browser
	 *
	 * Only needed when the automatic derivation is not distinct enough — see
	 * get_rest_namespace().
	 *
	 * @param string $namespace Namespace, e.g. 'my-plugin/v1'.
	 *
	 * @return self
	 */
	public function set_rest_namespace( string $namespace ): self {
		$this->rest_namespace = trim( $namespace, '/' );

		return $this;
	}

	/**
	 * Get the REST namespace for this browser
	 *
	 * A REST route is a public URL, which means it lives in a global space that
	 * Strauss cannot isolate. Strauss rewrites PHP symbols; it does not rewrite
	 * runtime strings like route paths, option names or action names. So if an
	 * EDD plugin and a WooCommerce plugin each bundle this library, each gets
	 * its own PHP namespace but both would otherwise register the *same* route.
	 *
	 * That is not merely wasteful. WP_REST_Server::register_route() merges
	 * same-path registrations with array_merge() on a numerically-indexed
	 * handler list, so the handlers are appended rather than replaced, and
	 * dispatch runs the first one whose methods match. The plugin that
	 * registered first would therefore answer the other plugin's requests —
	 * using its own credentials, its own capability, and its own bucket
	 * allow-list.
	 *
	 * The default derivation exploits the very thing Strauss *does* rewrite:
	 * this file's namespace. In a prefixed build __NAMESPACE__ starts with the
	 * consumer's prefix ("EDDR2\ArrayPress\S3\..."), which is unique per
	 * plugin by construction, so the namespace comes out distinct with no
	 * configuration required.
	 *
	 * @return string
	 */
	public function get_rest_namespace(): string {
		if ( '' !== $this->rest_namespace ) {
			return $this->rest_namespace;
		}

		$segments = explode( '\\', __NAMESPACE__ );
		$prefix   = $segments[0] ?? '';

		// Unprefixed (plain Composer install, or development) leaves the
		// library's own vendor name at the front.
		$base = ( '' === $prefix || 'ArrayPress' === $prefix )
			? 's3-browser'
			: strtolower( preg_replace( '/[^A-Za-z0-9]+/', '-', $prefix ) ) . '-s3-browser';

		return trim( $base, '-' ) . '/v1';
	}

	/**
	 * Get the route base identifying this browser instance
	 *
	 * Mirrors the AJAX action naming, so multiple browsers inside one plugin
	 * (different providers, or the same provider in different contexts) stay
	 * separated exactly as they already do.
	 *
	 * @return string
	 */
	protected function get_rest_route_base(): string {
		return sanitize_key( $this->route_base );
	}

	/**
	 * Register REST routes for this browser instance
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		$namespace = $this->get_rest_namespace();
		$base      = $this->get_rest_route_base();
		$claim     = $namespace . '|' . $base;

		// Refuse to double-register rather than let WordPress silently merge
		// two instances' handlers onto one path.
		if ( isset( self::$claimed_routes[ $claim ] ) ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: 1: REST namespace, 2: route base */
					esc_html__( 'REST routes for "%1$s/%2$s" are already registered by another S3 Browser instance. Give this instance a distinct context, or call set_rest_namespace() with a unique namespace. Skipping registration to avoid serving requests with the wrong credentials.', 'arraypress' ),
					esc_html( $namespace ),
					esc_html( $base )
				),
				'1.2.0'
			);

			return;
		}

		self::$claimed_routes[ $claim ] = $claim;

		$bucket_arg = [
			'bucket' => [
				'description'       => __( 'Bucket name.', 'arraypress' ),
				'type'              => 'string',
				'required'          => true,
				'validate_callback' => [ $this, 'rest_validate_bucket' ],
				'sanitize_callback' => 'sanitize_text_field',
			],
		];

		$object_key_arg = [
			'key' => [
				'description'       => __( 'Object key.', 'arraypress' ),
				'type'              => 'string',
				'required'          => true,
				'minLength'         => 1,
				'sanitize_callback' => [ $this, 'rest_sanitize_object_key' ],
			],
		];

		// --- Connection test -------------------------------------------------
		register_rest_route( $namespace, '/' . $base . '/connection', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_connection_test' ],
				'permission_callback' => [ $this, 'rest_permission_check' ],
			],
		] );

		// --- Cache -----------------------------------------------------------
		register_rest_route( $namespace, '/' . $base . '/cache', [
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'rest_clear_cache' ],
				'permission_callback' => [ $this, 'rest_permission_check' ],
			],
		] );

		// --- Bucket details --------------------------------------------------
		register_rest_route( $namespace, '/' . $base . '/buckets/(?P<bucket>[^/]+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_get_bucket_details' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg,
			],
		] );

		// --- Objects ---------------------------------------------------------
		register_rest_route( $namespace, '/' . $base . '/buckets/(?P<bucket>[^/]+)/objects', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_list_objects' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg + [
					'prefix'             => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => [ $this, 'rest_sanitize_object_key' ],
					],
					'continuation_token' => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'rest_delete_object' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg + $object_key_arg,
			],
			[
				'methods'             => 'PATCH',
				'callback'            => [ $this, 'rest_rename_object' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg + [
					'current_key'  => [
						'type'              => 'string',
						'required'          => true,
						'minLength'         => 1,
						'sanitize_callback' => [ $this, 'rest_sanitize_object_key' ],
					],
					'new_filename' => [
						'type'              => 'string',
						'required'          => true,
						'minLength'         => 1,
						'sanitize_callback' => [ $this, 'rest_sanitize_text' ],
					],
				],
			],
		] );

		// --- Presigned download URL ------------------------------------------
		register_rest_route( $namespace, '/' . $base . '/buckets/(?P<bucket>[^/]+)/objects/download-url', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_get_download_url' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg + $object_key_arg + [
					'expires_minutes' => [
						'type'    => 'integer',
						'default' => 60,
						'minimum' => 1,
						'maximum' => 10080,
					],
				],
			],
		] );

		// --- Presigned upload URL --------------------------------------------
		register_rest_route( $namespace, '/' . $base . '/buckets/(?P<bucket>[^/]+)/objects/upload-url', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_get_upload_url' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg + $object_key_arg,
			],
		] );

		// --- Folders ----------------------------------------------------------
		register_rest_route( $namespace, '/' . $base . '/buckets/(?P<bucket>[^/]+)/folders', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_create_folder' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg + [
					'folder_name' => [
						'type'              => 'string',
						'required'          => true,
						'minLength'         => 1,
						'sanitize_callback' => [ $this, 'rest_sanitize_text' ],
					],
					'prefix'      => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => [ $this, 'rest_sanitize_object_key' ],
					],
				],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'rest_delete_folder' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg + [
					'folder_path' => [
						'type'              => 'string',
						'required'          => true,
						'minLength'         => 1,
						'sanitize_callback' => [ $this, 'rest_sanitize_object_key' ],
					],
				],
			],
		] );

		// --- CORS -------------------------------------------------------------
		register_rest_route( $namespace, '/' . $base . '/buckets/(?P<bucket>[^/]+)/cors', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'rest_setup_cors' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg + [
					'origin' => [
						'type'              => 'string',
						'default'           => '',
						'format'            => 'uri',
						'sanitize_callback' => 'esc_url_raw',
					],
				],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'rest_delete_cors' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg,
			],
		] );
	}

	/**
	 * Base permission check: capability only
	 *
	 * Nonce verification is deliberately absent. For cookie-authenticated REST
	 * requests WordPress validates the X-WP-Nonce header itself, in
	 * rest_cookie_check_errors(), before any permission_callback runs — so
	 * re-checking here would be redundant, and checking a *different* nonce
	 * would break legitimate clients using application passwords.
	 *
	 * @return bool|WP_Error
	 */
	public function rest_permission_check() {
		if ( ! current_user_can( $this->capability ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'arraypress' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Permission check for bucket-scoped routes
	 *
	 * Authorization lives here rather than in the handler so that every
	 * bucket-scoped route is gated by construction: a route cannot be added
	 * without a permission_callback, and this one cannot pass without the
	 * bucket being allowed.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return bool|WP_Error
	 */
	public function rest_bucket_permission_check( WP_REST_Request $request ) {
		$allowed = $this->rest_permission_check();

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$bucket = (string) $request['bucket'];

		if ( ! $this->is_bucket_allowed( $bucket ) ) {
			return new WP_Error(
				'rest_bucket_forbidden',
				__( 'You do not have permission to access this bucket.', 'arraypress' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Check whether a bucket may be addressed through this browser
	 *
	 * Every AJAX endpoint takes the bucket straight from $_POST, so without a
	 * check here the capability gate ('upload_files' by default — Author and
	 * up) grants read, write, rename and delete over *every* bucket the
	 * configured credentials can reach, not just the one the browser is
	 * pointed at. S3 credentials are usually account-wide, so that is a real
	 * privilege boundary and not a theoretical one.
	 *
	 * An empty allow-list preserves the historical behaviour of permitting any
	 * bucket. Sites that only ever use one bucket should set it — see
	 * Browser::set_allowed_buckets() and the 's3_browser_allowed_buckets'
	 * filter.
	 *
	 * @param string $bucket Bucket name from the request
	 *
	 * @return bool True if the bucket may be used
	 */
	protected function is_bucket_allowed( string $bucket ): bool {
		if ( '' === $bucket ) {
			return false;
		}

		// Reject anything that is not a syntactically valid bucket name before
		// it reaches URL building — the value ends up in a Host header or a
		// request path.
		if ( ! preg_match( '/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]$/i', $bucket ) ) {
			return false;
		}

		$allowed = ( $this->allowed_buckets )();

		if ( empty( $allowed ) ) {
			return true;
		}

		return in_array( $bucket, $allowed, true );
	}

	/**
	 * Validate a bucket name argument
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return bool|WP_Error
	 */
	public function rest_validate_bucket( $value ) {
		if ( ! is_string( $value ) || ! $this->is_bucket_allowed( $value ) ) {
			return new WP_Error(
				'rest_invalid_bucket',
				__( 'Invalid bucket name.', 'arraypress' ),
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	/**
	 * Sanitize an object key or prefix
	 *
	 * Object keys legitimately contain spaces, quotes, brackets and unicode, so
	 * they get slash-stripping and control-character removal rather than
	 * sanitize_text_field(), which would mangle them. Traversal is rejected
	 * downstream by Encode::object_key().
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string
	 */
	public function rest_sanitize_object_key( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = wp_unslash( (string) $value );

		// Strip control characters (including NUL) but keep everything else.
		return (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $value );
	}

	/**
	 * Sanitize a plain text argument
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string
	 */
	public function rest_sanitize_text( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( (string) $value ) );
	}

	/**
	 * Build a success response
	 *
	 * @param array $data   Payload.
	 * @param int   $status HTTP status.
	 *
	 * @return WP_REST_Response
	 */
	private function rest_ok( array $data = [], int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response( $data, $status );
	}

	/**
	 * Relay a failed client response to the caller.
	 *
	 * Keeps the provider's own code and status rather than inventing one. The
	 * code is what distinguishes a bucket-scoped token from wrong credentials,
	 * and replacing it leaves the admin re-entering keys that were never the
	 * problem.
	 *
	 * @param ResponseInterface $response Failed response.
	 *
	 * @return WP_Error
	 */
	private function rest_relay( ResponseInterface $response ): WP_Error {
		if ( $response instanceof ErrorResponse ) {
			return $response->to_wp_error();
		}

		// is_successful() is a flag rather than a type, so a failure that is
		// not an ErrorResponse is possible even if nothing produces one today.
		return new WP_Error(
			'rest_upstream_failed',
			__( 'The storage provider rejected the request.', 'arraypress' ),
			[ 'status' => 502 ]
		);
	}

	/**
	 * Build an error response from a failed S3 operation
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 *
	 * @return WP_Error
	 */
	private function rest_fail( string $code, string $message, int $status = 400 ): WP_Error {
		return new WP_Error( $code, $message, [ 'status' => $status ] );
	}

	/**
	 * Test the storage connection
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_connection_test() {
		// Account-level ListBuckets works for admin / master tokens.
		$result = $this->client->get_bucket_count();

		if ( $result->is_successful() ) {
			$data = $result->get_data();

			return $this->rest_ok( [
				'status'  => 'ok',
				'message' => __( 'Connection successful.', 'arraypress' ),
				'summary' => sprintf(
					_n( 'Found %d accessible bucket', 'Found %d accessible buckets', $data['count'], 'arraypress' ),
					$data['count']
				),
				'buckets' => $data['buckets'] ?? [],
				'count'   => $data['count'],
			] );
		}

		// A token scoped to specific buckets cannot list them. That is not a
		// failure — Cloudflare recommends scoping R2 tokens — so it must not be
		// reported as one. The only thing missing is the bucket name, which the
		// consumer supplies through this filter.
		$scoped          = 'bucket_listing_forbidden' === $result->get_error_code();
		$fallback_bucket = (string) apply_filters( 'arraypress_s3_connection_test_fallback_bucket', '' );

		if ( '' !== $fallback_bucket ) {
			$exists = $this->client->bucket_exists( $fallback_bucket, false );

			if ( $exists->is_successful() ) {
				return $this->rest_ok( [
					'status'  => 'ok',
					'message' => __( 'Connection successful.', 'arraypress' ),
					'summary' => sprintf(
						/* translators: %s: bucket name */
						__( 'Verified access to "%s". This token is scoped to specific buckets, so it cannot list the others.', 'arraypress' ),
						$fallback_bucket
					),
					'buckets' => [ $fallback_bucket ],
					'count'   => 1,
					'scoped'  => true,
				] );
			}

			// The name is wrong, or the token cannot reach it. Say which
			// bucket was tried — otherwise the admin has no way to tell a typo
			// from a permissions problem.
			return $this->rest_ok( [
				'status'  => 'failed',
				'message' => __( 'Could not reach that bucket.', 'arraypress' ),
				'summary' => sprintf(
					/* translators: 1: bucket name, 2: error from the provider */
					__( 'The credentials work, but "%1$s" could not be reached: %2$s', 'arraypress' ),
					$fallback_bucket,
					$exists->get_error_message()
				),
				'scoped'  => $scoped,
			] );
		}

		if ( $scoped ) {
			// Credentials are fine; the configuration is simply incomplete.
			return $this->rest_ok( [
				'status'  => 'needs_bucket',
				'message' => __( 'Credentials accepted.', 'arraypress' ),
				'summary' => __( 'This token is scoped to specific buckets, so it cannot list them — which is the recommended setup. Enter the bucket name to finish configuring and verify access.', 'arraypress' ),
				'scoped'  => true,
			] );
		}

		// Anything else really is a credential or endpoint problem, and the
		// provider's own message is more useful than a generic one.
		return $this->rest_ok( [
			'status'  => 'failed',
			'message' => __( 'Connection failed.', 'arraypress' ),
			'summary' => $result->get_error_message(),
		] );
	}

	/**
	 * Clear all cached S3 data
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_clear_cache() {
		if ( ! $this->client->clear_all_cache() ) {
			return $this->rest_fail( 'rest_cache_clear_failed', __( 'Failed to clear cache', 'arraypress' ), 500 );
		}

		return $this->rest_ok( [ 'message' => __( 'Cache cleared successfully', 'arraypress' ) ] );
	}

	/**
	 * Get details for a single bucket
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_get_bucket_details( WP_REST_Request $request ) {
		$bucket = (string) $request['bucket'];
		$result = $this->client->get_bucket_details( $bucket, Origin::current(), false );

		if ( ! $result->is_successful() ) {
			return $this->rest_relay( $result );
		}

		$data = $result->get_data();

		// The debug payload carries raw provider error strings; it is a
		// development aid, not something to ship to every browser.
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			unset( $data['debug'] );
		}

		return $this->rest_ok( $data );
	}

	/**
	 * List a page of objects
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_list_objects( WP_REST_Request $request ) {
		$page = Objects::get_page_data(
			$this->client,
			$this->provider_id,
			(string) $request['bucket'],
			(string) $request['prefix'],
			(string) $request['continuation_token']
		);

		if ( is_wp_error( $page ) ) {
			return $page;
		}

		return $this->rest_ok( $page );
	}

	/**
	 * Delete a single object
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_delete_object( WP_REST_Request $request ) {
		$bucket = (string) $request['bucket'];
		$key    = (string) $request['key'];

		$result = $this->client->delete_object( $bucket, $key );

		if ( ! $result->is_successful() ) {
			return $this->rest_relay( $result );
		}

		$this->client->clear_bucket_cache( $bucket );

		return $this->rest_ok( [
			'message' => __( 'File deleted successfully', 'arraypress' ),
			'bucket'  => $bucket,
			'key'     => $key,
		] );
	}

	/**
	 * Rename an object
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_rename_object( WP_REST_Request $request ) {
		$bucket       = (string) $request['bucket'];
		$current_key  = (string) $request['current_key'];
		$new_filename = (string) $request['new_filename'];

		$validation = Validate::filename( $new_filename );
		if ( ! $validation['valid'] ) {
			return $this->rest_fail( 'rest_invalid_filename', $validation['message'] );
		}

		if ( Directory::is_rename_same_key( $current_key, $new_filename ) ) {
			return $this->rest_fail(
				'rest_rename_noop',
				__( 'The new filename is the same as the current filename', 'arraypress' )
			);
		}

		$new_key = Directory::build_rename_key( $current_key, $new_filename );

		$exists = $this->client->object_exists( $bucket, $new_key );
		if ( $exists->is_successful() && ( $exists->get_data()['exists'] ?? false ) ) {
			return $this->rest_fail(
				'rest_rename_conflict',
				sprintf( __( 'A file named "%s" already exists in this location', 'arraypress' ), $new_filename ),
				409
			);
		}

		$result = $this->client->rename_object( $bucket, $current_key, $new_key );

		if ( ! $result->is_successful() ) {
			return $this->rest_relay( $result );
		}

		$this->client->clear_bucket_cache( $bucket );

		return $this->rest_ok( [
			'message'      => sprintf( __( 'File renamed to "%s" successfully', 'arraypress' ), $new_filename ),
			'bucket'       => $bucket,
			'old_key'      => $current_key,
			'new_key'      => $new_key,
			'new_filename' => $new_filename,
		] );
	}

	/**
	 * Mint a presigned download URL
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_get_download_url( WP_REST_Request $request ) {
		$bucket  = (string) $request['bucket'];
		$key     = (string) $request['key'];
		$minutes = Sanitize::minutes( (int) $request['expires_minutes'] );

		$exists = $this->client->object_exists( $bucket, $key );
		if ( ! $exists->is_successful() ) {
			return $this->rest_fail( 'rest_object_check_failed', __( 'Error checking if file exists', 'arraypress' ), 502 );
		}

		if ( ! ( $exists->get_data()['exists'] ?? false ) ) {
			return $this->rest_fail( 'rest_object_not_found', __( 'File does not exist', 'arraypress' ), 404 );
		}

		$result = $this->client->get_presigned_url( $bucket, $key, $minutes );

		if ( ! $result->is_successful() ) {
			return $this->rest_relay( $result );
		}

		return $this->rest_ok( [
			'url'        => $result->get_url(),
			'expires_at' => Timestamp::in_minutes( $minutes ),
			'expires_in' => $minutes,
			'bucket'     => $bucket,
			'key'        => $key,
			'message'    => sprintf(
				__( 'Link generated successfully (expires in %d minutes)', 'arraypress' ),
				$minutes
			),
		] );
	}

	/**
	 * Mint a presigned upload URL
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_get_upload_url( WP_REST_Request $request ) {
		$bucket = (string) $request['bucket'];
		$key    = (string) $request['key'];

		$result = $this->client->get_presigned_upload_url( $bucket, $key );

		if ( ! $result->is_successful() ) {
			return $this->rest_relay( $result );
		}

		$this->client->clear_bucket_cache( $bucket );

		return $this->rest_ok( [
			'url'     => $result->get_url(),
			'expires' => Timestamp::in_minutes( 15 ),
		] );
	}

	/**
	 * Create a folder
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_create_folder( WP_REST_Request $request ) {
		$bucket      = (string) $request['bucket'];
		$prefix      = (string) $request['prefix'];
		$folder_name = (string) $request['folder_name'];

		$validation = Validate::folder_name( $folder_name );
		if ( ! $validation['valid'] ) {
			return $this->rest_fail( 'rest_invalid_folder_name', $validation['message'] );
		}

		$folder_key = Directory::build_folder_key( $prefix, $folder_name );
		$result     = $this->client->create_folder( $bucket, $folder_key );

		if ( ! $result->is_successful() ) {
			return $this->rest_relay( $result );
		}

		$this->client->clear_bucket_cache( $bucket );

		return $this->rest_ok( [
			'message'    => sprintf( __( 'Folder "%s" created successfully', 'arraypress' ), $folder_name ),
			'folder_key' => $folder_key,
			'bucket'     => $bucket,
			'prefix'     => $prefix,
		], 201 );
	}

	/**
	 * Delete a folder and everything under it
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_delete_folder( WP_REST_Request $request ) {
		$bucket      = (string) $request['bucket'];
		$folder_path = (string) $request['folder_path'];

		// Refuse to recursively delete the bucket root. After normalization a
		// folder_path of '' or '/' resolves to depth 0, which would wipe the
		// entire bucket, so require at least one path segment.
		if ( Directory::depth( $folder_path ) < 1 ) {
			return $this->rest_fail(
				'rest_refuse_bucket_root',
				__( 'Refusing to delete bucket root. Specify a folder.', 'arraypress' )
			);
		}

		$result = $this->delete_folder_with_fallback( $bucket, $folder_path );

		if ( ! $result->is_successful() ) {
			return $this->rest_relay( $result );
		}

		$this->client->clear_bucket_cache( $bucket );

		$data = $result->get_data();

		return $this->rest_ok( [
			'message'     => $this->format_folder_deletion_message( $data ),
			'bucket'      => $bucket,
			'folder_path' => $data['folder_path'] ?? Directory::normalize( $folder_path ),
			'data'        => $data,
		] );
	}

	/**
	 * Configure CORS for browser uploads
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_setup_cors( WP_REST_Request $request ) {
		$bucket = (string) $request['bucket'];
		$origin = (string) $request['origin'] ?: Origin::current();

		if ( '' === $origin ) {
			return $this->rest_fail( 'rest_origin_required', __( 'Origin is required for CORS setup', 'arraypress' ) );
		}

		$this->client->clear_bucket_cache( $bucket );

		$result = $this->client->set_cors_scenario( $bucket, 'upload_only', [ $origin ] );

		if ( ! $result->is_successful() ) {
			// AccessDenied on PutBucketCors is not a transient failure and no
			// amount of retrying fixes it: R2 API tokens carry a permission
			// level, and a token with Object Read & Write can upload objects
			// while being refused any change to bucket configuration. That is
			// the recommended way to scope a token, so it is a normal state
			// rather than a misconfiguration — but it does mean CORS has to be
			// set once in the provider's console. Saying "setup failed" sends
			// people to retry something that cannot succeed.
			$denied = in_array(
				$result->get_error_code(),
				[ 'AccessDenied', 'access_denied' ],
				true
			) || 403 === $result->get_status_code();

			return $this->rest_fail(
				$denied ? 'rest_cors_permission_denied' : 'rest_cors_setup_failed',
				$denied
					? __( 'This API token can read and write objects but is not permitted to change bucket settings, so CORS has to be configured once in your provider\'s console. The rule to add is below.', 'arraypress' )
					: $result->get_error_message(),
				502
			);
		}

		$this->client->clear_bucket_cache( $bucket );

		$verification = $this->client->cors_allows_upload( $bucket, $origin, false );
		$verified     = $verification->is_successful()
			&& ( $verification->get_data()['allows_upload'] ?? false );

		return $this->rest_ok( [
			'bucket'              => $bucket,
			'origin'              => $origin,
			'verification_passed' => $verified,
			'message'             => $verified
				? __( 'CORS configured successfully for uploads', 'arraypress' )
				: __( 'CORS was saved but is not active yet — it can take a moment to propagate.', 'arraypress' ),
		] );
	}

	/**
	 * Remove the bucket's CORS configuration
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_delete_cors( WP_REST_Request $request ) {
		$bucket = (string) $request['bucket'];
		$result = $this->client->delete_cors_configuration( $bucket );

		if ( ! $result->is_successful() ) {
			return $this->rest_relay( $result );
		}

		$this->client->clear_bucket_cache( $bucket );

		return $this->rest_ok( [
			'bucket'  => $bucket,
			'message' => __( 'CORS configuration removed', 'arraypress' ),
		] );
	}

	/**
	 * Delete a folder, falling back to per-object deletion
	 *
	 * Batch deletion is more efficient for large folders, but not every
	 * S3-compatible provider implements it, and it can time out. Fall back only
	 * on error codes that indicate the batch path itself was the problem.
	 *
	 * @param string $bucket      Bucket name.
	 * @param string $folder_path Raw folder path (normalized downstream).
	 *
	 * @return \ArrayPress\S3\Interfaces\Response
	 */
	private function delete_folder_with_fallback( string $bucket, string $folder_path ) {
		$result = $this->client->delete_folder_batch( $bucket, $folder_path );

		if ( ! $result->is_successful() ) {
			$recoverable = [ 'batch_delete_timeout', 'batch_delete_not_supported', 'network_error' ];

			if ( in_array( $result->get_error_code(), $recoverable, true ) ) {
				$result = $this->client->delete_folder( $bucket, $folder_path, true, true );
			}
		}

		return $result;
	}

	/**
	 * Format the folder deletion success message
	 *
	 * @param array $data Deletion result data.
	 *
	 * @return string
	 */
	private function format_folder_deletion_message( array $data ): string {
		if ( ! empty( $data['deleted_count'] ) ) {
			return sprintf( __( 'Folder deleted successfully (%d items removed)', 'arraypress' ), $data['deleted_count'] );
		}

		if ( ! empty( $data['success_count'] ) ) {
			return sprintf( __( 'Folder deleted successfully (%d objects removed)', 'arraypress' ), $data['success_count'] );
		}

		return __( 'Folder deleted successfully', 'arraypress' );
	}

}
