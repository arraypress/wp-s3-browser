<?php
/**
 * CORS Rule Generation
 *
 * @package     ArrayPress\S3\Cors
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Cors;

/**
 * Class Rules
 */
class Rules {

	/**
	 * Headers worth exposing to JavaScript for a readable object.
	 *
	 * Without these on the rule, a browser hides them from fetch() even
	 * though the response carries them.
	 */
	private const READ_HEADERS = [ 'Content-Length', 'Content-Type', 'ETag', 'Last-Modified' ];

	/**
	 * Rule templates, keyed by scenario.
	 *
	 * Each entry is a partial rule; origins and max-age are filled in by
	 * generate() so a caller can override them per call.
	 */
	private const SCENARIOS = [
		'public_read'      => [
			'ID'             => 'PublicRead',
			'AllowedMethods' => [ 'GET', 'HEAD' ],
			'AllowedHeaders' => [ 'Range' ],
			'ExposeHeaders'  => self::READ_HEADERS,
			'MaxAgeSeconds'  => 86400,
		],
		'upload_only'      => [
			'ID'             => 'UploadOnly',
			'AllowedMethods' => [ 'PUT', 'POST' ],
			'AllowedHeaders' => [ 'Content-Type', 'Content-Length', 'Content-MD5', 'x-amz-*' ],
			'MaxAgeSeconds'  => 3600,
		],
		'presigned_upload' => [
			'ID'             => 'PresignedUpload',
			'AllowedMethods' => [ 'PUT' ],
			'AllowedHeaders' => [ 'Content-Type', 'Content-Length' ],
			'MaxAgeSeconds'  => 600,
		],
		'full_access'      => [
			'ID'             => 'FullAccess',
			'AllowedMethods' => [ 'GET', 'PUT', 'POST', 'DELETE', 'HEAD' ],
			'AllowedHeaders' => [ '*' ],
			'ExposeHeaders'  => self::READ_HEADERS,
			'MaxAgeSeconds'  => 3600,
		],
	];

	/**
	 * Build the rules for a scenario.
	 *
	 * @param string $scenario One of the keys in SCENARIOS, or 'custom'.
	 * @param array  $origins  Origins the rule should allow.
	 * @param array  $extra    Overrides merged over the template.
	 *
	 * @return array CORS rules.
	 */
	public static function generate( string $scenario = 'public_read', array $origins = [ '*' ], array $extra = [] ): array {
		if ( 'mixed' === $scenario ) {
			// Read stays open to everyone; only the write half is pinned to
			// the caller's origins.
			return array_merge(
				self::generate( 'public_read', [ '*' ] ),
				self::generate( 'upload_only', $origins, $extra )
			);
		}

		$rule = self::SCENARIOS[ $scenario ] ?? [
			'ID'             => 'Custom',
			'AllowedMethods' => [ 'GET' ],
			'MaxAgeSeconds'  => 3600,
		];

		$rule['AllowedOrigins'] = $origins;

		if ( isset( $extra['max_age'] ) ) {
			$rule['MaxAgeSeconds'] = (int) $extra['max_age'];
			unset( $extra['max_age'] );
		}

		return [ array_merge( $rule, $extra ) ];
	}

	/**
	 * List the scenarios generate() understands.
	 *
	 * @return array Scenario keys.
	 */
	public static function scenarios(): array {
		return array_merge( array_keys( self::SCENARIOS ), [ 'mixed' ] );
	}

}
