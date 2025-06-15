<?php
/**
 * Mega S4 Provider
 *
 * Provider implementation for Mega S4 storage.
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
 * Class MegaS4
 */
class MegaS4 extends Provider {

	/**
	 * Provider ID
	 *
	 * @var string
	 */
	protected string $id = 'mega_s4';

	/**
	 * Provider label
	 *
	 * @var string
	 */
	protected string $label = 'Mega S4';

	/**
	 * Endpoint pattern
	 *
	 * @var string
	 */
	protected string $endpoint_pattern = 's3.{region}.s4.mega.io';

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
		'eu-central-1' => [
			'label'    => 'Amsterdam',
			'location' => 'Amsterdam'
		],
		'eu-central-2' => [
			'label'    => 'Bettembourg',
			'location' => 'Bettembourg'
		],
		'ca-central-1' => [
			'label'    => 'Montreal',
			'location' => 'Montreal'
		],
		'ca-west-1' => [
			'label'    => 'Vancouver',
			'location' => 'Vancouver'
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
			throw new InvalidArgumentException( 'Account ID is required for Mega S4' );
		}

		parent::__construct( $region, $params );
	}

	/**
	 * Get default region
	 *
	 * @return string
	 */
	public function get_default_region(): string {
		return 'eu-central-1';
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

		return str_replace( '{region}', $this->region, $this->endpoint_pattern );
	}

	/**
	 * Get alternative endpoints
	 *
	 * @return array
	 */
	protected function get_alternative_endpoints(): array {
		return [ 'g.s4.mega.io' ]; // Global endpoint
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
	 * Check if provider supports presigned POST uploads
	 *
	 * @return bool
	 */
	public function supports_presigned_post(): bool {
		return false; // Mega S4 does not support presigned POST
	}

	/**
	 * Get IAM endpoint for this provider
	 *
	 * @return string
	 */
	public function get_iam_endpoint(): string {
		return 'iam.' . $this->get_endpoint();
	}

	/**
	 * Override URL matching to handle global endpoint
	 *
	 * @param string $url_without_protocol URL without protocol
	 * @param string $endpoint             Endpoint to match against
	 *
	 * @return bool
	 */
	protected function url_matches_endpoint( string $url_without_protocol, string $endpoint ): bool {
		// Handle global endpoint
		if ( $endpoint === 'g.s4.mega.io' ) {
			if ( $this->uses_path_style() ) {
				return str_starts_with( $url_without_protocol, $endpoint );
			} else {
				$pattern = '/^[^.]+\.' . preg_quote( $endpoint, '/' ) . '/';
				return (bool) preg_match( $pattern, $url_without_protocol );
			}
		}

		return parent::url_matches_endpoint( $url_without_protocol, $endpoint );
	}

}