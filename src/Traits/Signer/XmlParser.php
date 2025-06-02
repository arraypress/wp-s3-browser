<?php
/**
 * XML Parser Trait - Enhanced with List Parsing
 *
 * Provides S3-specific XML parsing functionality including specialized
 * parsers for common S3 operations like list objects and copy results.
 *
 * @package     ArrayPress\S3\Traits\Signer
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Signer;

use ArrayPress\S3\Utils\Xml;
use ArrayPress\S3\Responses\ErrorResponse;
use SimpleXMLElement;

/**
 * Trait XmlParser
 */
trait XmlParser {

	/**
	 * Parse XML response
	 *
	 * @param string $xml_string XML string to parse
	 * @param bool   $log_errors Whether to log parsing errors
	 *
	 * @return array|ErrorResponse Parsed array or ErrorResponse on failure
	 */
	protected function parse_xml_response( string $xml_string, bool $log_errors = true ) {
		if ( empty( $xml_string ) ) {
			return new ErrorResponse( 'Empty response received', 'empty_response', 400 );
		}

		// Store previous error handling state
		$previous_state = libxml_use_internal_errors( true );

		// Attempt to load the XML string
		$xml = simplexml_load_string( $xml_string );

		// Check for parsing errors
		if ( $xml === false ) {
			$errors = libxml_get_errors();
			libxml_clear_errors();

			// Restore previous error handling state
			libxml_use_internal_errors( $previous_state );

			// Log errors if requested
			if ( $log_errors && ! empty( $errors ) ) {
				$this->debug_log_xml_errors( $errors, $xml_string );
			}

			// Return ErrorResponse with details
			return new ErrorResponse(
				! empty( $errors ) ? trim( $errors[0]->message ) : 'Failed to parse XML response',
				'xml_parse_error',
				400,
				[
					'errors'       => $errors,
					'xml_fragment' => mb_substr( $xml_string, 0, 200 ) . ( mb_strlen( $xml_string ) > 200 ? '...' : '' )
				]
			);
		}

		// Restore previous error handling state
		libxml_use_internal_errors( $previous_state );

		// Convert SimpleXML to array
		return $this->xml_to_array( $xml );
	}

