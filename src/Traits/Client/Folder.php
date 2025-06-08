<?php
/**
 * Client Folder Operations Trait
 *
 * Handles folder-related operations for the S3 Client.
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
use ArrayPress\S3\Utils\Directory;

/**
 * Trait Folders
 */
trait Folder {

	/**
	 * Check if a folder (prefix) exists
	 *
	 * @param string $bucket      Bucket name
	 * @param string $folder_path Folder path
	 *
	 * @return ResponseInterface Response with existence info
	 */
	public function folder_exists( string $bucket, string $folder_path ): ResponseInterface {
		if ( empty( $bucket ) || empty( $folder_path ) ) {
			return new ErrorResponse(
				__( 'Bucket and folder path are required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		// Apply contextual filter to modify parameters
		$check_params = $this->apply_contextual_filters(
			'arraypress_s3_folder_exists_params',
			[
				'bucket'      => $bucket,
				'folder_path' => $folder_path
			],
			$bucket,
			$folder_path
		);

		$bucket      = $check_params['bucket'];
		$folder_path = $check_params['folder_path'];

		// Normalize the folder path
		$normalized_path = Directory::normalize( $folder_path );

		// Check by listing objects with this prefix (limit to 1 for efficiency)
		$objects_result = $this->get_object_models( $bucket, 1, $normalized_path, '/', '', false );

		if ( ! $objects_result->is_successful() ) {
			return new ErrorResponse(
				__( 'Failed to check folder existence', 'arraypress' ),
				'folder_check_error',
				400,
				[ 'error' => $objects_result->get_error_message() ]
			);
		}

		$data         = $objects_result->get_data();
		$has_objects  = ! empty( $data['objects'] );
		$has_prefixes = ! empty( $data['prefixes'] );
		$exists       = $has_objects || $has_prefixes;

		// Check if there's a direct placeholder object
		$has_placeholder = false;
		if ( $has_objects ) {
			foreach ( $data['objects'] as $object ) {
				if ( $object->get_key() === $normalized_path ) {
					$has_placeholder = true;
					break;
				}
			}
		}

		$result_data = [
			'bucket'          => $bucket,
			'folder_path'     => $normalized_path,
			'exists'          => $exists,
			'has_placeholder' => $has_placeholder,
			'has_objects'     => $has_objects,
			'has_subfolders'  => $has_prefixes,
			'object_count'    => count( $data['objects'] ),
			'subfolder_count' => count( $data['prefixes'] )
		];

		// Apply contextual filter to the folder existence result
		$result_data = $this->apply_contextual_filters(
			'arraypress_s3_folder_exists_result',
			$result_data,
			$bucket,
			$normalized_path,
			$exists
		);

		return new SuccessResponse(
			$exists ?
				sprintf( __( 'Folder "%s" exists', 'arraypress' ), $normalized_path ) :
				sprintf( __( 'Folder "%s" does not exist', 'arraypress' ), $normalized_path ),
			200,
			$result_data
		);
	}

	/**
	 * Create a folder (prefix) by uploading a placeholder object
	 *
	 * @param string $bucket      Bucket name
	 * @param string $folder_path Folder path (will be normalized to end with /)
	 *
	 * @return ResponseInterface Response
	 */
	public function create_folder( string $bucket, string $folder_path ): ResponseInterface {
		if ( empty( $bucket ) || empty( $folder_path ) ) {
			return new ErrorResponse(
				__( 'Bucket and folder path are required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		// Apply contextual filter to modify parameters
		$create_params = $this->apply_contextual_filters(
			'arraypress_s3_create_folder_params',
			[
				'bucket'      => $bucket,
				'folder_path' => $folder_path
			],
			$bucket,
			$folder_path
		);

		$bucket      = $create_params['bucket'];
		$folder_path = $create_params['folder_path'];

		// Normalize the folder path to ensure it ends with /
		$normalized_path = Directory::normalize( $folder_path );

		// Check if folder already exists
		$existing_check = $this->get_objects( $bucket, 1, $normalized_path, '/', '', false );

		if ( ! $existing_check->is_successful() ) {
			return new ErrorResponse(
				__( 'Failed to check if folder exists', 'arraypress' ),
				'folder_check_error',
				400,
				[ 'error' => $existing_check->get_error_message() ]
			);
		}

		// Check if we got any objects or prefixes
		$models_result = $this->get_object_models( $bucket, 1, $normalized_path, '/', '', false );
		if ( $models_result->is_successful() ) {
			$data         = $models_result->get_data();
			$has_objects  = ! empty( $data['objects'] );
			$has_prefixes = ! empty( $data['prefixes'] );

			if ( $has_objects || $has_prefixes ) {
				return new SuccessResponse(
					sprintf( __( 'Folder "%s" already exists', 'arraypress' ), $normalized_path ),
					200,
					[
						'bucket'      => $bucket,
						'folder_path' => $normalized_path,
						'existed'     => true
					]
				);
			}
		}

		// Create a placeholder object to represent the folder
		$placeholder_content = '';

		// Apply contextual filter to modify placeholder content
		$placeholder_content = $this->apply_contextual_filters(
			'arraypress_s3_folder_placeholder_content',
			$placeholder_content,
			$bucket,
			$normalized_path
		);

		// Upload the placeholder
		$upload_result = $this->put_object(
			$bucket,
			$normalized_path,
			$placeholder_content,
			false, // Not a file path, it's content
			'application/x-directory' // MIME type for directories
		);

		if ( ! $upload_result->is_successful() ) {
			return new ErrorResponse(
				sprintf( __( 'Failed to create folder "%s"', 'arraypress' ), $normalized_path ),
				'folder_creation_error',
				400,
				[ 'upload_error' => $upload_result->get_error_message() ]
			);
		}

		$success_data = [
			'bucket'      => $bucket,
			'folder_path' => $normalized_path,
			'created'     => true
		];

		// Apply contextual filter to the folder creation success result
		$success_data = $this->apply_contextual_filters(
			'arraypress_s3_folder_created',
			$success_data,
			$bucket,
			$normalized_path
		);

		return new SuccessResponse(
			sprintf( __( 'Folder "%s" created successfully', 'arraypress' ), $normalized_path ),
			201,
			$success_data
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
	 * @return ResponseInterface Response
	 */
	public function rename_folder(
		string $bucket,
		string $source_prefix,
		string $target_prefix,
		bool $recursive = true
	): ResponseInterface {
		// Apply contextual filter to modify parameters
		$rename_params = $this->apply_contextual_filters(
			'arraypress_s3_rename_folder_params',
			[
				'bucket'        => $bucket,
				'source_prefix' => $source_prefix,
				'target_prefix' => $target_prefix,
				'recursive'     => $recursive
			],
			$bucket,
			$source_prefix,
			$target_prefix
		);

		$bucket        = $rename_params['bucket'];
		$source_prefix = $rename_params['source_prefix'];
		$target_prefix = $rename_params['target_prefix'];
		$recursive     = $rename_params['recursive'];

		// Ensure prefixes end with a slash
		$source_prefix = Directory::normalize( $source_prefix );
		$target_prefix = Directory::normalize( $target_prefix );

		// Get all objects in the source prefix
		$objects_result = $this->get_object_models( $bucket, 1000, $source_prefix, $recursive ? '' : '/' );

		if ( ! $objects_result->is_successful() ) {
			return new ErrorResponse(
				__( 'Failed to list objects in source prefix', 'arraypress' ),
				'list_objects_error',
				400,
				[ 'error' => $objects_result->get_error_message() ]
			);
		}

		// Check if there are objects to move
		$data          = $objects_result->get_data();
		$objects       = $data['objects'];
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

		// Track success and failure counts
		$success_count = 0;
		$failure_count = 0;
		$failures      = [];

		// Process each object
		foreach ( $objects as $object ) {
			$source_key    = $object->get_key();
			$relative_path = substr( $source_key, strlen( $source_prefix ) );
			$target_key    = $target_prefix . $relative_path;

			// Apply contextual filter to individual object rename
			$object_rename_params = $this->apply_contextual_filters(
				'arraypress_s3_rename_folder_object',
				[
					'source_key' => $source_key,
					'target_key' => $target_key,
					'proceed'    => true
				],
				$bucket,
				$source_key,
				$target_key
			);

			if ( ! $object_rename_params['proceed'] ) {
				continue; // Skip this object
			}

			$source_key = $object_rename_params['source_key'];
			$target_key = $object_rename_params['target_key'];

			// Copy the object to the new location
			$copy_result = $this->copy_object( $bucket, $source_key, $bucket, $target_key );

			if ( ! $copy_result->is_successful() ) {
				$failure_count ++;
				$failures[] = [
					'source_key' => $source_key,
					'target_key' => $target_key,
					'error'      => $copy_result->get_error_message()
				];
				continue;
			}

			// Delete the original object
			$delete_result = $this->delete_object( $bucket, $source_key );

			if ( ! $delete_result->is_successful() ) {
				// Count as partial success if copy worked but delete failed
				$failures[] = [
					'source_key' => $source_key,
					'target_key' => $target_key,
					'warning'    => 'Object copied but original not deleted'
				];
			}

			$success_count ++;
		}

		// Create an appropriate response based on results
		$result_data = [
			'source_prefix'     => $source_prefix,
			'target_prefix'     => $target_prefix,
			'objects_processed' => $total_objects,
			'success_count'     => $success_count,
			'failure_count'     => $failure_count,
			'failures'          => $failures
		];

		// Apply contextual filter to the rename result
		$result_data = $this->apply_contextual_filters(
			'arraypress_s3_folder_renamed',
			$result_data,
			$bucket,
			$source_prefix,
			$target_prefix
		);

		if ( $failure_count === 0 ) {
			return new SuccessResponse(
				__( 'Prefix renamed successfully', 'arraypress' ),
				200,
				$result_data
			);
		} elseif ( $success_count > 0 ) {
			return new SuccessResponse(
				__( 'Prefix partially renamed with some failures', 'arraypress' ),
				207, // Multi-Status
				$result_data
			);
		} else {
			return new ErrorResponse(
				__( 'Failed to rename prefix', 'arraypress' ),
				'rename_prefix_error',
				400,
				$result_data
			);
		}
	}

	/**
	 * Delete a folder (prefix) and optionally all its contents
	 *
	 * @param string $bucket      Bucket name
	 * @param string $folder_path Folder path
	 * @param bool   $recursive   Whether to delete all contents recursively
	 * @param bool   $force       Force deletion even if folder has contents (when recursive is false)
	 *
	 * @return ResponseInterface Response
	 */
	public function delete_folder(
		string $bucket,
		string $folder_path,
		bool $recursive = false,
		bool $force = false
	): ResponseInterface {
		if ( empty( $bucket ) || empty( $folder_path ) ) {
			return new ErrorResponse(
				__( 'Bucket and folder path are required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		// Apply contextual filter to modify parameters and allow preventing deletion
		$delete_params = $this->apply_contextual_filters(
			'arraypress_s3_delete_folder_params',
			[
				'bucket'      => $bucket,
				'folder_path' => $folder_path,
				'recursive'   => $recursive,
				'force'       => $force,
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
		$force       = $delete_params['force'];

		// Normalize the folder path
		$normalized_path = Directory::normalize( $folder_path );

		// Get all objects in this folder
		$objects_result = $this->get_object_models( $bucket, 1000, $normalized_path, $recursive ? '' : '/' );
		if ( ! $objects_result->is_successful() ) {
			return new ErrorResponse(
				__( 'Failed to list folder contents', 'arraypress' ),
				'folder_list_error',
				400,
				[ 'error' => $objects_result->get_error_message() ]
			);
		}

		$data          = $objects_result->get_data();
		$objects       = $data['objects'];
		$prefixes      = $data['prefixes'];
		$total_objects = count( $objects );
		$total_folders = count( $prefixes );

		// Check if folder has contents and we're not doing recursive delete
		if ( ! $recursive && ( $total_objects > 1 || $total_folders > 0 ) ) {
			// Check if the only object is the folder placeholder itself
			$has_real_content = false;
			foreach ( $objects as $object ) {
				if ( $object->get_key() !== $normalized_path ) {
					$has_real_content = true;
					break;
				}
			}

			if ( $has_real_content || $total_folders > 0 ) {
				if ( ! $force ) {
					return new ErrorResponse(
						sprintf(
							__( 'Folder "%s" is not empty. Use recursive=true to delete all contents or force=true to delete anyway', 'arraypress' ),
							$normalized_path
						),
						'folder_not_empty',
						400,
						[
							'folder_path'  => $normalized_path,
							'object_count' => $total_objects,
							'folder_count' => $total_folders
						]
					);
				}
			}
		}

		$deleted_count = 0;
		$failed_count  = 0;
		$failures      = [];

		// Delete all objects if recursive or force
		if ( $recursive || $force ) {
			foreach ( $objects as $object ) {
				$object_key = $object->get_key();

				// Apply contextual filter to individual object deletion
				$object_delete_params = $this->apply_contextual_filters(
					'arraypress_s3_delete_folder_object',
					[
						'object_key' => $object_key,
						'proceed'    => true
					],
					$bucket,
					$normalized_path,
					$object_key
				);

				if ( ! $object_delete_params['proceed'] ) {
					continue; // Skip this object
				}

				$delete_result = $this->delete_object( $bucket, $object_key );

				if ( ! $delete_result->is_successful() ) {
					$failed_count ++;
					$failures[] = [
						'key'   => $object_key,
						'error' => $delete_result->get_error_message()
					];
				} else {
					$deleted_count ++;
				}
			}

			// If recursive, also handle subfolders
			if ( $recursive ) {
				foreach ( $prefixes as $prefix ) {
					$subfolder_result = $this->delete_folder( $bucket, $prefix, true, true );

					if ( ! $subfolder_result->is_successful() ) {
						$failed_count ++;
						$failures[] = [
							'key'   => $prefix,
							'error' => $subfolder_result->get_error_message()
						];
					}
				}
			}
		} else {
			// Just delete the folder placeholder if it exists
			$placeholder_found = false;
			foreach ( $objects as $object ) {
				if ( $object->get_key() === $normalized_path ) {
					$placeholder_found = true;
					$delete_result     = $this->delete_object( $bucket, $object->get_key() );

					if ( ! $delete_result->is_successful() ) {
						$failed_count ++;
						$failures[] = [
							'key'   => $object->get_key(),
							'error' => $delete_result->get_error_message()
						];
					} else {
						$deleted_count ++;
					}
					break;
				}
			}

			if ( ! $placeholder_found ) {
				return new ErrorResponse(
					sprintf( __( 'Folder "%s" not found', 'arraypress' ), $normalized_path ),
					'folder_not_found',
					404
				);
			}
		}

		// CRITICAL: Always try to delete the folder placeholder as a final step
		// This ensures the folder disappears from listings
		if ( $recursive || $force ) {
			$final_cleanup_result = $this->delete_object( $bucket, $normalized_path );
			if ( $final_cleanup_result->is_successful() ) {
				$this->debug( 'Successfully deleted folder placeholder in final cleanup', $normalized_path );
			} else {
				$this->debug( 'Failed to delete folder placeholder in final cleanup', [
					'folder' => $normalized_path,
					'error'  => $final_cleanup_result->get_error_message()
				] );
				// Don't count this as a failure if other deletions succeeded
			}
		}

		// Prepare result data
		$result_data = [
			'bucket'        => $bucket,
			'folder_path'   => $normalized_path,
			'deleted_count' => $deleted_count,
			'failed_count'  => $failed_count,
			'failures'      => $failures,
			'recursive'     => $recursive
		];

		// Apply contextual filter to the deletion result
		$result_data = $this->apply_contextual_filters(
			'arraypress_s3_folder_deleted',
			$result_data,
			$bucket,
			$normalized_path,
			$deleted_count > 0
		);

		// Return appropriate response
		if ( $failed_count === 0 ) {
			return new SuccessResponse(
				sprintf( __( 'Folder "%s" deleted successfully', 'arraypress' ), $normalized_path ),
				200,
				$result_data
			);
		} elseif ( $deleted_count > 0 ) {
			return new SuccessResponse(
				sprintf( __( 'Folder "%s" partially deleted with some failures', 'arraypress' ), $normalized_path ),
				207, // Multi-Status
				$result_data
			);
		} else {
			return new ErrorResponse(
				sprintf( __( 'Failed to delete folder "%s"', 'arraypress' ), $normalized_path ),
				'folder_deletion_failed',
				400,
				$result_data
			);
		}
	}

}