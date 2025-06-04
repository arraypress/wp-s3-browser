<?php
/**
 * Request Timeouts Trait
 *
 * Provides standardized timeout management for S3 operations.
 *
 * @package     ArrayPress\S3\Traits\Common
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Shared;

/**
 * Trait Timeouts
 *
 * Centralized timeout management for all S3 operations
 */
trait Timeouts {

	/**
	 * Operation timeout mappings
	 *
	 * @var array
	 */
	private static array $operation_timeouts = [
		// Quick operations (HEAD, DELETE)
		'head_object'        => 15,
		'head_bucket'        => 15,
		'delete_object'      => 15,
		'delete_bucket'      => 15,

		// Standard operations (GET, PUT, LIST)
		'get_object'         => 30,
		'put_object'         => 30,
		'list_objects'       => 30,
		'list_buckets'       => 30,
		'copy_object'        => 30,
		'create_bucket'      => 30,

		// Batch/bulk operations
		'batch_delete'       => 60,
		'batch_copy'         => 60,
		'multipart_init'     => 60,
		'multipart_upload'   => 120,
		'multipart_complete' => 60,

		// Upload operations
		'upload_object'      => 120,
		'upload_large'       => 180,

		// Default fallback
		'default'            => 30
	];

	/**
	 * Get timeout for a specific operation
	 *
	 * @param string $operation Operation name (e.g., 'get_object', 'list_buckets')
	 *
	 * @return int Timeout in seconds
	 */
	protected function get_operation_timeout( string $operation ): int {
		return self::$operation_timeouts[ $operation ] ?? self::$operation_timeouts['default'];
	}

	/**
	 * Get all available operation timeouts
	 *
	 * @return array All timeout mappings
	 */
	protected function get_all_operation_timeouts(): array {
		return self::$operation_timeouts;
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

	/**
	 * Reset timeout for specific operation to default
	 *
	 * @param string $operation Operation name
	 */
	protected function reset_operation_timeout( string $operation ): void {
		if ( isset( self::$operation_timeouts[ $operation ] ) ) {
			unset( self::$operation_timeouts[ $operation ] );
		}
	}

	/**
	 * Reset all timeouts to defaults
	 */
	protected function reset_all_timeouts(): void {
		self::$operation_timeouts = [
			'head_object'        => 15,
			'head_bucket'        => 15,
			'delete_object'      => 15,
			'delete_bucket'      => 15,
			'get_object'         => 30,
			'put_object'         => 30,
			'list_objects'       => 30,
			'list_buckets'       => 30,
			'copy_object'        => 30,
			'create_bucket'      => 30,
			'batch_delete'       => 60,
			'batch_copy'         => 60,
			'multipart_init'     => 60,
			'multipart_upload'   => 120,
			'multipart_complete' => 60,
			'upload_object'      => 120,
			'upload_large'       => 180,
			'default'            => 30
		];
	}

	/**
	 * Get timeout for quick operations (HEAD, DELETE)
	 *
	 * @return int Timeout in seconds
	 */
	protected function get_quick_operation_timeout(): int {
		return 15;
	}

	/**
	 * Get timeout for standard operations (GET, PUT, LIST)
	 *
	 * @return int Timeout in seconds
	 */
	protected function get_standard_operation_timeout(): int {
		return 30;
	}

	/**
	 * Get timeout for batch operations
	 *
	 * @return int Timeout in seconds
	 */
	protected function get_batch_operation_timeout(): int {
		return 60;
	}

	/**
	 * Get timeout for upload operations
	 *
	 * @return int Timeout in seconds
	 */
	protected function get_upload_operation_timeout(): int {
		return 120;
	}

}