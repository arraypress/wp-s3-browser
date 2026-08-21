<?php
/**
 * XML Extraction
 *
 * Reads S3 fields out of the arrays Parser produces. The shapes are awkward
 * because SimpleXML collapses a text node to a 'value' key, an element with
 * attributes to '@text', and a repeated element to a list -- every getter
 * here exists to absorb one of those.
 *
 * @package     ArrayPress\S3\Xml
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Xml;

use ArrayPress\S3\Utils\File;

/**
 * Class Extract
 */
class Extract {

	/**
	 * Read the text of a node.
	 *
	 * @param mixed $node Node data.
	 *
	 * @return string Text value.
	 */
	public static function text( $node ): string {
		if ( is_array( $node ) ) {
			return (string) ( $node['value'] ?? $node['@text'] ?? '' );
		}

		return (string) $node;
	}

	/**
	 * Read a node that may hold one value or many.
	 *
	 * @param mixed $data Node data.
	 *
	 * @return array List of values.
	 */
	public static function values( $data ): array {
		if ( empty( $data ) ) {
			return [];
		}

		if ( ! is_array( $data ) ) {
			$value = self::text( $data );

			return '' === $value ? [] : [ $value ];
		}

		// A single value that SimpleXML wrapped.
		if ( isset( $data['value'] ) || isset( $data['@text'] ) ) {
			return [ self::text( $data ) ];
		}

		return array_values( array_filter( array_map( [ self::class, 'text' ], $data ), 'strlen' ) );
	}

	/**
	 * Read an ETag, which S3 returns wrapped in literal quotes.
	 *
	 * @param mixed $node ETag node.
	 *
	 * @return string Unquoted ETag.
	 */
	public static function etag( $node ): string {
		return trim( self::text( $node ), '"' );
	}

	/**
	 * Read a boolean-valued node.
	 *
	 * @param mixed $node Node data.
	 *
	 * @return bool Whether the node reads true.
	 */
	public static function flag( $node ): bool {
		return in_array( self::text( $node ), [ 'true', '1' ], true );
	}

	/**
	 * Build an object row from a <Contents> node.
	 *
	 * @param array $node Object node.
	 *
	 * @return array Object data, or empty if the node has no key.
	 */
	public static function object( array $node ): array {
		$key = self::text( $node['Key'] ?? '' );

		if ( '' === $key ) {
			return [];
		}

		$filename = File::name( $key );
		$size     = (int) self::text( $node['Size'] ?? '0' );

		return [
			'Key'           => $key,
			'Filename'      => $filename,
			'LastModified'  => self::text( $node['LastModified'] ?? '' ),
			'ETag'          => self::etag( $node['ETag'] ?? '' ),
			'Size'          => $size,
			'StorageClass'  => self::text( $node['StorageClass'] ?? 'STANDARD' ),
			'FormattedSize' => size_format( $size ),
			'Category'      => File::category( $filename ),
			'MimeType'      => File::mime_type( $filename ),
		];
	}

	/**
	 * Build a bucket row from a <Bucket> node.
	 *
	 * @param array $node Bucket node.
	 *
	 * @return array Bucket data.
	 */
	public static function bucket( array $node ): array {
		return [
			'Name'         => self::text( $node['Name'] ?? '' ),
			'CreationDate' => self::text( $node['CreationDate'] ?? '' ),
		];
	}

	/**
	 * Read a bucket's region from a GetBucketLocation response.
	 *
	 * @param array  $xml     Parsed XML.
	 * @param string $default Region to assume when the response says nothing.
	 *
	 * @return string Region.
	 */
	public static function location( array $xml, string $default ): string {
		$location = Parser::find( $xml, 'LocationConstraint' );

		if ( null === $location ) {
			return $default;
		}

		// S3 returns an empty LocationConstraint for us-east-1 rather than
		// naming it, so an empty element is a value, not a missing one.
		return self::text( $location ) ?: 'us-east-1';
	}
}
