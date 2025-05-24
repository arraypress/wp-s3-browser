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
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Abstracts;

use ArrayPress\S3\Interfaces\Provider as ProviderInterface;
use ArrayPress\S3\Utils\Encode;
use InvalidArgumentException;

/**
 * Class Provider
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
	 * Get provider endpoint
	 *
	 * @return string
	 */
	abstract public function get_endpoint(): string;

	/**
	 * Get default region
	 *
	 * @return string
	 */
	abstract public function get_default_region(): string;

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
	 * Format bucket URL
	 *
	 * @param string $bucket Bucket name
	 * @param string $object Optional object key
	 *
	 * @return string
	 */
	public function format_url( string $bucket, string $object = '' ): string {
		$endpoint       = $this->get_endpoint();
		$encoded_object = empty( $object ) ? '' : Encode::object_key( $object );

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
	 * Check if a URL belongs to this provider
	 *
	 * @param string $url URL to check
	 *
	 * @return bool True if URL belongs to this provider
	 */
	public function is_provider_url( string $url ): bool {
		if ( empty( $url ) ) {
			return false;
		}

		// Remove protocol for easier matching
		$url_without_protocol = preg_replace( '/^https?:\/\//', '', $url );

		// Check against main endpoint
		$main_endpoint = $this->get_endpoint();
		if ( $this->url_matches_endpoint( $url_without_protocol, $main_endpoint ) ) {
			return true;
		}

		// Check against alternative endpoints (dev, staging, etc.)
		$alternative_endpoints = $this->get_alternative_endpoints();
		foreach ( $alternative_endpoints as $endpoint ) {
			if ( $this->url_matches_endpoint( $url_without_protocol, $endpoint ) ) {
				return true;
			}
		}

		// Check against configured custom domains
		$custom_domains = $this->get_all_custom_domains();
		foreach ( $custom_domains as $domain ) {
			if ( str_starts_with( $url_without_protocol, $domain ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extract bucket and object from a provider URL
	 *
	 * @param string $url Provider URL
	 *
	 * @return array|null Array with 'bucket' and 'object' keys, or null if invalid
	 */
	public function parse_provider_url( string $url ): ?array {
		if ( ! $this->is_provider_url( $url ) ) {
			return null;
		}

		// Remove protocol and query parameters
		$url_without_protocol = preg_replace( '/^https?:\/\//', '', $url );
		$url_without_protocol = strtok( $url_without_protocol, '?' );

		// Try path-style parsing first if this provider uses it
		if ( $this->uses_path_style() ) {
			$result = $this->parse_path_style_url( $url_without_protocol );
			if ( $result ) {
				return $result;
			}
		}

		// Try virtual-hosted style parsing
		$result = $this->parse_virtual_hosted_style_url( $url_without_protocol );
		if ( $result ) {
			return $result;
		}

		// Try custom domain parsing
		return $this->parse_custom_domain_url( $url_without_protocol );
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
	 * Get available regions as an associative array of code => label
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
		// Base implementation - providers should override this
		return null;
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
	 * Check if account ID is required
	 * Override in specific providers
	 *
	 * @return bool
	 */
	public function requires_account_id(): bool {
		return false;
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

	/**
	 * Get alternative endpoints for development/staging
	 * Override in specific providers
	 *
	 * @return array Array of alternative endpoint hostnames
	 */
	protected function get_alternative_endpoints(): array {
		return [];
	}

	/**
	 * Get all configured custom domains
	 *
	 * @return array Array of custom domains
	 */
	protected function get_all_custom_domains(): array {
		$domains = [];

		foreach ( $this->params as $key => $value ) {
			if ( str_starts_with( $key, 'custom_domain_' ) && ! empty( $value ) ) {
				$domains[] = $value;
			}
		}

		return $domains;
	}

	/**
	 * Check if URL matches an endpoint pattern
	 *
	 * @param string $url_without_protocol URL without protocol
	 * @param string $endpoint             Endpoint to match against
	 *
	 * @return bool
	 */
	protected function url_matches_endpoint( string $url_without_protocol, string $endpoint ): bool {
		// Direct match
		if ( str_starts_with( $url_without_protocol, $endpoint ) ) {
			return true;
		}

		// Virtual-hosted style: bucket.endpoint
		$pattern = '/^[^.]+\.' . preg_quote( $endpoint, '/' ) . '/';
		if ( preg_match( $pattern, $url_without_protocol ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Parse path-style URL (host.com/bucket/object)
	 *
	 * @param string $url_without_protocol URL without protocol
	 *
	 * @return array|null
	 */
	protected function parse_path_style_url( string $url_without_protocol ): ?array {
		// Split by slash
		$parts = explode( '/', $url_without_protocol );

		if ( count( $parts ) < 2 ) {
			return null;
		}

		// Remove the host part
		array_shift( $parts );

		if ( empty( $parts ) ) {
			return null;
		}

		$bucket = array_shift( $parts );
		$object = implode( '/', $parts );

		return [
			'bucket' => $bucket,
			'object' => $object
		];
	}

	/**
	 * Parse virtual-hosted style URL (bucket.host.com/object)
	 *
	 * @param string $url_without_protocol URL without protocol
	 *
	 * @return array|null
	 */
	protected function parse_virtual_hosted_style_url( string $url_without_protocol ): ?array {
		// Split by slash to separate host from path
		$first_slash = strpos( $url_without_protocol, '/' );

		if ( $first_slash === false ) {
			// No path, just host
			$host = $url_without_protocol;
			$path = '';
		} else {
			$host = substr( $url_without_protocol, 0, $first_slash );
			$path = substr( $url_without_protocol, $first_slash + 1 );
		}

		// Extract bucket from subdomain
		$main_endpoint = $this->get_endpoint();
		$bucket_pattern = '/^([^.]+)\.' . preg_quote( $main_endpoint, '/' ) . '$/';

		if ( preg_match( $bucket_pattern, $host, $matches ) ) {
			return [
				'bucket' => $matches[1],
				'object' => $path
			];
		}

		return null;
	}

	/**
	 * Parse custom domain URL
	 *
	 * @param string $url_without_protocol URL without protocol
	 *
	 * @return array|null
	 */
	protected function parse_custom_domain_url( string $url_without_protocol ): ?array {
		// Check each custom domain to see which bucket it belongs to
		foreach ( $this->params as $key => $domain ) {
			if ( str_starts_with( $key, 'custom_domain_' ) && str_starts_with( $url_without_protocol, $domain ) ) {
				$bucket = str_replace( 'custom_domain_', '', $key );

				// Extract object path
				$domain_length = strlen( $domain );
				$remaining = substr( $url_without_protocol, $domain_length );
				$object = ltrim( $remaining, '/' );

				return [
					'bucket' => $bucket,
					'object' => $object ?: ''
				];
			}
		}

		return null;
	}

}