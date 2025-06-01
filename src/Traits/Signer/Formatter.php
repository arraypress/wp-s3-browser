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

use ArrayPress\S3\Utils\File;

/**
 * Trait Formatter
 */
trait Formatter {

	/**
	 * Safe helper to extract text values consistently
	 */
	private function get_xml_text_value( $xml_node ): string {
		if ( is_array( $xml_node ) ) {
			return (string) ( $xml_node['value'] ?? $xml_node['@text'] ?? '' );
		}

		return (string) $xml_node;
	}

	/**
	 * Safe helper to extract and clean ETag
	 */
	private function get_clean_etag( $etag_node ): string {
		$etag = $this->get_xml_text_value( $etag_node );

		return trim( $etag, '"' );
	}

	/**
	 * Format an object from XML data
	 *
	 * @param array $objects Array of objects to add to
	 * @param array $object  Object data to format and add
	 */
	private function add_formatted_object( array &$objects, array $object ): void {
		// Use helpers for consistent extraction
		$key_value     = $this->get_xml_text_value( $object['Key'] ?? '' );
		$last_modified = $this->get_xml_text_value( $object['LastModified'] ?? '' );
		$etag          = $this->get_clean_etag( $object['ETag'] ?? '' );
		$size          = (int) $this->get_xml_text_value( $object['Size'] ?? '0' );
		$storage_class = $this->get_xml_text_value( $object['StorageClass'] ?? 'STANDARD' );

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