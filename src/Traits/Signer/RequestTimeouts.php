<?php
/**
 * Request Timeouts Trait
 *
 * Provides standardized timeout management for S3 operations.
 *
 * @package     ArrayPress\S3\Traits\Signer
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Signer;

use ArrayPress\S3\Utils\Request;
use WP_Error;

/**
 * Trait RequestTimeouts
 */
trait RequestTimeouts {

	/**
	 * Operation timeout mappings
	 *
	 * @var array
	 */
	private static array $operation_timeouts = [
		// Quick operations
		'head_object'      => 15,
		'delete_object'    => 15,

		// Standard operations
		'get_object'       => 30,
		'list_objects'     => 30,
		'list_buckets'     => 30,
		'copy_object'      => 30,
		'put_object'       => 60,

		// Batch/bulk operations
		'batch_delete'     => 60,
		'upload_object'    => 120,
		'multipart_upload' => 180,

		// Default fallback
		'default'          => 30
	];

	/**
	 * Get timeout for a specific operation
	 *
	 * @param string $operation Operation name
	 *
	 * @return int Timeout in seconds
	 */
	protected function get_operation_timeout( string $operation ): int {
		return self::$operation_timeouts[ $operation ] ?? self::$operation_timeouts['default'];
	}

	/**
	 * Make GET request with appropriate timeout
	 *
	 * @param string $url       Request URL
	 * @param array  $headers   Request headers
	 * @param string $operation Operation name for timeout determination
	 *
	 * @return array|WP_Error Response or error
	 */
	protected function make_get_request( string $url, array $headers, string $operation ) {
		return Request::get(
			$url,
			$headers,
			'',
			$this->get_operation_timeout( $operation )
		);
	}

	/**
	 * Make HEAD request with appropriate timeout
	 *
	 * @param string $url       Request URL
	 * @param array  $headers   Request headers
	 * @param string $operation Operation name for timeout determination
	 *
	 * @return array|WP_Error Response or error
	 */
	protected function make_head_request( string $url, array $headers, string $operation ) {
		return Request::head(
			$url,
			$headers,
			$this->get_operation_timeout( $operation )
		);
	}

	/**
	 * Make POST request with appropriate timeout
	 *
	 * @param string $url       Request URL
	 * @param array  $headers   Request headers
	 * @param string $body      Request body
	 * @param string $operation Operation name for timeout determination
	 *
	 * @return array|WP_Error Response or error
	 */
	protected function make_post_request( string $url, array $headers, string $body, string $operation ) {
		return Request::post(
			$url,
			$headers,
			$body,
			'',
			$this->get_operation_timeout( $operation )
		);
	}

	/**
	 * Make PUT request with appropriate timeout
	 *
	 * @param string $url       Request URL
	 * @param array  $headers   Request headers
	 * @param string $body      Request body
	 * @param string $operation Operation name for timeout determination
	 *
	 * @return array|WP_Error Response or error
	 */
	protected function make_put_request( string $url, array $headers, string $body, string $operation ) {
		return Request::put(
			$url,
			$headers,
			$body,
			'',
			$this->get_operation_timeout( $operation )
		);
	}

	/**
	 * Make DELETE request with appropriate timeout
	 *
	 * @param string $url       Request URL
	 * @param array  $headers   Request headers
	 * @param string $operation Operation name for timeout determination
	 *
	 * @return array|WP_Error Response or error
	 */
	protected function make_delete_request( string $url, array $headers, string $operation ) {
		return Request::delete(
			$url,
			$headers,
			'',
			$this->get_operation_timeout( $operation )
		);
	}

	/**
	 * Override timeout for specific operation (useful for testing or special cases)
	 *
	 * @param string $operation Operation name
	 * @param int    $timeout   Timeout in seconds
	 */
	protected function set_operation_timeout( string $operation, int $timeout ): void {
		self::$operation_timeouts[ $operation ] = $timeout;
	}

}