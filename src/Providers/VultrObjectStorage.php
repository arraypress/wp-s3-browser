<?php
/**
 * Vultr Object Storage Provider
 *
 * Provider implementation for Vultr Object Storage.
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

/**
 * Class VultrObjectStorage
 */
class VultrObjectStorage extends Provider {

	/**
	 * Provider ID
	 *
	 * @var string
	 */
	protected string $id = 'vultr';

	/**
	 * Provider label
	 *
	 * @var string
	 */
	protected string $label = 'Vultr Object Storage';

	/**
	 * Endpoint pattern
	 *
	 * @var string
	 */
	protected string $endpoint_pattern = '{region}.vultrobjects.com';

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
		'ams1' => [
			'label' => 'Amsterdam',
			'code'  => 'ams1'
		],
		'blr1' => [
			'label' => 'Bangalore',
			'code'  => 'blr1'
		],
		'sgp1' => [
			'label' => 'Singapore',
			'code'  => 'sgp1'
		],
		'del1' => [
			'label' => 'New Delhi',
			'code'  => 'del1'
		],
		'ewr1' => [
			'label' => 'New Jersey',
			'code'  => 'ewr1'
		],
		'sjc1' => [
			'label' => 'Silicon Valley',
			'code'  => 'sjc1'
		]
	];

	/**
	 * Get default region
	 *
	 * @return string
	 */
	public function get_default_region(): string {
		return 'ewr1';
	}

	/**
	 * Get provider endpoint
	 *
	 * @return string
	 * @throws InvalidArgumentException If region is invalid
	 */
	public function get_endpoint(): string {
		if ( empty( $this->region ) || ! $this->is_valid_region( $this->region ) ) {
			throw new InvalidArgumentException( sprintf(
				'Invalid region "%s" for provider "%s". Available regions: %s',
				$this->region,
				$this->get_label(),
				$this->get_region_codes_list()
			) );
		}

		// Replace placeholders in endpoint pattern
		return str_replace(
			[ '{region}' ],
			[ $this->region ],
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
	 * Format canonical URI for Vultr
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 *
	 * @return string
	 */
	public function format_canonical_uri( string $bucket, string $object_key = '' ): string {
		// For Vultr, always use path-style
		if ( empty( $bucket ) ) {
			return '/';
		}

		// For bucket operations, use '/bucket'
		if ( empty( $object_key ) ) {
			return '/' . $bucket;
		}

		// For object operations, use '/bucket/object'
		return '/' . $bucket . '/' . ltrim( $object_key, '/' );
	}
}