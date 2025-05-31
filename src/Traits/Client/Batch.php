<?php
/**
 * Enhanced Client Batch Trait with Timeout Fixes
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Client;

use ArrayPress\S3\Interfaces\Response as ResponseInterface;
use ArrayPress\S3\Responses\SuccessResponse;
use ArrayPress\S3\Responses\ErrorResponse;
use ArrayPress\S3\Utils\Directory;

trait Batch {

	/**
	 * Delete multiple objects efficiently using batch delete with fallback
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
		int $batch_size = 50  // Reduced default batch size for R2
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
		$batch_size  = min( $params['batch_size'], 100 );

		if ( empty( $object_keys ) ) {
			return new ErrorResponse(
				__( 'No objects specified for deletion', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		$this->debug( 'Starting batch delete', [
			'total_objects' => count( $object_keys ),
			'batch_size'    => $batch_size,
			'provider'      => get_class( $this->provider )
		] );

		// For small numbers of objects, use individual delete to avoid batch complexity
		if ( count( $object_keys ) <= 3 ) {
			$this->debug( 'Using individual deletes for small batch', count( $object_keys ) );

			return $this->individual_delete_objects( $bucket, $object_keys );
		}

		// Split into smaller batches for R2 compatibility
		$batches       = array_chunk( $object_keys, $batch_size );
		$total_success = 0;
		$total_errors  = 0;
		$all_deleted   = [];
		$all_errors    = [];

		foreach ( $batches as $batch_index => $batch ) {
			$this->debug( "Processing batch", $batch_index + 1 . '/' . count( $batches ) );

			// Try batch delete first
			$result = $this->signer->batch_delete_objects( $bucket, $batch );

			if ( $result->is_successful() ) {
				// Batch delete worked
				$data          = $result->get_data();
				$total_success += $data['success_count'];
				$total_errors  += $data['error_count'];
				$all_deleted   = array_merge( $all_deleted, $data['deleted_objects'] );
				$all_errors    = array_merge( $all_errors, $data['failed_objects'] );
			} else {
				// Batch delete failed - fallback to individual deletes
				$this->debug( 'Batch delete failed, falling back to individual deletes', [
					'error'      => $result->get_error_message(),
					'batch_size' => count( $batch )
				] );

				$fallback_result = $this->individual_delete_objects( $bucket, $batch );
				if ( $fallback_result->is_successful() ) {
					$fallback_data = $fallback_result->get_data();
					$total_success += $fallback_data['success_count'];
					$total_errors  += $fallback_data['error_count'];
					$all_deleted   = array_merge( $all_deleted, $fallback_data['deleted_objects'] );
					$all_errors    = array_merge( $all_errors, $fallback_data['failed_objects'] );
				} else {
					// Even individual deletes failed - mark all as errors
					foreach ( $batch as $key ) {
						$all_errors[] = [
							'key'     => $key,
							'code'    => $fallback_result->get_error_code(),
							'message' => $fallback_result->get_error_message()
						];
					}
					$total_errors += count( $batch );
				}
			}
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
	 * Delete objects individually as fallback
	 *
	 * @param string $bucket      Bucket name
	 * @param array  $object_keys Array of object keys to delete
	 *
	 * @return ResponseInterface Response with individual delete results
	 */
	private function individual_delete_objects( string $bucket, array $object_keys ): ResponseInterface {
		$deleted = [];
		$errors  = [];

		foreach ( $object_keys as $object_key ) {
			$delete_result = $this->signer->delete_object( $bucket, $object_key );

			if ( $delete_result->is_successful() ) {
				$deleted[] = [
					'key'        => $object_key,
					'version_id' => null
				];
			} else {
				$errors[] = [
					'key'     => $object_key,
					'code'    => $delete_result->get_error_code(),
					'message' => $delete_result->get_error_message()
				];
			}
		}

		return new SuccessResponse(
			sprintf( __( 'Individual delete completed: %d succeeded, %d failed', 'arraypress' ), count( $deleted ), count( $errors ) ),
			200,
			[
				'total_requested' => count( $object_keys ),
				'success_count'   => count( $deleted ),
				'error_count'     => count( $errors ),
				'deleted_objects' => $deleted,
				'failed_objects'  => $errors
			]
		);
	}

	/**
	 * Enhanced delete folder using batch delete with better R2 compatibility
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

		// CRITICAL: Always include the folder placeholder object itself
		// This ensures the folder disappears after all contents are deleted
		if ( ! in_array( $normalized_path, $object_keys, true ) ) {
			// Check if folder placeholder exists as a separate call
			$folder_exists = $this->object_exists( $bucket, $normalized_path, false );
			if ( $folder_exists->is_successful() ) {
				$exists_data = $folder_exists->get_data();
				if ( $exists_data['exists'] ) {
					$object_keys[] = $normalized_path;
					$this->debug( 'Added folder placeholder to deletion list', $normalized_path );
				}
			}
		}

		if ( empty( $object_keys ) ) {
			return new SuccessResponse(
				sprintf( __( 'Folder "%s" is already empty or does not exist', 'arraypress' ), $normalized_path ),
				200,
				[ 'folder_path' => $normalized_path, 'success_count' => 0, 'error_count' => 0 ]
			);
		}

		$this->debug( 'Delete Folder Batch - Final object list', [
			'folder_path' => $normalized_path,
			'object_keys' => $object_keys,
			'total_count' => count( $object_keys )
		] );

		// ENHANCED LOGIC: Use different strategies based on object count
		if ( count( $object_keys ) <= 5 ) {
			// Small number of objects - use individual deletes (more reliable)
			$this->debug( 'Using individual deletes for small folder', count( $object_keys ) );
			$delete_result = $this->individual_delete_objects( $bucket, $object_keys );
		} elseif ( $use_batch ) {
			// Larger number - try batch with smaller batch sizes for R2
			$this->debug( 'Using batch delete with reduced batch size', count( $object_keys ) );
			$delete_result = $this->batch_delete_objects( $bucket, $object_keys, 20 ); // Small batches for R2
		} else {
			// Fallback to regular folder deletion
			$this->debug( 'Using regular folder deletion fallback', count( $object_keys ) );

			return $this->delete_folder( $bucket, $folder_path, $recursive );
		}

		$data          = $delete_result->get_data();
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

		// FINAL CLEANUP: Ensure folder placeholder is removed
		$this->cleanup_folder_placeholder( $bucket, $normalized_path );

		// Apply contextual filter to final response
		return $this->apply_contextual_filters(
			'arraypress_s3_delete_folder_batch_response',
			$response,
			$bucket,
			$normalized_path,
			$data['success_count'] > 0
		);
	}

	/**
	 * Clean up folder placeholder after deletion
	 *
	 * @param string $bucket          Bucket name
	 * @param string $normalized_path Normalized folder path
	 *
	 * @return void
	 */
	private function cleanup_folder_placeholder( string $bucket, string $normalized_path ): void {
		// Try to delete the folder placeholder object
		$placeholder_result = $this->delete_object( $bucket, $normalized_path );
		if ( $placeholder_result->is_successful() ) {
			$this->debug( 'Successfully cleaned up folder placeholder', $normalized_path );
		} else {
			$this->debug( 'Failed to clean up folder placeholder', [
				'folder' => $normalized_path,
				'error'  => $placeholder_result->get_error_message()
			] );
		}

		// Double-check by listing objects again
		$check_result = $this->get_object_models( $bucket, 10, $normalized_path, '' );
		if ( $check_result->is_successful() ) {
			$check_data = $check_result->get_data();
			if ( ! empty( $check_data['objects'] ) ) {
				$this->debug( 'Found remaining objects after cleanup', [
					'folder'            => $normalized_path,
					'remaining_objects' => array_map( function ( $obj ) {
						return $obj->get_key();
					}, $check_data['objects'] )
				] );
			}
		}
	}

	/**
	 * Ensure a folder is completely removed including any placeholder objects
	 *
	 * @param string $bucket      Bucket name
	 * @param string $folder_path Folder path
	 *
	 * @return ResponseInterface Response
	 */
	public function cleanup_folder_after_deletion( string $bucket, string $folder_path ): ResponseInterface {
		$normalized_path = Directory::normalize( $folder_path );

		// Try to delete the folder placeholder object
		$placeholder_result = $this->delete_object( $bucket, $normalized_path );

		// Check if the folder still appears in listings
		$check_result = $this->folder_exists( $bucket, $folder_path );
		if ( $check_result->is_successful() ) {
			$data = $check_result->get_data();
			if ( $data['exists'] ) {
				// Folder still exists, try to list and delete any remaining objects
				$remaining_objects = $this->get_object_models( $bucket, 100, $normalized_path, '' );
				if ( $remaining_objects->is_successful() ) {
					$remaining_data = $remaining_objects->get_data();
					if ( ! empty( $remaining_data['objects'] ) ) {
						foreach ( $remaining_data['objects'] as $object ) {
							$this->delete_object( $bucket, $object->get_key() );
						}
					}
				}
			}
		}

		return new SuccessResponse(
			sprintf( __( 'Folder cleanup completed for "%s"', 'arraypress' ), $normalized_path ),
			200,
			[ 'folder_path' => $normalized_path ]
		);
	}

}