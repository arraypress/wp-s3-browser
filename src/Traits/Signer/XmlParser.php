<?php
/**
 * XML Parser Trait
 *
 * Provides S3-specific XML parsing functionality.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Signer;

use ArrayPress\S3\Utils\Xml;
use WP_Error;

/**
 * Trait XmlParser
 */
trait XmlParser {

	/**
	 * Parse XML response
	 *
	 * @param string $xml_string XML string to parse
	 * @param bool $log_errors Whether to log parsing errors
	 * @return array|WP_Error Parsed array or WP_Error on failure
	 */
	protected function parse_xml_response( string $xml_string, bool $log_errors = true ) {
		if ( empty( $xml_string ) ) {
			return new WP_Error( 'empty_response', 'Empty response received' );
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
			if ( $log_errors && ! empty( $errors ) && $this->debug_logger_exists() ) {
				$this->debug_log_xml_errors( $errors, $xml_string );
			}

			// Return WP_Error with details
			return new WP_Error(
				'xml_parse_error',
				! empty( $errors ) ? $errors[0]->message : 'Failed to parse XML response',
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
	 * Convert SimpleXML object to array
	 *
	 * @param \SimpleXMLElement $xml SimpleXML object
	 * @param int $depth Current recursion depth
	 * @param int $max_depth Maximum recursion depth
	 * @return array Converted array
	 */
	protected function xml_to_array( \SimpleXMLElement $xml, int $depth = 0, int $max_depth = 100 ): array {
		// Prevent infinite recursion
		if ( $depth >= $max_depth ) {
			if ( $this->debug_logger_exists() ) {
				$this->debug( 'Maximum XML recursion depth reached', [
					'max_depth' => $max_depth,
					'node_name' => $xml->getName()
				] );
			}

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
	 * @param array $array The array to search
	 * @param string $path Dot notation path (e.g. "Buckets.Bucket")
	 * @return mixed|null The value or null if not found
	 */
	protected function get_value_from_path( array $array, string $path ) {
		return Xml::get_value( $array, $path );
	}

	/**
	 * Process XML namespaces
	 *
	 * @param \SimpleXMLElement $xml SimpleXML object
	 * @param array $result Result array to modify
	 * @param int $depth Current recursion depth
	 * @param int $max_depth Maximum recursion depth
	 */
	protected function process_xml_namespaces( \SimpleXMLElement $xml, array &$result, int $depth, int $max_depth ): void {
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
	 * @param array $errors Array of LibXMLError objects
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

					for ( $i = $context_start; $i <= $context_end; $i++ ) {
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

	/**
	 * Check if debug logger exists
	 *
	 * @return bool True if debug logger exists
	 */
	private function debug_logger_exists(): bool {
		return method_exists( $this, 'log_debug' ) ||
		       method_exists( $this, 'debug' ) ||
		       ( isset( $this->debug_logger ) && is_callable( $this->debug_logger ) );
	}

}