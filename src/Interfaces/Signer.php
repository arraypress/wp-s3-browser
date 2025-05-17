<?php
/**
 * Signer Interface
 *
 * Defines the contract for AWS Signature Version 4 signing implementation.
 *
 * @package     ArrayPress\S3\Interfaces
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      ArrayPress Team
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Interfaces;

use WP_Error;

/**
 * Interface SignerInterface
 *
 * Defines the methods that all AWS Signature Version 4 implementations must provide.
 */
interface Signer {

	/**
	 * Generate authorization headers for an S3 request
	 *
	 * @param string $method       HTTP method (GET, PUT, etc.)
	 * @param string $bucket       Bucket name
	 * @param string $object_key   Object key (if applicable)
	 * @param array  $query_params Query parameters
	 * @param string $payload      Request payload (or empty string)
	 *
	 * @return array Headers with AWS signature
	 */
	public function generate_auth_headers(
		string $method,
		string $bucket,
		string $object_key = '',
		array $query_params = [],
		string $payload = ''
	): array;

	/**
	 * Generate a pre-signed URL for an object
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 * @param int    $expires    Expiration time in minutes
	 *
	 * @return string|WP_Error Presigned URL or WP_Error on failure
	 */
	public function get_presigned_url( string $bucket, string $object_key, int $expires = 60 );

	/**
	 * List all buckets in the account
	 *
	 * @param int    $max_keys Maximum number of buckets to return
	 * @param string $prefix   Optional prefix to filter buckets
	 * @param string $marker   Optional marker for pagination
	 *
	 * @return array|WP_Error Response array or WP_Error on failure
	 */
	public function list_buckets( int $max_keys = 1000, string $prefix = '', string $marker = '' );

	/**
	 * List objects in a bucket
	 *
	 * @param string $bucket             Bucket name
	 * @param int    $max_keys           Maximum number of objects to return
	 * @param string $prefix             Optional prefix to filter objects
	 * @param string $delimiter          Optional delimiter for hierarchical listing
	 * @param string $continuation_token Optional continuation token for pagination
	 *
	 * @return array|WP_Error Response array or WP_Error on failure
	 */
	public function list_objects(
		string $bucket,
		int $max_keys = 1000,
		string $prefix = '',
		string $delimiter = '/',
		string $continuation_token = ''
	);

	/**
	 * Download an object
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 *
	 * @return array|WP_Error Object data or error
	 */
	public function get_object( string $bucket, string $object_key );

	/**
	 * Get object metadata (HEAD request)
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 *
	 * @return array|WP_Error Object metadata or error
	 */
	public function head_object( string $bucket, string $object_key );

	/**
	 * Set debug callback function
	 *
	 * @param callable $callback Debug callback
	 *
	 * @return self
	 */
	public function set_debug_callback( callable $callback ): self;

}