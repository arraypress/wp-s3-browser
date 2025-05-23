<?php
/**
 * Validate Utility Class
 *
 * Handles validation of S3 paths, buckets, and objects.
 *
 * @package     ArrayPress\S3\Utils
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      ArrayPress Team
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Utils;

use ArrayPress\S3\Abstracts\Provider;
use Exception;

/**
 * Class Validate
 *
 * Handles validation of S3 paths, buckets, and objects
 */
class Validate {

	/**
	 * Validate bucket name
	 *
	 * @param string $bucket Bucket name to validate
	 * @param Provider|null $provider Optional provider for specific validation
	 * @return bool
	 */
	public static function bucket( string $bucket, ?Provider $provider = null ): bool {
		if ( empty( $bucket ) ) {
			return false;
		}

		// Use provider validation if available
		if ( $provider && method_exists( $provider, 'is_valid_bucket_name' ) ) {
			try {
				return $provider->is_valid_bucket_name( $bucket );
			} catch ( Exception $e ) {
				return false;
			}
		}

		// Fallback to basic S3 validation
		return self::bucket_basic( $bucket );
	}

	/**
	 * Validate object key
	 *
	 * @param string $object Object key to validate
	 * @param Provider|null $provider Optional provider for specific validation
	 * @return bool
	 */
	public static function object( string $object, ?Provider $provider = null ): bool {
		if ( empty( $object ) ) {
			return false;
		}

		// Use provider validation if available
		if ( $provider && method_exists( $provider, 'is_valid_object_key' ) ) {
			try {
				return $provider->is_valid_object_key( $object );
			} catch ( Exception $e ) {
				return false;
			}
		}

		// Fallback to basic S3 validation
		return self::object_basic( $object );
	}

	/**
	 * Validate complete S3 path
	 *
	 * @param string $path Path to validate
	 * @param Provider|null $provider Optional provider for validation
	 * @return bool
	 */
	public static function path( string $path, ?Provider $provider = null ): bool {
		$parsed = Parse::path( $path );

		if ( ! $parsed ) {
			return false;
		}

		// Object must have a file extension
		if ( ! File::has_extension( $parsed['object'] ) ) {
			return false;
		}

		return self::bucket_and_object(
			$parsed['bucket'],
			$parsed['object'],
			$provider
		);
	}

	/**
	 * Validate bucket and object together
	 *
	 * @param string $bucket Bucket name
	 * @param string $object Object key
	 * @param Provider|null $provider Optional provider
	 * @return bool
	 */
	public static function bucket_and_object(
		string $bucket,
		string $object,
		?Provider $provider = null
	): bool {
		return self::bucket( $bucket, $provider ) &&
		       self::object( $object, $provider );
	}

	/**
	 * Validate and parse S3 path in one step
	 *
	 * @param string $path Path to validate and parse
	 * @param Provider|null $provider Optional provider
	 * @return array|false Array with 'bucket' and 'object' keys or false if invalid
	 */
	public static function and_parse( string $path, ?Provider $provider = null ) {
		$parsed = Parse::path( $path );

		if ( ! $parsed ) {
			return false;
		}

		// Object must have a file extension
		if ( ! File::has_extension( $parsed['object'] ) ) {
			return false;
		}

		if ( ! self::bucket_and_object( $parsed['bucket'], $parsed['object'], $provider ) ) {
			return false;
		}

		return $parsed;
	}

	/**
	 * Basic bucket validation (S3 standard rules)
	 *
	 * @param string $bucket Bucket name
	 * @return bool
	 */
	private static function bucket_basic( string $bucket ): bool {
		$length = strlen( $bucket );

		// Length check (3-63 characters)
		if ( $length < 3 || $length > 63 ) {
			return false;
		}

		// Character check
		if ( ! preg_match( '/^[a-z0-9\-\.]+$/', $bucket ) ) {
			return false;
		}

		// Cannot start or end with hyphen or dot
		if ( $bucket[0] === '-' || $bucket[0] === '.' ||
		     $bucket[ $length - 1 ] === '-' || $bucket[ $length - 1 ] === '.' ) {
			return false;
		}

		// Cannot have consecutive dots
		if ( strpos( $bucket, '..' ) !== false ) {
			return false;
		}

		return true;
	}

	/**
	 * Basic object key validation (S3 standard rules)
	 *
	 * @param string $object Object key
	 * @return bool
	 */
	private static function object_basic( string $object ): bool {
		$length = strlen( $object );

		// Length check (1-1024 characters)
		if ( $length < 1 || $length > 1024 ) {
			return false;
		}

		// Cannot start with slash
		if ( $object[0] === '/' ) {
			return false;
		}

		// Check for null bytes or control characters
		if ( preg_match( '/[\x00-\x1F\x7F]/', $object ) ) {
			return false;
		}

		return true;
	}

}