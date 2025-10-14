<?php
/**
 * XML Core Trait
 *
 * Provides basic XML parsing functionality and utilities using WordPress patterns.
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
	 * Parse XML response using WordPress-compatible secure parsing
	 *
	 * Follows WordPress core patterns for XML parsing security, similar to
	 * how SimplePie (WordPress's feed parser) handles XML.
	 *
	 * @param string $xml_string XML string to parse
	 * @param bool   $log_errors Whether to log parsing errors
	 *
	 * @return array|ErrorResponse Parsed array or ErrorResponse on failure
	 */
	protected function parse_response( string $xml_string, bool $log_errors = true ) {
		if ( empty( $xml_string ) ) {
			return new ErrorResponse(
				__( 'Empty response received', 'arraypress' ),
				'empty_response',
				400
			);
		}

		// WordPress pattern: Store previous error handling state
		$use_errors = libxml_use_internal_errors( true );

		// WordPress pattern: Clear any existing errors before parsing
		libxml_clear_errors();

		// SECURITY: Disable external entity loading (WordPress SimplePie pattern)
		// This prevents XXE (XML External Entity) attacks
		$disable_entities = false;
		if ( function_exists( 'libxml_disable_entity_loader' ) ) {
			$disable_entities = libxml_disable_entity_loader( true );
		}

		// SECURITY: Parse with secure options to prevent XXE attacks
		// LIBXML_NONET: Disable network access during XML loading
		// LIBXML_NOCDATA: Merge CDATA as text nodes (prevents CDATA injection)
		$options = LIBXML_NONET | LIBXML_NOCDATA;

		// SECURITY: Add NOENT if available (prevents entity expansion attacks)
		// This constant was added in PHP 5.3.0
		if ( defined( 'LIBXML_NOENT' ) ) {
			$options |= LIBXML_NOENT;
		}

		// WordPress pattern: Use @ suppression with error checking
		// WordPress core uses this pattern throughout (SimplePie, REST API, etc.)
		$xml = @simplexml_load_string( $xml_string, 'SimpleXMLElement', $options );

		// WordPress pattern: Get errors that occurred during parsing
		$errors = libxml_get_errors();

		// WordPress pattern: Always restore previous libxml settings
		libxml_clear_errors();
		libxml_use_internal_errors( $use_errors );

		if ( function_exists( 'libxml_disable_entity_loader' ) ) {
			libxml_disable_entity_loader( $disable_entities );
		}

		// Handle parsing failure
		if ( false === $xml ) {
			// Log errors only if requested AND in debug mode (WordPress pattern)
			if ( $log_errors && ! empty( $errors ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$this->debug_log_errors( $errors, $xml_string );
			}

			// WordPress pattern: Use translatable strings
			$error_message = ! empty( $errors )
				? trim( $errors[0]->message )
				: __( 'Failed to parse XML response', 'arraypress' );

			// Return WordPress-style error response
			return new ErrorResponse(
				$error_message,
				'xml_parse_error',
				400,
				array(
					'errors'       => $errors,
					// WordPress pattern: Use mb_substr for multibyte safety
					'xml_fragment' => function_exists( 'mb_substr' )
						? mb_substr( $xml_string, 0, 200 ) . ( mb_strlen( $xml_string ) > 200 ? '...' : '' )
						: substr( $xml_string, 0, 200 ) . ( strlen( $xml_string ) > 200 ? '...' : '' )
				)
			);
		}

		// Convert SimpleXML to array
		return $this->to_array( $xml );
	}

	/**
	 * Convert SimpleXML object to array
	 *
	 * WordPress pattern: Recursive conversion with depth limiting to prevent
	 * infinite recursion attacks or accidental deep nesting.
	 *
	 * @param SimpleXMLElement $xml       SimpleXML object
	 * @param int              $depth     Current recursion depth
	 * @param int              $max_depth Maximum recursion depth (default: 100)
	 *
	 * @return array Converted array
	 */
	protected function to_array( SimpleXMLElement $xml, int $depth = 0, int $max_depth = 100 ): array {
		// WordPress pattern: Prevent infinite recursion
		if ( $depth >= $max_depth ) {
			// WordPress pattern: Only log in debug mode
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$this->debug(
					__( 'Maximum XML recursion depth reached', 'arraypress' ),
					array(
						'max_depth' => $max_depth,
						'node_name' => $xml->getName()
					)
				);
			}

			return array( 'value' => __( 'ERROR: Maximum recursion depth reached', 'arraypress' ) );
		}

		$result = array();

		// Process attributes if present
		foreach ( $xml->attributes() as $key => $value ) {
			$result['@attributes'][ $key ] = (string) $value;
		}

		// WordPress pattern: Early return for simple values
		if ( count( $xml->children() ) === 0 ) {
			$text = (string) $xml;

			if ( strlen( $text ) === 0 ) {
				// If it's empty and has no attributes, return empty array
				if ( ! isset( $result['@attributes'] ) ) {
					return array();
				}

				// Otherwise, return just the attributes
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

		// Process child nodes recursively
		foreach ( $xml->children() as $child_key => $child ) {
			// WordPress pattern: Increment depth for recursion tracking
			$child_array = $this->to_array( $child, $depth + 1, $max_depth );

			// Add to result based on whether this key already exists
			if ( isset( $result[ $child_key ] ) ) {
				// WordPress pattern: Handle multiple children with same name
				if ( is_array( $result[ $child_key ] ) && isset( $result[ $child_key ][0] ) ) {
					$result[ $child_key ][] = $child_array;
				} else {
					// Convert to array of arrays
					$result[ $child_key ] = array( $result[ $child_key ], $child_array );
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
	 * WordPress pattern: Handle namespaced elements with depth tracking.
	 *
	 * @param SimpleXMLElement  $xml       SimpleXML object
	 * @param array            &$result    Result array to modify (passed by reference)
	 * @param int               $depth     Current recursion depth
	 * @param int               $max_depth Maximum recursion depth
	 *
	 * @return void
	 */
	protected function process_namespaces( SimpleXMLElement $xml, array &$result, int $depth, int $max_depth ): void {
		// WordPress pattern: Get all namespaces
		$namespaces = $xml->getNamespaces( true );

		if ( empty( $namespaces ) ) {
			return;
		}

		foreach ( $namespaces as $prefix => $ns ) {
			// WordPress pattern: Use 'ns' prefix for default namespace
			if ( empty( $prefix ) ) {
				$prefix = 'ns';
			}

			// Process children in this namespace
			foreach ( $xml->children( $ns ) as $child_key => $child ) {
				$namespaced_key = $prefix . ':' . $child_key;

				// WordPress pattern: Recursive call with depth tracking
				$child_array = $this->to_array( $child, $depth + 1, $max_depth );

				// Add to result (same logic as main processing)
				if ( isset( $result[ $namespaced_key ] ) ) {
					if ( is_array( $result[ $namespaced_key ] ) && isset( $result[ $namespaced_key ][0] ) ) {
						$result[ $namespaced_key ][] = $child_array;
					} else {
						$result[ $namespaced_key ] = array( $result[ $namespaced_key ], $child_array );
					}
				} else {
					$result[ $namespaced_key ] = $child_array;
				}
			}
		}
	}

}