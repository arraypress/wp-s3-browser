<?php
/**
 * Cloudflare R2 Provider
 *
 * Provider implementation for Cloudflare R2 storage.
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
 * Class CloudflareR2Provider
 */
class CloudflareR2 extends Provider {

	/**
	 * Provider ID
	 *
	 * @var string
	 */
	protected string $id = 'cloudflare_r2';

	/**
	 * Provider label
	 *
	 * @var string
	 */
	protected string $label = 'Cloudflare R2';

	/**
	 * Endpoint pattern
	 *
	 * @var string
	 */
	protected string $endpoint_pattern = '{account_id}.{region_prefix}r2.cloudflarestorage.com';

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
		'default' => [
			'label'  => 'Automatic',
			'prefix' => '',
			'code'   => 'auto',
		],
		'eu'      => [
			'label'  => 'European Union',
			'prefix' => 'eu.',
			'code'   => 'auto',  // R2 still uses 'auto' for signing even in EU region
		],
		'fedramp' => [
			'label'  => 'FedRAMP',
			'prefix' => 'fedramp.',
			'code'   => 'auto',  // R2 still uses 'auto' for signing even in FedRAMP region
		],
		'apac'    => [
			'label'  => 'Asia Pacific',
			'prefix' => 'apac.',
			'code'   => 'auto',  // R2 still uses 'auto' for signing even in APAC region
		]
	];

	/**
	 * Constructor
	 *
	 * @param string $region Region code
	 * @param array  $params Additional parameters
	 *
	 * @throws InvalidArgumentException If account_id is not provided
	 */
	public function __construct( string $region = '', array $params = [] ) {
		// Ensure account_id is provided
		if ( empty( $params['account_id'] ) ) {
			throw new InvalidArgumentException( 'Account ID is required for Cloudflare R2' );
		}

		parent::__construct( $region, $params );
	}

	/**
	 * Get default region
	 *
	 * @return string
	 */
	public function get_default_region(): string {
		return 'default';
	}

	/**
	 * Get provider endpoint
	 *
	 * @return string
	 * @throws InvalidArgumentException If account ID is missing
	 */
	public function get_endpoint(): string {
		// Get account ID from parameters
		$account_id = $this->get_param( 'account_id' );

		if ( empty( $account_id ) ) {
			throw new InvalidArgumentException( 'Account ID is required for Cloudflare R2' );
		}

		// Get a region prefix using the new helper method
		$region_prefix = $this->get_current_region_attribute( 'prefix', '' );

		// Replace placeholders in endpoint pattern
		return str_replace(
			[ '{account_id}', '{region_prefix}' ],
			[ $account_id, $region_prefix ],
			$this->endpoint_pattern
		);
	}

	/**
	 * Check if account ID is required
	 *
	 * @return bool
	 */
	public function requires_account_id(): bool {
		return true;
	}

	/**
	 * Get region for signing
	 *
	 * @return string
	 */
	public function get_region(): string {
		// Cloudflare R2 always uses 'auto' for signing region
		return 'auto';
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
		// First try the parent method
		$url = parent::get_public_url( $bucket, $object );
		if ( $url ) {
			return $url;
		}

		// If not found, try R2-specific public URLs
		$account_id = $this->get_param( 'account_id' );
		if ( ! empty( $account_id ) ) {
			return 'https://' . $bucket . '.' . $account_id . '.r2.dev/' . ltrim( $object, '/' );
		}

		return null;
	}

	/**
	 * Build public bucket URL for Cloudflare R2
	 *
	 * @param string $account_id Cloudflare account ID
	 * @param string $bucket     Bucket name
	 *
	 * @return string Public bucket URL
	 */
	public static function build_public_url( string $account_id, string $bucket ): string {
		return 'https://' . $bucket . '.' . $account_id . '.r2.dev';
	}

	/**
	 * Override URL encoding for R2
	 *
	 * @param string $object_key Object key
	 *
	 * @return string
	 */
	protected function encode_object_key( string $object_key ): string {
		// Start with parent implementation
		$encoded = parent::encode_object_key( $object_key );

		// R2 sometimes has issues with spaces, even encoded ones
		$encoded = str_replace( ' ', '%20', $encoded );

		// Special handling for parentheses
		$encoded = str_replace( '(', '%28', $encoded );
		$encoded = str_replace( ')', '%29', $encoded );

		return $encoded;
	}

}