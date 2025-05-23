<?php
/**
 * Bucket Operations Trait
 *
 * Handles bucket-related operations for S3-compatible storage.
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
use ArrayPress\S3\Responses\BucketsResponse;
use ArrayPress\S3\Responses\ErrorResponse;
use ArrayPress\S3\Utils\Xml;

/**
 * Trait BucketOperations
 */
trait Extraction {

	/**
	 * Extract owner information from XML structure
	 *
	 * @param array $xml Parsed XML structure
	 *
	 * @return array|null Owner information or null if not found
	 */
	private function extract_owner_from_xml( array $xml ): ?array {
		// Common paths for owner info
		$possible_paths = [
			'Owner',
			'ListAllMyBucketsResult.Owner'
		];

		foreach ( $possible_paths as $path ) {
			$owner_data = Xml::get_value( $xml, $path );
			if ( $owner_data ) {
				return [
					'ID'          => Xml::get_value( $owner_data, 'ID.value' ) ?? '',
					'DisplayName' => Xml::get_value( $owner_data, 'DisplayName.value' ) ?? ''
				];
			}
		}

		return null;
	}

	/**
	 * Extract buckets from XML structure
	 *
	 * @param array $xml Parsed XML structure
	 *
	 * @return array Array of buckets
	 */
	private function extract_buckets_from_xml( array $xml ): array {
		// Common paths for buckets
		$possible_bucket_paths = [
			'Buckets.Bucket',
			'ListAllMyBucketsResult.Buckets.Bucket'
		];

		foreach ( $possible_bucket_paths as $path ) {
			$buckets_data = Xml::get_value( $xml, $path );
			if ( $buckets_data ) {
				return $this->format_buckets_data( $buckets_data );
			}
		}

		// If no buckets found through common paths, search recursively
		return $this->search_for_buckets_recursively( $xml );
	}

	/**
	 * Format buckets data into standard format
	 *
	 * @param array $buckets_data Raw buckets data from XML
	 *
	 * @return array Formatted buckets
	 */
	private function format_buckets_data( $buckets_data ): array {
		$formatted = [];

		// Single bucket case
		if ( isset( $buckets_data['Name'] ) ) {
			$formatted[] = [
				'Name'         => $buckets_data['Name']['value'] ?? '',
				'CreationDate' => $buckets_data['CreationDate']['value'] ?? ''
			];
		} // Multiple buckets case
		elseif ( is_array( $buckets_data ) ) {
			foreach ( $buckets_data as $bucket ) {
				if ( isset( $bucket['Name'] ) ) {
					$formatted[] = [
						'Name'         => $bucket['Name']['value'] ?? '',
						'CreationDate' => $bucket['CreationDate']['value'] ?? ''
					];
				}
			}
		}

		return $formatted;
	}

	/**
	 * Search recursively for buckets in the XML structure
	 *
	 * @param array $data XML data to search
	 *
	 * @return array Found buckets
	 */
	private function search_for_buckets_recursively( array $data ): array {
		$buckets = [];

		// Look for patterns that might represent buckets
		foreach ( $data as $key => $value ) {
			// If we find something that looks like a bucket
			if ( is_array( $value ) && isset( $value['Name'] ) && isset( $value['CreationDate'] ) ) {
				$buckets[] = [
					'Name'         => $value['Name']['value'] ?? '',
					'CreationDate' => $value['CreationDate']['value'] ?? ''
				];
			} // Recursively search deeper
			elseif ( is_array( $value ) ) {
				$found_buckets = $this->search_for_buckets_recursively( $value );
				if ( ! empty( $found_buckets ) ) {
					$buckets = array_merge( $buckets, $found_buckets );
				}
			}
		}

		return $buckets;
	}

	/**
	 * Extract truncation info from XML structure
	 *
	 * @param array $xml Parsed XML structure
	 *
	 * @return array Truncation info
	 */
	private function extract_truncation_info_from_xml( array $xml ): array {
		$result = [
			'truncated'   => false,
			'next_marker' => ''
		];

		// Common paths for truncation info
		$possible_truncated_paths = [
			'IsTruncated',
			'ListAllMyBucketsResult.IsTruncated'
		];

		// Check for truncation
		foreach ( $possible_truncated_paths as $path ) {
			$is_truncated = Xml::get_value( $xml, $path );
			if ( $is_truncated ) {
				$result['truncated'] = ( $is_truncated === 'true' || $is_truncated === true );
				break;
			}
		}

		// If truncated, look for next marker
		if ( $result['truncated'] ) {
			$possible_marker_paths = [
				'NextMarker.value',
				'ListAllMyBucketsResult.NextMarker.value'
			];

			foreach ( $possible_marker_paths as $path ) {
				$marker = Xml::get_value( $xml, $path );
				if ( $marker ) {
					$result['next_marker'] = $marker;
					break;
				}
			}
		}

		return $result;
	}

}