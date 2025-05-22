<?php
/**
 * Client Advanced Operations Trait
 *
 * Handles advanced/complex operations for the S3 Client.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Client;

use ArrayPress\S3\Interfaces\Response as ResponseInterface;
use ArrayPress\S3\Responses\ErrorResponse;
use ArrayPress\S3\Responses\SuccessResponse;

/**
 * Trait RenameOperations
 */
trait RenameOperations {

	/**
	 * Rename an object in a bucket
	 *
	 * @param string $bucket     Bucket name
	 * @param string $source_key Current object key
	 * @param string $target_key New object key
	 *
	 * @return ResponseInterface Response or error
	 */
	public function rename_object( string $bucket, string $source_key, string $target_key ): ResponseInterface {
		// 1. Copy the object to the new key
		$copy_result = $this->copy_object( $bucket, $source_key, $bucket, $target_key );

		// If copy failed, return the error
		if ( is_wp_error( $copy_result ) || ! $copy_result->is_successful() ) {
			if ( is_wp_error( $copy_result ) ) {
				return $copy_result;
			}

			return new ErrorResponse(
				__( 'Failed to copy object during rename operation', 'arraypress' ),
				'rename_error',
				400,
				[ 'copy_error' => $copy_result ]
			);
		}

		// 2. Delete the original object
		$delete_result = $this->delete_object( $bucket, $source_key );

		// If delete failed, return a warning but still consider the operation successful
		// since the object was copied successfully
		if ( is_wp_error( $delete_result ) || ! $delete_result->is_successful() ) {
			return new SuccessResponse(
				__( 'Object renamed, but failed to delete the original', 'arraypress' ),
				207, // 207 Multi-Status
				[
					'warning'    => __( 'The object was copied but the original could not be deleted', 'arraypress' ),
					'source_key' => $source_key,
					'target_key' => $target_key
				]
			);
		}

		// Both operations succeeded
		return new SuccessResponse(
			__( 'Object renamed successfully', 'arraypress' ),
			200,
			[
				'source_key' => $source_key,
				'target_key' => $target_key
			]
		);
	}

	/**
	 * Rename a prefix (folder) in a bucket
	 *
	 * @param string $bucket        Bucket name
	 * @param string $source_prefix Current prefix
	 * @param string $target_prefix New prefix
	 * @param bool   $recursive     Whether to process recursively
	 *
	 * @return ResponseInterface|WP_Error Response or error
	 */
	public function rename_prefix(
		string $bucket,
		string $source_prefix,
		string $target_prefix,
		bool $recursive = true
	): ResponseInterface {
		// 1. Ensure prefixes end with a slash
		$source_prefix = rtrim( $source_prefix, '/' ) . '/';
		$target_prefix = rtrim( $target_prefix, '/' ) . '/';

		// 2. Get all objects in the source prefix
		$objects_result = $this->get_object_models( $bucket, 1000, $source_prefix, $recursive ? '' : '/' );

		if ( is_wp_error( $objects_result ) ) {
			return new ErrorResponse(
				__( 'Failed to list objects in source prefix', 'arraypress' ),
				'list_objects_error',
				400,
				[ 'error' => $objects_result->get_error_message() ]
			);
		}

		// 3. Check if there are objects to move
		$objects       = $objects_result['objects'];
		$total_objects = count( $objects );

		if ( $total_objects === 0 ) {
			return new SuccessResponse(
				__( 'No objects found to rename', 'arraypress' ),
				200,
				[
					'source_prefix' => $source_prefix,
					'target_prefix' => $target_prefix
				]
			);
		}

		// 4. Track success and failure counts
		$success_count = 0;
		$failure_count = 0;
		$failures      = [];

		// 5. Process each object
		foreach ( $objects as $object ) {
			$source_key    = $object->get_key();
			$relative_path = substr( $source_key, strlen( $source_prefix ) );
			$target_key    = $target_prefix . $relative_path;

			// Copy the object to the new location
			$copy_result = $this->copy_object( $bucket, $source_key, $bucket, $target_key );

			if ( is_wp_error( $copy_result ) || ! $copy_result->is_successful() ) {
				$failure_count ++;
				$failures[] = [
					'source_key' => $source_key,
					'target_key' => $target_key,
					'error'      => is_wp_error( $copy_result ) ?
						$copy_result->get_error_message() :
						'Copy operation failed'
				];
				continue;
			}

			// Delete the original object
			$delete_result = $this->delete_object( $bucket, $source_key );

			if ( is_wp_error( $delete_result ) || ! $delete_result->is_successful() ) {
				// Count as partial success if copy worked but delete failed
				$failures[] = [
					'source_key' => $source_key,
					'target_key' => $target_key,
					'warning'    => 'Object copied but original not deleted'
				];
			}

			$success_count ++;
		}

		// 6. Create an appropriate response based on results
		if ( $failure_count === 0 ) {
			return new SuccessResponse(
				__( 'Prefix renamed successfully', 'arraypress' ),
				200,
				[
					'source_prefix'     => $source_prefix,
					'target_prefix'     => $target_prefix,
					'objects_processed' => $total_objects
				]
			);
		} elseif ( $success_count > 0 ) {
			return new SuccessResponse(
				__( 'Prefix partially renamed with some failures', 'arraypress' ),
				207, // Multi-Status
				[
					'source_prefix' => $source_prefix,
					'target_prefix' => $target_prefix,
					'success_count' => $success_count,
					'failure_count' => $failure_count,
					'failures'      => $failures
				]
			);
		} else {
			return new ErrorResponse(
				__( 'Failed to rename prefix', 'arraypress' ),
				'rename_prefix_error',
				400,
				[
					'source_prefix' => $source_prefix,
					'target_prefix' => $target_prefix,
					'failures'      => $failures
				]
			);
		}
	}

}