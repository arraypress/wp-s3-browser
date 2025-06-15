<?php
/**
 * XML Parser Trait
 *
 * Provides high-level parsing functionality for S3 API responses.
 *
 * @package     ArrayPress\S3\Traits\XML
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\XML;

use ArrayPress\S3\Utils\Xml;

/**
 * Trait Parser
 */
trait Parser {

	/**
	 * Parse ListObjectsV2 XML response
	 *
	 * Extracts objects, prefixes, and pagination data from S3 ListObjectsV2 response.
	 * Handles both single and multiple objects/prefixes in the response.
	 *
	 * @param array $xml Parsed XML array from parse_response()
	 *
	 * @return array Array containing objects, prefixes, truncated status, and continuation token
	 */
	protected function parse_objects_list( array $xml ): array {
		$objects            = [];
		$prefixes           = [];
		$truncated          = false;
		$continuation_token = '';

		// Extract from ListObjectsV2Result format
		$result_path = $xml['ListObjectsV2Result'] ?? $xml;

		// Check for truncation
		if ( isset( $result_path['IsTruncated'] ) ) {
			$is_truncated = $this->extract_text_value( $result_path['IsTruncated'] );
			$truncated    = ( $is_truncated === 'true' || $is_truncated === '1' );
		}

		// Get continuation token if available
		if ( $truncated && isset( $result_path['NextContinuationToken'] ) ) {
			$continuation_token = $this->extract_text_value( $result_path['NextContinuationToken'] );
		}

		// Extract objects
		if ( isset( $result_path['Contents'] ) ) {
			$contents = $result_path['Contents'];

			// Single object case
			if ( isset( $contents['Key'] ) ) {
				$objects[] = $this->extract_object_data( $contents );
			} // Multiple objects case
			elseif ( is_array( $contents ) ) {
				foreach ( $contents as $object ) {
					if ( isset( $object['Key'] ) ) {
						$objects[] = $this->extract_object_data( $object );
					}
				}
			}
		}

		// Extract prefixes (folders)
		if ( isset( $result_path['CommonPrefixes'] ) ) {
			$common_prefixes = $result_path['CommonPrefixes'];

			// Single prefix case
			if ( isset( $common_prefixes['Prefix'] ) ) {
				$prefix_value = $this->extract_text_value( $common_prefixes['Prefix'] );
				if ( ! empty( $prefix_value ) ) {
					$prefixes[] = $prefix_value;
				}
			} // Multiple prefixes case
			elseif ( is_array( $common_prefixes ) ) {
				foreach ( $common_prefixes as $prefix_data ) {
					if ( isset( $prefix_data['Prefix'] ) ) {
						$prefix_value = $this->extract_text_value( $prefix_data['Prefix'] );
						if ( ! empty( $prefix_value ) ) {
							$prefixes[] = $prefix_value;
						}
					}
				}
			}
		}

		return [
			'objects'            => $objects,
			'prefixes'           => $prefixes,
			'truncated'          => $truncated,
			'continuation_token' => $continuation_token
		];
	}

	/**
	 * Parse copy object result XML
	 *
	 * Extracts ETag and LastModified from S3 copy operation response.
	 *
	 * @param array $xml Parsed XML array from parse_response()
	 *
	 * @return array Array containing etag and last_modified
	 */
	protected function parse_copy_result( array $xml ): array {
		$etag          = '';
		$last_modified = '';

		if ( isset( $xml['CopyObjectResult'] ) ) {
			$result = $xml['CopyObjectResult'];

			if ( isset( $result['ETag'] ) ) {
				$etag = trim( $this->extract_text_value( $result['ETag'] ), '"' );
			}

			if ( isset( $result['LastModified'] ) ) {
				$last_modified = $this->extract_text_value( $result['LastModified'] );
			}
		}

		return [
			'etag'          => $etag,
			'last_modified' => $last_modified
		];
	}

	/**
	 * Parse ListBuckets XML response
	 *
	 * Extracts bucket information, owner data, and truncation info from S3 ListBuckets response.
	 *
	 * @param array $xml Parsed XML array from parse_response()
	 *
	 * @return array Array containing buckets, owner, and truncation data
	 */
	protected function parse_buckets_list( array $xml ): array {
		$buckets     = [];
		$owner       = null;
		$truncated   = false;
		$next_marker = '';

		// Extract from ListAllMyBucketsResult format
		$result_path = $xml['ListAllMyBucketsResult'] ?? $xml;

		// Extract buckets using utility
		$buckets_data = Xml::find_value( $result_path, 'Bucket' );
		if ( $buckets_data !== null ) {
			// Single bucket case
			if ( isset( $buckets_data['Name'] ) ) {
				$buckets[] = $this->extract_bucket_data( $buckets_data );
			} // Multiple buckets case
			elseif ( is_array( $buckets_data ) ) {
				foreach ( $buckets_data as $bucket ) {
					if ( isset( $bucket['Name'] ) ) {
						$buckets[] = $this->extract_bucket_data( $bucket );
					}
				}
			}
		}

		// If no buckets found through common paths, search recursively
		if ( empty( $buckets ) ) {
			$buckets = $this->search_for_buckets_recursively( $xml );
		}

		// Extract owner information
		$owner_data = Xml::find_value( $xml, 'Owner' );
		if ( $owner_data !== null ) {
			$owner = [
				'ID'          => $this->extract_text_value( $owner_data['ID'] ?? '' ),
				'DisplayName' => $this->extract_text_value( $owner_data['DisplayName'] ?? '' )
			];
		}

		// Extract truncation info
		$is_truncated = Xml::find_value( $xml, 'IsTruncated' );
		if ( $is_truncated !== null ) {
			$truncated = ( $this->extract_text_value( $is_truncated ) === 'true' );
		}

		if ( $truncated ) {
			$marker = Xml::find_value( $xml, 'NextMarker' );
			if ( $marker !== null ) {
				$next_marker = $this->extract_text_value( $marker );
			}
		}

		return [
			'buckets'     => $buckets,
			'owner'       => $owner,
			'truncated'   => $truncated,
			'next_marker' => $next_marker
		];
	}

