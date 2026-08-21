<?php
/**
 * S3 Response Shapes
 *
 * Turns parsed XML into the arrays the rest of the library works with.
 *
 * Every list here handles both a single element and a repeated one, because
 * SimpleXML gives no hint which it produced: one <Contents> becomes a node
 * with a 'Key', many become a list of such nodes.
 *
 * @package     ArrayPress\S3\Xml
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Xml;

use ArrayPress\S3\Responses\ErrorResponse;

/**
 * Class Response
 */
class Response {

	/**
	 * Parse a ListObjectsV2 response.
	 *
	 * @param array $xml Parsed XML.
	 *
	 * @return array Objects, prefixes, and pagination state.
	 */
	public static function objects( array $xml ): array {
		$result    = $xml['ListObjectsV2Result'] ?? $xml;
		$truncated = Extract::flag( $result['IsTruncated'] ?? '' );

		return [
			'objects'            => array_values( array_filter(
				array_map( [ Extract::class, 'object' ], self::items( $result['Contents'] ?? null, 'Key' ) )
			) ),
			'prefixes'           => array_values( array_filter( array_map(
				static fn( $node ) => Extract::text( $node['Prefix'] ?? '' ),
				self::items( $result['CommonPrefixes'] ?? null, 'Prefix' )
			), 'strlen' ) ),
			'truncated'          => $truncated,
			'continuation_token' => $truncated ? Extract::text( $result['NextContinuationToken'] ?? '' ) : '',
		];
	}

	/**
	 * Parse a ListBuckets response.
	 *
	 * @param array $xml Parsed XML.
	 *
	 * @return array Buckets, owner, and pagination state.
	 */
	public static function buckets( array $xml ): array {
		$result  = $xml['ListAllMyBucketsResult'] ?? $xml;
		$buckets = array_map( [ Extract::class, 'bucket' ], self::items( Parser::find( $result, 'Bucket' ), 'Name' ) );

		// Some S3-compatible providers nest the bucket list somewhere other
		// than under <Bucket>. Fall back to walking for anything shaped like
		// one before giving up.
		if ( ! $buckets ) {
			$buckets = self::find_buckets( $xml );
		}

		$owner     = Parser::find( $xml, 'Owner' );
		$truncated = Extract::flag( Parser::find( $xml, 'IsTruncated' ) ?? '' );

		return [
			'buckets'     => $buckets,
			'owner'       => null === $owner ? null : [
				'ID'          => Extract::text( $owner['ID'] ?? '' ),
				'DisplayName' => Extract::text( $owner['DisplayName'] ?? '' ),
			],
			'truncated'   => $truncated,
			'next_marker' => $truncated ? (string) self::next_marker( $xml ) : '',
		];
	}

	/**
	 * Parse a GetBucketCors response.
	 *
	 * @param array $xml Parsed XML.
	 *
	 * @return array CORS rules.
	 */
	public static function cors( array $xml ): array {
		$config = $xml['CORSConfiguration'] ?? $xml;
		$rules  = [];

		foreach ( self::items( $config['CORSRule'] ?? null, 'AllowedMethod', 'AllowedOrigin' ) as $rule ) {
			$parsed = [
				'ID'             => Extract::text( $rule['ID'] ?? '' ),
				'AllowedMethods' => Extract::values( $rule['AllowedMethod'] ?? [] ),
				'AllowedOrigins' => Extract::values( $rule['AllowedOrigin'] ?? [] ),
				'AllowedHeaders' => Extract::values( $rule['AllowedHeader'] ?? [] ),
				'ExposeHeaders'  => Extract::values( $rule['ExposeHeader'] ?? [] ),
				'MaxAgeSeconds'  => (int) Extract::text( $rule['MaxAgeSeconds'] ?? '0' ),
			];

			// Drop what the rule did not set, but keep a MaxAgeSeconds of 0 --
			// that is a real instruction to browsers, not an absent field.
			$rules[] = array_filter(
				$parsed,
				static fn( $value, $key ) => ! empty( $value ) || ( 'MaxAgeSeconds' === $key && 0 === $value ),
				ARRAY_FILTER_USE_BOTH
			);
		}

		return $rules;
	}

