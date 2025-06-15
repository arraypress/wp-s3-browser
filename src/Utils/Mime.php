<?php
/**
 * Mime Utility Class
 *
 * Simple MIME type and extension handling for S3 uploads.
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
 * Class Mime
 *
 * Simple MIME type operations
 */
class Mime {

	/**
	 * Get additional MIME types commonly used in S3 uploads
	 *
	 * @return array Array of extension => mime_type pairs
	 */
	public static function get_additional_types(): array {
		return [
			// Archives
			'zip' => 'application/zip',
			'rar' => 'application/x-rar-compressed',
			'7z'  => 'application/x-7z-compressed',

			// Executables
			'exe' => 'application/x-msdownload',
			'dmg' => 'application/x-apple-diskimage',

			// eBooks
			'epub' => 'application/epub+zip',
			'mobi' => 'application/x-mobipocket-ebook',

			// Modern formats
			'webp' => 'image/webp',
			'webm' => 'video/webm',
			'flac' => 'audio/flac'
		];
	}

	/**
	 * Get WordPress allowed MIME types
	 *
	 * @param string|null $context Optional context for filtering (e.g., 'woocommerce', 'edd')
	 *
	 * @return array Array of allowed MIME types
	 */
	public static function get_allowed_types( ?string $context = null ): array {
		$wp_mime_types = get_allowed_mime_types();

		// Apply context-specific filter if context provided
		if ( $context ) {
			$wp_mime_types = apply_filters(
				"s3_browser_allowed_mime_types_{$context}",
				$wp_mime_types
			);
		}

		return $wp_mime_types;
	}

	/**
	 * Get allowed file extensions (derived from MIME types)
	 *
	 * @param string|null $context Optional context for filtering
	 *
	 * @return array Array of allowed file extensions
	 */
	public static function get_allowed_extensions( ?string $context = null ): array {
		$mime_types = self::get_allowed_types( $context );
		$extensions = [];

		foreach ( $mime_types as $ext => $mime ) {
			// Handle multiple extensions (e.g., "jpg|jpeg|jpe")
			$ext_parts = explode( '|', $ext );
			foreach ( $ext_parts as $extension ) {
				$extensions[] = strtolower( trim( $extension ) );
			}
		}

		return array_unique( $extensions );
	}

}