<?php
/**
 * AWS S3 Provider
 *
 * Provider implementation for Amazon S3 storage.
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
 * Class AwsS3Provider
 */
class AwsS3 extends Provider {

	/**
	 * Provider ID
	 *
	 * @var string
	 */
	protected string $id = 'aws_s3';

	/**
	 * Provider label
	 *
	 * @var string
	 */
	protected string $label = 'Amazon S3';

	/**
	 * Endpoint pattern
	 *
	 * @var string
	 */
	protected string $endpoint_pattern = 's3.{region}.amazonaws.com';

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
		'us-east-1'      => [
			'label' => 'US East (N. Virginia)',
			'code'  => 'us-east-1'
		],
		'us-east-2'      => [
			'label' => 'US East (Ohio)',
			'code'  => 'us-east-2'
		],
		'us-west-1'      => [
			'label' => 'US West (N. California)',
			'code'  => 'us-west-1'
		],
		'us-west-2'      => [
			'label' => 'US West (Oregon)',
			'code'  => 'us-west-2'
		],
		'ca-central-1'   => [
			'label' => 'Canada (Central)',
			'code'  => 'ca-central-1'
		],
		'eu-west-1'      => [
			'label' => 'EU (Ireland)',
			'code'  => 'eu-west-1'
		],
		'eu-west-2'      => [
			'label' => 'EU (London)',
			'code'  => 'eu-west-2'
		],
		'eu-west-3'      => [
			'label' => 'EU (Paris)',
			'code'  => 'eu-west-3'
		],
		'eu-central-1'   => [
			'label' => 'EU (Frankfurt)',
			'code'  => 'eu-central-1'
		],
		'eu-central-2'   => [
			'label' => 'EU (Zurich)',
			'code'  => 'eu-central-2'
		],
		'eu-north-1'     => [
			'label' => 'EU (Stockholm)',
			'code'  => 'eu-north-1'
		],
		'eu-south-1'     => [
			'label' => 'EU (Milan)',
			'code'  => 'eu-south-1'
		],
		'eu-south-2'     => [
			'label' => 'EU (Spain)',
			'code'  => 'eu-south-2'
		],
		'ap-east-1'      => [
			'label' => 'Asia Pacific (Hong Kong)',
			'code'  => 'ap-east-1'
		],
		'ap-northeast-1' => [
			'label' => 'Asia Pacific (Tokyo)',
			'code'  => 'ap-northeast-1'
		],
		'ap-northeast-2' => [
			'label' => 'Asia Pacific (Seoul)',
			'code'  => 'ap-northeast-2'
		],
		'ap-northeast-3' => [
			'label' => 'Asia Pacific (Osaka)',
			'code'  => 'ap-northeast-3'
		],
		'ap-southeast-1' => [
			'label' => 'Asia Pacific (Singapore)',
			'code'  => 'ap-southeast-1'
		],
		'ap-southeast-2' => [
			'label' => 'Asia Pacific (Sydney)',
			'code'  => 'ap-southeast-2'
		],
		'ap-southeast-3' => [
			'label' => 'Asia Pacific (Jakarta)',
			'code'  => 'ap-southeast-3'
		],
		'ap-southeast-4' => [
			'label' => 'Asia Pacific (Melbourne)',
			'code'  => 'ap-southeast-4'
		],
		'ap-south-1'     => [
			'label' => 'Asia Pacific (Mumbai)',
			'code'  => 'ap-south-1'
		],
		'ap-south-2'     => [
			'label' => 'Asia Pacific (Hyderabad)',
			'code'  => 'ap-south-2'
		],
		'sa-east-1'      => [
			'label' => 'South America (São Paulo)',
			'code'  => 'sa-east-1'
		],
		'me-south-1'     => [
			'label' => 'Middle East (Bahrain)',
			'code'  => 'me-south-1'
		],
		'me-central-1'   => [
			'label' => 'Middle East (UAE)',
			'code'  => 'me-central-1'
		],
		'af-south-1'     => [
			'label' => 'Africa (Cape Town)',
			'code'  => 'af-south-1'
		],
		'il-central-1'   => [
			'label' => 'Israel (Tel Aviv)',
			'code'  => 'il-central-1'
		]
	];

	/**
	 * Standard endpoint for us-east-1 compatibility mode
	 *
	 * @var string
	 */
	private string $standard_endpoint = 's3.amazonaws.com';

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
	 */
	public function get_endpoint(): string {
		// Check if region is valid
		if ( ! $this->is_valid_region( $this->region ) ) {
			// Fall back to default region if invalid
			$this->region = $this->get_default_region();
		}

		// Special case for us-east-1 - can use the standard endpoint
		if ( $this->region === 'us-east-1' && $this->get_param( 'use_standard_endpoint', false ) ) {
			return $this->standard_endpoint;
		}

		// Replace placeholders in endpoint pattern
		return str_replace(
			'{region}',
			$this->region,
			$this->endpoint_pattern
		);
	}

	/**
	 * Get alternative endpoints for AWS CloudFront and legacy endpoints
	 *
	 * @return array Array of alternative endpoint patterns
	 */
	protected function get_alternative_endpoints(): array {
		$alternatives = [];

		// Legacy S3 endpoint
		$alternatives[] = 's3.amazonaws.com';

		// Virtual-hosted style endpoints for all regions
		foreach ( $this->regions as $region_code => $region_data ) {
			if ( $region_code !== $this->region ) {
				$alternatives[] = str_replace( '{region}', $region_code, $this->endpoint_pattern );
			}
		}

		// Check for CloudFront domains
		foreach ( $this->params as $key => $value ) {
			if ( str_starts_with( $key, 'cloudfront_domain_' ) && ! empty( $value ) ) {
				$alternatives[] = $value;
			}
		}

		return $alternatives;
	}

	/**
	 * Override URL matching to handle CloudFront and legacy URLs
	 *
	 * @param string $url_without_protocol URL without protocol
	 * @param string $endpoint             Endpoint to match against
	 *
	 * @return bool
	 */
	protected function url_matches_endpoint( string $url_without_protocol, string $endpoint ): bool {
		// Handle CloudFront domains (custom domains)
		if ( ! str_contains( $endpoint, 'amazonaws.com' ) ) {
			return str_starts_with( $url_without_protocol, $endpoint );
		}

		// Use parent logic for AWS endpoints
		return parent::url_matches_endpoint( $url_without_protocol, $endpoint );
	}

	/**
	 * Parse custom domain URLs (including CloudFront)
	 *
	 * @param string $url_without_protocol URL without protocol
	 *
	 * @return array|null
	 */
	protected function parse_custom_domain_url( string $url_without_protocol ): ?array {
		// First try parent method for configured custom domains
		$result = parent::parse_custom_domain_url( $url_without_protocol );
		if ( $result ) {
			return $result;
		}

		// Check CloudFront domains specifically
		foreach ( $this->params as $key => $domain ) {
			if ( str_starts_with( $key, 'cloudfront_domain_' ) && str_starts_with( $url_without_protocol, $domain ) ) {
				$bucket = str_replace( 'cloudfront_domain_', '', $key );

				// Extract object path
				$domain_length = strlen( $domain );
				$remaining     = substr( $url_without_protocol, $domain_length );
				$object        = ltrim( $remaining, '/' );

				return [
					'bucket' => $bucket,
					'object' => $object ?: ''
				];
			}
		}

		return null;
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
	 * Get the CloudFront domain for a bucket if configured
	 *
	 * @param string $bucket Bucket name
	 *
	 * @return string|null CloudFront domain or null if not configured
	 */
	public function get_cloudfront_domain( string $bucket ): ?string {
		return $this->get_param( 'cloudfront_domain_' . $bucket );
	}

	/**
	 * Set CloudFront domain for a bucket
	 *
	 * @param string $bucket Bucket name
	 * @param string $domain CloudFront domain
	 *
	 * @return self
	 */
	public function set_cloudfront_domain( string $bucket, string $domain ): self {
		return $this->set_param( 'cloudfront_domain_' . $bucket, $domain );
	}

	/**
	 * Check if the provider has integrated CDN
	 *
	 * @return bool
	 */
	public function has_integrated_cdn(): bool {
		// Check if any CloudFront domains are configured
		foreach ( $this->params as $key => $value ) {
			if ( strpos( $key, 'cloudfront_domain_' ) === 0 && ! empty( $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get CDN URL for a bucket (if supported)
	 *
	 * @param string $bucket Bucket name
	 * @param string $object Optional object key
	 *
	 * @return string|null CDN URL or null if not supported
	 */
	public function get_cdn_url( string $bucket, string $object = '' ): ?string {
		$cloudfront_domain = $this->get_cloudfront_domain( $bucket );

		if ( empty( $cloudfront_domain ) ) {
			return null;
		}

		return 'https://' . $cloudfront_domain . '/' . ltrim( $object, '/' );
	}

	/**
	 * Set to use virtual hosted style URLs
	 *
	 * @param bool $use_virtual Whether to use virtual hosted style URLs
	 *
	 * @return self
	 */
	public function set_virtual_hosted_style( bool $use_virtual = true ): self {
		$this->path_style = ! $use_virtual;

		return $this;
	}

	/**
	 * Set to use standard endpoint for us-east-1
	 *
	 * @param bool $use_standard Whether to use standard endpoint for us-east-1
	 *
	 * @return self
	 */
	public function set_use_standard_endpoint( bool $use_standard = true ): self {
		return $this->set_param( 'use_standard_endpoint', $use_standard );
	}

}