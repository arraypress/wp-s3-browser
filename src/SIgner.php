<?php
/**
 * Signer Class - Refactored with Traits
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

use ArrayPress\S3\Abstracts\Provider;
use ArrayPress\S3\Interfaces\Signer as SignerInterface;
use ArrayPress\S3\Traits\Signer\XmlParser;
use ArrayPress\S3\Traits\Signer\Authentication;
use ArrayPress\S3\Traits\Signer\Buckets;
use ArrayPress\S3\Traits\Signer\Objects;
use ArrayPress\S3\Traits\Signer\PresignedUrls;
use ArrayPress\S3\Traits\Signer\ErrorHandling;
use ArrayPress\S3\Traits\Signer\Batch;
use ArrayPress\S3\Traits\Signer\Headers;
use ArrayPress\S3\Traits\Signer\Cors;
use ArrayPress\S3\Traits\Common\Debug;
use ArrayPress\S3\Traits\Common\Context;
use ArrayPress\S3\Traits\Common\Config;
use ArrayPress\S3\Traits\Common\Timeouts;

/**
 * Class Signer
 */
class Signer implements SignerInterface {
	use XmlParser;
	use Authentication;
	use Buckets;
	use ErrorHandling;
	use Objects;
	use PresignedUrls;
	use Batch;
	use Headers;
	use Debug;
	use Context;
	use Config;
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