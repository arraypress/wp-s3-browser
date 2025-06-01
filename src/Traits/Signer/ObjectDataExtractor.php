<?php
/**
 * Object Data Extraction Trait
 *
 * Provides standardized object data extraction from XML responses.
 *
 * @package     ArrayPress\S3\Traits\Signer
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Signer;

use ArrayPress\S3\Utils\File;

/**
 * Trait ObjectDataExtractor
 */
trait ObjectDataExtractor {

	/**
	 * Extract standardized object data from XML array
	 *
	 * @param array $object Raw object data from XML
	 *
	 * @return array Standardized object data
	 */
	protected function extract_object_data( array $object ): array {
		return [
			'key'           => $this->extract_text_value( $object['Key'] ?? '' ),
			'last_modified' => $this->extract_text_value( $object['LastModified'] ?? '' ),
			'etag'          => $this->extract_etag( $object['ETag'] ?? '' ),
			'size'          => $this->extract_size( $object['Size'] ?? '0' ),
			'storage_class' => $this->extract_text_value( $object['StorageClass'] ?? 'STANDARD' )
		];
	}

	/**
	 * Format object data for response with additional metadata
	 *
	 * @param array $object_data Extracted object data
	 *
	 * @return array Formatted object with additional metadata
	 */
	protected function format_object_data( array $object_data ): array {
		$filename = File::name( $object_data['key'] );

		return array_merge( $object_data, [
			'filename'       => $filename,
			'formatted_size' => size_format( $object_data['size'] ),
			'type'           => File::type( $filename ),
			'mime_type'      => File::mime_type( $filename )
		] );
	}

	/**
	 * Extract and format object for API response
	 *
	 * @param array $object Raw object data from XML
	 *
	 * @return array Fully formatted object data
	 */
	protected function extract_and_format_object( array $object ): array {
		$extracted = $this->extract_object_data( $object );
		return $this->format_object_data( $extracted );
	}

	/**
	 * Extract ETag value and remove quotes
	 *
	 * @param mixed $etag_data ETag data from XML
	 *
	 * @return string Clean ETag value
	 */
	protected function extract_etag( $etag_data ): string {
		$etag = $this->extract_text_value( $etag_data );
		return trim( $etag, '"' );
	}

	/**
	 * Extract size as integer
	 *
	 * @param mixed $size_data Size data from XML
	 *
	 * @return int Object size in bytes
	 */
	protected function extract_size( $size_data ): int {
		return (int) $this->extract_text_value( $size_data );
	}

	/**
	 * Extract owner information from XML
	 *
	 * @param array $owner_data Owner data from XML
	 *
	 * @return array Owner information
	 */
	protected function extract_owner_data( array $owner_data ): array {
		return [
			'id'           => $this->extract_text_value( $owner_data['ID'] ?? '' ),
			'display_name' => $this->extract_text_value( $owner_data['DisplayName'] ?? '' )
		];
	}

	/**
	 * Extract bucket information from XML
	 *
	 * @param array $bucket_data Bucket data from XML
	 *
	 * @return array Bucket information
	 */
	protected function extract_bucket_data( array $bucket_data ): array {
		return [
			'name'          => $this->extract_text_value( $bucket_data['Name'] ?? '' ),
			'creation_date' => $this->extract_text_value( $bucket_data['CreationDate'] ?? '' )
		];
	}

	/**
	 * Extract prefix (folder) information
	 *
	 * @param array $prefix_data Prefix data from XML
	 *
	 * @return string Prefix value
	 */
	protected function extract_prefix_data( array $prefix_data ): string {
		return $this->extract_text_value( $prefix_data['Prefix'] ?? $prefix_data );
	}

	/**
	 * Extract pagination information from XML
	 *
	 * @param array $xml Parsed XML response
	 *
	 * @return array Pagination information
	 */
	protected function extract_pagination_data( array $xml ): array {
		return [
			'is_truncated'         => $this->is_truncated_response( $xml ),
			'continuation_token'   => $this->get_continuation_token( $xml ),
			'next_marker'          => $this->get_next_marker( $xml )
		];
	}

	/**
	 * Extract copy operation metadata
	 *
	 * @param array $xml Copy operation XML response
	 *
	 * @return array Copy metadata
	 */
	protected function extract_copy_metadata( array $xml ): array {
		$result = $xml['CopyObjectResult'] ?? [];

		return [
			'etag'          => $this->extract_etag( $result['ETag'] ?? '' ),
			'last_modified' => $this->extract_text_value( $result['LastModified'] ?? '' )
		];
	}

}