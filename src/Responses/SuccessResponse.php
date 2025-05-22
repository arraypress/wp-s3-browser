<?php
/**
 * Success Response Class
 *
 * Represents a successful operation response.
 *
 * @package     ArrayPress\S3\Responses
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      ArrayPress Team
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Responses;

use ArrayPress\S3\Abstracts\Response;

/**
 * Response for successful operations
 */
class SuccessResponse extends Response {

	/**
	 * Success message
	 *
	 * @var string
	 */
	private string $message;

	/**
	 * Additional data
	 *
	 * @var array
	 */
	private array $data;

	/**
	 * Constructor
	 *
	 * @param string $message     Success message
	 * @param int    $status_code HTTP status code
	 * @param array  $data        Additional data
	 * @param mixed  $raw_data    Original raw data
	 */
	public function __construct(
		string $message,
		int $status_code = 200,
		array $data = [],
		$raw_data = null
	) {
		// Always pass true for a success flag regardless of status code
		parent::__construct( $status_code, true, $raw_data );
		$this->message = $message;
		$this->data    = $data;
	}

	/**
	 * Get success message
	 *
	 * @return string
	 */
	public function get_message(): string {
		return $this->message;
	}

	/**
	 * Get additional data
	 *
	 * @return array
	 */
	public function get_data(): array {
		return $this->data;
	}

	/**
	 * Check if the operation was successful - always returns true for SuccessResponse
	 *
	 * @return bool
	 */
	public function is_successful(): bool {
		return true;
	}

	/**
	 * Convert to array
	 *
	 * @return array
	 */
	public function to_array(): array {
		$array            = parent::to_array();
		$array['message'] = $this->message;
		$array['data']    = $this->data;

		return $array;
	}

}