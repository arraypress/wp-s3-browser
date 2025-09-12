<?php
/**
 * File Utility Class
 *
 * Handles file-related operations and metadata.
 *
 * @package     ArrayPress\S3\Utils
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      ArrayPress Team
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Utils;

/**
 * Class File
 *
 * Handles file-related operations and metadata
 */
class File {

	/**
	 * Get file extension from the filename or object key
	 *
	 * @param string $filename Filename or object key
	 *
	 * @return string File extension (lowercase)
	 */
	public static function extension( string $filename ): string {
		return strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	}

	/**
	 * Get filename from a filename or object key
	 *
	 * @param string $object_key Filename or object key
	 *
	 * @return string Filename
	 */
	public static function name( string $object_key ): string {
		return basename( $object_key );
	}

	/**
	 * Get MIME type from filename (e.g., "image/jpeg", "application/pdf")
	 *
	 * @param string $filename Filename
	 *
	 * @return string MIME type
	 */
	public static function mime_type( string $filename ): string {
		if ( empty( $filename ) ) {
			return 'application/octet-stream';
		}

		$filetype = wp_check_filetype( $filename );

		return $filetype['type'] ?: 'application/octet-stream';
	}

	/**
	 * Get file category (image, video, audio, document, archive, other)
	 *
	 * @param string $filename Filename
	 *
	 * @return string File category (lowercase)
	 */
	public static function category( string $filename ): string {
		$mime_type = self::mime_type( $filename );

		if ( str_starts_with( $mime_type, 'image/' ) ) {
			return 'image';
		}

		if ( str_starts_with( $mime_type, 'video/' ) ) {
			return 'video';
		}

		if ( str_starts_with( $mime_type, 'audio/' ) ) {
			return 'audio';
		}

		// Document types
		if ( in_array( $mime_type, [
			'application/pdf',
			'application/msword',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'application/vnd.ms-excel',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'application/vnd.ms-powerpoint',
			'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'text/plain',
			'text/csv',
			'application/rtf'
		], true ) ) {
			return 'document';
		}

		// Archive types
		if ( in_array( $mime_type, [
			'application/zip',
			'application/x-rar-compressed',
			'application/x-tar',
			'application/gzip'
		], true ) ) {
			return 'archive';
		}

		return 'other';
	}

	/**
	 * Check if a file type is allowed by WordPress
	 *
	 * @param string $filename Filename
	 *
	 * @return bool True if a file type is allowed
	 */
	public static function is_allowed_type( string $filename ): bool {
		$filetype = wp_check_filetype( $filename );

		return ! empty( $filetype['type'] ) && ! empty( $filetype['ext'] );
	}

	/**
	 * Resolve a path/URL to a local file path
	 *
	 * @param string $path Path or URL to resolve
	 *
	 * @return string|null Local file path or null if not found
	 */
	public static function resolve_local_path( string $path ): ?string {
		// Check if it's already a valid local path
		if ( file_exists( $path ) ) {
			return $path;
		}

		// Handle file:// URLs
		if ( strpos( $path, 'file://' ) === 0 ) {
			$local = str_replace( 'file://', '', $path );

			return file_exists( $local ) ? $local : null;
		}

		// Try to convert WordPress URLs
		$upload_dir = wp_upload_dir();
		if ( strpos( $path, $upload_dir['baseurl'] ) === 0 ) {
			$local = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $path );

			return file_exists( $local ) ? $local : null;
		}

		// Try site URL
		$site_url = get_site_url();
		if ( strpos( $path, $site_url ) === 0 ) {
			$relative = str_replace( $site_url, '', $path );
			$local    = ABSPATH . ltrim( $relative, '/' );

			return file_exists( $local ) ? $local : null;
		}

		return null;
	}

}