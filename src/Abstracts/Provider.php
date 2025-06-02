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
	 * Format bucket URL for HTTP requests
	 *
	 * This is the primary URL building method used throughout the S3 client.
	 * It automatically handles object key encoding and chooses between path-style
	 * and virtual-hosted style URLs based on the provider configuration.
	 *
	 * Used by:
	 * - Signer traits for GET/PUT/DELETE operations
	 * - Public URL generation
	 * - Presigned URL generation (base URL)
	 *
	 * Examples:
	 * Path-style (Cloudflare R2): https://account.r2.cloudflarestorage.com/bucket/folder/file.jpg
	 * Virtual-hosted (AWS S3): https://bucket.s3.amazonaws.com/folder/file.jpg
	 *
	 * @param string $bucket Bucket name
	 * @param string $object Optional object key (will be URL-encoded automatically)
	 *
	 * @return string Complete HTTPS URL ready for HTTP requests
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
	 * Format object URI for AWS Signature Version 4 signing
	 *
	 * This creates the canonical URI component used in AWS signature calculation.
	 * Unlike format_url(), this does NOT URL-encode the object key because encoding
	 * happens at a different stage in the signing process.
	 *
	 * Used exclusively by:
	 * - Authentication trait (generate_auth_headers method)
	 * - Presigned URL generation (for signature calculation)
	 *
	 * Examples:
	 * Path-style: /bucket/folder/file with spaces.jpg
	 * Virtual-hosted: /folder/file with spaces.jpg
	 * Service-level (empty bucket): /
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key (NOT URL-encoded)
	 *
	 * @return string Canonical URI for signature calculation (starts with /)
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
	 * Used for:
	 * - URL validation before parsing
	 * - Determining which provider handles a given URL
	 * - Security checks to ensure URLs are from expected sources
	 *
	 * Checks against:
	 * - Main provider endpoint
	 * - Alternative endpoints (dev/staging)
	 * - Custom domains configured via set_custom_domain()
	 *
	 * @param string $url URL to check (with or without protocol)
	 *
	 * @return bool True if the URL belongs to this provider
	 */
	public function is_provider_url( string $url ): bool {
		if ( empty( $url ) ) {
			return false;
		}

		// Remove protocol for easier matching
		$url_without_protocol = preg_replace( '/^https?:\/\//', '', $url );

		// Check against the main endpoint
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
	 * Parses various URL formats to extract bucket and object information.
	 * This is useful for:
	 * - Processing URLs from external sources
	 * - Converting between URL formats
	 * - Extracting metadata from existing URLs
	 *
	 * Supports:
	 * - Path-style URLs: https://endpoint/bucket/object
	 * - Virtual-hosted URLs: https://bucket.endpoint/object
	 * - Custom domain URLs: https://custom.domain.com/object
	 *
	 * Returns null if URL doesn't belong to this provider.
	 *
	 * @param string $url Provider URL to parse
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
	 * Allows configuring custom domains for public bucket access.
	 * Used for:
	 * - CDN integration
	 * - Brand-consistent URLs
	 * - Public file serving
	 *
	 * Example:
	 * $provider->set_custom_domain('my-bucket', 'cdn.mysite.com');
	 * // Results in URLs like: https://cdn.mysite.com/file.jpg
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
	 * Base implementation - providers should override this
	 *
	 * @param string $bucket Bucket name
	 * @param string $object Optional object key
	 *
	 * @return string|null CDN URL or null if not supported
	 */
	public function get_cdn_url( string $bucket, string $object = '' ): ?string {
		return null;
	}

	/**
	 * Get public URL for a bucket (if configured)
	 *
	 * Returns a public URL for accessing objects without authentication.
	 * Used for:
	 * - Public file downloads
	 * - Image/asset serving
	 * - Static website hosting
	 *
	 * Checks in order:
	 * 1. Custom domain (via set_custom_domain)
	 * 2. Public URL parameter (via set_param)
	 * 3. Returns null if no public access configured
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
	 * Used internally by is_provider_url() to match URLs against endpoints.
	 * Handles both direct matches and virtual-hosted style patterns.
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
	 * Used internally by parse_provider_url().
	 * Handles URLs like: https://endpoint.com/bucket/folder/file.jpg
	 *
	 * @param string $url_without_protocol URL without protocol
	 *
	 * @return array|null Array with bucket/object or null if parsing fails
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
	 * Used internally by parse_provider_url().
	 * Handles URLs like: https://bucket.s3.amazonaws.com/folder/file.jpg
	 *
	 * @param string $url_without_protocol URL without protocol
	 *
	 * @return array|null Array with bucket/object or null if parsing fails
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
		$main_endpoint  = $this->get_endpoint();
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
	 * Used internally by parse_provider_url().
	 * Handles URLs from custom domains set via set_custom_domain().
	 * Example: https://cdn.mysite.com/folder/file.jpg
	 *
	 * @param string $url_without_protocol URL without protocol
	 *
	 * @return array|null Array with bucket/object or null if no custom domain matches
	 */
	protected function parse_custom_domain_url( string $url_without_protocol ): ?array {
		// Check each custom domain to see which bucket it belongs to
		foreach ( $this->params as $key => $domain ) {
			if ( str_starts_with( $key, 'custom_domain_' ) && str_starts_with( $url_without_protocol, $domain ) ) {
				$bucket = str_replace( 'custom_domain_', '', $key );

				// Extract object path
				$domain_length = strlen( $domain );
				$remaining     = substr( $url_without_protocol, $domain_length );
				$object        = ltrim( $remaining, '/' );

				return [
					'bucket' => $bucket,
					'object' => $object ?: ''
				];
			}
		}

		return null;
	}

	/**
	 * Build URL with pre-encoded object key (for presigned URLs)
	 *
	 * Used when you already have an encoded key and don't want double-encoding.
	 * Specifically for presigned URL generation.
	 *
	 * @param string $bucket      Bucket name
	 * @param string $encoded_key Already URL-encoded object key
	 *
	 * @return string Complete HTTPS URL
	 */
	public function format_url_with_encoded_key( string $bucket, string $encoded_key ): string {
		$endpoint = $this->get_endpoint();

		if ( $this->uses_path_style() ) {
			return 'https://' . $endpoint . '/' . $bucket . '/' . $encoded_key;
		} else {
			return 'https://' . $bucket . '.' . $endpoint . '/' . $encoded_key;
		}
	}

	/**
	 * Build URL with encoded object key for specific operations
	 *
	 * Builds a complete URL with an already-encoded object key.
	 * This is useful for operations like DELETE where you need precise
	 * control over the encoding process.
	 *
	 * Used primarily by:
	 * - Delete operations
	 * - Operations requiring custom encoding
	 * - Cases where the key is pre-encoded
	 *
	 * @param string $bucket      Bucket name
	 * @param string $encoded_key Already URL-encoded object key
	 *
	 * @return string Complete HTTPS URL
	 */
	public function build_url_with_encoded_key( string $bucket, string $encoded_key ): string {
		$endpoint = $this->get_endpoint();

		if ( $this->uses_path_style() ) {
			return 'https://' . $endpoint . '/' . $bucket . '/' . $encoded_key;
		} else {
			return 'https://' . $bucket . '.' . $endpoint . '/' . $encoded_key;
		}
	}

	/**
	 * Build URL with query parameters
	 *
	 * Handles both service-level and bucket/object operations with query params.
	 *
	 * @param string $bucket       Optional bucket name (empty for service-level)
	 * @param string $object       Optional object key (will be URL-encoded)
	 * @param array  $query_params Optional query parameters
	 *
	 * @return string Complete URL with query parameters
	 */
	public function build_url_with_query( string $bucket = '', string $object = '', array $query_params = [] ): string {
		// For service-level operations (like list buckets), use endpoint directly
		if ( empty( $bucket ) ) {
			$url = 'https://' . $this->get_endpoint();
		} else {
			$url = $this->format_url( $bucket, $object );
		}

		// Add query parameters if provided
		if ( ! empty( $query_params ) ) {
			$url .= '?' . http_build_query( $query_params );
		}

		return $url;
	}

	/**
	 * Get base bucket URL (for batch operations)
	 *
	 * Returns just the bucket URL without an object, useful for operations
	 * like batch delete that append custom query strings.
	 *
	 * @param string $bucket Bucket name
	 *
	 * @return string Base bucket URL
	 */
	public function get_bucket_base_url( string $bucket ): string {
		$endpoint = $this->get_endpoint();

		if ( $this->uses_path_style() ) {
			return 'https://' . $endpoint . '/' . $bucket;
		} else {
			return 'https://' . $bucket . '.' . $endpoint;
		}
	}

}