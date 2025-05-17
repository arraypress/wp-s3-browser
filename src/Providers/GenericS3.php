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
 * @author      ArrayPress Team
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Providers;

use ArrayPress\S3\Abstracts\Provider;
use InvalidArgumentException;

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
	 */
	public function set_use_https( bool $use_https = true ): self {
		$this->params['use_https'] = $use_https;

		return $this;
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
		$encoded_object = empty( $object ) ? '' : $this->encode_object_key( $object );
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