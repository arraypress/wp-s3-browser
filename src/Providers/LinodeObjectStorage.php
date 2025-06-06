<?php
/**
 * Linode Object Storage Provider
 *
 * Provider implementation for Linode Object Storage.
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
 * Class LinodeObjectStorage
 */
class LinodeObjectStorage extends Provider {

	/**
	 * Provider ID
	 *
	 * @var string
	 */
	protected string $id = 'linode';

	/**
	 * Provider label
	 *
	 * @var string
	 */
	protected string $label = 'Linode Object Storage';

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
		'us-southeast' => [
			'label'    => 'Atlanta, GA, United States',
			'endpoint' => 'us-southeast-1.linodeobjects.com'
		],
		'us-ord'       => [
			'label'    => 'Chicago, IL, United States',
			'endpoint' => 'us-ord-1.linodeobjects.com'
		],
		'us-lax'       => [
			'label'    => 'Los Angeles, CA, United States',
			'endpoint' => 'us-lax-1.linodeobjects.com'
		],
		'us-mia'       => [
			'label'    => 'Miami, FL, United States',
			'endpoint' => 'us-mia-1.linodeobjects.com'
		],
		'us-east'      => [
			'label'    => 'Newark, NJ, United States',
			'endpoint' => 'us-east-1.linodeobjects.com'
		],
		'us-sea'       => [
			'label'    => 'Seattle, WA, United States',
			'endpoint' => 'us-sea-1.linodeobjects.com'
		],
		'us-iad'       => [
			'label'    => 'Washington, DC, United States',
			'endpoint' => 'us-iad-1.linodeobjects.com'
		],
		'id-cgk'       => [
			'label'    => 'Jakarta, Indonesia',
			'endpoint' => 'id-cgk-1.linodeobjects.com'
		],
		'in-maa'       => [
			'label'    => 'Chennai, India',
			'endpoint' => 'in-maa-1.linodeobjects.com'
		],
		'in-bom-2'     => [
			'label'    => 'Mumbai 2, India',
			'endpoint' => 'in-bom-1.linodeobjects.com'
		],
		'jp-osa'       => [
			'label'    => 'Osaka, Japan',
			'endpoint' => 'jp-osa-1.linodeobjects.com'
		],
		'jp-tyo-3'     => [
			'label'    => 'Tokyo 3, Japan',
			'endpoint' => 'jp-tyo-1.linodeobjects.com'
		],
		'ap-south'     => [
			'label'    => 'Singapore',
			'endpoint' => 'ap-south-1.linodeobjects.com'
		],
		'sg-sin-2'     => [
			'label'    => 'Singapore 2',
			'endpoint' => 'sg-sin-1.linodeobjects.com'
		],
		'eu-central'   => [
			'label'    => 'Frankfurt, Germany',
			'endpoint' => 'eu-central-1.linodeobjects.com'
		],
		'de-fra-2'     => [
			'label'    => 'Frankfurt 2, Germany',
			'endpoint' => 'de-fra-1.linodeobjects.com'
		],
		'es-mad'       => [
			'label'    => 'Madrid, Spain',
			'endpoint' => 'es-mad-1.linodeobjects.com'
		],
		'fr-par'       => [
			'label'    => 'Paris, France',
			'endpoint' => 'fr-par-1.linodeobjects.com'
		],
		'gb-lon'       => [
			'label'    => 'London 2, Great Britain',
			'endpoint' => 'gb-lon-1.linodeobjects.com'
		],
		'it-mil'       => [
			'label'    => 'Milan, Italy',
			'endpoint' => 'it-mil-1.linodeobjects.com'
		],
		'nl-ams'       => [
			'label'    => 'Amsterdam, Netherlands',
			'endpoint' => 'nl-ams-1.linodeobjects.com'
		],
		'se-sto'       => [
			'label'    => 'Stockholm, Sweden',
			'endpoint' => 'se-sto-1.linodeobjects.com'
		],
		'au-mel'       => [
			'label'    => 'Melbourne, Australia',
			'endpoint' => 'au-mel-1.linodeobjects.com'
		],
		'br-gru'       => [
			'label'    => 'Sao Paulo, Brazil',
			'endpoint' => 'br-gru-1.linodeobjects.com'
		]
	];

	/**
	 * Get default region
	 *
	 * @return string
	 */
	public function get_default_region(): string {
		return 'us-east';
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
				implode( ', ', array_keys( $this->regions ) )
			) );
		}

		// Get the endpoint for the selected region
		if ( ! isset( $this->regions[ $this->region ]['endpoint'] ) ) {
			throw new InvalidArgumentException( sprintf(
				'No endpoint found for region "%s"',
				$this->region
			) );
		}

		return $this->regions[ $this->region ]['endpoint'];
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
	 * Check if provider supports public buckets
	 *
	 * @return bool
	 */
	public function supports_public_buckets(): bool {
		return true;
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
		// First check if parent method provides a custom domain or public URL
		$url = parent::get_public_url( $bucket, $object );
		if ( $url ) {
			return $url;
		}

		// Now check if this is a static website enabled bucket
		$is_website = $this->get_param( 'website_enabled_' . $bucket, false );
		if ( $is_website ) {
			$endpoint = $this->get_endpoint();

			return 'https://' . $bucket . '.' . $endpoint . '/' . ltrim( $object, '/' );
		}

		return null;
	}

	/**
	 * Enable static website for a bucket
	 *
	 * @param string $bucket  Bucket name
	 * @param bool   $enabled Whether to enable or disable
	 *
	 * @return self
	 */
	public function set_website_enabled( string $bucket, bool $enabled = true ): self {
		$this->params[ 'website_enabled_' . $bucket ] = $enabled;

		return $this;
	}

}