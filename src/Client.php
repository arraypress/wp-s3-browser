<?php
/**
 * S3 Client Class - Refactored with Traits
 *
 * Main client for interacting with S3-compatible storage.
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
use ArrayPress\S3\Traits\Client\Buckets;
use ArrayPress\S3\Traits\Client\Caching;
use ArrayPress\S3\Traits\Client\Configuration;
use ArrayPress\S3\Traits\Client\Folders;
use ArrayPress\S3\Traits\Client\Items;
use ArrayPress\S3\Traits\Client\Item;
use ArrayPress\S3\Traits\Client\Permissions;
use ArrayPress\S3\Traits\Client\PresignedUrls;
use ArrayPress\S3\Traits\Client\Rename;
use ArrayPress\S3\Traits\Client\Upload;

/**
 * Class Client
 */
class Client {
	use Caching;
	use Buckets;
	use Configuration;
	use Folders;
	use Items;
	use Item;
	use Permissions;
	use PresignedUrls;
	use Rename;
	use Upload;

	/**
	 * Provider instance
	 *
	 * @var Provider
	 */
	private Provider $provider;

	/**
	 * Signer instance
	 *
	 * @var Signer
	 */
	private Signer $signer;

	/**
	 * Debug mode
	 *
	 * @var bool
	 */
	private bool $debug = false;

	/**
	 * Custom debug logger callback
	 *
	 * @var callable|null
	 */
	private $debug_logger = null;

	/**
	 * Constructor
	 *
	 * @param Provider $provider   Provider instance
	 * @param string   $access_key Access key ID
	 * @param string   $secret_key Secret access key
	 * @param bool     $use_cache  Whether to use cache
	 * @param int      $cache_ttl  Cache TTL in seconds
	 * @param bool     $debug      Whether to enable debug mode
	 */
	public function __construct(
		Provider $provider,
		string $access_key,
		string $secret_key,
		bool $use_cache = true,
		int $cache_ttl = 86400, // DAY_IN_SECONDS
		bool $debug = false
	) {
		$this->provider = $provider;
		$this->signer   = new Signer( $provider, $access_key, $secret_key );
		$this->init_cache( $use_cache, $cache_ttl );
		$this->debug = $debug;
	}

}