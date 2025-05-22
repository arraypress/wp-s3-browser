<?php
/**
 * Backblaze B2 Provider
 *
 * Provider implementation for Backblaze B2 storage.
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
 * Class BackblazeB2
 */
class BackblazeB2 extends Provider {

	/**
	 * Provider ID
	 *
	 * @var string
	 */
	protected string $id = 'backblaze';

	/**
	 * Provider label
	 *
	 * @var string
	 */
	protected string $label = 'Backblaze B2';

	/**
	 * Endpoint pattern
	 *
	 * @var string
	 */
	protected string $endpoint_pattern = 's3.{region}.backblazeb2.com';

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
		'us-west-000' => [
			'label' => 'US West (000)',
			'code'  => 'us-west-000'
		],
		'us-west-001' => [
			'label' => 'US West (001)',
			'code'  => 'us-west-001'
		],
		'us-west-002' => [
			'label' => 'US West (002)',
			'code'  => 'us-west-002'
		],
		'us-west-003' => [
			'label' => 'US West (003)',
			'code'  => 'us-west-003'
		],
		'us-west-004' => [
			'label' => 'US West (004)',
			'code'  => 'us-west-004'
		],
		// Europe
		'eu-central-003' => [
			'label' => 'EU Central (003)',
			'code'  => 'eu-central-003'
		]
	];

	/**
	 * Get default region
	 *
	 * @return string
	 */
	public function get_default_region(): string {
		return 'us-west-004';
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
	 * Check if provider supports public buckets
	 *
	 * @return bool
	 */
	public function supports_public_buckets(): bool {
		return true;
	}

	/**
	 * Get public URL for a bucket
	 *
	 * @param string $bucket  Bucket name
	 * @param string $object  Optional object key
	 *
	 * @return string|null Public URL or null if public bucket is not configured
	 */
	public function get_public_url( string $bucket, string $object = '' ): ?string {
		// First try the parent method
		$url = parent::get_public_url( $bucket, $object );
		if ( $url ) {
			return $url;
		}

		// Default to direct download URL for Backblaze
		$account_id = $this->get_param( 'account_id' );
		if ( ! empty( $account_id ) ) {
			return 'https://f' . $account_id . '.backblazeb2.com/file/' . $bucket . '/' . ltrim( $object, '/' );
		}

		return null;
	}

	/**
	 * Set account ID
	 *
	 * @param string $account_id Backblaze account ID
	 *
	 * @return self
	 */
	public function set_account_id( string $account_id ): self {
		return $this->set_param( 'account_id', $account_id );
	}

}