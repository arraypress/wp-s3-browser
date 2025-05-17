<?php
/**
 * Response Formatter Trait
 *
 * Provides methods for formatting S3 API responses.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      ArrayPress Team
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits;

use ArrayPress\S3\Utils;

/**
 * Trait ResponseFormatterTrait
 */
trait ResponseFormatter {

	/**
	 * Format buckets response
	 *
	 * @param array $xml         Parsed XML response
	 * @param int   $status_code HTTP status code
	 *
	 * @return array Formatted response
	 */
	protected function format_buckets_response( array $xml, int $status_code ): array {
		$formatted = [
			'success'     => $status_code >= 200 && $status_code < 300,
			'status_code' => $status_code,
			'buckets'     => [],
			'owner'       => null,
			'truncated'   => false,
			'next_marker' => '',
			'count'       => 0
		];

		if ( ! $formatted['success'] ) {
			return $formatted;
		}

		$data = $xml;

		// Log data structure if debugging
		if ( $this->is_debug_enabled() ) {
			$this->log_debug( 'Bucket data structure:', $data );
		}

		// Extract owner information - checking multiple paths
		if ( isset( $data['ListAllMyBucketsResult']['Owner'] ) ) {
			$owner              = $data['ListAllMyBucketsResult']['Owner'];
			$formatted['owner'] = [
				'ID'          => $owner['ID']['value'] ?? '',
				'DisplayName' => $owner['DisplayName']['value'] ?? ''
			];
		} elseif ( isset( $data['Owner'] ) ) {
			$owner              = $data['Owner'];
			$formatted['owner'] = [
				'ID'          => $owner['ID']['value'] ?? '',
				'DisplayName' => $owner['DisplayName']['value'] ?? ''
			];
		}

		// Check all possible paths for bucket information
		$buckets_found = false;

		// Path 1: Standard S3 XML format
		if ( isset( $data['ListAllMyBucketsResult']['Buckets']['Bucket'] ) ) {
			$buckets       = $data['ListAllMyBucketsResult']['Buckets']['Bucket'];
			$buckets_found = true;
		} // Path 2: Direct Buckets structure
		elseif ( isset( $data['Buckets']['Bucket'] ) ) {
			$buckets       = $data['Buckets']['Bucket'];
			$buckets_found = true;
		}

		// Process buckets if found
		if ( $buckets_found ) {
			// Single bucket case
			if ( isset( $buckets['Name'] ) ) {
				$formatted['buckets'][] = [
					'Name'         => $buckets['Name']['value'] ?? '',
					'CreationDate' => $buckets['CreationDate']['value'] ?? ''
				];
			} // Multiple buckets case
			elseif ( is_array( $buckets ) ) {
				foreach ( $buckets as $bucket ) {
					$formatted['buckets'][] = [
						'Name'         => $bucket['Name']['value'] ?? '',
						'CreationDate' => $bucket['CreationDate']['value'] ?? ''
					];
				}
			}
		}

		// Set counts and pagination info
		$formatted['count'] = count( $formatted['buckets'] );

		// Check if the result is truncated
		if ( isset( $data['ListAllMyBucketsResult']['IsTruncated'] ) ) {
			$is_truncated           = $data['ListAllMyBucketsResult']['IsTruncated'];
			$formatted['truncated'] = ( $is_truncated === 'true' || $is_truncated === true );

			if ( $formatted['truncated'] && isset( $data['ListAllMyBucketsResult']['NextMarker'] ) ) {
				$formatted['next_marker'] = $data['ListAllMyBucketsResult']['NextMarker']['value'] ?? '';
			}
		} elseif ( isset( $data['IsTruncated'] ) ) {
			$is_truncated           = $data['IsTruncated'];
			$formatted['truncated'] = ( $is_truncated === 'true' || $is_truncated === true );

			if ( $formatted['truncated'] && isset( $data['NextMarker'] ) ) {
				$formatted['next_marker'] = $data['NextMarker']['value'] ?? '';
			}
		}

		return $formatted;
	}

