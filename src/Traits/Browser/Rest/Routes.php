<?php
/**
 * REST Route Registration Trait
 *
 * Registers the browser's REST API surface, and — critically — keeps two
 * separately-distributed copies of this library from colliding on it.
 *
 * @package     ArrayPress\S3\Traits\Browser\Rest
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Browser\Rest;

use WP_REST_Request;
use WP_Error;

/**
 * Trait Routes
 */
trait Routes {

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
		return sanitize_key( $this->get_hook_suffix() );
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

		$allowed = $this->get_allowed_buckets();

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

}
