<?php
/**
 * Client Batch Operations Trait
 *
 * Handles batch object-related operations for the S3 Client.
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
use ArrayPress\S3\Responses\SuccessResponse;
use ArrayPress\S3\Responses\ErrorResponse;
use ArrayPress\S3\Utils\Directory;

/**
 * Add this to your Client Objects trait
 */
trait Batch {

	/**
	 * Delete multiple objects efficiently using batch delete
	 *
	 * @param string $bucket      Bucket name
	 * @param array  $object_keys Array of object keys to delete
	 * @param int    $batch_size  Objects per batch (max 1000)
	 *
	 * @return ResponseInterface Response with batch delete results
	 */
	public function batch_delete_objects(
		string $bucket,
		array $object_keys,
		int $batch_size = 1000
	): ResponseInterface {

		// Apply contextual filter to modify parameters
		$params = $this->apply_contextual_filters(
			'arraypress_s3_batch_delete_objects_params',
			[
				'bucket'      => $bucket,
				'object_keys' => $object_keys,
				'batch_size'  => $batch_size,
				'proceed'     => true
			],
			$bucket
		);

		// Check if deletion should proceed
		if ( ! $params['proceed'] ) {
			return new ErrorResponse(
				__( 'Batch delete was prevented by filter', 'arraypress' ),
				'deletion_prevented',
				403
			);
		}

		$bucket      = $params['bucket'];
		$object_keys = $params['object_keys'];
		$batch_size  = min( $params['batch_size'], 1000 ); // Enforce S3 limit

		if ( empty( $object_keys ) ) {
			return new ErrorResponse(
				__( 'No objects specified for deletion', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		// Split into batches
		$batches       = array_chunk( $object_keys, $batch_size );
		$total_success = 0;
		$total_errors  = 0;
		$all_deleted   = [];
		$all_errors    = [];

		foreach ( $batches as $batch_index => $batch ) {
			$this->debug( "Processing batch", $batch_index + 1 . '/' . count( $batches ) );

			$result = $this->signer->batch_delete_objects( $bucket, $batch );

			if ( ! $result->is_successful() ) {
				// If a batch fails entirely, treat all objects in that batch as errors
				$batch_errors = [];
				foreach ( $batch as $key ) {
					$batch_errors[] = [
						'key'     => $key,
						'code'    => $result->get_error_code(),
						'message' => $result->get_error_message()
					];
				}
				$all_errors   = array_merge( $all_errors, $batch_errors );
				$total_errors += count( $batch );
				continue;
			}

			$data          = $result->get_data();
			$total_success += $data['success_count'];
			$total_errors  += $data['error_count'];
			$all_deleted   = array_merge( $all_deleted, $data['deleted_objects'] );
			$all_errors    = array_merge( $all_errors, $data['failed_objects'] );
		}

		// Clear cache after batch delete
		if ( $total_success > 0 && $this->is_cache_enabled() ) {
			$this->clear_bucket_cache( $bucket );
		}

		// Determine response status
		$status_code = 200;
		if ( $total_errors > 0 && $total_success === 0 ) {
			$status_code = 400; // All failed
		} elseif ( $total_errors > 0 ) {
			$status_code = 207; // Partial success
		}

		$message = sprintf(
			__( 'Batch delete completed: %d objects deleted, %d failed', 'arraypress' ),
			$total_success,
			$total_errors
		);

		$response_data = [
			'total_requested' => count( $object_keys ),
			'total_batches'   => count( $batches ),
			'success_count'   => $total_success,
			'error_count'     => $total_errors,
			'deleted_objects' => $all_deleted,
			'failed_objects'  => $all_errors
		];

		$response = $total_errors === 0
			? new SuccessResponse( $message, $status_code, $response_data )
			: new ErrorResponse( $message, 'partial_batch_delete_failure', $status_code, $response_data );

		// Apply contextual filter to final response
		return $this->apply_contextual_filters(
			'arraypress_s3_batch_delete_objects_response',
			$response,
			$bucket,
			$object_keys
		);
	}

	/**
	 * Enhanced delete folder using batch delete
	 *
	 * @param string $bucket      Bucket name
	 * @param string $folder_path Folder path
	 * @param bool   $recursive   Whether to delete all contents recursively
	 * @param bool   $use_batch   Whether to use batch delete for efficiency
	 *
	 * @return ResponseInterface Response
	 */
	public function delete_folder_batch(
		string $bucket,
		string $folder_path,
		bool $recursive = true,
		bool $use_batch = true
	): ResponseInterface {

		// Apply contextual filter to modify parameters and allow preventing deletion
		$delete_params = $this->apply_contextual_filters(
			'arraypress_s3_delete_folder_batch_params',
			[
				'bucket'      => $bucket,
				'folder_path' => $folder_path,
				'recursive'   => $recursive,
				'use_batch'   => $use_batch,
				'proceed'     => true
			],
			$bucket,
			$folder_path
		);

		// Check if deletion should proceed
		if ( ! $delete_params['proceed'] ) {
			return new ErrorResponse(
				__( 'Folder deletion was prevented by filter', 'arraypress' ),
				'deletion_prevented',
				403,
				[
					'bucket'      => $bucket,
					'folder_path' => $folder_path
				]
			);
		}

		$bucket      = $delete_params['bucket'];
		$folder_path = $delete_params['folder_path'];
		$recursive   = $delete_params['recursive'];
		$use_batch   = $delete_params['use_batch'];

		// Get all objects in this folder first
		$normalized_path = Directory::normalize( $folder_path );
		$objects_result  = $this->get_object_models( $bucket, 10000, $normalized_path, $recursive ? '' : '/' );

		if ( ! $objects_result->is_successful() ) {
			return new ErrorResponse(
				__( 'Failed to list folder contents', 'arraypress' ),
				'folder_list_error',
				400,
				[ 'error' => $objects_result->get_error_message() ]
			);
		}

		$data        = $objects_result->get_data();
		$objects     = $data['objects'];
		$object_keys = array_map( function ( $object ) {
			return $object->get_key();
		}, $objects );

		if ( empty( $object_keys ) ) {
			return new SuccessResponse(
				sprintf( __( 'Folder "%s" is already empty or does not exist', 'arraypress' ), $normalized_path ),
				200,
				[ 'folder_path' => $normalized_path, 'success_count' => 0, 'error_count' => 0 ]
			);
		}

		// Use batch delete if enabled and we have multiple objects
		if ( $use_batch && count( $object_keys ) > 1 ) {
			$delete_result = $this->batch_delete_objects( $bucket, $object_keys );

			$data = $delete_result->get_data();

			$response_data = array_merge( $data, [ 'folder_path' => $normalized_path ] );

			$message = sprintf(
				__( 'Folder "%s" deleted: %d objects removed, %d failed', 'arraypress' ),
				$normalized_path,
				$data['success_count'],
				$data['error_count']
			);

			$response = $delete_result->is_successful()
				? new SuccessResponse( $message, 200, $response_data )
				: new ErrorResponse( $message, 'folder_batch_delete_partial_failure', 207, $response_data );

			// Apply contextual filter to final response
			return $this->apply_contextual_filters(
				'arraypress_s3_delete_folder_batch_response',
				$response,
				$bucket,
				$normalized_path,
				$data['success_count'] > 0
			);
		}

		// Fallback to individual deletes for single objects or if batch is disabled
		return $this->delete_folder( $bucket, $folder_path, $recursive );
	}

}