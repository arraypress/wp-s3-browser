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
	 * Get the region for signing
	 *
	 * Cloudflare R2 always uses 'auto' for signing region
	 *
	 * @return string
	 */
	public function get_region(): string {
		return 'auto';
	}

}