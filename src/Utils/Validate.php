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
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Utils;

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
	 *
	 * @return bool True if bucket name syntax is valid
	 */
	public static function bucket( string $bucket ): bool {
		$length = strlen( $bucket );

		// Length check (3-63 characters)
		if ( $length < 3 || $length > 63 ) {
			return false;
		}

		// Character check
		if ( ! preg_match( '/^[a-z0-9\-.]+$/', $bucket ) ) {
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
	 * Validate object key
	 *
	 * @param string $object Object key to validate
	 *
	 * @return bool True if object key syntax is valid
	 */
	public static function object( string $object ): bool {
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

	/**
	 * Validate complete S3 path
	 *
	 * @param string $path Path to validate
	 *
	 * @return bool True if path syntax is valid
	 */
	public static function path( string $path ): bool {
		$parsed = Parse::path( $path );

		if ( ! $parsed ) {
			return false;
		}

		// Must have valid bucket and object syntax
		if ( ! self::bucket( $parsed['bucket'] ) || ! self::object( $parsed['object'] ) ) {
			return false;
		}

		// Object must have a file extension
		return File::has_extension( $parsed['object'] );
	}

	/**
	 * Validate bucket and object together
	 *
	 * @param string $bucket Bucket name
	 * @param string $object Object key
	 *
	 * @return bool True if both are syntactically valid
	 */
	public static function bucket_and_object( string $bucket, string $object ): bool {
		return self::bucket( $bucket ) && self::object( $object );
	}

	/**
	 * Validate and parse S3 path in one step
	 *
	 * @param string $path Path to validate and parse
	 *
	 * @return array|false Array with 'bucket' and 'object' keys or false if invalid
	 */
	public static function and_parse( string $path ) {
		$parsed = Parse::path( $path );

		if ( ! $parsed ) {
			return false;
		}

		// Object must have a file extension
		if ( ! File::has_extension( $parsed['object'] ) ) {
			return false;
		}

		if ( ! self::bucket_and_object( $parsed['bucket'], $parsed['object'] ) ) {
			return false;
		}

		return $parsed;
	}

	/**
	 * Validate folder name with comprehensive checks
	 *
	 * @param string $folder_name Folder name to validate
	 *
	 * @return array Validation result with 'valid' boolean and 'message' string
	 */
	public static function folder_comprehensive( string $folder_name ): array {
		// Check length
		if ( strlen( $folder_name ) === 0 ) {
			return [
				'valid'   => false,
				'message' => __( 'Folder name cannot be empty', 'arraypress' )
			];
		}

		if ( strlen( $folder_name ) > 63 ) {
			return [
				'valid'   => false,
				'message' => __( 'Folder name cannot exceed 63 characters', 'arraypress' )
			];
		}

		// Check for valid characters (letters, numbers, hyphens, underscores, dots)
		if ( ! preg_match( '/^[a-zA-Z0-9._-]+$/', $folder_name ) ) {
			return [
				'valid'   => false,
				'message' => __( 'Folder name can only contain letters, numbers, dots, hyphens, and underscores', 'arraypress' )
			];
		}

		// Cannot start or end with dot or hyphen
		if ( in_array( $folder_name[0], [ '.', '-' ] ) || in_array( $folder_name[ strlen( $folder_name ) - 1 ], [
				'.',
				'-'
			] ) ) {
			return [
				'valid'   => false,
				'message' => __( 'Folder name cannot start or end with dots or hyphens', 'arraypress' )
			];
		}

		// Cannot contain consecutive dots
		if ( strpos( $folder_name, '..' ) !== false ) {
			return [
				'valid'   => false,
				'message' => __( 'Folder name cannot contain consecutive dots', 'arraypress' )
			];
		}

		// Reserved names
		$reserved = [
			'CON',
			'PRN',
			'AUX',
			'NUL',
			'COM1',
			'COM2',
			'COM3',
			'COM4',
			'COM5',
			'COM6',
			'COM7',
			'COM8',
			'COM9',
			'LPT1',
			'LPT2',
			'LPT3',
			'LPT4',
			'LPT5',
			'LPT6',
			'LPT7',
			'LPT8',
			'LPT9'
		];
		if ( in_array( strtoupper( $folder_name ), $reserved ) ) {
			return [
				'valid'   => false,
				'message' => __( 'Folder name cannot be a reserved system name', 'arraypress' )
			];
		}

		return [ 'valid' => true, 'message' => '' ];
	}

	/**
	 * Validate folder name (simple version)
	 *
	 * @param string $folder_name Folder name to validate
	 *
	 * @return bool True if the folder name is valid
	 */
	public static function folder( string $folder_name ): bool {
		$result = self::folder_comprehensive( $folder_name );

		return $result['valid'];
	}

}