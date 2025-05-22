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

namespace ArrayPress\S3\Interfaces;

/**
 * Interface ResponseInterface
 * Base interface for all S3 operation responses
 */
interface Response {

	/**
	 * Check if the operation was successful
	 *
	 * @return bool
	 */
	public function is_successful(): bool;

	/**
	 * Get the status code
	 *
	 * @return int
	 */
	public function get_status_code(): int;

	/**
	 * Convert to array representation
	 *
	 * @return array
	 */
	public function to_array(): array;

}