	/**
	 * Parse a DeleteObjects response.
	 *
	 * @param array $xml Parsed XML.
	 *
	 * @return array Deleted keys and per-key errors.
	 */
	public static function batch_delete( array $xml ): array {
		$result = $xml['DeleteResult'] ?? $xml;

		$deleted = array_map(
			static fn( $node ) => [
				'key'        => Extract::text( $node['Key'] ),
				'version_id' => Extract::text( $node['VersionId'] ?? null ),
			],
			self::items( Parser::find( $result, 'Deleted' ), 'Key' )
		);

		$errors = array_map(
			static fn( $node ) => [
				'key'     => Extract::text( $node['Key'] ),
				'code'    => Extract::text( $node['Code'] ?? 'Unknown' ),
				'message' => Extract::text( $node['Message'] ?? 'Unknown error' ),
			],
			self::items( Parser::find( $result, 'Error' ), 'Key' )
		);

		return [
			'success_count' => count( $deleted ),
			'error_count'   => count( $errors ),
			'deleted'       => $deleted,
			'errors'        => $errors,
		];
	}

	/**
	 * Parse a CopyObject response.
	 *
	 * @param array $xml Parsed XML.
	 *
	 * @return array ETag and last-modified date.
	 */
	public static function copy( array $xml ): array {
		// CopyObjectResult is the *root* element, so the parser hands back its
		// children with no key to look under -- the same shape trap as <Error>.
		$result = $xml['CopyObjectResult'] ?? $xml;

		return [
			'etag'          => Extract::etag( $result['ETag'] ?? '' ),
			'last_modified' => Extract::text( $result['LastModified'] ?? '' ),
		];
	}

	/**
	 * Read a ListObjectsV2 continuation token.
	 *
	 * @param array $xml Parsed XML.
	 *
	 * @return string|null Token, or null if the listing is complete.
	 */
	public static function continuation_token( array $xml ): ?string {
		$token = Parser::find( $xml, 'NextContinuationToken' );

		return null === $token ? null : Extract::text( $token );
	}

	/**
	 * Read a ListBuckets marker.
	 *
	 * @param array $xml Parsed XML.
	 *
	 * @return string|null Marker, or null if the listing is complete.
	 */
	public static function next_marker( array $xml ): ?string {
		$marker = Parser::find( $xml, 'NextMarker' );

		return null === $marker ? null : Extract::text( $marker );
	}

	/**
	 * Read a provider's error document.
	 *
	 * @param int    $status  HTTP status code.
	 * @param string $body    Response body.
	 * @param string $default Message to use when the body says nothing useful.
	 *
	 * @return ErrorResponse Provider's error, or the default.
	 */
	public static function error( int $status, string $body, string $default ): ErrorResponse {
		// Match on the element, not on an '<?xml' prolog. Cloudflare R2 omits
		// the prolog, and requiring it meant every R2 failure collapsed to the
		// caller's generic default -- discarding the provider's error code,
		// which is the one thing that separates a scoped token from bad keys.
		if ( false === stripos( $body, '<Error' ) ) {
			return new ErrorResponse( $default, 'request_failed', $status );
		}

		$xml = Parser::parse( $body );

		if ( ! is_array( $xml ) ) {
			return new ErrorResponse( $default, 'request_failed', $status );
		}

		// S3 returns <Error> as the *root* element, so the parser hands back
		// its children with no 'Error' key to look under. Accept both that and
		// the nested shape some providers use.
		$error = $xml['Error'] ?? $xml;

		if ( ! isset( $error['Code'] ) && ! isset( $error['Message'] ) ) {
			return new ErrorResponse( $default, 'request_failed', $status );
		}

		return new ErrorResponse(
			Extract::text( $error['Message'] ?? '' ) ?: $default,
			Extract::text( $error['Code'] ?? '' ) ?: 'unknown_error',
			$status
		);
	}

	/**
	 * Normalise a node that holds either one item or a list of them.
	 *
	 * @param mixed  $node Node data.
	 * @param string ...$markers Keys that identify a single item.
	 *
	 * @return array List of item nodes.
	 */
	private static function items( $node, string ...$markers ): array {
		if ( ! is_array( $node ) || ! $node ) {
			return [];
		}

		foreach ( $markers as $marker ) {
			if ( isset( $node[ $marker ] ) ) {
				return [ $node ];
			}
		}

		return array_values( array_filter( $node, 'is_array' ) );
	}

	/**
	 * Walk the document for anything shaped like a bucket.
	 *
	 * @param array $data Parsed XML.
	 *
	 * @return array Buckets found.
	 */
	private static function find_buckets( array $data ): array {
		$buckets = [];

		foreach ( $data as $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}

			if ( isset( $value['Name'], $value['CreationDate'] ) ) {
				$buckets[] = Extract::bucket( $value );
				continue;
			}

			$buckets = array_merge( $buckets, self::find_buckets( $value ) );
		}

		return $buckets;
	}

}
