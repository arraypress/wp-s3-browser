<?php
/**
 * Abstract Provider
 *
 * Base implementation for S3-compatible storage providers.
 *
 * @package     ArrayPress\S3
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      ArrayPress Team
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Abstracts;

use ArrayPress\S3\Interfaces\Provider as ProviderInterface;
use InvalidArgumentException;

/**
 * Class AbstractProvider
 */
abstract class Provider implements ProviderInterface {

	/**
	 * Provider ID
	 *
	 * @var string
	 */
	protected string $id;

	/**
	 * Provider label
	 *
	 * @var string
	 */
	protected string $label;

	/**
	 * Endpoint pattern
	 *
	 * @var string
	 */
	protected string $endpoint_pattern;

	/**
	 * Whether to use path-style URLs
	 *
	 * @var bool
	 */
	protected bool $path_style;

	/**
	 * Region
	 *
	 * @var string
	 */
	protected string $region;

	/**
	 * Available regions
	 *
	 * @var array
	 */
	protected array $regions = [];

	/**
	 * Additional parameters
	 *
	 * @var array
	 */
	protected array $params;

	/**
	 * Constructor
	 *
	 * @param string $region Region code
	 * @param array  $params Additional parameters
	 *
	 * @throws InvalidArgumentException If a region is invalid
	 */
	public function __construct( string $region = '', array $params = [] ) {
		// Set region or use default
		$this->region = ! empty( $region ) ? $region : $this->get_default_region();
		$this->params = $params;

		// Validate region if provided
		if ( ! empty( $region ) && ! $this->is_valid_region( $region ) ) {
			throw new InvalidArgumentException( sprintf(
				'Invalid region "%s" for provider "%s". Available regions: %s',
				$region,
				$this->get_label(),
				implode( ', ', array_keys( $this->regions ) )
			) );
		}
	}

	/**
	 * Get provider ID
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Get provider label
	 *
	 * @return string
	 */
	public function get_label(): string {
		return $this->label;
	}

	/**
	 * Get provider region
	 *
	 * @return string
	 */
	public function get_region(): string {
		return $this->region;
	}

	/**
	 * Check if the provider uses path-style URLs
	 *
	 * @return bool
	 */
	public function uses_path_style(): bool {
		return $this->path_style;
	}

	/**
	 * Check if a region is valid for this provider
	 *
	 * @param string $region Region code to check
	 *
	 * @return bool True if the region is valid
	 */
	public function is_valid_region( string $region ): bool {
		return isset( $this->regions[ $region ] );
	}

	/**
	 * Format URL encode S3 object key preserving path structure
	 *
	 * @param string $object_key S3 object key to encode
	 *
	 * @return string Encoded object key
	 */
	protected function encode_object_key( string $object_key ): string {
		// Remove any leading slash
		$object_key = ltrim( $object_key, '/' );

		// Replace + with space (for consistent handling)
		$object_key = str_replace( '+', ' ', $object_key );

		// URL encode the key but preserve slashes
		return str_replace( '%2F', '/', rawurlencode( $object_key ) );
	}

	/**
	 * Format bucket URL
	 *
	 * @param string $bucket Bucket name
	 * @param string $object Optional object key
	 *
	 * @return string
	 */
	public function format_url( string $bucket, string $object = '' ): string {
		$endpoint       = $this->get_endpoint();
		$encoded_object = empty( $object ) ? '' : $this->encode_object_key( $object );

		if ( $this->uses_path_style() ) {
			return 'https://' . $endpoint . '/' . $bucket .
			       ( empty( $encoded_object ) ? '' : '/' . $encoded_object );
		} else {
			return 'https://' . $bucket . '.' . $endpoint .
			       ( empty( $encoded_object ) ? '' : '/' . $encoded_object );
		}
	}

	/**
	 * Format object URI for signing
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 *
	 * @return string
	 */
	public function format_canonical_uri( string $bucket, string $object_key ): string {
		if ( empty( $bucket ) ) {
			return '/';
		}

		if ( $this->uses_path_style() ) {
			if ( empty( $object_key ) ) {
				return '/' . $bucket;
			}

			return '/' . $bucket . '/' . ltrim( $object_key, '/' );
		} else {
			return '/' . ltrim( $object_key, '/' );
		}
	}

	/**
	 * Get available regions as associative array of code => label
	 *
	 * @return array
	 */
	public function get_available_regions(): array {
		$result = [];

		foreach ( $this->regions as $code => $region ) {
			if ( is_array( $region ) ) {
				$result[ $code ] = $region['label'] ?? $code;
			} else {
				$result[ $code ] = $region;
			}
		}

		return $result;
	}