	/**
	 * Format objects response
	 *
	 * @param array $xml         Parsed XML response
	 * @param int   $status_code HTTP status code
	 *
	 * @return array Formatted response
	 */
	protected function format_objects_response( array $xml, int $status_code ): array {
		$formatted = [
			'success'            => $status_code >= 200 && $status_code < 300,
			'status_code'        => $status_code,
			'objects'            => [],
			'prefixes'           => [],
			'truncated'          => false,
			'continuation_token' => '',
			'count'              => 0
		];

		if ( ! $formatted['success'] ) {
			return $formatted;
		}

		$data = $xml;

		// Log data structure if debugging
		if ( $this->is_debug_enabled() ) {
			$this->log_debug( 'Objects data structure:', $data );
		}

		// Check for truncation flag directly in the data or in ListBucketResult
		if ( isset( $data['IsTruncated'] ) ) {
			$is_truncated           = $data['IsTruncated'];
			$formatted['truncated'] = ( $is_truncated === 'true' || $is_truncated === true );
		} elseif ( isset( $data['ListBucketResult']['IsTruncated'] ) ) {
			$is_truncated           = $data['ListBucketResult']['IsTruncated'];
			$formatted['truncated'] = ( $is_truncated === 'true' || $is_truncated === true );
		} elseif ( isset( $data['ListObjectsV2Result']['IsTruncated'] ) ) {
			$is_truncated           = $data['ListObjectsV2Result']['IsTruncated'];
			$formatted['truncated'] = ( $is_truncated === 'true' || $is_truncated === true );
		}

		// Get continuation token if available
		if ( $formatted['truncated'] ) {
			if ( isset( $data['NextContinuationToken'] ) ) {
				$formatted['continuation_token'] = $data['NextContinuationToken']['value'] ?? '';
			} elseif ( isset( $data['ListBucketResult']['NextContinuationToken'] ) ) {
				$formatted['continuation_token'] = $data['ListBucketResult']['NextContinuationToken']['value'] ?? '';
			} elseif ( isset( $data['ListObjectsV2Result']['NextContinuationToken'] ) ) {
				$formatted['continuation_token'] = $data['ListObjectsV2Result']['NextContinuationToken']['value'] ?? '';
			}
		}

		// Look for objects in different possible locations
		$objects_found = false;
		$objects       = null;

		// Check standard S3 format
		if ( isset( $data['ListBucketResult']['Contents'] ) ) {
			$objects       = $data['ListBucketResult']['Contents'];
			$objects_found = true;
		} elseif ( isset( $data['ListObjectsV2Result']['Contents'] ) ) {
			$objects       = $data['ListObjectsV2Result']['Contents'];
			$objects_found = true;
		} elseif ( isset( $data['Contents'] ) ) {
			$objects       = $data['Contents'];
			$objects_found = true;
		}

		// Process objects if found
		if ( $objects_found && $objects !== null ) {
			// Single object case
			if ( isset( $objects['Key'] ) ) {
				$this->add_formatted_object( $formatted['objects'], $objects );
			} // Multiple objects case
			elseif ( is_array( $objects ) ) {
				foreach ( $objects as $object ) {
					$this->add_formatted_object( $formatted['objects'], $object );
				}
			}
		}

		// Look for prefixes (folders) in different possible locations
		$prefixes_found = false;
		$prefixes       = null;

		// Check standard S3 format
		if ( isset( $data['ListBucketResult']['CommonPrefixes'] ) ) {
			$prefixes       = $data['ListBucketResult']['CommonPrefixes'];
			$prefixes_found = true;
		} elseif ( isset( $data['ListObjectsV2Result']['CommonPrefixes'] ) ) {
			$prefixes       = $data['ListObjectsV2Result']['CommonPrefixes'];
			$prefixes_found = true;
		} elseif ( isset( $data['CommonPrefixes'] ) ) {
			$prefixes       = $data['CommonPrefixes'];
			$prefixes_found = true;
		}

		// Process prefixes if found
		if ( $prefixes_found && $prefixes !== null ) {
			// Single prefix case
			if ( isset( $prefixes['Prefix'] ) ) {
				$prefix_value = $prefixes['Prefix']['value'] ?? '';
				if ( ! empty( $prefix_value ) ) {
					$formatted['prefixes'][] = $prefix_value;
				}
			} // Multiple prefixes case
			elseif ( is_array( $prefixes ) ) {
				foreach ( $prefixes as $prefix ) {
					if ( isset( $prefix['Prefix']['value'] ) ) {
						$formatted['prefixes'][] = $prefix['Prefix']['value'];
					} elseif ( isset( $prefix['Prefix'] ) ) {
						$formatted['prefixes'][] = $prefix['Prefix'];
					}
				}
			}
		}

		// Set count
		$formatted['count'] = count( $formatted['objects'] ) + count( $formatted['prefixes'] );

		return $formatted;
	}

	/**
	 * Add a formatted object to the objects array
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
		$filename = Utils::get_filename( $key_value );

		// Add formatted object
		$objects[] = [
			'Key'           => $key_value,
			'Filename'      => $filename,
			'LastModified'  => $last_modified,
			'ETag'          => $etag,
			'Size'          => $size,
			'StorageClass'  => $storage_class,
			'FormattedSize' => Utils::format_size( $size ),
			'Type'          => Utils::get_file_type( $filename ),
			'MimeType'      => Utils::get_mime_type( $filename )
		];
	}

}