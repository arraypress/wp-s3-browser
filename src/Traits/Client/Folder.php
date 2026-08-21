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
				'folder_path' => $folder_path,
			],
			$bucket,
			$folder_path
		);

		$bucket      = $create_params['bucket'];
		$folder_path = $create_params['folder_path'];

		// Use folder path as-is (already normalized by build_folder_key)
		$normalized_path = $folder_path;

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
					/* translators: %1$s: folder name */
					sprintf( __( 'Folder "%s" already exists', 'arraypress' ), $normalized_path ),
					200,
					[
						'bucket'      => $bucket,
						'folder_path' => $normalized_path,
						'existed'     => true,
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

		// Use signer directly for folder creation (bypass presigned URL for empty content)
		$headers = $this->api->generate_auth_headers(
			'PUT',
			$bucket,
			$normalized_path,
			[],
			$placeholder_content
		);

		$headers['Content-Type']   = 'application/x-directory';
		$headers['Content-Length'] = '0';
		$headers                   = $this->api->get_base_request_headers( $headers );

		$url = $this->provider->format_url( $bucket, $normalized_path );

		$response = wp_remote_request( $url, [
			'method'  => 'PUT',
			'headers' => $headers,
			'body'    => '',
			'timeout' => 30,
		] );

		if ( is_wp_error( $response ) ) {
			return ErrorResponse::from_wp_error( $response );
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$body = wp_remote_retrieve_body( $response );

			return new ErrorResponse(
				/* translators: %1$s: folder name */
				sprintf( __( 'Failed to create folder "%s"', 'arraypress' ), $normalized_path ),
				'folder_creation_error',
				$status_code,
				[ 'response_body' => $body ]
			);
		}

		$success_data = [
			'bucket'      => $bucket,
			'folder_path' => $normalized_path,
			'created'     => true,
		];

		// Apply contextual filter to the folder creation success result
		$success_data = $this->apply_contextual_filters(
			'arraypress_s3_folder_created',
			$success_data,
			$bucket,
			$normalized_path
		);

		return new SuccessResponse(
			/* translators: %1$s: folder name */
			sprintf( __( 'Folder "%s" created successfully', 'arraypress' ), $normalized_path ),
			201,
			$success_data
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
				'proceed'     => true,
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
					'folder_path' => $folder_path,
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
							/* translators: %1$s: folder name */
							__( 'Folder "%s" is not empty. Use recursive=true to delete all contents or force=true to delete anyway', 'arraypress' ),
							$normalized_path
						),
						'folder_not_empty',
						400,
						[
							'folder_path'  => $normalized_path,
							'object_count' => $total_objects,
							'folder_count' => $total_folders,
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
						'proceed'    => true,
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
					++$failed_count;
					$failures[] = [
						'key'   => $object_key,
						'error' => $delete_result->get_error_message(),
					];
				} else {
					++$deleted_count;
				}
			}

			// If recursive, also handle subfolders
			if ( $recursive ) {
				foreach ( $prefixes as $prefix ) {
					$subfolder_result = $this->delete_folder( $bucket, $prefix, true, true );

					if ( ! $subfolder_result->is_successful() ) {
						++$failed_count;
						$failures[] = [
							'key'   => $prefix,
							'error' => $subfolder_result->get_error_message(),
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
						++$failed_count;
						$failures[] = [
							'key'   => $object->get_key(),
							'error' => $delete_result->get_error_message(),
						];
					} else {
						++$deleted_count;
					}
					break;
				}
			}

			if ( ! $placeholder_found ) {
				return new ErrorResponse(
					/* translators: %1$s: folder name */
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
					'error'  => $final_cleanup_result->get_error_message(),
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
			'recursive'     => $recursive,
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
				/* translators: %1$s: folder name */
				sprintf( __( 'Folder "%s" deleted successfully', 'arraypress' ), $normalized_path ),
				200,
				$result_data
			);
		} elseif ( $deleted_count > 0 ) {
			return new SuccessResponse(
				/* translators: %1$s: folder name */
				sprintf( __( 'Folder "%s" partially deleted with some failures', 'arraypress' ), $normalized_path ),
				207, // Multi-Status
				$result_data
			);
		} else {
			return new ErrorResponse(
				/* translators: %1$s: folder name */
				sprintf( __( 'Failed to delete folder "%s"', 'arraypress' ), $normalized_path ),
				'folder_deletion_failed',
				400,
				$result_data
			);
		}
	}
}
