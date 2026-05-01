<?php
/**
 * Generic S3 Provider
 *
 * Provider implementation for any S3-compatible storage service.
 *
 * @package     ArrayPress\S3\Providers
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Providers;

use ArrayPress\S3\Abstracts\Provider;
use InvalidArgumentException;
use ArrayPress\S3\Utils\Encode;

/**
 * Class GenericS3Provider
 */
class GenericS3Provider extends Provider {

	/**
	 * Provider ID
	 *
	 * @var string
	 */
	protected string $id = 'generic_s3';

	/**
	 * Provider label
	 *
	 * @var string
	 */
	protected string $label = 'S3-Compatible Storage';

	/**
	 * Endpoint pattern
	 *
	 * @var string
	 */
	protected string $endpoint_pattern = '{endpoint}';

	/**
	 * Whether to use path-style URLs
	 *
	 * @var bool
	 */
	protected bool $path_style = true;

	/**
	 * Available regions
	 *
	 * @var array
	 */
	protected array $regions = [
		'auto' => [
			'label' => 'Automatic',
			'code'  => 'auto'
		]
	];

	/**
	 * Constructor
	 *
	 * @param string $endpoint   Endpoint hostname (e.g., 'play.min.io:9000')
	 * @param string $region     Region code (default: 'auto')
	 * @param array  $params     Additional parameters
	 * @param bool   $path_style Whether to use path-style URLs (default: true)
	 *
	 * @throws InvalidArgumentException If endpoint is not provided
	 */
	public function __construct(
		string $endpoint,
		string $region = 'auto',
		array $params = [],
		bool $path_style = true
	) {
		if ( empty( $endpoint ) ) {
			throw new InvalidArgumentException( 'Endpoint is required for generic S3 provider' );
		}

		// SECURITY: validate the endpoint host before we ever sign a request to it.
		// Without this check, a caller that exposes endpoint configuration to admins
		// (or anyone with option-write access) creates an authenticated SSRF: a value
		// like 169.254.169.254 would have credentialed S3-style requests issued to
		// cloud metadata services, internal hosts, or loopback. Define
		// ARRAYPRESS_S3_ALLOW_LOCAL_ENDPOINTS to bypass for local dev workflows.
		self::assert_endpoint_safe( $endpoint );

		// Store endpoint in params
		$params['endpoint'] = $endpoint;

		// Set URL style
		$this->path_style = $path_style;

		// Add custom regions if provided
		if ( isset( $params['regions'] ) && is_array( $params['regions'] ) ) {
			$this->add_regions( $params['regions'] );
			unset( $params['regions'] );
		}

		parent::__construct( $region, $params );
	}

	/**
	 * Add custom regions
	 *
	 * @param array $regions Regions to add
	 *
	 * @return self
	 */
	public function add_regions( array $regions ): self {
		foreach ( $regions as $code => $region ) {
			// Handle both simple strings and complex arrays
			if ( is_string( $region ) ) {
				$this->regions[ $code ] = [
					'label' => $region,
					'code'  => $code
				];
			} elseif ( is_array( $region ) && isset( $region['label'] ) ) {
				$this->regions[ $code ] = $region;
			}
		}

		return $this;
	}

	/**
	 * Get default region
	 *
	 * @return string
	 */
	public function get_default_region(): string {
		return 'auto';
	}

	/**
	 * Get provider endpoint
	 *
	 * @return string
	 * @throws InvalidArgumentException If endpoint is not set
	 */
	public function get_endpoint(): string {
		$endpoint = $this->get_param( 'endpoint' );

		if ( empty( $endpoint ) ) {
			throw new InvalidArgumentException( 'Endpoint is required for generic S3 provider' );
		}

		// Remove protocol if included
		if ( preg_match( '/^https?:\/\//i', $endpoint ) ) {
			$endpoint = preg_replace( '/^https?:\/\//i', '', $endpoint );
		}

		// Replace placeholder in endpoint pattern
		return str_replace( '{endpoint}', $endpoint, $this->endpoint_pattern );
	}

	/**
	 * Check if account ID is required
	 *
	 * @return bool
	 */
	public function requires_account_id(): bool {
		return false;
	}

	/**
	 * Set whether to use path-style URLs
	 *
	 * @param bool $use_path_style Whether to use path-style URLs
	 *
	 * @return self
	 */
	public function set_path_style( bool $use_path_style ): self {
		$this->path_style = $use_path_style;

		return $this;
	}

	/**
	 * Set a service name for identifying this provider
	 *
	 * @param string $name Service name
	 *
	 * @return self
	 */
	public function set_service_name( string $name ): self {
		$this->label = $name;

		return $this;
	}

	/**
	 * Override the region code used for signing
	 *
	 * @param string $signing_region Region code to use for signing
	 *
	 * @return self
	 */
	public function set_signing_region( string $signing_region ): self {
		$this->params['signing_region'] = $signing_region;

		return $this;
	}

