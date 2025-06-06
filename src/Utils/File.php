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
	 * Check if an object key has a file extension
	 *
	 * @param string $object_key Object key to check
	 *
	 * @return bool True if object has a file extension
	 */
	public static function has_extension( string $object_key ): bool {
		return ! empty( self::extension( $object_key ) );
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
	 * Check if the file is an image type
	 *
	 * @param string $filename Filename
	 *
	 * @return bool True if a file is an image
	 */
	public static function is_image( string $filename ): bool {
		return self::category( $filename ) === 'image';
	}

	/**
	 * Check if the file is a video type
	 *
	 * @param string $filename Filename
	 *
	 * @return bool True if a file is a video
	 */
	public static function is_video( string $filename ): bool {
		return self::category( $filename ) === 'video';
	}

	/**
	 * Check if the file is an audio type
	 *
	 * @param string $filename Filename
	 *
	 * @return bool True if a file is audio
	 */
	public static function is_audio( string $filename ): bool {
		return self::category( $filename ) === 'audio';
	}

	/**
	 * Check if the file is a document type
	 *
	 * @param string $filename Filename
	 *
	 * @return bool True if a file is a document
	 */
	public static function is_document( string $filename ): bool {
		return self::category( $filename ) === 'document';
	}

	/**
	 * Check if the file is an archive type
	 *
	 * @param string $filename Filename
	 *
	 * @return bool True if a file is an archive
	 */
	public static function is_archive( string $filename ): bool {
		return self::category( $filename ) === 'archive';
	}

}