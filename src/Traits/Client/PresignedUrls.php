<?php
/**
 * Client Upload Operations Trait
 *
 * Handles upload-related operations for the S3 Client.
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

/**
 * Trait PresignedUrls
 */
trait PresignedUrls {

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
	 * Generate a presigned URL with fallback
	 *
	 * Convenience method that generates a presigned URL for downloading an object
	 * and returns either the presigned URL on success or a fallback value on failure.
	 * This is useful when you want a simple string result without handling response objects.
	 *
	 * Common use cases:
	 * - WordPress/WooCommerce download URLs
	 * - Media library file access
	 * - Simple file sharing links
	 *
	 * Usage Examples:
	 * ```php
	 * // Return presigned URL or original URL on failure
	 * $download_url = $client->get_presigned_url_or_fallback(
	 *     'my-bucket',
	 *     'downloads/file.pdf',
	 *     60,
	 *     $original_url
	 * );
	 *
	 * // Return presigned URL or empty string on failure
	 * $secure_url = $client->get_presigned_url_or_fallback(
	 *     'private-bucket',
	 *     'documents/secret.pdf',
	 *     15
	 * );
	 *
	 * // Return presigned URL or CDN URL on failure
	 * $media_url = $client->get_presigned_url_or_fallback(
	 *     'media-bucket',
	 *     'images/photo.jpg',
	 *     120,
	 *     'https://cdn.example.com/images/photo.jpg'
	 * );
	 * ```
	 *
	 * @param string $bucket     Bucket name containing the object
	 * @param string $object_key Object key (path) to generate URL for
	 * @param int    $expires    URL expiration time in minutes (default: 60)
	 * @param string $fallback   Fallback value to return on failure (default: empty string)
	 *
	 * @return string Presigned URL on success, fallback value on failure
	 *
	 * @see   get_presigned_url() For full response object with error details
	 */
	public function get_presigned_url_or_fallback(
		string $bucket,
		string $object_key,
		int $expires = 60,
		string $fallback = ''
	): string {
		$response = $this->get_presigned_url( $bucket, $object_key, $expires );

		return $response->is_successful() ? $response->get_url() : $fallback;
	}

	/**
	 * Generate a pre-signed URL for uploading an object
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 * @param int    $expires    Expiration time in minutes
	 *
	 * @return ResponseInterface Pre-signed URL response or error
	 */
	public function get_presigned_upload_url( string $bucket, string $object_key, int $expires = 15 ): ResponseInterface {
		return $this->signer->get_presigned_upload_url( $bucket, $object_key, $expires );
	}

	/**
	 * Generate a presigned upload URL with fallback
	 *
	 * Convenience method that generates a presigned URL for uploading an object
	 * and returns either the presigned URL on success or a fallback value on failure.
	 * This is useful for simple upload scenarios without response object handling.
	 *
	 * Usage Examples:
	 * ```php
	 * // Return upload URL or empty string on failure
	 * $upload_url = $client->get_presigned_upload_url_or_fallback(
	 *     'uploads-bucket',
	 *     'documents/new-file.pdf',
	 *     15
	 * );
	 *
	 * if ( $upload_url ) {
	 *     // Proceed with upload
	 *     echo "Upload to: " . $upload_url;
	 * } else {
	 *     // Handle upload URL generation failure
	 *     echo "Failed to generate upload URL";
	 * }
	 * ```
	 *
	 * @param string $bucket     Bucket name for the upload
	 * @param string $object_key Object key (path) for the upload
	 * @param int    $expires    URL expiration time in minutes (default: 15)
	 * @param string $fallback   Fallback value to return on failure (default: empty string)
	 *
	 * @return string Presigned upload URL on success, fallback value on failure
	 *
	 * @see   get_presigned_upload_url() For full response object with error details
	 */
	public function get_presigned_upload_url_or_fallback(
		string $bucket,
		string $object_key,
		int $expires = 15,
		string $fallback = ''
	): string {
		$response = $this->get_presigned_upload_url( $bucket, $object_key, $expires );

		return $response->is_successful() ? $response->get_url() : $fallback;
	}

}