	/**
	 * Get region for signing
	 *
	 * @return string
	 */
	public function get_region(): string {
		// Use custom signing region if set
		$signing_region = $this->get_param( 'signing_region' );

		if ( ! empty( $signing_region ) ) {
			return $signing_region;
		}

		// Otherwise use the current region
		return $this->region;
	}

	/**
	 * Set whether to use HTTPS
	 *
	 * @param bool $use_https Whether to use HTTPS (default: true)
	 *
	 * @return self
	 * @throws InvalidArgumentException If HTTPS is being disabled in a non-dev context
	 */
	public function set_use_https( bool $use_https = true ): self {
		if ( ! $use_https && ! self::dev_endpoints_allowed() ) {
			throw new InvalidArgumentException(
				'Refusing to disable HTTPS for an S3 endpoint. ' .
				'Define ARRAYPRESS_S3_ALLOW_LOCAL_ENDPOINTS=true to allow plain HTTP for local development.'
			);
		}

		$this->params['use_https'] = $use_https;

		return $this;
	}

	/**
	 * Whether dev / local-network endpoints are explicitly allowed
	 *
	 * @return bool
	 */
	private static function dev_endpoints_allowed(): bool {
		return defined( 'ARRAYPRESS_S3_ALLOW_LOCAL_ENDPOINTS' ) && ARRAYPRESS_S3_ALLOW_LOCAL_ENDPOINTS;
	}

	/**
	 * Validate that the configured endpoint resolves to a public address.
	 *
	 * Resolves the endpoint hostname to its IPs and rejects private, loopback,
	 * and reserved ranges so a credentialed S3 request cannot be aimed at
	 * cloud-metadata services or internal hosts. This is best-effort defence
	 * (DNS rebinding can still flip an IP between the construction-time check
	 * and the actual request); for stronger guarantees, pin the IP into
	 * wp_remote_* via the pre_http_request filter.
	 *
	 * @param string $endpoint Raw endpoint string as passed to the constructor
	 *
	 * @return void
	 * @throws InvalidArgumentException If the host resolves to a private/reserved address
	 */
	private static function assert_endpoint_safe( string $endpoint ): void {
		// Strip an optional scheme so we can extract the host portion.
		$stripped = preg_replace( '#^https?://#i', '', $endpoint );

		// Drop any path component.
		$slash = strpos( $stripped, '/' );
		if ( false !== $slash ) {
			$stripped = substr( $stripped, 0, $slash );
		}

		// Strip the port. Bracketed IPv6 literals: keep contents, drop brackets.
		if ( '' !== $stripped && '[' === $stripped[0] ) {
			$end = strpos( $stripped, ']' );
			$host = false !== $end ? substr( $stripped, 1, $end - 1 ) : trim( $stripped, '[]' );
		} else {
			$colon = strrpos( $stripped, ':' );
			$host  = false !== $colon ? substr( $stripped, 0, $colon ) : $stripped;
		}

		if ( '' === $host ) {
			throw new InvalidArgumentException( 'Endpoint host is empty' );
		}

		if ( self::dev_endpoints_allowed() ) {
			return;
		}

		// Resolve hostnames; literal IPs are checked directly.
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips = [ $host ];
		} else {
			$resolved = gethostbynamel( $host );
			$ips      = is_array( $resolved ) ? $resolved : [];
		}

		if ( empty( $ips ) ) {
			throw new InvalidArgumentException( sprintf(
				'Endpoint host "%s" could not be resolved.',
				$host
			) );
		}

		foreach ( $ips as $ip ) {
			$is_public = (bool) filter_var(
				$ip,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);

			if ( ! $is_public ) {
				throw new InvalidArgumentException( sprintf(
					'Endpoint "%s" resolves to a private, loopback, or reserved address (%s). ' .
					'Define ARRAYPRESS_S3_ALLOW_LOCAL_ENDPOINTS=true to allow this for local development.',
					$host,
					$ip
				) );
			}
		}
	}

	/**
	 * Check if HTTPS should be used
	 *
	 * @return bool
	 */
	public function use_https(): bool {
		return (bool) $this->get_param( 'use_https', true );
	}

	/**
	 * Format bucket URL with protocol support
	 *
	 * @param string $bucket Bucket name
	 * @param string $object Optional object key
	 *
	 * @return string
	 */
	public function format_url( string $bucket, string $object = '' ): string {
		$endpoint       = $this->get_endpoint();
		$encoded_object = empty( $object ) ? '' : Encode::object_key( $object );
		$protocol       = $this->use_https() ? 'https://' : 'http://';

		if ( $this->uses_path_style() ) {
			return $protocol . $endpoint . '/' . $bucket .
			       ( empty( $encoded_object ) ? '' : '/' . $encoded_object );
		} else {
			return $protocol . $bucket . '.' . $endpoint .
			       ( empty( $encoded_object ) ? '' : '/' . $encoded_object );
		}
	}

}