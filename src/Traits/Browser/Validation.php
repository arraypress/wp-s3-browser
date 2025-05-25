<?php
/**
 * Browser AJAX Handlers Trait
 *
 * Handles AJAX operations for the S3 Browser.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Browser;

/**
 * Trait Validation
 */
trait Validation {

	/**
	 * Validate folder name
	 *
	 * @param string $folder_name Folder name to validate
	 *
	 * @return array Validation result with 'valid' boolean and 'message' string
	 */
	private function validate_folder_name( string $folder_name ): array {
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

}