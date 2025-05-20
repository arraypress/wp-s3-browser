<?php
/**
 * S3 Client Class
 *
 * Main client for interacting with S3-compatible storage.
 *
 * @package     ArrayPress\S3
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      ArrayPress Team
 */

declare( strict_types=1 );

namespace ArrayPress\S3;

use ArrayPress\S3\Interfaces\Response as ResponseInterface;
use ArrayPress\S3\Abstracts\Provider;
use ArrayPress\S3\Responses\BucketsResponse;
use ArrayPress\S3\Responses\ErrorResponse;
use ArrayPress\S3\Responses\ObjectsResponse;
use ArrayPress\S3\Traits\ResponseFormatter;
use ArrayPress\S3\Traits\Caching;
use ArrayPress\S3\Utils\Path;
use Generator;
use WP_Error;

/**
 * Class Client
 */
class Client {
	use ResponseFormatter;
	use Caching;

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

	/**
	 * Get the provider instance
	 *
	 * @return Provider
	 */
	public function get_provider(): Provider {
		return $this->provider;
	}

	/**
	 * Get buckets list
	 *
	 * @param int    $max_keys  Maximum number of buckets to return
	 * @param string $prefix    Prefix to filter buckets
	 * @param string $marker    Marker for pagination
	 * @param bool   $use_cache Whether to use cache
	 *
	 * @return ResponseInterface|WP_Error Response or error
	 */
	public function get_buckets(
		int $max_keys = 1000,
		string $prefix = '',
		string $marker = '',
		bool $use_cache = true
	) {
		// Check cache if enabled
		if ( $use_cache && $this->is_cache_enabled() ) {
			$cache_key = $this->get_cache_key( 'buckets', [
				'max_keys' => $max_keys,
				'prefix'   => $prefix,
				'marker'   => $marker
			] );

			$cached = $this->get_from_cache( $cache_key );
			if ( $cached !== false ) {
				return $cached;
			}
		}

		// Use signer to list buckets
		$result = $this->signer->list_buckets( $max_keys, $prefix, $marker );

		// Handle errors
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Debug logging if enabled
		if ( $this->debug ) {
			$this->log_debug( 'Client: Raw result from signer:', $result );
		}

		// Cache the result if successful
		if ( $use_cache && $this->is_cache_enabled() && $result->is_successful() ) {
			$this->save_to_cache( $cache_key, $result );
		}

		return $result;
	}

	/**
	 * Get buckets as models
	 *
	 * @param int    $max_keys  Maximum number of buckets to return
	 * @param string $prefix    Prefix to filter buckets
	 * @param string $marker    Marker for pagination
	 * @param bool   $use_cache Whether to use cache
	 *
	 * @return array|WP_Error Array of bucket models or WP_Error
	 */
	public function get_bucket_models(
		int $max_keys = 1000,
		string $prefix = '',
		string $marker = '',
		bool $use_cache = true
	) {
		// Get buckets response
		$response = $this->get_buckets(
			$max_keys,
			$prefix,
			$marker,
			$use_cache
		);

		// Handle errors
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! ( $response instanceof BucketsResponse ) ) {
			return new WP_Error(
				'invalid_response',
				'Expected BucketsResponse but got ' . get_class( $response )
			);
		}

