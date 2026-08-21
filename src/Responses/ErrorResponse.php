<?php
/**
 * Provider Interface
 *
 * Defines the contract for S3-compatible storage providers.
 *
 * @package     ArrayPress\S3\Interfaces
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Responses;

use ArrayPress\S3\Abstracts\Response;
use WP_Error;

/**
 * Error response for S3 operations
 */
class ErrorResponse extends Response {

	/**
	 * Error message
	 *
	 * @var string
	 */
	private string $error_message;

	/**
	 * Error code
	 *
	 * @var string
	 */
	private string $error_code;

	/**
	 * Additional error data
	 *
	 * @var array
	 */
	private array $error_data;

	/**
	 * Constructor
	 *
	 * @param string $error_message Error message
	 * @param string $error_code    Error code
	 * @param int    $status_code   HTTP status code
	 * @param array  $error_data    Additional error data
	 * @param mixed  $raw_data      Original raw data
	 */
	public function __construct(
		string $error_message,
		string $error_code = 'unknown_error',
		int $status_code = 400,
		array $error_data = [],
		$raw_data = null
	) {
		parent::__construct( $status_code, false, $raw_data );
		$this->error_message = $error_message;
		$this->error_code    = $error_code;
		$this->error_data    = $error_data;
	}

	/**
	 * Get an error message
	 *
	 * @return string
	 */
	public function get_error_message(): string {
		return $this->error_message;
	}

	/**
	 * Get error code
	 *
	 * @return string
	 */
	public function get_error_code(): string {
		return $this->error_code;
	}

	/**
	 * Get error data
	 *
	 * @return array
	 */
	public function get_error_data(): array {
		return $this->error_data;
	}

	/**
	 * Convert to a WP_Error for returning from a REST callback.
	 *
	 * The provider's own code is kept as the WP_Error code. It is the only
	 * thing that separates "this token cannot list buckets" -- a normal,
	 * recommended R2 setup -- from "these credentials are wrong", and a caller
	 * that invents its own code in place of it leaves the admin with no way to
	 * tell those apart.
	 *
	 * @return WP_Error
	 */
	public function to_wp_error(): WP_Error {
		return new WP_Error(
			$this->get_error_code(),
			$this->get_error_message(),
			[ 'status' => $this->rest_status() ] + $this->get_error_data()
		);
	}

	/**
	 * Map the provider's status onto one worth returning over REST.
	 *
	 * A status the browser can act on is relayed as-is: 404 tells the UI the
	 * bucket or key is gone, 403 that the token lacks the permission. Anything
	 * else becomes 502, because the failure is upstream of WordPress rather
	 * than a fault in the request that arrived here.
	 *
	 * 401 is deliberately not relayed. It is WordPress's own signal that the
	 * *user* is unauthenticated, and returning it for a storage credential
	 * problem sends the admin to a login screen that will not help.
	 *
	 * @return int
	 */
	private function rest_status(): int {
		$status = $this->get_status_code();

		return in_array( $status, [ 400, 403, 404, 409, 429 ], true ) ? $status : 502;
	}


	/**
	 * Create from WordPress error
	 *
	 * @param WP_Error $wp_error    WordPress error
	 * @param int      $status_code HTTP status code
	 *
	 * @return self
	 */
	public static function from_wp_error( WP_Error $wp_error, int $status_code = 400 ): self {
		return new self(
			$wp_error->get_error_message(),
			$wp_error->get_error_code(),
			$status_code,
			$wp_error->get_error_data() ? (array) $wp_error->get_error_data() : []
		);
	}

	/**
	 * Convert to array
	 *
	 * @return array
	 */
	public function to_array(): array {
		$array = parent::to_array();

		$array['error'] = [
			'message' => $this->error_message,
			'code'    => $this->error_code,
		];

		if ( ! empty( $this->error_data ) ) {
			$array['error']['data'] = $this->error_data;
		}

		return $array;
	}
}
