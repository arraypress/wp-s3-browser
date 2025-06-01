<?php
/**
 * Request Utility Class
 *
 * Handles HTTP request configurations and utilities for S3 operations.
 *
 * @package     ArrayPress\S3\Utils
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Utils;

/**
 * Class Request
 *
 * Provides utilities for HTTP request handling
 */
class Request {

	/**
	 * Default user agent string
	 *
	 * @var string
	 */
	public const DEFAULT_USER_AGENT = 'ArrayPress-S3-Client/1.0';

	/**
	 * Get default request arguments for wp_remote_* functions
	 *
	 * @param string $user_agent         Optional custom user agent
	 * @param int    $timeout            Optional timeout in seconds
	 * @param bool   $include_user_agent Whether to include user agent
	 *
	 * @return array Default request arguments
	 */
	public static function get_default_args( string $user_agent = '', int $timeout = 30, bool $include_user_agent = true ): array {
		$args = [
			'timeout'   => $timeout,
			'blocking'  => true,
			'sslverify' => true,
		];

		// Only add user-agent if requested and provided/default available
		if ( $include_user_agent ) {
			$args['user-agent'] = ! empty( $user_agent ) ? $user_agent : self::DEFAULT_USER_AGENT;
		}

		return $args;
	}

	/**
	 * Get request arguments for GET operations
	 *
	 * @param array  $headers    HTTP headers
	 * @param string $user_agent Optional custom user agent
	 * @param int    $timeout    Optional timeout in seconds
	 *
	 * @return array Request arguments for wp_remote_get
	 */
	public static function get_args( array $headers = [], string $user_agent = '', int $timeout = 30 ): array {
		$args = self::get_default_args( $user_agent, $timeout );

		if ( ! empty( $headers ) ) {
			$args['headers'] = $headers;
		}

		return $args;
	}

	/**
	 * Get request arguments for HEAD operations (typically faster, no user agent needed)
	 *
	 * @param array $headers HTTP headers
	 * @param int   $timeout Optional timeout in seconds
	 *
	 * @return array Request arguments for wp_remote_head
	 */
	public static function head_args( array $headers = [], int $timeout = 15 ): array {
		$args = self::get_default_args( '', $timeout, false );

		if ( ! empty( $headers ) ) {
			$args['headers'] = $headers;
		}

		return $args;
	}

	/**
	 * Get request arguments for POST operations
	 *
	 * @param array  $headers    HTTP headers
	 * @param string $body       Request body
	 * @param string $user_agent Optional custom user agent
	 * @param int    $timeout    Optional timeout in seconds
	 *
	 * @return array Request arguments for wp_remote_post
	 */
	public static function post_args( array $headers = [], string $body = '', string $user_agent = '', int $timeout = 60 ): array {
		$args           = self::get_default_args( $user_agent, $timeout );
		$args['method'] = 'POST';

		if ( ! empty( $headers ) ) {
			$args['headers'] = $headers;
		}

		if ( ! empty( $body ) ) {
			$args['body'] = $body;
		}

		return $args;
	}

	/**
	 * Get request arguments for PUT operations
	 *
	 * @param array  $headers    HTTP headers
	 * @param string $body       Request body
	 * @param string $user_agent Optional custom user agent
	 * @param int    $timeout    Optional timeout in seconds
	 *
	 * @return array Request arguments for wp_remote_request with PUT method
	 */
	public static function put_args( array $headers = [], string $body = '', string $user_agent = '', int $timeout = 30 ): array {
		$args           = self::get_default_args( $user_agent, $timeout );
		$args['method'] = 'PUT';

		if ( ! empty( $headers ) ) {
			$args['headers'] = $headers;
		}

		if ( ! empty( $body ) ) {
			$args['body'] = $body;
		}

		return $args;
	}

	/**
	 * Get request arguments for DELETE operations
	 *
	 * @param array  $headers    HTTP headers
	 * @param string $user_agent Optional custom user agent
	 * @param int    $timeout    Optional timeout in seconds
	 *
	 * @return array Request arguments for wp_remote_request with DELETE method
	 */
	public static function delete_args( array $headers = [], string $user_agent = '', int $timeout = 15 ): array {
		$args           = self::get_default_args( $user_agent, $timeout );
		$args['method'] = 'DELETE';

		if ( ! empty( $headers ) ) {
			$args['headers'] = $headers;
		}

		// DELETE operations typically have no body but need Content-Length
		$args['body'] = '';

		// Ensure Content-Length header is set for DELETE operations
		if ( ! isset( $headers['Content-Length'] ) ) {
			$args['headers']['Content-Length'] = '0';
		}

		return $args;
	}