	/**
	 * Parse CORS configuration XML response
	 *
	 * Extracts CORS rules from S3 CORS configuration response.
	 * Handles both single and multiple CORS rules in the response.
	 *
	 * @param array $xml Parsed XML array from parse_response()
	 *
	 * @return array Array of CORS rules
	 */
	protected function parse_cors_configuration( array $xml ): array {
		$rules       = [];
		$cors_config = $xml['CORSConfiguration'] ?? $xml;

		if ( isset( $cors_config['CORSRule'] ) ) {
			$cors_rules = $cors_config['CORSRule'];

			// Handle single rule vs multiple rules
			if ( isset( $cors_rules['AllowedMethod'] ) || isset( $cors_rules['AllowedOrigin'] ) ) {
				$cors_rules = [ $cors_rules ];
			}

			foreach ( $cors_rules as $rule ) {
				$parsed_rule = [
					'ID'             => $this->extract_text_value( $rule['ID'] ?? '' ),
					'AllowedMethods' => $this->extract_array_values( $rule['AllowedMethod'] ?? [] ),
					'AllowedOrigins' => $this->extract_array_values( $rule['AllowedOrigin'] ?? [] ),
					'AllowedHeaders' => $this->extract_array_values( $rule['AllowedHeader'] ?? [] ),
					'ExposeHeaders'  => $this->extract_array_values( $rule['ExposeHeader'] ?? [] ),
					'MaxAgeSeconds'  => (int) $this->extract_text_value( $rule['MaxAgeSeconds'] ?? '0' )
				];

				// Remove empty values but keep MaxAgeSeconds if it's 0
				$rules[] = array_filter( $parsed_rule, function ( $value, $key ) {
					return ! empty( $value ) || ( $key === 'MaxAgeSeconds' && $value === 0 );
				}, ARRAY_FILTER_USE_BOTH );
			}
		}

		return $rules;
	}

	/**
	 * Parse batch delete response XML with enhanced error handling
	 *
	 * @param array $xml Parsed XML response from parse_response()
	 *
	 * @return array Results summary
	 */
	protected function parse_batch_delete_response( array $xml ): array {
		$deleted = [];
		$errors  = [];

		// Handle DeleteResult format - search for the root element
		$result_path = $xml['DeleteResult'] ?? $xml;

		// Parse deleted objects using XML utility
		$deleted_items = Xml::find_value( $result_path, 'Deleted' );
		if ( $deleted_items !== null ) {
			// Single deleted object
			if ( isset( $deleted_items['Key'] ) ) {
				$deleted[] = [
					'key'        => $this->extract_text_value( $deleted_items['Key'] ),
					'version_id' => $this->extract_text_value( $deleted_items['VersionId'] ?? null )
				];
			} // Multiple deleted objects (array format)
			elseif ( is_array( $deleted_items ) ) {
				foreach ( $deleted_items as $item ) {
					if ( isset( $item['Key'] ) ) {
						$deleted[] = [
							'key'        => $this->extract_text_value( $item['Key'] ),
							'version_id' => $this->extract_text_value( $item['VersionId'] ?? null )
						];
					}
				}
			}
		}

		// Parse error objects using XML utility
		$error_items = Xml::find_value( $result_path, 'Error' );
		if ( $error_items !== null ) {
			// Single error
			if ( isset( $error_items['Key'] ) ) {
				$errors[] = [
					'key'     => $this->extract_text_value( $error_items['Key'] ),
					'code'    => $this->extract_text_value( $error_items['Code'] ?? 'Unknown' ),
					'message' => $this->extract_text_value( $error_items['Message'] ?? 'Unknown error' )
				];
			} // Multiple errors (array format)
			elseif ( is_array( $error_items ) ) {
				foreach ( $error_items as $item ) {
					if ( isset( $item['Key'] ) ) {
						$errors[] = [
							'key'     => $this->extract_text_value( $item['Key'] ),
							'code'    => $this->extract_text_value( $item['Code'] ?? 'Unknown' ),
							'message' => $this->extract_text_value( $item['Message'] ?? 'Unknown error' )
						];
					}
				}
			}
		}

		return [
			'success_count' => count( $deleted ),
			'error_count'   => count( $errors ),
			'deleted'       => $deleted,
			'errors'        => $errors
		];
	}

	/**
	 * Search recursively for buckets in the XML structure
	 *
	 * Fallback method for providers with non-standard XML structures.
	 *
	 * @param array $data XML data to search
	 *
	 * @return array Found buckets
	 */
	protected function search_for_buckets_recursively( array $data ): array {
		$buckets = [];

		// Look for patterns that might represent buckets
		foreach ( $data as $value ) {
			// If we find something that looks like a bucket
			if ( is_array( $value ) && isset( $value['Name'] ) && isset( $value['CreationDate'] ) ) {
				$buckets[] = $this->extract_bucket_data( $value );
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

}