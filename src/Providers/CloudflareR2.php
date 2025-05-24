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
 * @author      David Sherlock
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
			'code'   => 'auto',
		],
		'fedramp' => [
			'label'  => 'FedRAMP',
			'prefix' => 'fedramp.',
			'code'   => 'auto',
		],
		'apac'    => [
			'label'  => 'Asia Pacific',
			'prefix' => 'apac.',
			'code'   => 'auto',
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

		// Get a region prefix
		$region_prefix = '';
		if ( isset( $this->regions[ $this->region ]['prefix'] ) ) {
			$region_prefix = $this->regions[ $this->region ]['prefix'];
		}

		// Replace placeholders in endpoint pattern
		return str_replace(
			[ '{account_id}', '{region_prefix}' ],
			[ $account_id, $region_prefix ],
			$this->endpoint_pattern
		);
	}

	/**
	 * Get alternative endpoints for R2 public domains and regional endpoints
	 *
	 * @return array Array of alternative endpoint patterns
	 */
	protected function get_alternative_endpoints(): array {
		$alternatives = [];
		$account_id = $this->get_param( 'account_id' );

		if ( $account_id ) {
			// R2 public domain pattern
			$alternatives[] = $account_id . '.r2.dev';

			// Regional R2 endpoints
			foreach ( $this->regions as $region_code => $region_data ) {
				if ( isset( $region_data['prefix'] ) && ! empty( $region_data['prefix'] ) ) {
					$alternatives[] = $account_id . '.' . $region_data['prefix'] . 'r2.cloudflarestorage.com';
				}
			}
		}

		return $alternatives;
	}

	/**
	 * Override URL matching to handle R2's special patterns
	 *
	 * @param string $url_without_protocol URL without protocol
	 * @param string $endpoint             Endpoint to match against
	 *
	 * @return bool
	 */
	protected function url_matches_endpoint( string $url_without_protocol, string $endpoint ): bool {
		// Handle R2 public domains: bucket.account-id.r2.dev
		if ( str_ends_with( $endpoint, '.r2.dev' ) ) {
			// Pattern: bucket.account-id.r2.dev
			$pattern = '/^[^.]+\.' . preg_quote( $endpoint, '/' ) . '/';
			return preg_match( $pattern, $url_without_protocol );
		}

		// Use parent logic for standard endpoints
		return parent::url_matches_endpoint( $url_without_protocol, $endpoint );
	}

	/**
	 * Parse R2 public domain URLs and regional endpoints
	 *
	 * @param string $url_without_protocol URL without protocol
	 *
	 * @return array|null
	 */
	protected function parse_virtual_hosted_style_url( string $url_without_protocol ): ?array {
		$account_id = $this->get_param( 'account_id' );

		if ( $account_id ) {
			// Parse R2 public domains: bucket.account-id.r2.dev/object
			$r2_public_pattern = '/^([^.]+)\.' . preg_quote( $account_id, '/' ) . '\.r2\.dev(?:\/(.*))?$/';

			if ( preg_match( $r2_public_pattern, $url_without_protocol, $matches ) ) {
				return [
					'bucket' => $matches[1],
					'object' => isset( $matches[2] ) ? $matches[2] : ''
				];
			}
		}

		// Fall back to parent logic for standard virtual-hosted URLs
		return parent::parse_virtual_hosted_style_url( $url_without_protocol );
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
		// First try the parent method for custom domains
		$url = parent::get_public_url( $bucket, $object );
		if ( $url ) {
			return $url;
		}

		// Try R2-specific public URLs
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

}