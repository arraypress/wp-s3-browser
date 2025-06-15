<?php
/**
 * XML Extract Trait
 *
 * Provides data extraction functionality from XML nodes.
 *
 * @package     ArrayPress\S3\Traits\XML
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\XML;

use ArrayPress\S3\Utils\File;
use ArrayPress\S3\Utils\Xml;

/**
 * Trait Extract
 */
trait Extract {

	/**
	 * Extract text value from XML node (handles S3-specific structure)
	 *
	 * @param mixed $node XML node data
	 *
	 * @return string Extracted text value
	 */
	protected function extract_text_value( $node ): string {
		if ( is_array( $node ) ) {
			return (string) ( $node['value'] ?? $node['@text'] ?? '' );
		}

		return (string) $node;
	}

	/**
	 * Extract array values from XML structure
	 *
	 * Handles the conversion of XML node data to arrays, supporting both
	 * single values and multiple values in various XML structures.
	 *
	 * @param mixed $data XML data to extract values from
	 *
	 * @return array Array of extracted values
	 */
	protected function extract_array_values( $data ): array {
		if ( empty( $data ) ) {
			return [];
		}

		if ( is_array( $data ) ) {
			// Check if it's a single value wrapped in array structure
			if ( isset( $data['value'] ) ) {
				return [ $data['value'] ];
			}

			// Check if it's array with @text key
			if ( isset( $data['@text'] ) ) {
				return [ $data['@text'] ];
			}

			// Multiple values - extract text from each
			$values = [];
			foreach ( $data as $item ) {
				$extracted = $this->extract_text_value( $item );
				if ( ! empty( $extracted ) ) {
					$values[] = $extracted;
				}
			}

			return $values;
		}

		// Single value
		$extracted = $this->extract_text_value( $data );

		return ! empty( $extracted ) ? [ $extracted ] : [];
	}

	/**
	 * Extract and clean ETag from XML node
	 *
	 * Removes surrounding quotes from ETag values as returned by S3.
	 *
	 * @param mixed $etag_node XML ETag node
	 *
	 * @return string Clean ETag value
	 */
	protected function extract_clean_etag( $etag_node ): string {
		$etag = $this->extract_text_value( $etag_node );

		return trim( $etag, '"' );
	}

	/**
	 * Extract object data from XML node with full formatting
	 *
	 * @param array $object_node XML object node
	 *
	 * @return array Formatted object data with additional metadata
	 */
	protected function extract_object_data( array $object_node ): array {
		$key_value     = $this->extract_text_value( $object_node['Key'] ?? '' );
		$last_modified = $this->extract_text_value( $object_node['LastModified'] ?? '' );
		$etag          = $this->extract_clean_etag( $object_node['ETag'] ?? '' );
		$size          = (int) $this->extract_text_value( $object_node['Size'] ?? '0' );
		$storage_class = $this->extract_text_value( $object_node['StorageClass'] ?? 'STANDARD' );

		if ( empty( $key_value ) ) {
			return [];
		}

		// Get filename from key
		$filename = File::name( $key_value );

		return [
			'Key'           => $key_value,
			'Filename'      => $filename,
			'LastModified'  => $last_modified,
			'ETag'          => $etag,
			'Size'          => $size,
			'StorageClass'  => $storage_class,
			'FormattedSize' => size_format( $size ),
			'Category'      => File::category( $filename ),
			'MimeType'      => File::mime_type( $filename )
		];
	}

	/**
	 * Extract bucket data from XML node
	 *
	 * @param array $bucket_node XML bucket node
	 *
	 * @return array Formatted bucket data
	 */
	protected function extract_bucket_data( array $bucket_node ): array {
		return [
			'Name'         => $this->extract_text_value( $bucket_node['Name'] ?? '' ),
			'CreationDate' => $this->extract_text_value( $bucket_node['CreationDate'] ?? '' ),
		];
	}

	/**
	 * Extract bucket location from XML response
	 *
	 * @param array $xml Parsed XML array
	 *
	 * @return string Bucket location/region
	 */
	protected function extract_bucket_location( array $xml ): string {
		// Check for LocationConstraint element
		$location = Xml::find_value( $xml, 'LocationConstraint' );
		if ( $location !== null ) {
			$location_text = $this->extract_text_value( $location );

			// Empty location constraint typically means us-east-1
			return ! empty( $location_text ) ? $location_text : 'us-east-1';
		}

		// Fallback to provider default region
		return $this->provider->get_region();
	}

	/**
	 * Extract bucket versioning information from XML response
	 *
	 * @param array $xml Parsed XML array
	 *
	 * @return array Versioning information
	 */
	protected function extract_bucket_versioning( array $xml ): array {
		$versioning_info = [
			'enabled' => false,
			'status'  => 'Disabled'
		];

		// Look for VersioningConfiguration
		$versioning_config = $xml['VersioningConfiguration'] ?? $xml;

		// Check Status element
		$status = Xml::find_value( $versioning_config, 'Status' );
		if ( $status !== null ) {
			$status_text                = $this->extract_text_value( $status );
			$versioning_info['status']  = $status_text;
			$versioning_info['enabled'] = ( $status_text === 'Enabled' );
		}

		return $versioning_info;
	}

