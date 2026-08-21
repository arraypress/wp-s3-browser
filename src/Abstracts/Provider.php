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
	 * SigV4 requires the canonical URI to be the *URI-encoded* resource path, and
	 * it must match byte-for-byte what actually goes on the wire. This method
	 * therefore applies exactly the same encoding as format_url() — anything else
	 * yields SignatureDoesNotMatch for any key containing a space or a character
	 * outside the RFC 3986 unreserved set.
	 *
	 * Used by:
	 * - Authentication trait (generate_auth_headers method)
	 * - Presigned URL generation (for signature calculation)
	 *
	 * Examples:
	 * Path-style: /bucket/folder/file%20with%20spaces.jpg
	 * Virtual-hosted: /folder/file%20with%20spaces.jpg
	 * Service-level (empty bucket): /
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key (raw; encoded here)
	 *
	 * @return string Canonical URI for signature calculation (starts with /)
	 */
	public function format_canonical_uri( string $bucket, string $object_key ): string {
		if ( empty( $bucket ) ) {
			return '/';
		}

		$encoded_key = empty( $object_key ) ? '' : Encode::object_key( $object_key );

		// Under virtual-hosted addressing the bucket lives in the Host header,
		// so it must NOT be repeated in the path.
		if ( ! $this->uses_path_style() ) {
			return '/' . $encoded_key;
		}

		return '/' . rawurlencode( $bucket ) . ( '' === $encoded_key ? '' : '/' . $encoded_key );
	}

	/**
	 * Get the Host header value for a request against a given bucket
	 *
	 * The Host header is part of every SigV4 canonical request, so the value
	 * signed has to be the host the request is actually sent to. Under
	 * virtual-hosted addressing that is "bucket.endpoint", not the bare
	 * endpoint — signing the latter while requesting the former is an
	 * immediate SignatureDoesNotMatch.
	 *
	 * @param string $bucket Bucket name ('' for service-level operations)
	 *
	 * @return string Host header value
	 */
	public function get_request_host( string $bucket = '' ): string {
		$endpoint = $this->get_endpoint();

		if ( empty( $bucket ) || $this->uses_path_style() ) {
			return $endpoint;
		}

		return $bucket . '.' . $endpoint;
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

		// Add query parameters if provided.
		//
		// PHP_QUERY_RFC3986 is required, not cosmetic: the signature is computed
		// over rawurlencode()'d parameters, and http_build_query()'s default
		// RFC1738 encoding renders a space as "+" and a tilde as "%7E". Any
		// prefix or continuation token containing either would then be signed
		// one way and sent another.
		if ( ! empty( $query_params ) ) {
			$url .= '?' . http_build_query( $query_params, '', '&', PHP_QUERY_RFC3986 );
		}

		return $url;
	}

}