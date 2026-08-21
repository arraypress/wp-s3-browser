<?php
/**
 * XML Parser
 *
 * Turns an S3 XML payload into a nested array. Knows nothing about S3
 * semantics -- see Extract and Response for that.
 *
 * @package     ArrayPress\S3\Xml
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Xml;

use ArrayPress\S3\Responses\ErrorResponse;
use SimpleXMLElement;

/**
 * Class Parser
 */
class Parser {

	/**
	 * Guard against a hostile or malformed document nesting without end.
	 */
	private const MAX_DEPTH = 100;

	/**
	 * Parse an XML string into an array.
	 *
	 * @param string $xml_string XML to parse.
	 *
	 * @return array|ErrorResponse Parsed array, or ErrorResponse on failure.
	 */
	public static function parse( string $xml_string ) {
		if ( '' === trim( $xml_string ) ) {
			return new ErrorResponse(
				__( 'Empty response received', 'arraypress' ),
				'empty_response',
				400
			);
		}

		$use_errors = libxml_use_internal_errors( true );
		libxml_clear_errors();

		// No libxml_disable_entity_loader() call: it is deprecated as of PHP 8.0
		// precisely because external entity loading is off by default there, and
		// this package requires 8.2. Calling it only emits a deprecation.
		//
		// LIBXML_NONET   -- no network access while loading.
		// LIBXML_NOCDATA -- merge CDATA into text nodes.
		//
		// LIBXML_NOENT is deliberately absent. Despite the name it *substitutes*
		// entities, which is exactly what enables XXE and billion-laughs. The
		// default (no substitution) plus NONET is the safe combination.
		$xml = @simplexml_load_string( $xml_string, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA );

		$errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $use_errors );

		if ( false === $xml ) {
			return new ErrorResponse(
				$errors ? trim( $errors[0]->message ) : __( 'Failed to parse XML response', 'arraypress' ),
				'xml_parse_error',
				400,
				[
					'errors'   => array_map( [ self::class, 'format_error' ], $errors ),
					'fragment' => mb_strimwidth( $xml_string, 0, 200, '...' ),
				]
			);
		}

		return self::to_array( $xml );
	}

	/**
	 * Search recursively for the first value stored under a key.
	 *
	 * S3-compatible providers vary in how deeply they nest their result
	 * elements, so callers look for an element by name rather than by path.
	 *
	 * @param array  $data Parsed XML array.
	 * @param string $key  Element name to find.
	 *
	 * @return mixed|null First match, or null.
	 */
	public static function find( array $data, string $key ) {
		foreach ( $data as $current => $value ) {
			if ( $current === $key ) {
				return $value;
			}

			if ( is_array( $value ) ) {
				$found = self::find( $value, $key );

				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Convert a SimpleXML element to an array.
	 *
	 * @param SimpleXMLElement $xml   Element to convert.
	 * @param int              $depth Current recursion depth.
	 *
	 * @return array Converted array.
	 */
	private static function to_array( SimpleXMLElement $xml, int $depth = 0 ): array {
		if ( $depth >= self::MAX_DEPTH ) {
			return [ 'value' => __( 'ERROR: Maximum recursion depth reached', 'arraypress' ) ];
		}

		$result = [];

		foreach ( $xml->attributes() as $key => $value ) {
			$result['@attributes'][ $key ] = (string) $value;
		}

		$children = self::children( $xml );

		// A leaf node: no children, so the element is its text.
		if ( ! $children ) {
			$text = (string) $xml;

			if ( '' === $text ) {
				return $result;
			}

			// Attributes and text together need both preserved; text alone
			// collapses to a plain value, which is what Extract reads.
			$result[ isset( $result['@attributes'] ) ? '@text' : 'value' ] = $text;

			return $result;
		}

		foreach ( $children as [ $key, $child ] ) {
			self::add_child( $result, $key, self::to_array( $child, $depth + 1 ) );
		}

		return $result;
	}

	/**
	 * Collect an element's children, prefixed ones included.
	 *
	 * SimpleXML resolves a *default* namespace transparently, so children()
	 * with no argument already returns the elements of a document declaring
	 * xmlns="..." -- which every S3 response does. Walking the namespace list
	 * as well would return those same children a second time, so only a
	 * namespace with an explicit prefix is worth visiting.
	 *
	 * @param SimpleXMLElement $xml Element to read.
	 *
	 * @return array List of [ key, element ] pairs.
	 */
	private static function children( SimpleXMLElement $xml ): array {
		$children = [];

		foreach ( $xml->children() as $key => $child ) {
			$children[] = [ $key, $child ];
		}

		foreach ( $xml->getNamespaces( true ) as $prefix => $namespace ) {
			if ( '' === $prefix ) {
				continue;
			}

			foreach ( $xml->children( $namespace ) as $key => $child ) {
				$children[] = [ "{$prefix}:{$key}", $child ];
			}
		}

		return $children;
	}

	/**
	 * Add a child to the result, promoting repeats to a list.
	 *
	 * S3 repeats element names for collections -- many <Contents>, many
	 * <Bucket>. The first becomes the value; the second turns it into a list.
	 *
	 * @param array  $result Result array, modified in place.
	 * @param string $key    Child element name.
	 * @param array  $child  Converted child.
	 *
	 * @return void
	 */
	private static function add_child( array &$result, string $key, array $child ): void {
		if ( ! isset( $result[ $key ] ) ) {
			$result[ $key ] = $child;

			return;
		}

		// Already a list; append. isset() on [0] distinguishes a list of
		// children from a single child that happens to have named keys.
		if ( isset( $result[ $key ][0] ) ) {
			$result[ $key ][] = $child;

			return;
		}

		$result[ $key ] = [ $result[ $key ], $child ];
	}

	/**
	 * Reduce a libxml error to a readable line.
	 *
	 * @param object $error LibXMLError instance.
	 *
	 * @return string Formatted error.
	 */
	private static function format_error( object $error ): string {
		$levels = [
			LIBXML_ERR_WARNING => 'Warning',
			LIBXML_ERR_ERROR   => 'Error',
			LIBXML_ERR_FATAL   => 'Fatal Error',
		];

		return sprintf(
			'%s (line %d, column %d): %s',
			$levels[ $error->level ] ?? 'Unknown',
			$error->line,
			$error->column,
			trim( $error->message )
		);
	}

}