	/**
	 * Get request arguments for custom HTTP methods
	 *
	 * @param string $method     HTTP method (GET, POST, PUT, DELETE, etc.)
	 * @param array  $headers    HTTP headers
	 * @param string $body       Request body
	 * @param string $user_agent Optional custom user agent
	 * @param int    $timeout    Optional timeout in seconds
	 *
	 * @return array Request arguments for wp_remote_request
	 */
	public static function custom_args( string $method, array $headers = [], string $body = '', string $user_agent = '', int $timeout = 30 ): array {
		$args           = self::get_default_args( $user_agent, $timeout );
		$args['method'] = strtoupper( $method );

		if ( ! empty( $headers ) ) {
			$args['headers'] = $headers;
		}

		if ( ! empty( $body ) ) {
			$args['body'] = $body;
		}

		return $args;
	}

	/**
	 * Get request arguments for batch operations (typically longer timeouts)
	 *
	 * @param array  $headers    HTTP headers
	 * @param string $body       Request body
	 * @param string $user_agent Optional custom user agent
	 * @param int    $timeout    Optional timeout in seconds (default 60 for batch ops)
	 *
	 * @return array Request arguments for batch operations
	 */
	public static function batch_args( array $headers = [], string $body = '', string $user_agent = '', int $timeout = 60 ): array {
		return self::post_args( $headers, $body, $user_agent, $timeout );
	}

	/**
	 * Get request arguments for upload operations (typically longer timeouts)
	 *
	 * @param array  $headers    HTTP headers
	 * @param string $body       Request body (file content)
	 * @param string $user_agent Optional custom user agent
	 * @param int    $timeout    Optional timeout in seconds (default 120 for uploads)
	 *
	 * @return array Request arguments for upload operations
	 */
	public static function upload_args( array $headers = [], string $body = '', string $user_agent = '', int $timeout = 120 ): array {
		return self::put_args( $headers, $body, $user_agent, $timeout );
	}

	/**
	 * Merge additional arguments with existing request arguments
	 *
	 * @param array $base_args       Base request arguments
	 * @param array $additional_args Additional arguments to merge
	 *
	 * @return array Merged request arguments
	 */
	public static function merge_args( array $base_args, array $additional_args ): array {
		// Special handling for headers - merge them properly
		if ( isset( $base_args['headers'] ) && isset( $additional_args['headers'] ) ) {
			$additional_args['headers'] = array_merge( $base_args['headers'], $additional_args['headers'] );
		}

		return array_merge( $base_args, $additional_args );
	}

	/**
	 * Add or update headers in request arguments
	 *
	 * @param array $args    Existing request arguments
	 * @param array $headers Headers to add or update
	 *
	 * @return array Updated request arguments
	 */
	public static function add_headers( array $args, array $headers ): array {
		if ( ! isset( $args['headers'] ) ) {
			$args['headers'] = [];
		}

		$args['headers'] = array_merge( $args['headers'], $headers );

		return $args;
	}

	/**
	 * Set request timeout
	 *
	 * @param array $args    Existing request arguments
	 * @param int   $timeout Timeout in seconds
	 *
	 * @return array Updated request arguments
	 */
	public static function set_timeout( array $args, int $timeout ): array {
		$args['timeout'] = $timeout;

		return $args;
	}

	/**
	 * Set request user agent
	 *
	 * @param array  $args       Existing request arguments
	 * @param string $user_agent User agent string
	 *
	 * @return array Updated request arguments
	 */
	public static function set_user_agent( array $args, string $user_agent ): array {
		$args['user-agent'] = $user_agent;

		return $args;
	}

	/**
	 * Get recommended timeout for operation type
	 *
	 * @param string $operation Operation type (get, head, post, put, delete, batch, upload)
	 *
	 * @return int Recommended timeout in seconds
	 */
	public static function get_recommended_timeout( string $operation ): int {
		switch ( strtolower( $operation ) ) {
			case 'head':
			case 'delete':
				return 15;

			case 'batch':
				return 60;

			case 'upload':
				return 120;

			default:
				return 30;
		}
	}

}