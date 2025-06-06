<?php
/**
 * Helper Utility Class for WordPress/WooCommerce Integration
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

class Helper {

	/**
	 * Check if a download method should be redirect for S3-compatible URLs
	 *
	 * @param string        $file_url File URL/path
	 * @param Provider|null $provider Optional provider instance
	 *
	 * @return bool True if should use redirect method
	 */
	public static function should_use_redirect( string $file_url, ?Provider $provider = null ): bool {
		return Detect::is_s3_compatible( $file_url, $provider );
	}

	/**
	 * Check if a file should be allowed (bypasses WooCommerce validation)
	 *
	 * @param string        $file_path File path
	 * @param Provider|null $provider  Optional provider instance
	 *
	 * @return bool True if file should be allowed
	 */
	public static function should_allow_file( string $file_path, ?Provider $provider = null ): bool {
		return Detect::is_s3_compatible( $file_path, $provider );
	}

	/**
	 * Get invalid S3-like paths from a list (for validation warnings)
	 *
	 * @param array $file_paths Array of file paths
	 *
	 * @return array Array of invalid paths
	 */
	public static function get_invalid_s3_like_paths( array $file_paths ): array {
		$invalid_paths = [];

		foreach ( $file_paths as $path ) {
			if ( Detect::is_invalid_s3_like_path( $path ) ) {
				$invalid_paths[] = $path;
			}
		}

		return $invalid_paths;
	}

	/**
	 * Validate and suggest fixes for common S3 path issues
	 *
	 * @param string $path Path to validate
	 *
	 * @return array Array with 'valid' boolean and 'suggestion' string
	 */
	public static function validate_s3_path_with_suggestions( string $path ): array {
		if ( Detect::is_s3_path( $path ) ) {
			return [
				'valid'      => true,
				'suggestion' => ''
			];
		}

		if ( Detect::is_normal_url( $path ) ) {
			return [
				'valid'      => false,
				'suggestion' => 'This is a regular URL, not an S3 path. Use format: bucket/folder/file.ext'
			];
		}

		if ( Detect::is_filesystem_path( $path ) ) {
			return [
				'valid'      => false,
				'suggestion' => 'This is a filesystem path, not an S3 path. Use format: bucket/folder/file.ext'
			];
		}

		if ( strpos( $path, '/' ) === false ) {
			return [
				'valid'      => false,
				'suggestion' => 'Missing bucket/object structure. Use format: bucket/folder/file.ext'
			];
		}

		if ( ! Detect::path_has_file_extension( $path ) ) {
			return [
				'valid'      => false,
				'suggestion' => 'Missing file extension. S3 objects should have file extensions.'
			];
		}

		return [
			'valid'      => false,
			'suggestion' => 'Invalid S3 path format. Use format: bucket/folder/file.ext'
		];
	}

}