		// Return result using response object's transformation method
		return [
			'buckets'         => $response->to_bucket_models(),
			'truncated'       => $response->is_truncated(),
			'next_marker'     => $response->get_next_marker(),
			'owner'           => $response->get_owner(),
			'response_object' => $response  // Return the response object too
		];
	}

	/**
	 * Get objects in a bucket
	 *
	 * @param string $bucket             Bucket name
	 * @param int    $max_keys           Maximum number of objects to return
	 * @param string $prefix             Prefix to filter objects
	 * @param string $delimiter          Delimiter (e.g., '/' for folder-like structure)
	 * @param string $continuation_token Continuation token for pagination
	 * @param bool   $use_cache          Whether to use cache
	 *
	 * @return ResponseInterface|WP_Error Response or error
	 */
	public function get_objects(
		string $bucket,
		int $max_keys = 1000,
		string $prefix = '',
		string $delimiter = '/',
		string $continuation_token = '',
		bool $use_cache = true
	) {
		// Check cache if enabled
		if ( $use_cache && $this->is_cache_enabled() ) {
			$cache_key = $this->get_cache_key( 'objects_' . $bucket, [
				'max_keys'           => $max_keys,
				'prefix'             => $prefix,
				'delimiter'          => $delimiter,
				'continuation_token' => $continuation_token
			] );

			$cached = $this->get_from_cache( $cache_key );
			if ( $cached !== false ) {
				return $cached;
			}
		}

		// Use signer to list objects
		$result = $this->signer->list_objects(
			$bucket,
			$max_keys,
			$prefix,
			$delimiter,
			$continuation_token
		);

		// Handle errors
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Debug logging if enabled
		if ( $this->debug ) {
			$this->log_debug( 'Client: Raw result from signer for objects:', $result );
		}

		// Cache the result if successful
		if ( $use_cache && $this->is_cache_enabled() && $result->is_successful() ) {
			$this->save_to_cache( $cache_key, $result );
		}

		return $result;
	}

	/**
	 * Get objects as models
	 *
	 * @param string $bucket             Bucket name
	 * @param int    $max_keys           Maximum number of objects to return
	 * @param string $prefix             Prefix to filter objects
	 * @param string $delimiter          Delimiter (e.g., '/' for folder-like structure)
	 * @param string $continuation_token Continuation token for pagination
	 * @param bool   $use_cache          Whether to use cache
	 *
	 * @return array|WP_Error Array of models or WP_Error
	 */
	public function get_object_models(
		string $bucket,
		int $max_keys = 1000,
		string $prefix = '',
		string $delimiter = '/',
		string $continuation_token = '',
		bool $use_cache = true
	) {
		// Get regular object response
		$response = $this->get_objects(
			$bucket,
			$max_keys,
			$prefix,
			$delimiter,
			$continuation_token,
			$use_cache
		);

		// Handle errors
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Check if the response is an ErrorResponse and convert it to WP_Error
		if ( $response instanceof ErrorResponse ) {
			return new WP_Error(
				$response->get_error_code(),
				$response->get_error_message(),
				[ 'status' => $response->get_status_code() ]
			);
		}

		if ( ! ( $response instanceof ObjectsResponse ) ) {
			return new WP_Error(
				'invalid_response',
				'Expected ObjectsResponse but got ' . get_class( $response )
			);
		}

		// Return result using response object's transformation methods
		return [
			'objects'            => $response->to_object_models(),
			'prefixes'           => $response->to_prefix_models(),
			'truncated'          => $response->is_truncated(),
			'continuation_token' => $response->get_continuation_token(),
			'response_object'    => $response  // Return the response object too
		];
	}

	/**
	 * Get objects as models using an iterator for automatic pagination
	 *
	 * @param string $bucket    Bucket name
	 * @param string $prefix    Prefix to filter objects
	 * @param string $delimiter Delimiter (e.g., '/' for folder-like structure)
	 * @param int    $max_keys  Maximum number of objects to return per request
	 * @param bool   $use_cache Whether to use cache
	 *
	 * @return Generator|WP_Error Generator yielding models or WP_Error
	 */
	public function get_objects_iterator(
		string $bucket,
		string $prefix = '',
		string $delimiter = '/',
		int $max_keys = 1000,
		bool $use_cache = true
	) {
		$continuation_token = '';

		do {
			$result = $this->get_object_models(
				$bucket,
				$max_keys,
				$prefix,
				$delimiter,
				$continuation_token,
				$use_cache
			);

			// Check for errors
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Yield the objects and prefixes
			foreach ( $result['objects'] as $object ) {
				yield 'object' => $object;
			}

			foreach ( $result['prefixes'] as $prefix_model ) {
				yield 'prefix' => $prefix_model;
			}

			// Update continuation token for the next iteration
			$continuation_token = $result['truncated'] ? $result['continuation_token'] : '';

		} while ( ! empty( $continuation_token ) );
	}

	/**
	 * Delete an object from a bucket
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 *
	 * @return ResponseInterface|WP_Error Response or error
	 */
	public function delete_object( string $bucket, string $object_key ) {
		// Use signer to delete object
		$result = $this->signer->delete_object( $bucket, $object_key );

		// Handle errors
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Debug logging if enabled
		if ( $this->debug ) {
			$this->log_debug( 'Client: Raw result from signer for delete operation:', $result );
		}

		// If we're caching, we need to bust the cache for this bucket/prefix
		if ( $this->is_cache_enabled() ) {
			// Extract the directory prefix from the object key
			$prefix = Path::extract_directory_prefix( $object_key );

			// Clear cache for this specific prefix
			$cache_key = $this->get_cache_key( 'objects_' . $bucket, [
				'max_keys'  => 1000,
				'prefix'    => $prefix,
				'delimiter' => '/'
			] );
			$this->clear_cache_item( $cache_key );
		}

		return $result;
	}

	/**
	 * Generate a pre-signed URL for an object
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 * @param int    $expires    Expiration time in minutes
	 *
	 * @return ResponseInterface Pre-signed URL response, URL string, or error
	 */
	public function get_presigned_url( string $bucket, string $object_key, int $expires = 60 ): ResponseInterface {
		return $this->signer->get_presigned_url( $bucket, $object_key, $expires );
	}

	/**
	 * Generate a pre-signed URL for uploading an object
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 * @param int    $expires    Expiration time in minutes
	 *
	 * @return ResponseInterface|WP_Error Pre-signed URL response or error
	 */
	public function get_presigned_upload_url( string $bucket, string $object_key, int $expires = 15 ) {
		return $this->signer->get_presigned_upload_url( $bucket, $object_key, $expires );
	}

	/**
	 * Set a custom debug logger callback
	 *
	 * @param callable $callback Function to call for debug logging
	 *
	 * @return self
	 */
	public function set_debug_logger( callable $callback ): self {
		$this->debug_logger = $callback;

		// Also set the debug callback for the signer
		$this->signer->set_debug_callback( $callback );

		return $this;
	}

	/**
	 * Enable or disable debug mode
	 *
	 * @param bool $enable Whether to enable debug mode
	 *
	 * @return self
	 */
	public function set_debug( bool $enable ): self {
		$this->debug = $enable;

		return $this;
	}

	/**
	 * Check if debug mode is enabled
	 *
	 * @return bool
	 */
	public function is_debug_enabled(): bool {
		return $this->debug;
	}

	/**
	 * Log debug information
	 *
	 * @param string $message Message to log
	 * @param mixed  $data    Optional data to include
	 */
	private function log_debug( string $message, $data = null ): void {
		if ( ! $this->debug ) {
			return;
		}

		// Use custom logger if set
		if ( is_callable( $this->debug_logger ) ) {
			call_user_func( $this->debug_logger, $message, $data );

			return;
		}

		// Default to error_log
		error_log( $message );
		if ( $data !== null ) {
			error_log( print_r( $data, true ) );
		}
	}

}