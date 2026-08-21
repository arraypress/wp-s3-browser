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

}