	/**
	 * Extract bucket lifecycle configuration from XML response
	 *
	 * @param array $xml Parsed XML array
	 *
	 * @return array Lifecycle information
	 */
	protected function extract_bucket_lifecycle( array $xml ): array {
		$lifecycle_info = [
			'has_lifecycle' => false,
			'rules_count'   => 0,
			'rules'         => []
		];

		// Look for LifecycleConfiguration
		$lifecycle_config = $xml['LifecycleConfiguration'] ?? $xml;

		// Extract rules
		$rules = Xml::find_value( $lifecycle_config, 'Rule' );
		if ( $rules !== null ) {
			$lifecycle_info['has_lifecycle'] = true;

			// Handle single rule vs multiple rules
			if ( isset( $rules['ID'] ) || isset( $rules['Status'] ) ) {
				// Single rule
				$lifecycle_info['rules'][] = $this->extract_lifecycle_rule( $rules );
			} elseif ( is_array( $rules ) ) {
				// Multiple rules
				foreach ( $rules as $rule ) {
					if ( is_array( $rule ) ) {
						$lifecycle_info['rules'][] = $this->extract_lifecycle_rule( $rule );
					}
				}
			}

			$lifecycle_info['rules_count'] = count( $lifecycle_info['rules'] );
		}

		return $lifecycle_info;
	}

	/**
	 * Extract individual lifecycle rule from XML
	 *
	 * @param array $rule_data Rule XML data
	 *
	 * @return array Formatted rule data
	 */
	protected function extract_lifecycle_rule( array $rule_data ): array {
		return [
			'id'          => $this->extract_text_value( $rule_data['ID'] ?? '' ),
			'status'      => $this->extract_text_value( $rule_data['Status'] ?? 'Disabled' ),
			'filter'      => $this->extract_lifecycle_filter( $rule_data['Filter'] ?? [] ),
			'transitions' => $this->extract_lifecycle_transitions( $rule_data['Transition'] ?? [] ),
			'expiration'  => $this->extract_lifecycle_expiration( $rule_data['Expiration'] ?? [] )
		];
	}

	/**
	 * Extract lifecycle rule filter
	 *
	 * @param array $filter_data Filter XML data
	 *
	 * @return array Filter information
	 */
	protected function extract_lifecycle_filter( array $filter_data ): array {
		$filter = [];

		if ( isset( $filter_data['Prefix'] ) ) {
			$filter['prefix'] = $this->extract_text_value( $filter_data['Prefix'] );
		}

		if ( isset( $filter_data['Tag'] ) ) {
			$tag_data      = $filter_data['Tag'];
			$filter['tag'] = [
				'key'   => $this->extract_text_value( $tag_data['Key'] ?? '' ),
				'value' => $this->extract_text_value( $tag_data['Value'] ?? '' )
			];
		}

		return $filter;
	}

	/**
	 * Extract lifecycle transitions
	 *
	 * @param array $transitions_data Transitions XML data
	 *
	 * @return array Transitions information
	 */
	protected function extract_lifecycle_transitions( array $transitions_data ): array {
		$transitions = [];

		// Handle single vs multiple transitions
		if ( isset( $transitions_data['StorageClass'] ) ) {
			$transitions[] = $this->extract_single_transition( $transitions_data );
		} elseif ( is_array( $transitions_data ) ) {
			foreach ( $transitions_data as $transition ) {
				if ( is_array( $transition ) && isset( $transition['StorageClass'] ) ) {
					$transitions[] = $this->extract_single_transition( $transition );
				}
			}
		}

		return $transitions;
	}

	/**
	 * Extract single lifecycle transition
	 *
	 * @param array $transition_data Transition XML data
	 *
	 * @return array Transition information
	 */
	protected function extract_single_transition( array $transition_data ): array {
		return [
			'days'          => (int) $this->extract_text_value( $transition_data['Days'] ?? '0' ),
			'date'          => $this->extract_text_value( $transition_data['Date'] ?? '' ),
			'storage_class' => $this->extract_text_value( $transition_data['StorageClass'] ?? '' )
		];
	}

	/**
	 * Extract lifecycle expiration
	 *
	 * @param array $expiration_data Expiration XML data
	 *
	 * @return array Expiration information
	 */
	protected function extract_lifecycle_expiration( array $expiration_data ): array {
		$expiration = [];

		if ( isset( $expiration_data['Days'] ) ) {
			$expiration['days'] = (int) $this->extract_text_value( $expiration_data['Days'] );
		}

		if ( isset( $expiration_data['Date'] ) ) {
			$expiration['date'] = $this->extract_text_value( $expiration_data['Date'] );
		}

		if ( isset( $expiration_data['ExpiredObjectDeleteMarker'] ) ) {
			$expiration['expired_object_delete_marker'] =
				$this->extract_text_value( $expiration_data['ExpiredObjectDeleteMarker'] ) === 'true';
		}

		return $expiration;
	}

}