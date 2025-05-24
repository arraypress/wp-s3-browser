<?php
/**
 * Build Utility Class
 *
 * Handles building S3 paths from components.
 *
 * @package     ArrayPress\S3\Utils
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Utils;

use ArrayPress\S3\Abstracts\Provider;

/**
 * Class Build
 *
 * Handles building S3 paths from components
 */
class Build {

	/**
	 * Build S3 path from components
	 *
	 * @param string $bucket        Bucket name
	 * @param string $object        Object key
	 * @param bool   $with_protocol Whether to add s3:// protocol
	 *
	 * @return string
	 */
	public static function path( string $bucket, string $object, bool $with_protocol = false ): string {
		$bucket = trim( $bucket, '/' );
		$object = ltrim( $object, '/' );

		$path = $bucket . '/' . $object;

		if ( $with_protocol ) {
			$path = 's3://' . $path;
		}

		return $path;
	}

	/**
	 * Build S3 URL with protocol
	 *
	 * @param string $bucket Bucket name
	 * @param string $object Object key
	 *
	 * @return string
	 */
	public static function url( string $bucket, string $object ): string {
		return self::path( $bucket, $object, true );
	}

	/**
	 * Build provider URL using provider's format_url method
	 *
	 * @param string   $bucket   Bucket name
	 * @param string   $object   Object key
	 * @param Provider $provider Provider instance
	 *
	 * @return string
	 */
	public static function provider_url( string $bucket, string $object, Provider $provider ): string {
		return $provider->format_url( $bucket, $object );
	}

	/**
	 * Normalize S3 path to simple bucket/object format
	 *
	 * @param string $path Path to normalize
	 *
	 * @return string|false Normalized path or false on failure
	 */
	public static function normalize( string $path ) {
		$parsed = Parse::path( $path );

		if ( ! $parsed ) {
			return false;
		}

		return self::path( $parsed['bucket'], $parsed['object'] );
	}

}