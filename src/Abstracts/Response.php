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

namespace ArrayPress\S3\Abstracts;

use ArrayPress\S3\Interfaces\Response as ResponseInterface;

/**
 * Abstract base class for S3 operation responses
 */
abstract class Response implements ResponseInterface {

	/**
	 * HTTP status code
	 *
	 * @var int
	 */
	protected int $status_code;

	/**
	 * Success flag
	 *
	 * @var bool
	 */
	protected bool $success;

	/**
	 * Original response data
	 *
	 * @var mixed
	 */
	protected $raw_data;

	/**
	 * Constructor
	 *
	 * @param int   $status_code HTTP status code
	 * @param bool  $success     Success flag
	 * @param mixed $raw_data    Original response data
	 */
	public function __construct( int $status_code, bool $success, $raw_data = null ) {
		$this->status_code = $status_code;
		$this->success     = $success;
		$this->raw_data    = $raw_data;
	}

	/**
	 * Check if the operation was successful
	 *
	 * @return bool
	 */
	public function is_successful(): bool {
		return $this->success;
	}

	/**
	 * Get the status code
	 *
	 * @return int
	 */
	public function get_status_code(): int {
		return $this->status_code;
	}

	/**
	 * Get raw response data
	 *
	 * @return mixed
	 */
	public function get_raw_data() {
		return $this->raw_data;
	}

	/**
	 * Base implementation for to_array
	 *
	 * @return array
	 */
	public function to_array(): array {
		return [
			'success'     => $this->success,
			'status_code' => $this->status_code
		];
	}

}