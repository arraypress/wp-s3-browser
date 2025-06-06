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
		if ( strpos( $filename, '..' ) !== false || strpos( $filename, '/' ) !== false || strpos( $filename, '\\' ) !== false ) {
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
			'CON', 'PRN', 'AUX', 'NUL',
			'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
			'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9'
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
	 * Validate folder name with comprehensive checks
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

		// Check for valid characters
		if ( ! preg_match( '/^[a-zA-Z0-9._-]+$/', $folder_name ) ) {
			return [
				'valid'   => false,
				'message' => __( 'Folder name can only contain letters, numbers, dots, hyphens, and underscores', 'arraypress' )
			];
		}

		// Cannot start or end with dot or hyphen
		if ( in_array( $folder_name[0], [ '.', '-' ] ) || in_array( $folder_name[ strlen( $folder_name ) - 1 ], [ '.', '-' ] ) ) {
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

		// Check for reserved system names
		$reserved = [
			'CON', 'PRN', 'AUX', 'NUL',
			'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
			'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9'
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

}