	/**
	 * Get list of available region codes
	 *
	 * @return array
	 */
	public function get_region_codes(): array {
		return array_keys( $this->regions );
	}

	/**
	 * Get comma-separated list of available region codes
	 *
	 * @return string
	 */
	public function get_region_codes_list(): string {
		return implode( ', ', $this->get_region_codes() );
	}

	/**
	 * Get region data by code
	 *
	 * @param string $region_code Region code
	 *
	 * @return array|null Region data or null if not found
	 */
	public function get_region_data( string $region_code ): ?array {
		if ( ! isset( $this->regions[ $region_code ] ) ) {
			return null;
		}

		$region = $this->regions[ $region_code ];

		if ( is_array( $region ) ) {
			return $region;
		}

		return [
			'label' => $region,
			'code'  => $region_code
		];
	}

	/**
	 * Get region attribute value
	 *
	 * @param string $region_code   Region code
	 * @param string $attribute     Attribute name
	 * @param mixed  $default_value Default value if attribute doesn't exist
	 *
	 * @return mixed Attribute value or default
	 */
	public function get_region_attribute( string $region_code, string $attribute, $default_value = null ) {
		$region_data = $this->get_region_data( $region_code );
		if ( ! $region_data ) {
			return $default_value;
		}

		return $region_data[ $attribute ] ?? $default_value;
	}

	/**
	 * Get current region's attribute value
	 *
	 * @param string $attribute     Attribute name
	 * @param mixed  $default_value Default value if attribute doesn't exist
	 *
	 * @return mixed Attribute value or default
	 */
	public function get_current_region_attribute( string $attribute, $default_value = null ) {
		return $this->get_region_attribute( $this->region, $attribute, $default_value );
	}

	/**
	 * Get parameter value
	 *
	 * @param string $key     Parameter key
	 * @param mixed  $default Default value if parameter doesn't exist
	 *
	 * @return mixed Parameter value or default
	 */
	protected function get_param( string $key, $default = null ) {
		return $this->params[ $key ] ?? $default;
	}

	/**
	 * Check if the provider has integrated CDN
	 *
	 * @return bool Default is false, providers can override
	 */
	public function has_integrated_cdn(): bool {
		return false;
	}

	/**
	 * Get CDN URL for a bucket (if supported)
	 *
	 * @param string $bucket Bucket name
	 * @param string $object Optional object key
	 *
	 * @return string|null CDN URL or null if not supported
	 */
	public function get_cdn_url( string $bucket, string $object = '' ): ?string {
		if ( ! $this->has_integrated_cdn() ) {
			return null;
		}

		// Base implementation - providers should override this
		return $this->format_url( $bucket, $object );
	}

	/**
	 * Get public URL for a bucket (if configured)
	 *
	 * @param string $bucket Bucket name
	 * @param string $object Optional object key
	 *
	 * @return string|null Public URL or null if not configured
	 */
	public function get_public_url( string $bucket, string $object = '' ): ?string {
		// Check if a custom domain is configured
		$custom_domain = $this->get_param( 'custom_domain_' . $bucket );
		if ( ! empty( $custom_domain ) ) {
			return 'https://' . $custom_domain . '/' . ltrim( $object, '/' );
		}

		// Check if a public bucket URL is configured
		$public_url = $this->get_param( 'public_url_' . $bucket );
		if ( ! empty( $public_url ) ) {
			return rtrim( $public_url, '/' ) . '/' . ltrim( $object, '/' );
		}

		return null;
	}

	/**
	 * Check if the provider supports public buckets
	 *
	 * @return bool Default is false, providers can override
	 */
	public function supports_public_buckets(): bool {
		return false;
	}

	/**
	 * Set a custom domain for a bucket
	 *
	 * @param string $bucket        Bucket name
	 * @param string $custom_domain Custom domain (without protocol)
	 *
	 * @return self
	 */
	public function set_custom_domain( string $bucket, string $custom_domain ): self {
		$this->params[ 'custom_domain_' . $bucket ] = $custom_domain;

		return $this;
	}

	/**
	 * Set public URL for a bucket
	 *
	 * @param string $bucket     Bucket name
	 * @param string $public_url Public URL
	 *
	 * @return self
	 */
	public function set_public_url( string $bucket, string $public_url ): self {
		$this->params[ 'public_url_' . $bucket ] = $public_url;

		return $this;
	}

	/**
	 * Set a parameter value
	 *
	 * @param string $key   Parameter key
	 * @param mixed  $value Parameter value
	 *
	 * @return self
	 */
	public function set_param( string $key, $value ): self {
		$this->params[ $key ] = $value;

		return $this;
	}

}