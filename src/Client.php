<?php
/**
 * S3 Client Class
 *
 * Main client for interacting with S3-compatible storage.
 *
 * @package     ArrayPress\S3
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      ArrayPress Team
 */

declare( strict_types=1 );

namespace ArrayPress\S3;

use ArrayPress\S3\Interfaces\Response as ResponseInterface;
use ArrayPress\S3\Abstracts\Provider;
use ArrayPress\S3\Responses\BucketsResponse;
use ArrayPress\S3\Responses\ErrorResponse;
use ArrayPress\S3\Responses\ObjectsResponse;
use ArrayPress\S3\Responses\SuccessResponse;
use ArrayPress\S3\Traits\ResponseFormatter;
use ArrayPress\S3\Traits\Caching;
use ArrayPress\S3\Utils\File;
use ArrayPress\S3\Utils\Path;
use Exception;
use Generator;
use WP_Error;

/**
 * Class Client
 */
class Client {
	use ResponseFormatter;
	use Caching;

	/**
	 * Provider instance
	 *
	 * @var Provider
	 */
	private Provider $provider;

	/**
	 * Signer instance
	 *
	 * @var Signer
	 */
	private Signer $signer;

	/**
	 * Debug mode
	 *
	 * @var bool
	 */
	private bool $debug = false;

	/**
	 * Custom debug logger callback
	 *
	 * @var callable|null
	 */
	private $debug_logger = null;

	/**
	 * Constructor
	 *
	 * @param Provider $provider   Provider instance
	 * @param string   $access_key Access key ID
	 * @param string   $secret_key Secret access key
	 * @param bool     $use_cache  Whether to use cache
	 * @param int      $cache_ttl  Cache TTL in seconds
	 * @param bool     $debug      Whether to enable debug mode
	 */
	public function __construct(
		Provider $provider,
		string $access_key,
		string $secret_key,
		bool $use_cache = true,
		int $cache_ttl = 86400, // DAY_IN_SECONDS
		bool $debug = false
	) {
		$this->provider = $provider;
		$this->signer   = new Signer( $provider, $access_key, $secret_key );
		$this->init_cache( $use_cache, $cache_ttl );
		$this->debug = $debug;
	}

	/**
	 * Get the provider instance
	 *
	 * @return Provider
	 */
	public function get_provider(): Provider {
		return $this->provider;
	}

	/**
	 * Get buckets list
	 *
	 * @param int    $max_keys  Maximum number of buckets to return
	 * @param string $prefix    Prefix to filter buckets
	 * @param string $marker    Marker for pagination
	 * @param bool   $use_cache Whether to use cache
	 *
	 * @return ResponseInterface|WP_Error Response or error
	 */
	public function get_buckets(
		int $max_keys = 1000,
		string $prefix = '',
		string $marker = '',
		bool $use_cache = true
	) {
		// Check cache if enabled
		if ( $use_cache && $this->is_cache_enabled() ) {
			$cache_key = $this->get_cache_key( 'buckets', [
				'max_keys' => $max_keys,
				'prefix'   => $prefix,
				'marker'   => $marker
			] );

			$cached = $this->get_from_cache( $cache_key );
			if ( $cached !== false ) {
				return $cached;
			}
		}

		// Use signer to list buckets
		$result = $this->signer->list_buckets( $max_keys, $prefix, $marker );

		// Handle errors
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Debug logging if enabled
		if ( $this->debug ) {
			$this->log_debug( 'Client: Raw result from signer:', $result );
		}

		// Cache the result if successful
		if ( $use_cache && $this->is_cache_enabled() && $result->is_successful() ) {
			$this->save_to_cache( $cache_key, $result );
		}

		return $result;
	}

	/**
	 * Get buckets as models
	 *
	 * @param int    $max_keys  Maximum number of buckets to return
	 * @param string $prefix    Prefix to filter buckets
	 * @param string $marker    Marker for pagination
	 * @param bool   $use_cache Whether to use cache
	 *
	 * @return array|WP_Error Array of bucket models or WP_Error
	 */
	public function get_bucket_models(
		int $max_keys = 1000,
		string $prefix = '',
		string $marker = '',
		bool $use_cache = true
	) {
		// Get buckets response
		$response = $this->get_buckets(
			$max_keys,
			$prefix,
			$marker,
			$use_cache
		);

		// Handle errors
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! ( $response instanceof BucketsResponse ) ) {
			return new WP_Error(
				'invalid_response',
				'Expected BucketsResponse but got ' . get_class( $response )
			);
		}

