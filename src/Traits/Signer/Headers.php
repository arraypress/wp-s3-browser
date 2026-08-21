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
		// x-amz-copy-source must be part of the signature. S3 requires every
		// x-amz-* header sent to appear in SignedHeaders, so adding it after
		// signing — as this did — yields a request AWS rejects. R2 happens to
		// tolerate it, which is why it went unnoticed.
		return $this->generate_auth_headers(
			'PUT',
			$target_bucket,
			$target_key,
			[],
			'',
			[ 'x-amz-copy-source' => $source_bucket . '/' . Encode::object_key( $source_key ) ]
		);
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
		$headers = $this->generate_auth_headers( 'DELETE', $bucket, $object_key );

		// Content-Length header is required for DELETE operations
		$headers['Content-Length'] = '0';

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
		return $this->generate_auth_headers( 'HEAD', $bucket, $object_key );
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