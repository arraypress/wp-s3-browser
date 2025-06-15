<?php
/**
 * XML Core Trait
 *
 * Provides basic XML parsing functionality and utilities.
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
use ArrayPress\S3\Responses\ErrorResponse;
use SimpleXMLElement;

/**
 * Trait Core
 */
trait Core {

	/**
	 * Parse XML response
	 *
	 * @param string $xml_string XML string to parse
	 * @param bool   $log_errors Whether to log parsing errors
	 *
	 * @return array|ErrorResponse Parsed array or ErrorResponse on failure
	 */
	protected function parse_response( string $xml_string, bool $log_errors = true ) {
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
				$this->debug_log_errors( $errors, $xml_string );
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
		return $this->to_array( $xml );
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
	protected function to_array( SimpleXMLElement $xml, int $depth = 0, int $max_depth = 100 ): array {
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
			$child_array = $this->to_array( $child, $depth + 1, $max_depth );

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
		$this->process_namespaces( $xml, $result, $depth, $max_depth );

		return $result;
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
	protected function process_namespaces( SimpleXMLElement $xml, array &$result, int $depth, int $max_depth ): void {
		foreach ( $xml->getNamespaces( true ) as $prefix => $ns ) {
			if ( $prefix === '' ) {
				$prefix = 'ns';
			}

			foreach ( $xml->children( $ns ) as $child_key => $child ) {
				$child_key   = $prefix . ':' . $child_key;
				$child_array = $this->to_array( $child, $depth + 1, $max_depth );

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

}