	/**
	 * Parse ListObjectsV2 XML response
	 *
	 * Extracts objects, prefixes, and pagination data from S3 ListObjectsV2 response.
	 * Handles both single and multiple objects/prefixes in the response.
	 *
	 * @param array $xml Parsed XML array from parse_xml_response()
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
			}
			// Multiple objects case
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
			}
			// Multiple prefixes case
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
	 * @param array $xml Parsed XML array from parse_xml_response()
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
	 * @param array $xml Parsed XML array from parse_xml_response()
	 *
	 * @return array Array containing buckets, owner, and truncation data
	 */
	protected function parse_buckets_list( array $xml ): array {
		$buckets = [];
		$owner = null;
		$truncated = false;
		$next_marker = '';

		// Extract from ListAllMyBucketsResult format
		$result_path = $xml['ListAllMyBucketsResult'] ?? $xml;

		// Extract buckets using utility
		$buckets_data = Xml::find_value( $result_path, 'Bucket' );
		if ( $buckets_data !== null ) {
			// Single bucket case
			if ( isset( $buckets_data['Name'] ) ) {
				$buckets[] = $this->extract_bucket_data( $buckets_data );
			}
			// Multiple buckets case
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
		foreach ( $data as $key => $value ) {
			// If we find something that looks like a bucket
			if ( is_array( $value ) && isset( $value['Name'] ) && isset( $value['CreationDate'] ) ) {
				$buckets[] = $this->extract_bucket_data( $value );
			}
			// Recursively search deeper
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
	 * Extract object data from XML node
	 *
	 * @param array $object_node XML object node
	 *
	 * @return array Formatted object data
	 */
	protected function extract_object_data( array $object_node ): array {
		return [
			'Key'          => $this->extract_text_value( $object_node['Key'] ?? '' ),
			'LastModified' => $this->extract_text_value( $object_node['LastModified'] ?? '' ),
			'ETag'         => trim( $this->extract_text_value( $object_node['ETag'] ?? '' ), '"' ),
			'Size'         => (int) $this->extract_text_value( $object_node['Size'] ?? '0' ),
			'StorageClass' => $this->extract_text_value( $object_node['StorageClass'] ?? 'STANDARD' ),
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
	 * Build XML for batch delete request with R2 compatibility
	 *
	 * @param array $object_keys Array of object keys
	 *
	 * @return string XML string
	 */
	protected function build_batch_delete_xml( array $object_keys ): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

		// Try without namespace first for R2 compatibility
		$xml .= '<Delete>' . "\n";
		$xml .= '  <Quiet>false</Quiet>' . "\n";

		foreach ( $object_keys as $key ) {
			// Ensure the key is properly handled but not double-encoded
			$clean_key = rawurldecode( $key ); // Decode first to avoid double encoding
			$xml       .= '  <Object>' . "\n";
			$xml       .= '    <Key>' . htmlspecialchars( $clean_key, ENT_XML1 | ENT_COMPAT, 'UTF-8' ) . '</Key>' . "\n";
			$xml       .= '  </Object>' . "\n";
		}

		$xml .= '</Delete>';

		return $xml;
	}

	/**
	 * Parse batch delete response XML with enhanced error handling
	 *
	 * @param array $xml Parsed XML response from parse_xml_response()
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
	 * Convert SimpleXML object to array
	 *
	 * @param SimpleXMLElement $xml       SimpleXML object
	 * @param int              $depth     Current recursion depth
	 * @param int              $max_depth Maximum recursion depth
	 *
	 * @return array Converted array
	 */
	protected function xml_to_array( SimpleXMLElement $xml, int $depth = 0, int $max_depth = 100 ): array {
		// Prevent infinite recursion
		if ( $depth >= $max_depth ) {
			$this->debug( 'Maximum XML recursion depth reached', [
				'max_depth' => $max_depth,
				'node_name' => $xml->getName()
			] );

			return [ 'value' => 'ERROR: Maximum recursion depth reached' ];
		}

		$result = [];

		// If this node has attributes, add them to the result
		foreach ( $xml->attributes() as $key => $value ) {
			$result['@attributes'][ $key ] = (string) $value;
		}

		// If this node is just a value, return it directly
		if ( count( $xml->children() ) === 0 ) {
			$text = (string) $xml;
			if ( strlen( $text ) === 0 ) {
				// If it's empty and has no attributes, return an empty array
				if ( ! isset( $result['@attributes'] ) ) {
					return [];
				}

				// Otherwise, just return the attributes
				return $result;
			}

			// If we have attributes and text, add the text as @text
			if ( isset( $result['@attributes'] ) ) {
				$result['@text'] = $text;

				return $result;
			}

			// Just a simple text node - return as value
			$result['value'] = $text;

			return $result;
		}

		// Process child nodes
		foreach ( $xml->children() as $child_key => $child ) {
			// Convert the child to array recursively
			$child_array = $this->xml_to_array( $child, $depth + 1, $max_depth );

			// Add to result based on whether this key already exists
			if ( isset( $result[ $child_key ] ) ) {
				// If it's already an array of arrays, add another entry
				if ( is_array( $result[ $child_key ] ) && isset( $result[ $child_key ][0] ) ) {
					$result[ $child_key ][] = $child_array;
				} else {
					// Convert to array of arrays
					$result[ $child_key ] = [ $result[ $child_key ], $child_array ];
				}
			} else {
				// First occurrence of this key
				$result[ $child_key ] = $child_array;
			}
		}

		// Handle namespaces if they exist
		$this->process_xml_namespaces( $xml, $result, $depth, $max_depth );

		return $result;
	}

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
	 * Check if XML array represents truncated results (S3-specific)
	 *
	 * @param array $xml XML array
	 *
	 * @return bool True if truncated
	 */
	protected function is_truncated_response( array $xml ): bool {
		$truncated_value = Xml::find_value( $xml, 'IsTruncated' );
		if ( $truncated_value !== null ) {
			$text_value = $this->extract_text_value( $truncated_value );

			return $text_value === 'true' || $text_value === '1';
		}

		return false;
	}

	/**
	 * Get next continuation token from XML (S3-specific)
	 *
	 * @param array $xml XML array
	 *
	 * @return string|null Next continuation token or null
	 */
	protected function get_continuation_token( array $xml ): ?string {
		$token = Xml::find_value( $xml, 'NextContinuationToken' );
		if ( $token !== null ) {
			return $this->extract_text_value( $token );
		}

		return null;
	}

	/**
	 * Get next marker from XML (S3-specific)
	 *
	 * @param array $xml XML array
	 *
	 * @return string|null Next marker or null
	 */
	protected function get_next_marker( array $xml ): ?string {
		$marker = Xml::find_value( $xml, 'NextMarker' );
		if ( $marker !== null ) {
			return $this->extract_text_value( $marker );
		}

		return null;
	}

	/**
	 * Get a value from a dot-notation path in an array (uses utility)
	 *
	 * @param array  $array The array to search
	 * @param string $path  Dot notation path (e.g. "Buckets.Bucket")
	 *
	 * @return mixed|null The value or null if not found
	 */
	protected function get_value_from_path( array $array, string $path ) {
		return Xml::get_value( $array, $path );
	}

	/**
	 * Process XML namespaces
	 *
	 * @param SimpleXMLElement $xml       SimpleXML object
	 * @param array            $result    Result array to modify
	 * @param int              $depth     Current recursion depth
	 * @param int              $max_depth Maximum recursion depth
	 */
	protected function process_xml_namespaces( SimpleXMLElement $xml, array &$result, int $depth, int $max_depth ): void {
		foreach ( $xml->getNamespaces( true ) as $prefix => $ns ) {
			if ( $prefix === '' ) {
				$prefix = 'ns';
			}

			foreach ( $xml->children( $ns ) as $child_key => $child ) {
				$child_key   = $prefix . ':' . $child_key;
				$child_array = $this->xml_to_array( $child, $depth + 1, $max_depth );

				if ( isset( $result[ $child_key ] ) ) {
					if ( is_array( $result[ $child_key ] ) && isset( $result[ $child_key ][0] ) ) {
						$result[ $child_key ][] = $child_array;
					} else {
						$result[ $child_key ] = [ $result[ $child_key ], $child_array ];
					}
				} else {
					$result[ $child_key ] = $child_array;
				}
			}
		}
	}

	/**
	 * Debug log XML parsing errors
	 *
	 * @param array  $errors     Array of LibXMLError objects
	 * @param string $xml_string The XML string that failed to parse
	 */
	protected function debug_log_xml_errors( array $errors, string $xml_string ): void {
		$error_messages = [];

		foreach ( $errors as $error ) {
			$error_type    = $this->get_xml_error_type( $error->level );
			$error_line    = $error->line;
			$error_column  = $error->column;
			$error_message = trim( $error->message );

			$error_messages[] = sprintf(
				"%s (Line %d, Column %d): %s",
				$error_type,
				$error_line,
				$error_column,
				$error_message
			);

			// Try to extract the problematic XML fragment
			if ( $error_line > 0 ) {
				$lines      = explode( "\n", $xml_string );
				$line_index = $error_line - 1;

				if ( isset( $lines[ $line_index ] ) ) {
					$context_start = max( 0, $line_index - 2 );
					$context_end   = min( count( $lines ) - 1, $line_index + 2 );
					$context       = [];

					for ( $i = $context_start; $i <= $context_end; $i ++ ) {
						$line_number = $i + 1;
						$prefix      = ( $i === $line_index ) ? ">> " : "   ";
						$context[]   = sprintf( "%s%d: %s", $prefix, $line_number, $lines[ $i ] );
					}

					$error_messages[] = "XML Context:\n" . implode( "\n", $context );
				}
			}
		}

		// Log the combined error information
		$this->debug( 'XML Parsing Errors', implode( "\n", $error_messages ) );
	}

	/**
	 * Get XML error type string
	 *
	 * @param int $level LibXML error level
	 *
	 * @return string Error type description
	 */
	private function get_xml_error_type( int $level ): string {
		switch ( $level ) {
			case LIBXML_ERR_WARNING:
				return 'Warning';
			case LIBXML_ERR_ERROR:
				return 'Error';
			case LIBXML_ERR_FATAL:
				return 'Fatal Error';
			default:
				return 'Unknown';
		}
	}

}