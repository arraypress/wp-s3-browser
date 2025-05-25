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
use ArrayPress\S3\Utils\Directory;

/**
 * Trait RenameOperations
 */
trait Folders {

	/**
	 * Check if a folder (prefix) exists
	 *
	 * @param string $bucket      Bucket name
	 * @param string $folder_path Folder path
	 *
	 * @return ResponseInterface Response with existence info or error
	 */
	public function folder_exists( string $bucket, string $folder_path ): ResponseInterface {
		if ( empty( $bucket ) || empty( $folder_path ) ) {
			return new ErrorResponse(
				__( 'Bucket and folder path are required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		// Normalize the folder path
		$normalized_path = Directory::normalize( $folder_path );


		// Check by listing objects with this prefix (limit to 1 for efficiency)
		$objects_result = $this->get_object_models( $bucket, 1, $normalized_path, '/', '', false );

		if ( is_wp_error( $objects_result ) ) {
			return new ErrorResponse(
				__( 'Failed to check folder existence', 'arraypress' ),
				'folder_check_error',
				400,
				[ 'error' => $objects_result->get_error_message() ]
			);
		}

		$has_objects  = ! empty( $objects_result['objects'] );
		$has_prefixes = ! empty( $objects_result['prefixes'] );
		$exists       = $has_objects || $has_prefixes;

		// Check if there's a direct placeholder object
		$has_placeholder = false;
		if ( $has_objects ) {
			foreach ( $objects_result['objects'] as $object ) {
				if ( $object->get_key() === $normalized_path ) {
					$has_placeholder = true;
					break;
				}
			}
		}

		return new SuccessResponse(
			$exists ?
				sprintf( __( 'Folder "%s" exists', 'arraypress' ), $normalized_path ) :
				sprintf( __( 'Folder "%s" does not exist', 'arraypress' ), $normalized_path ),
			200,
			[
				'bucket'          => $bucket,
				'folder_path'     => $normalized_path,
				'exists'          => $exists,
				'has_placeholder' => $has_placeholder,
				'has_objects'     => $has_objects,
				'has_subfolders'  => $has_prefixes,
				'object_count'    => count( $objects_result['objects'] ),
				'subfolder_count' => count( $objects_result['prefixes'] )
			]
		);
	}

	/**
	 * Create a folder (prefix) by uploading a placeholder object
	 *
	 * @param string $bucket      Bucket name
	 * @param string $folder_path Folder path (will be normalized to end with /)
	 *
	 * @return ResponseInterface Response or error
	 */
	public function create_folder( string $bucket, string $folder_path ): ResponseInterface {
		if ( empty( $bucket ) || empty( $folder_path ) ) {
			return new ErrorResponse(
				__( 'Bucket and folder path are required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		// Normalize the folder path to ensure it ends with /
		$normalized_path = Directory::normalize( $folder_path );

		// Check if folder already exists by listing objects with this prefix
		$existing_check = $this->get_objects( $bucket, 1, $normalized_path, '/', '', false );

		if ( is_wp_error( $existing_check ) ) {
			return new ErrorResponse(
				__( 'Failed to check if folder exists', 'arraypress' ),
				'folder_check_error',
				400,
				[ 'error' => $existing_check->get_error_message() ]
			);
		}

		// Check if we got any objects or prefixes - if so, the folder effectively exists
		$models_result = $this->get_object_models( $bucket, 1, $normalized_path, '/', '', false );
		if ( ! is_wp_error( $models_result ) ) {
			$has_objects  = ! empty( $models_result['objects'] );
			$has_prefixes = ! empty( $models_result['prefixes'] );

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
		// This is a common S3 pattern - create an empty object with the folder name + /
		$placeholder_content = '';

		// Upload the placeholder
		$upload_result = $this->put_object(
			$bucket,
			$normalized_path,
			$placeholder_content,
			false, // Not a file path, it's content
			'application/x-directory' // MIME type for directories
		);

		if ( is_wp_error( $upload_result ) ) {
			return new ErrorResponse(
				sprintf( __( 'Failed to create folder "%s"', 'arraypress' ), $normalized_path ),
				'folder_creation_error',
				400,
				[ 'upload_error' => $upload_result->get_error_message() ]
			);
		}

		if ( ! $upload_result->is_successful() ) {
			return new ErrorResponse(
				sprintf( __( 'Failed to create folder "%s"', 'arraypress' ), $normalized_path ),
				'folder_creation_error',
				400,
				[ 'upload_result' => $upload_result ]
			);
		}

		return new SuccessResponse(
			sprintf( __( 'Folder "%s" created successfully', 'arraypress' ), $normalized_path ),
			201,
			[
				'bucket'      => $bucket,
				'folder_path' => $normalized_path,
				'created'     => true
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
	 * @return ResponseInterface Response or error
	 */
	public function rename_folder(
		string $bucket,
		string $source_prefix,
		string $target_prefix,
		bool $recursive = true
	): ResponseInterface {
		// 1. Ensure prefixes end with a slash
		$source_prefix = Directory::normalize( $source_prefix );
		$target_prefix = Directory::normalize( $target_prefix );

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

	/**
	 * Delete a folder (prefix) and optionally all its contents
	 *
	 * @param string $bucket      Bucket name
	 * @param string $folder_path Folder path
	 * @param bool   $recursive   Whether to delete all contents recursively
	 * @param bool   $force       Force deletion even if folder has contents (when recursive is false)
	 *
	 * @return ResponseInterface Response or error
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

		// Normalize the folder path
		$normalized_path = Directory::normalize( $folder_path );

		// Get all objects in this folder
		$objects_result = $this->get_object_models( $bucket, 1000, $normalized_path, $recursive ? '' : '/' );
		if ( is_wp_error( $objects_result ) ) {
			return new ErrorResponse(
				__( 'Failed to list folder contents', 'arraypress' ),
				'folder_list_error',
				400,
				[ 'error' => $objects_result->get_error_message() ]
			);
		}

		$objects       = $objects_result['objects'];
		$prefixes      = $objects_result['prefixes'];
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
				$delete_result = $this->delete_object( $bucket, $object->get_key() );

				if ( is_wp_error( $delete_result ) || ! $delete_result->is_successful() ) {
					$failed_count ++;
					$failures[] = [
						'key'   => $object->get_key(),
						'error' => is_wp_error( $delete_result ) ?
							$delete_result->get_error_message() :
							'Delete operation failed'
					];
				} else {
					$deleted_count ++;
				}
			}

			// If recursive, also handle subfolders
			if ( $recursive ) {
				foreach ( $prefixes as $prefix ) {
					$subfolder_result = $this->delete_folder( $bucket, $prefix, true, true );

					if ( is_wp_error( $subfolder_result ) || ! $subfolder_result->is_successful() ) {
						$failed_count ++;
						$failures[] = [
							'key'   => $prefix,
							'error' => is_wp_error( $subfolder_result ) ?
								$subfolder_result->get_error_message() :
								'Subfolder deletion failed'
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

					if ( is_wp_error( $delete_result ) || ! $delete_result->is_successful() ) {
						$failed_count ++;
						$failures[] = [
							'key'   => $object->get_key(),
							'error' => is_wp_error( $delete_result ) ?
								$delete_result->get_error_message() :
								'Delete operation failed'
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

		// Return appropriate response
		if ( $failed_count === 0 ) {
			return new SuccessResponse(
				sprintf( __( 'Folder "%s" deleted successfully', 'arraypress' ), $normalized_path ),
				200,
				[
					'bucket'        => $bucket,
					'folder_path'   => $normalized_path,
					'deleted_count' => $deleted_count,
					'recursive'     => $recursive
				]
			);
		} elseif ( $deleted_count > 0 ) {
			return new SuccessResponse(
				sprintf( __( 'Folder "%s" partially deleted with some failures', 'arraypress' ), $normalized_path ),
				207, // Multi-Status
				[
					'bucket'        => $bucket,
					'folder_path'   => $normalized_path,
					'deleted_count' => $deleted_count,
					'failed_count'  => $failed_count,
					'failures'      => $failures,
					'recursive'     => $recursive
				]
			);
		} else {
			return new ErrorResponse(
				sprintf( __( 'Failed to delete folder "%s"', 'arraypress' ), $normalized_path ),
				'folder_deletion_failed',
				400,
				[
					'folder_path' => $normalized_path,
					'failures'    => $failures
				]
			);
		}
	}

}