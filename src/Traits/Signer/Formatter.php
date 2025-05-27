<?php
/**
 * Object Operations Trait
 *
 * Handles object-related operations for S3-compatible storage.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Signer;

use ArrayPress\S3\Interfaces\Response as ResponseInterface;
use ArrayPress\S3\Responses\ObjectsResponse;
use ArrayPress\S3\Responses\ObjectResponse;
use ArrayPress\S3\Responses\ErrorResponse;
use ArrayPress\S3\Responses\SuccessResponse;
use ArrayPress\S3\Utils\Encode;
use ArrayPress\S3\Utils\File;

/**
 * Trait Formatter
 */
trait Formatter {

	/**
	 * Format an object from XML data
	 *
	 * @param array $objects Array of objects to add to
	 * @param array $object  Object data to format and add
	 */
	private function add_formatted_object( array &$objects, array $object ): void {
		// Handle different possible formats
		$key_value     = '';
		$last_modified = '';
		$etag          = '';
		$size          = 0;
		$storage_class = 'STANDARD';

		if ( isset( $object['Key']['value'] ) ) {
			$key_value     = $object['Key']['value'];
			$last_modified = $object['LastModified']['value'] ?? '';
			$etag          = isset( $object['ETag']['value'] ) ? trim( $object['ETag']['value'], '"' ) : '';
			$size          = isset( $object['Size']['value'] ) ? (int) $object['Size']['value'] : 0;
			$storage_class = $object['StorageClass']['value'] ?? 'STANDARD';
		} elseif ( isset( $object['Key'] ) ) {
			$key_value     = $object['Key'];
			$last_modified = $object['LastModified'] ?? '';
			$etag          = isset( $object['ETag'] ) ? trim( $object['ETag'], '"' ) : '';
			$size          = isset( $object['Size'] ) ? (int) $object['Size'] : 0;
			$storage_class = $object['StorageClass'] ?? 'STANDARD';
		}

		if ( empty( $key_value ) ) {
			return;
		}

		// Get filename from key
		$filename = File::name( $key_value );

		// Add a formatted object
		$objects[] = [
			'Key'           => $key_value,
			'Filename'      => $filename,
			'LastModified'  => $last_modified,
			'ETag'          => $etag,
			'Size'          => $size,
			'StorageClass'  => $storage_class,
			'FormattedSize' => size_format( $size ),
			'Type'          => File::type( $filename ),
			'MimeType'      => File::mime_type( $filename )
		];
	}

}