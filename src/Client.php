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

use ArrayPress\S3\Provider;
use ArrayPress\S3\Traits\Client\Buckets;
use ArrayPress\S3\Traits\Client\Bucket;
use ArrayPress\S3\Traits\Client\Folder;
use ArrayPress\S3\Traits\Client\Files;
use ArrayPress\S3\Traits\Client\File;
use ArrayPress\S3\Traits\Client\Permissions;
use ArrayPress\S3\Traits\Client\PresignedUrls;
use ArrayPress\S3\Traits\Client\Batch;
use ArrayPress\S3\Traits\Client\Cors;
use ArrayPress\S3\Traits\Client\Upload;
use ArrayPress\S3\Traits\Client\Options;
use ArrayPress\S3\Traits\Shared\Debug;
use ArrayPress\S3\Traits\Shared\Context;
use ArrayPress\S3\Api;

/**
 * Class Client
 */
class Client {
	use Buckets;
	use Bucket;
	use Folder;
	use Files;
	use File;
	use Permissions;
	use PresignedUrls;
	use Batch;
	use Debug;
	use Context;
	use Cors;
	use Upload;
	use Options;

	/**
	 * Provider instance
	 *
	 * @var Provider
	 */
	private Provider $provider;

	/**
	 * Raw S3 API client
	 *
	 * @var Api
	 */
	private Api $api;

	/**
	 * Response cache.
	 *
	 * @var Cache
	 */
	private Cache $cache;

	/**
	 * Constructor
	 *
	 * @param Provider    $provider   Provider instance
	 * @param string      $access_key Access key ID
	 * @param string      $secret_key Secret access key
	 * @param bool        $use_cache  Whether to use cache
	 * @param int         $cache_ttl  Cache TTL in seconds
	 * @param bool        $debug      Whether to enable debug mode
	 * @param string|null $context    Optional. Context identifier for filtering and customization
	 */
	public function __construct(
		Provider $provider,
		string $access_key,
		string $secret_key,
		bool $use_cache = true,
		int $cache_ttl = 86400, // DAY_IN_SECONDS
		bool $debug = false,
		?string $context = null
	) {
		$this->provider = $provider;
		$this->api      = new Api( $provider, $access_key, $secret_key );
		$this->cache    = new Cache( $use_cache, $cache_ttl );

		// Both objects carry their own debug flag, so setting only this one
		// left every debug call in the request path silently disabled — the
		// canonical request, the string to sign, the response body. Those are
		// exactly what a SignatureDoesNotMatch needs, and asking for debug got
		// you none of them.
		$this->set_debug( $debug );

		// Set context if provided
		if ( $context !== null ) {
			$this->set_context( $context );
		}
	}

	/**
	 * Get this client's response cache
	 *
	 * @return Cache
	 */
	public function cache(): Cache {
		return $this->cache;
	}

	/**
	 * Get the provider instance
	 *
	 * @return Provider
	 */
	public function get_provider(): Provider {
		return $this->provider;
	}

}