<?php
/**
 * S3 API Client
 *
 * Core implementation of AWS Signature Version 4 for S3-compatible storage.
 *
 * @package     ArrayPress\S3
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3;

use ArrayPress\S3\Provider;
use ArrayPress\S3\Interfaces\Api as ApiInterface;
use ArrayPress\S3\Traits\Api\Signing;
use ArrayPress\S3\Traits\Api\Buckets;
use ArrayPress\S3\Traits\Api\Bucket;
use ArrayPress\S3\Traits\Api\Files;
use ArrayPress\S3\Traits\Api\File;
use ArrayPress\S3\Traits\Api\PresignedUrls;
use ArrayPress\S3\Traits\Api\Batch;
use ArrayPress\S3\Traits\Api\Headers;
use ArrayPress\S3\Traits\Api\Cors;
use ArrayPress\S3\Traits\Shared\Debug;
use ArrayPress\S3\Traits\Shared\Timeouts;

/**
 * Class Api
 */
class Api implements ApiInterface {
	use Signing;
	use Buckets;
	use Bucket;
	use Files;
	use File;
	use PresignedUrls;
	use Batch;
	use Headers;
	use Debug;
	use Timeouts;
	use Cors;

	/**
	 * Provider instance
	 *
	 * @var Provider
	 */
	private Provider $provider;

	/**
	 * Access key ID
	 *
	 * @var string
	 */
	private string $access_key;

	/**
	 * Secret access key
	 *
	 * @var string
	 */
	private string $secret_key;

	/**
	 * Constructor
	 *
	 * @param Provider $provider   Provider instance
	 * @param string   $access_key Access key ID
	 * @param string   $secret_key Secret access key
	 */
	public function __construct(
		Provider $provider,
		string $access_key,
		string $secret_key
	) {
		$this->provider   = $provider;
		$this->access_key = trim( $access_key );
		$this->secret_key = trim( $secret_key );
	}

}