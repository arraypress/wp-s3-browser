<?php
/**
 * Validate Utility Class
 *
 * Handles validation of filenames and folder names for S3 Browser operations.
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
 * Handles validation of filenames and folder names
 */
class Validate {

	/**
	 * Validate filename with comprehensive checks
	 *
	 * @param string $filename Filename to validate
	 *
	 * @return array Validation result with 'valid' boolean and 'message' string
	 */
	public static function filename( string $filename ): array {
		// Check if filename is empty
		if ( empty( trim( $filename ) ) ) {
			return [
				'valid'   => false,
				'message' => __( 'Filename cannot be empty', 'arraypress' )
			];
		}

		// Check filename length
		if ( strlen( $filename ) > 255 ) {
			return [
				'valid'   => false,
				'message' => __( 'Filename is too long (maximum 255 characters)', 'arraypress' )
			];
		}

		// Check for invalid characters
		if ( preg_match( '/[<>:"|?*]/', $filename ) ) {
			return [
				'valid'   => false,
				'message' => __( 'Filename contains invalid characters: < > : " | ? *', 'arraypress' )
			];
		}

		// Check if filename starts or ends with problematic characters
		if ( preg_match( '/^[.\-_\s]|[.\s]$/', $filename ) ) {
			return [
				'valid'   => false,
				'message' => __( 'Filename cannot start with dots, dashes, underscores, or spaces, or end with dots or spaces', 'arraypress' )
			];
		}

		// Check for directory traversal attempts
		if ( str_contains( $filename, '..' ) || str_contains( $filename, '/' ) || str_contains( $filename, '\\' ) ) {
			return [
				'valid'   => false,
				'message' => __( 'Filename cannot contain path separators or relative path indicators', 'arraypress' )
			];
		}

		// Check for control characters and null bytes
		if ( preg_match( '/[\x00-\x1F\x7F]/', $filename ) ) {
			return [
				'valid'   => false,
				'message' => __( 'Filename cannot contain control characters', 'arraypress' )
			];
		}

		// Check for reserved system names
		$reserved_names = [
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

		$base_name = pathinfo( $filename, PATHINFO_FILENAME );
		if ( in_array( strtoupper( $base_name ), $reserved_names, true ) ) {
			return [
				'valid'   => false,
				'message' => __( 'Filename cannot be a reserved system name', 'arraypress' )
			];
		}

		// Check if filename is just an extension
		if ( $filename[0] === '.' && strlen( trim( $base_name ) ) === 0 ) {
			return [
				'valid'   => false,
				'message' => __( 'Filename cannot be just an extension', 'arraypress' )
			];
		}

		return [
			'valid'   => true,
			'message' => ''
		];
	}

	/**
	 * Validate folder name with comprehensive checks including space support
	 *
	 * @param string $folder_name Folder name to validate
	 *
	 * @return array Validation result with 'valid' boolean and 'message' string
	 */
	public static function folder_name( string $folder_name ): array {
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

		// Check for valid characters (now includes spaces)
		if ( ! preg_match( '/^[a-zA-Z0-9._\s-]+$/', $folder_name ) ) {
			return [
				'valid'   => false,
				'message' => __( 'Folder name can only contain letters, numbers, spaces, dots, hyphens, and underscores', 'arraypress' )
			];
		}

		// Cannot start or end with dot, hyphen, or space
		$first_char = $folder_name[0];
		$last_char  = $folder_name[ strlen( $folder_name ) - 1 ];

		if ( in_array( $first_char, [ '.', '-', ' ' ] ) || in_array( $last_char, [ '.', '-', ' ' ] ) ) {
			return [
				'valid'   => false,
				'message' => __( 'Folder name cannot start or end with dots, hyphens, or spaces', 'arraypress' )
			];
		}

		// Cannot contain consecutive dots
		if ( str_contains( $folder_name, '..' ) ) {
			return [
				'valid'   => false,
				'message' => __( 'Folder name cannot contain consecutive dots', 'arraypress' )
			];
		}

		// Cannot contain multiple consecutive spaces
		if ( preg_match( '/\s{2,}/', $folder_name ) ) {
			return [
				'valid'   => false,
				'message' => __( 'Folder name cannot contain multiple consecutive spaces', 'arraypress' )
			];
		}

		// Check for reserved system names
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

		if ( in_array( strtoupper( $folder_name ), $reserved, true ) ) {
			return [
				'valid'   => false,
				'message' => __( 'Folder name cannot be a reserved system name', 'arraypress' )
			];
		}

		return [
			'valid'   => true,
			'message' => ''
		];
	}

	/**
	 * Validate CORS rules array
	 *
	 * @param array $cors_rules CORS rules to validate
	 *
	 * @return array Validation result with 'valid' boolean and 'message' string
	 */
	public static function cors_rules( array $cors_rules ): array {
		if ( count( $cors_rules ) > 100 ) {
			return [
				'valid'   => false,
				'message' => __( 'Maximum 100 CORS rules allowed per bucket', 'arraypress' ),
				'code'    => 'too_many_rules'
			];
		}

		foreach ( $cors_rules as $index => $rule ) {
			if ( ! is_array( $rule ) ) {
				return [
					'valid'   => false,
					'message' => sprintf( __( 'CORS rule at index %d must be an array', 'arraypress' ), $index ),
					'code'    => 'invalid_rule_format'
				];
			}

			// Required fields
			if ( empty( $rule['AllowedMethods'] ) ) {
				return [
					'valid'   => false,
					'message' => sprintf( __( 'CORS rule at index %d must have AllowedMethods', 'arraypress' ), $index ),
					'code'    => 'missing_allowed_methods'
				];
			}

			if ( empty( $rule['AllowedOrigins'] ) ) {
				return [
					'valid'   => false,
					'message' => sprintf( __( 'CORS rule at index %d must have AllowedOrigins', 'arraypress' ), $index ),
					'code'    => 'missing_allowed_origins'
				];
			}

			// Validate methods
			$valid_methods = [ 'GET', 'PUT', 'POST', 'DELETE', 'HEAD' ];
			foreach ( $rule['AllowedMethods'] as $method ) {
				if ( ! in_array( $method, $valid_methods, true ) ) {
					return [
						'valid'   => false,
						'message' => sprintf( __( 'Invalid HTTP method "%s" in CORS rule at index %d', 'arraypress' ), $method, $index ),
						'code'    => 'invalid_http_method'
					];
				}
			}

			// Validate ID length if present
			if ( ! empty( $rule['ID'] ) && strlen( $rule['ID'] ) > 255 ) {
				return [
					'valid'   => false,
					'message' => sprintf( __( 'CORS rule ID at index %d exceeds 255 characters', 'arraypress' ), $index ),
					'code'    => 'rule_id_too_long'
				];
			}
		}

		return [
			'valid'   => true,
			'message' => ''
		];
	}

}