<?php
/**
 * Headers Trait
 *
 * Provides header building functionality for various S3 operations.
 *
 * @package     ArrayPress\S3\Traits\Signer
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Signer;

use ArrayPress\S3\Utils\Encode;

/**
 * Trait Headers
 *
 * Provides specialized header building methods for S3 operations
 */
trait Headers {

	/**
	 * Build headers for S3 copy operations
	 *
	 * Creates the necessary headers for S3 copy operations including the
	 * special x-amz-copy-source header with properly encoded source path.
	 *
	 * @param string $source_bucket Source bucket name
	 * @param string $source_key    Source object key
	 * @param string $target_bucket Target bucket name
	 * @param string $target_key    Target object key
	 *
	 * @return array Complete headers array for copy operation
	 */
	protected function build_copy_headers(
		string $source_bucket,
		string $source_key,
		string $target_bucket,
		string $target_key
	): array {
		$encoded_target_key = Encode::object_key( $target_key );
		$headers = $this->generate_auth_headers( 'PUT', $target_bucket, $encoded_target_key );

		$encoded_source_key = Encode::object_key( $source_key );
		$headers['x-amz-copy-source'] = $source_bucket . '/' . $encoded_source_key;

		return $headers;
	}

	/**
	 * Build headers for delete operations
	 *
	 * Creates headers specifically for delete operations, including
	 * required Content-Length header.
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key to delete
	 *
	 * @return array Complete headers array for delete operation
	 */
	protected function build_delete_headers( string $bucket, string $object_key ): array {
		$encoded_key = Encode::object_key( $object_key );
		$headers = $this->generate_auth_headers( 'DELETE', $bucket, $encoded_key );

		// Content-Length header is required for DELETE operations
		$headers['Content-Length'] = '0';

		return $headers;
	}

	/**
	 * Build headers for upload operations
	 *
	 * Creates headers for object upload operations with optional
	 * content type and custom metadata.
	 *
	 * @param string $bucket       Bucket name
	 * @param string $object_key   Object key
	 * @param string $content_type Optional content type
	 * @param array  $metadata     Optional custom metadata
	 *
	 * @return array Complete headers array for upload operation
	 */
	protected function build_upload_headers(
		string $bucket,
		string $object_key,
		string $content_type = 'application/octet-stream',
		array $metadata = []
	): array {
		$encoded_key = Encode::object_key( $object_key );
		$headers = $this->generate_auth_headers( 'PUT', $bucket, $encoded_key );

		$headers['Content-Type'] = $content_type;

		// Add custom metadata
		foreach ( $metadata as $key => $value ) {
			$headers['x-amz-meta-' . $key] = $value;
		}

		return $headers;
	}

	/**
	 * Build headers for HEAD operations
	 *
	 * Creates headers for HEAD requests to get object metadata.
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 *
	 * @return array Complete headers array for HEAD operation
	 */
	protected function build_head_headers( string $bucket, string $object_key ): array {
		$encoded_key = Encode::object_key( $object_key );
		return $this->generate_auth_headers( 'HEAD', $bucket, $encoded_key );
	}

	/**
	 * Build headers for multipart upload operations
	 *
	 * Creates headers for multipart upload initiation, parts, and completion.
	 *
	 * @param string $bucket       Bucket name
	 * @param string $object_key   Object key
	 * @param string $method       HTTP method (POST for initiate, PUT for parts)
	 * @param array  $query_params Query parameters for the operation
	 * @param string $content_type Optional content type
	 *
	 * @return array Complete headers array for multipart operation
	 */
	protected function build_multipart_headers(
		string $bucket,
		string $object_key,
		string $method,
		array $query_params = [],
		string $content_type = 'application/octet-stream'
	): array {
		$encoded_key = Encode::object_key( $object_key );
		$headers = $this->generate_auth_headers( $method, $bucket, $encoded_key, $query_params );

		if ( $method === 'POST' || $method === 'PUT' ) {
			$headers['Content-Type'] = $content_type;
		}

		return $headers;
	}

	/**
	 * Build headers for batch delete operations
	 *
	 * Creates headers for batch delete operations including required
	 * Content-Type, Content-MD5, and Content-Length headers.
	 *
	 * @param string $bucket     Bucket name
	 * @param string $delete_xml XML content for the delete request
	 *
	 * @return array Complete headers array for batch delete operation
	 */
	protected function build_batch_delete_headers( string $bucket, string $delete_xml ): array {
		$headers = $this->generate_auth_headers( 'POST', $bucket, '', [ 'delete' => '' ] );

		$headers['Content-Type']   = 'application/xml';
		$headers['Content-MD5']    = base64_encode( md5( $delete_xml, true ) );
		$headers['Content-Length'] = (string) strlen( $delete_xml );

		return $headers;
	}

}