		// Return result using response object's transformation method
		return [
			'buckets'         => $response->to_bucket_models(),
			'truncated'       => $response->is_truncated(),
			'next_marker'     => $response->get_next_marker(),
			'owner'           => $response->get_owner(),
			'response_object' => $response  // Return the response object too
		];
	}

	/**
	 * Get objects in a bucket
	 *
	 * @param string $bucket             Bucket name
	 * @param int    $max_keys           Maximum number of objects to return
	 * @param string $prefix             Prefix to filter objects
	 * @param string $delimiter          Delimiter (e.g., '/' for folder-like structure)
	 * @param string $continuation_token Continuation token for pagination
	 * @param bool   $use_cache          Whether to use cache
	 *
	 * @return ResponseInterface|WP_Error Response or error
	 */
	public function get_objects(
		string $bucket,
		int $max_keys = 1000,
		string $prefix = '',
		string $delimiter = '/',
		string $continuation_token = '',
		bool $use_cache = true
	) {
		// Check cache if enabled
		if ( $use_cache && $this->is_cache_enabled() ) {
			$cache_key = $this->get_cache_key( 'objects_' . $bucket, [
				'max_keys'           => $max_keys,
				'prefix'             => $prefix,
				'delimiter'          => $delimiter,
				'continuation_token' => $continuation_token
			] );

			$cached = $this->get_from_cache( $cache_key );
			if ( $cached !== false ) {
				return $cached;
			}
		}

		// Use signer to list objects
		$result = $this->signer->list_objects(
			$bucket,
			$max_keys,
			$prefix,
			$delimiter,
			$continuation_token
		);

		// Handle errors
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Debug logging if enabled
		if ( $this->debug ) {
			$this->log_debug( 'Client: Raw result from signer for objects:', $result );
		}

		// Cache the result if successful
		if ( $use_cache && $this->is_cache_enabled() && $result->is_successful() ) {
			$this->save_to_cache( $cache_key, $result );
		}

		return $result;
	}

	/**
	 * Get objects as models
	 *
	 * @param string $bucket             Bucket name
	 * @param int    $max_keys           Maximum number of objects to return
	 * @param string $prefix             Prefix to filter objects
	 * @param string $delimiter          Delimiter (e.g., '/' for folder-like structure)
	 * @param string $continuation_token Continuation token for pagination
	 * @param bool   $use_cache          Whether to use cache
	 *
	 * @return array|WP_Error Array of models or WP_Error
	 */
	public function get_object_models(
		string $bucket,
		int $max_keys = 1000,
		string $prefix = '',
		string $delimiter = '/',
		string $continuation_token = '',
		bool $use_cache = true
	) {
		// Get regular object response
		$response = $this->get_objects(
			$bucket,
			$max_keys,
			$prefix,
			$delimiter,
			$continuation_token,
			$use_cache
		);

		// Handle errors
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Check if the response is an ErrorResponse and convert it to WP_Error
		if ( $response instanceof ErrorResponse ) {
			return new WP_Error(
				$response->get_error_code(),
				$response->get_error_message(),
				[ 'status' => $response->get_status_code() ]
			);
		}

		if ( ! ( $response instanceof ObjectsResponse ) ) {
			return new WP_Error(
				'invalid_response',
				'Expected ObjectsResponse but got ' . get_class( $response )
			);
		}

		// Return result using response object's transformation methods
		return [
			'objects'            => $response->to_object_models(),
			'prefixes'           => $response->to_prefix_models(),
			'truncated'          => $response->is_truncated(),
			'continuation_token' => $response->get_continuation_token(),
			'response_object'    => $response  // Return the response object too
		];
	}

	/**
	 * Get objects as models using an iterator for automatic pagination
	 *
	 * @param string $bucket    Bucket name
	 * @param string $prefix    Prefix to filter objects
	 * @param string $delimiter Delimiter (e.g., '/' for folder-like structure)
	 * @param int    $max_keys  Maximum number of objects to return per request
	 * @param bool   $use_cache Whether to use cache
	 *
	 * @return Generator|WP_Error Generator yielding models or WP_Error
	 */
	public function get_objects_iterator(
		string $bucket,
		string $prefix = '',
		string $delimiter = '/',
		int $max_keys = 1000,
		bool $use_cache = true
	) {
		$continuation_token = '';

		do {
			$result = $this->get_object_models(
				$bucket,
				$max_keys,
				$prefix,
				$delimiter,
				$continuation_token,
				$use_cache
			);

			// Check for errors
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Yield the objects and prefixes
			foreach ( $result['objects'] as $object ) {
				yield 'object' => $object;
			}

			foreach ( $result['prefixes'] as $prefix_model ) {
				yield 'prefix' => $prefix_model;
			}

			// Update continuation token for the next iteration
			$continuation_token = $result['truncated'] ? $result['continuation_token'] : '';

		} while ( ! empty( $continuation_token ) );
	}

	/**
	 * Delete an object from a bucket
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 *
	 * @return ResponseInterface|WP_Error Response or error
	 */
	public function delete_object( string $bucket, string $object_key ) {
		// Use signer to delete object
		$result = $this->signer->delete_object( $bucket, $object_key );

		// Handle errors
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Debug logging if enabled
		if ( $this->debug ) {
			$this->log_debug( 'Client: Raw result from signer for delete operation:', $result );
		}

		// If we're caching, we need to bust the cache for this bucket/prefix
		if ( $this->is_cache_enabled() ) {
			// Extract the directory prefix from the object key
			$prefix = Path::extract_directory_prefix( $object_key );

			// Clear cache for this specific prefix
			$cache_key = $this->get_cache_key( 'objects_' . $bucket, [
				'max_keys'  => 1000,
				'prefix'    => $prefix,
				'delimiter' => '/'
			] );
			$this->clear_cache_item( $cache_key );
		}

		return $result;
	}

	/**
	 * Rename an object in a bucket
	 *
	 * @param string $bucket     Bucket name
	 * @param string $source_key Current object key
	 * @param string $target_key New object key
	 *
	 * @return ResponseInterface|WP_Error Response or error
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
	 * Copy an object within or between buckets
	 *
	 * @param string $source_bucket Source bucket name
	 * @param string $source_key    Source object key
	 * @param string $target_bucket Target bucket name
	 * @param string $target_key    Target object key
	 *
	 * @return ResponseInterface|WP_Error Response or error
	 */
	public function copy_object(
		string $source_bucket,
		string $source_key,
		string $target_bucket,
		string $target_key
	): ResponseInterface {
		// Use signer to copy object
		$result = $this->signer->copy_object( $source_bucket, $source_key, $target_bucket, $target_key );

		// Handle errors
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Debug logging if enabled
		if ( $this->debug ) {
			$this->log_debug( 'Client: Raw result from signer for copy operation:', $result );
		}

		// Clear cache for target bucket/prefix
		if ( $this->is_cache_enabled() ) {
			// Extract the directory prefix from the target object key
			$prefix = Path::extract_directory_prefix( $target_key );

			// Clear cache for this specific prefix
			$cache_key = $this->get_cache_key( 'objects_' . $target_bucket, [
				'max_keys'  => 1000,
				'prefix'    => $prefix,
				'delimiter' => '/'
			] );
			$this->clear_cache_item( $cache_key );
		}

		return $result;
	}

	/**
	 * Upload a file to a bucket
	 *
	 * @param string $bucket            Bucket name
	 * @param string $target_key        Target object key
	 * @param string $file_path         Local file path or file data
	 * @param bool   $is_path           Whether $file_path is a path (true) or file contents (false)
	 * @param string $content_type      Optional content type
	 * @param array  $additional_params Optional additional parameters
	 *
	 * @return ResponseInterface|WP_Error Response or error
	 */
	public function upload_file(
		string $bucket,
		string $target_key,
		string $file_path,
		bool $is_path = true,
		string $content_type = '',
		array $additional_params = []
	): ResponseInterface {
		// 1. Get a presigned upload URL
		$upload_url_response = $this->get_presigned_upload_url( $bucket, $target_key, 15 );

		if ( is_wp_error( $upload_url_response ) ) {
			return $upload_url_response;
		}

		if ( ! $upload_url_response->is_successful() ) {
			return new ErrorResponse(
				__( 'Failed to generate upload URL', 'arraypress' ),
				'upload_url_error',
				400
			);
		}

		// Get the presigned upload URL
		$upload_url = $upload_url_response->get_url();

		// 2. Determine the content type if not provided
		if ( empty( $content_type ) ) {
			if ( $is_path ) {
				// If it's a file path, determine from the file
				$content_type = mime_content_type( $file_path ) ?: 'application/octet-stream';
			} else {
				// If it's file data, determine from the target key
				$content_type = File::get_mime_type( $target_key );
			}
		}

		// 3. Read the file contents
		$file_contents = $is_path ? file_get_contents( $file_path ) : $file_path;

		if ( $file_contents === false && $is_path ) {
			return new ErrorResponse(
				__( 'Failed to read file', 'arraypress' ),
				'file_read_error',
				400,
				[ 'file_path' => $file_path ]
			);
		}

		// 4. Upload the file using WordPress HTTP API
		$response = wp_remote_request( $upload_url, [
			'method'  => 'PUT',
			'body'    => $file_contents,
			'headers' => array_merge( [
				'Content-Type' => $content_type
			], $additional_params )
		] );

		// Handle upload errors
		if ( is_wp_error( $response ) ) {
			return new ErrorResponse(
				$response->get_error_message(),
				$response->get_error_code(),
				400,
				$response->get_error_data() ?: []
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			return new ErrorResponse(
				sprintf( __( 'Upload failed with status code: %d', 'arraypress' ), $status_code ),
				'upload_error',
				$status_code,
				[ 'response' => $response ]
			);
		}

		// 5. Clear cache for this bucket/prefix
		if ( $this->is_cache_enabled() ) {
			// Extract the directory prefix from the object key
			$prefix = Path::extract_directory_prefix( $target_key );

			// Clear cache for this specific prefix
			$cache_key = $this->get_cache_key( 'objects_' . $bucket, [
				'max_keys'  => 1000,
				'prefix'    => $prefix,
				'delimiter' => '/'
			] );
			$this->clear_cache_item( $cache_key );
		}

		// 6. Return success response
		return new SuccessResponse(
			__( 'File uploaded successfully', 'arraypress' ),
			$status_code,
			[
				'bucket' => $bucket,
				'key'    => $target_key,
				'size'   => strlen( $file_contents )
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

	/**
	 * Generate a pre-signed URL for an object
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 * @param int    $expires    Expiration time in minutes
	 *
	 * @return ResponseInterface Pre-signed URL response, URL string, or error
	 */
	public function get_presigned_url( string $bucket, string $object_key, int $expires = 60 ): ResponseInterface {
		return $this->signer->get_presigned_url( $bucket, $object_key, $expires );
	}

	/**
	 * Generate a pre-signed URL for uploading an object
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 * @param int    $expires    Expiration time in minutes
	 *
	 * @return ResponseInterface|WP_Error Pre-signed URL response or error
	 */
	public function get_presigned_upload_url( string $bucket, string $object_key, int $expires = 15 ) {
		return $this->signer->get_presigned_upload_url( $bucket, $object_key, $expires );
	}

	/**
	 * Set a custom debug logger callback
	 *
	 * @param callable $callback Function to call for debug logging
	 *
	 * @return self
	 */
	public function set_debug_logger( callable $callback ): self {
		$this->debug_logger = $callback;

		// Also set the debug callback for the signer
		$this->signer->set_debug_callback( $callback );

		return $this;
	}

	/**
	 * Enable or disable debug mode
	 *
	 * @param bool $enable Whether to enable debug mode
	 *
	 * @return self
	 */
	public function set_debug( bool $enable ): self {
		$this->debug = $enable;

		return $this;
	}

	/**
	 * Check if debug mode is enabled
	 *
	 * @return bool
	 */
	public function is_debug_enabled(): bool {
		return $this->debug;
	}

	/**
	 * Log debug information
	 *
	 * @param string $message Message to log
	 * @param mixed  $data    Optional data to include
	 */
	private function log_debug( string $message, $data = null ): void {
		if ( ! $this->debug ) {
			return;
		}

		// Use custom logger if set
		if ( is_callable( $this->debug_logger ) ) {
			call_user_func( $this->debug_logger, $message, $data );

			return;
		}

		// Default to error_log
		error_log( $message );
		if ( $data !== null ) {
			error_log( print_r( $data, true ) );
		}
	}

	/**
	 * Check permissions of the current access key
	 *
	 * @param string $bucket Test bucket name to use (must exist)
	 *
	 * @return array Permission details with 'read', 'write', 'delete' flags
	 */
	public function check_key_permissions( string $bucket ): array {
		$permissions = [
			'read'   => false,
			'write'  => false,
			'delete' => false,
			'errors' => []
		];

		// 1. Test READ permission with a list operation
		try {
			$list_result         = $this->get_objects( $bucket, 1 );
			$permissions['read'] = ( $list_result instanceof ObjectsResponse &&
			                         $list_result->is_successful() );
		} catch ( Exception $e ) {
			$permissions['errors']['read'] = $e->getMessage();
		}

		// 2. Test WRITE permission with a temporary file
		if ( $permissions['read'] ) {
			$test_key     = 'permissions-test-' . bin2hex( random_bytes( 8 ) ) . '.txt';
			$test_content = 'This is a test file to check permissions. It can be safely deleted.';

			try {
				// Get a presigned upload URL
				$upload_url_response = $this->get_presigned_upload_url( $bucket, $test_key, 1 );

				if ( $upload_url_response->is_successful() ) {
					$upload_url = $upload_url_response->get_url();

					// Try to upload a small file
					$response = wp_remote_request( $upload_url, [
						'method'  => 'PUT',
						'body'    => $test_content,
						'headers' => [
							'Content-Type' => 'text/plain'
						]
					] );

					$permissions['write'] = ! is_wp_error( $response ) &&
					                        wp_remote_retrieve_response_code( $response ) >= 200 &&
					                        wp_remote_retrieve_response_code( $response ) < 300;

					// 3. Test DELETE permission by trying to delete our test file
					if ( $permissions['write'] ) {
						try {
							$delete_result         = $this->delete_object( $bucket, $test_key );
							$permissions['delete'] = ( $delete_result instanceof SuccessResponse );
						} catch ( Exception $e ) {
							$permissions['errors']['delete'] = $e->getMessage();

							// If we can't delete, leave a note in the test file
							if ( $permissions['write'] ) {
								$this->upload_string_to_bucket(
									$bucket,
									$test_key . '.note',
									"Failed to delete test file. Please delete this and {$test_key} manually."
								);
							}
						}
					}
				}
			} catch ( Exception $e ) {
				$permissions['errors']['write'] = $e->getMessage();
			}
		}

		return $permissions;
	}

	/**
	 * Helper method to upload a string to S3
	 *
	 * @param string $bucket  Bucket name
	 * @param string $key     Object key
	 * @param string $content String content to upload
	 *
	 * @return bool Success flag
	 */
	private function upload_string_to_bucket( string $bucket, string $key, string $content ): bool {
		$upload_url_response = $this->get_presigned_upload_url( $bucket, $key, 1 );

		if ( ! $upload_url_response->is_successful() ) {
			return false;
		}

		$upload_url = $upload_url_response->get_url();

		$response = wp_remote_request( $upload_url, [
			'method'  => 'PUT',
			'body'    => $content,
			'headers' => [
				'Content-Type' => 'text/plain'
			]
		] );

		return ! is_wp_error( $response ) &&
		       wp_remote_retrieve_response_code( $response ) >= 200 &&
		       wp_remote_retrieve_response_code( $response ) < 300;
	}

}