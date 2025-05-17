<?php
/**
 * Wasabi Provider
 *
 * Provider implementation for Wasabi storage.
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
 * Class WasabiStorage
 */
class WasabiStorage extends Provider {

	/**
	 * Provider ID
	 *
	 * @var string
	 */
	protected string $id = 'wasabi';

	/**
	 * Provider label
	 *
	 * @var string
	 */
	protected string $label = 'Wasabi';

	/**
	 * Endpoint pattern
	 *
	 * @var string
	 */
	protected string $endpoint_pattern = 's3.{region}.wasabisys.com';

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
		// North America
		'us-east-1' => [
			'label' => 'Virginia 1',
			'code'  => 'us-east-1'
		],
		'us-east-2' => [
			'label' => 'Virginia 2',
			'code'  => 'us-east-2'
		],
		'us-central-1' => [
			'label' => 'Plano, TX',
			'code'  => 'us-central-1'
		],
		'ca-central-1' => [
			'label' => 'Toronto, Canada',
			'code'  => 'ca-central-1'
		],
		'us-west-1' => [
			'label' => 'Oregon',
			'code'  => 'us-west-1'
		],
		// Europe
		'eu-west-1' => [
			'label' => 'London, England',
			'code'  => 'eu-west-1'
		],
		'eu-west-2' => [
			'label' => 'Paris, France',
			'code'  => 'eu-west-2'
		],
		'eu-central-1' => [
			'label' => 'Amsterdam, Netherlands',
			'code'  => 'eu-central-1'
		],
		'eu-central-2' => [
			'label' => 'Frankfurt, Germany',
			'code'  => 'eu-central-2'
		],
		// Asia Pacific
		'ap-northeast-1' => [
			'label' => 'Tokyo, Japan',
			'code'  => 'ap-northeast-1'
		],
		'ap-northeast-2' => [
			'label' => 'Osaka, Japan',
			'code'  => 'ap-northeast-2'
		],
		'ap-southeast-2' => [
			'label' => 'Sydney, Australia',
			'code'  => 'ap-southeast-2'
		],
		'ap-southeast-1' => [
			'label' => 'Singapore',
			'code'  => 'ap-southeast-1'
		]
	];

	/**
	 * Get default region
	 *
	 * @return string
	 */
	public function get_default_region(): string {
		return 'us-east-1';
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
	 * Check if provider has integrated CDN
	 *
	 * @return bool
	 */
	public function has_integrated_cdn(): bool {
		return true;
	}

	/**
	 * Get CDN URL for a bucket
	 *
	 * @param string $bucket Bucket name
	 * @param string $object Optional object key
	 *
	 * @return string|null CDN URL or null if not supported
	 */
	public function get_cdn_url( string $bucket, string $object = '' ): ?string {
		$cdn_domain = $this->get_param( 'cdn_domain_' . $bucket );

		if ( ! empty( $cdn_domain ) ) {
			return 'https://' . $cdn_domain . '/' . ltrim( $object, '/' );
		}

		// Fall back to standard URL
		return $this->format_url( $bucket, $object );
	}

	/**
	 * Set CDN domain for a bucket
	 *
	 * @param string $bucket     Bucket name
	 * @param string $cdn_domain CDN domain (without protocol)
	 *
	 * @return self
	 */
	public function set_cdn_domain( string $bucket, string $cdn_domain ): self {
		$this->params[ 'cdn_domain_' . $bucket ] = $cdn_domain;

		return $this;
	}

}