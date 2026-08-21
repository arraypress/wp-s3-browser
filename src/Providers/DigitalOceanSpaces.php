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
 * @author      David Sherlock
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

}