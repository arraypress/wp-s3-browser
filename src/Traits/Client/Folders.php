<?php
/**
 * Client Folder Operations Trait
 *
 * Handles folder/prefix-related operations for the S3 Client.
 * Note: S3 doesn't have true folders, but we can simulate them using prefixes.
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
 * Trait Folders
 */
trait Folders {

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
		$normalized_path = rtrim( $folder_path, '/' ) . '/';

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

		// Check if we got any objects or prefixes - if so, folder effectively exists
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
		$upload_result = $this->upload_file(
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
		$normalized_path = rtrim( $folder_path, '/' ) . '/';

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
		$normalized_path = rtrim( $folder_path, '/' ) . '/';

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
	 * Rename a folder (this uses the existing rename_prefix method)
	 *
	 * @param string $bucket       Bucket name
	 * @param string $current_path Current folder path
	 * @param string $new_path     New folder path
	 * @param bool   $recursive    Whether to rename recursively
	 *
	 * @return ResponseInterface Response or error
	 */
	public function rename_folder(
		string $bucket,
		string $current_path,
		string $new_path,
		bool $recursive = true
	): ResponseInterface {
		if ( empty( $bucket ) || empty( $current_path ) || empty( $new_path ) ) {
			return new ErrorResponse(
				__( 'Bucket, current path, and new path are required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		// Normalize paths
		$normalized_current = rtrim( $current_path, '/' ) . '/';
		$normalized_new     = rtrim( $new_path, '/' ) . '/';

		// Check if source folder exists
		$exists_check = $this->folder_exists( $bucket, $normalized_current );
		if ( is_wp_error( $exists_check ) ) {
			return $exists_check;
		}

		$exists_data = $exists_check->get_data();
		if ( ! $exists_data['exists'] ) {
			return new ErrorResponse(
				sprintf( __( 'Source folder "%s" does not exist', 'arraypress' ), $normalized_current ),
				'source_folder_not_found',
				404
			);
		}

		// Check if target folder already exists
		$target_exists_check = $this->folder_exists( $bucket, $normalized_new );
		if ( ! is_wp_error( $target_exists_check ) ) {
			$target_exists_data = $target_exists_check->get_data();
			if ( $target_exists_data['exists'] ) {
				return new ErrorResponse(
					sprintf( __( 'Target folder "%s" already exists', 'arraypress' ), $normalized_new ),
					'target_folder_exists',
					409 // Conflict
				);
			}
		}

		// Use the existing rename_prefix method
		$rename_result = $this->rename_prefix( $bucket, $normalized_current, $normalized_new, $recursive );

		// Enhance the response message for folder context
		if ( $rename_result->is_successful() ) {
			$data                   = $rename_result->get_data();
			$data['renamed_folder'] = true;
			$data['source_folder']  = $normalized_current;
			$data['target_folder']  = $normalized_new;

			return new SuccessResponse(
				sprintf(
					__( 'Folder renamed from "%s" to "%s" successfully', 'arraypress' ),
					$normalized_current,
					$normalized_new
				),
				$rename_result->get_status_code(),
				$data
			);
		}

		return $rename_result;
	}

	/**
	 * Get folder information including size and object count
	 *
	 * @param string $bucket      Bucket name
	 * @param string $folder_path Folder path
	 * @param bool   $recursive   Whether to include subfolders in the count
	 *
	 * @return ResponseInterface Response with folder info or error
	 */
	public function get_folder_info( string $bucket, string $folder_path, bool $recursive = false ): ResponseInterface {
		if ( empty( $bucket ) || empty( $folder_path ) ) {
			return new ErrorResponse(
				__( 'Bucket and folder path are required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		// Normalize the folder path
		$normalized_path = rtrim( $folder_path, '/' ) . '/';

		// Check if folder exists first
		$exists_check = $this->folder_exists( $bucket, $normalized_path );
		if ( is_wp_error( $exists_check ) ) {
			return $exists_check;
		}

		$exists_data = $exists_check->get_data();
		if ( ! $exists_data['exists'] ) {
			return new ErrorResponse(
				sprintf( __( 'Folder "%s" does not exist', 'arraypress' ), $normalized_path ),
				'folder_not_found',
				404
			);
		}

		// Get objects in the folder
		$delimiter      = $recursive ? '' : '/';
		$objects_result = $this->get_object_models( $bucket, 1000, $normalized_path, $delimiter );

		if ( is_wp_error( $objects_result ) ) {
			return new ErrorResponse(
				__( 'Failed to get folder information', 'arraypress' ),
				'folder_info_error',
				400,
				[ 'error' => $objects_result->get_error_message() ]
			);
		}

		$objects  = $objects_result['objects'];
		$prefixes = $objects_result['prefixes'];

		// Calculate totals
		$total_size   = 0;
		$object_count = 0;
		$folder_count = count( $prefixes );

		foreach ( $objects as $object ) {
			// Skip the folder placeholder itself
			if ( $object->get_key() !== $normalized_path ) {
				$total_size += $object->get_size();
				$object_count ++;
			}
		}

		// If we're doing recursive counting and there are subfolders, we'd need to iterate
		// For now, we'll note if the results are truncated
		$is_complete = ! $objects_result['truncated'];

		return new SuccessResponse(
			sprintf( __( 'Folder information for "%s"', 'arraypress' ), $normalized_path ),
			200,
			[
				'bucket'          => $bucket,
				'folder_path'     => $normalized_path,
				'object_count'    => $object_count,
				'subfolder_count' => $folder_count,
				'total_size'      => $total_size,
				'formatted_size'  => size_format( $total_size ),
				'recursive'       => $recursive,
				'is_complete'     => $is_complete,
				'has_placeholder' => $exists_data['has_placeholder'],
				'truncated'       => $objects_result['truncated']
			]
		);
	}

}