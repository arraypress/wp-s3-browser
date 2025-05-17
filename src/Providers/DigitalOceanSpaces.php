<?php
/**
 * DigitalOcean Spaces Provider
 *
 * Provider implementation for DigitalOcean Spaces storage.
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

/**
 * Class DigitalOceanSpacesProvider
 */
class DigitalOceanSpaces extends Provider {

	/**
	 * Provider ID
	 *
	 * @var string
	 */
	protected string $id = 'digitalocean_spaces';

	/**
	 * Provider label
	 *
	 * @var string
	 */
	protected string $label = 'DigitalOcean Spaces';

	/**
	 * Endpoint pattern
	 *
	 * @var string
	 */
	protected string $endpoint_pattern = '{region}.digitaloceanspaces.com';

	/**
	 * Whether to use path-style URLs
	 *
	 * @var bool
	 */
	protected bool $path_style = false;

	/**
	 * Available regions
	 *
	 * @var array
	 */
	protected array $regions = [
		'nyc3' => [
			'label' => 'New York City, United States',
			'code'  => 'nyc3'
		],
		'sfo3' => [
			'label' => 'San Francisco, United States',
			'code'  => 'sfo3'
		],
		'sfo2' => [
			'label' => 'San Francisco, United States (Legacy)',
			'code'  => 'sfo2'
		],
		'ams3' => [
			'label' => 'Amsterdam, Netherlands',
			'code'  => 'ams3'
		],
		'sgp1' => [
			'label' => 'Singapore',
			'code'  => 'sgp1'
		],
		'fra1' => [
			'label' => 'Frankfurt, Germany',
			'code'  => 'fra1'
		],
		'syd1' => [
			'label' => 'Sydney, Australia',
			'code'  => 'syd1'
		]
	];

	/**
	 * Get default region
	 *
	 * @return string
	 */
	public function get_default_region(): string {
		return 'sfo3';
	}

	/**
	 * Get provider endpoint
	 *
	 * @return string
	 */
	public function get_endpoint(): string {
		// Check if a region is valid
		if ( ! $this->is_valid_region( $this->region ) ) {
			// Fall back to a default region if invalid
			$this->region = $this->get_default_region();
		}

		// Replace placeholders in endpoint pattern
		return str_replace(
			'{region}',
			$this->region,
			$this->endpoint_pattern
		);
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
	 * DigitalOcean Spaces includes a CDN by default
	 *
	 * @return bool
	 */
	public function has_integrated_cdn(): bool {
		return true;
	}

	/**
	 * Get CDN URL for DigitalOcean Spaces
	 *
	 * Each Space has built-in CDN
	 *
	 * @param string $bucket Bucket name (Space name)
	 * @param string $object Optional object key
	 *
	 * @return string CDN URL
	 */
	public function get_cdn_url( string $bucket, string $object = '' ): string {
		// If a custom CDN domain is set for this bucket, use it
		$custom_cdn = $this->get_param( 'custom_cdn_' . $bucket );
		if ( ! empty( $custom_cdn ) ) {
			return 'https://' . $custom_cdn . '/' . ltrim( $object, '/' );
		}

		// Format the standard CDN URL
		return 'https://' . $bucket . '.' . $this->region . '.cdn.digitaloceanspaces.com/' . ltrim( $object, '/' );
	}

	/**
	 * Set a custom CDN domain for a bucket
	 *
	 * @param string $bucket Bucket name
	 * @param string $domain Custom CDN domain (without protocol)
	 *
	 * @return self
	 */
	public function set_custom_cdn( string $bucket, string $domain ): self {
		return $this->set_param( 'custom_cdn_' . $bucket, $domain );
	}

	/**
	 * Format URL specifically for DigitalOcean Spaces
	 *
	 * Overrides the parent method to handle CDN URLs when requested
	 *
	 * @param string $bucket  Bucket name
	 * @param string $object  Optional object key
	 * @param bool   $use_cdn Whether to use CDN URL (default: false)
	 *
	 * @return string
	 */
	public function format_url( string $bucket, string $object = '', bool $use_cdn = false ): string {
		if ( $use_cdn ) {
			return $this->get_cdn_url( $bucket, $object );
		}

		return parent::format_url( $bucket, $object );
	}

	/**
	 * Enable edge caching with custom TTL
	 *
	 * @param int $ttl TTL in seconds
	 *
	 * @return self
	 */
	public function enable_edge_caching( int $ttl = 3600 ): self {
		$this->set_param( 'edge_caching', true );
		$this->set_param( 'edge_caching_ttl', $ttl );

		return $this;
	}

	/**
	 * Disable edge caching
	 *
	 * @return self
	 */
	public function disable_edge_caching(): self {
		return $this->set_param( 'edge_caching', false );
	}

	/**
	 * Check if edge caching is enabled
	 *
	 * @return bool
	 */
	public function is_edge_caching_enabled(): bool {
		return (bool) $this->get_param( 'edge_caching', false );
	}

	/**
	 * Get edge caching TTL
	 *
	 * @return int TTL in seconds
	 */
	public function get_edge_caching_ttl(): int {
		return (int) $this->get_param( 'edge_caching_ttl', 3600 );
	}

}