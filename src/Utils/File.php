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
	 * Get file extension from filename or object key
	 *
	 * @param string $filename Filename or object key
	 *
	 * @return string File extension (lowercase)
	 */
	public static function extension( string $filename ): string {
		return strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	}

	/**
	 * Get filename from object key
	 *
	 * @param string $object_key Object key
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
		$extension = self::extension( $object_key );

		return ! empty( $extension );
	}

	/**
	 * Get file type description based on extension
	 *
	 * Uses WordPress's wp_check_filetype() for comprehensive file type detection.
	 *
	 * @param string $filename Filename
	 *
	 * @return string File type description
	 */
	public static function type( string $filename ): string {
		$mime_type = self::mime_type( $filename );

		// Convert MIME type to friendly description
		return self::mime_to_description( $mime_type );
	}

	/**
	 * Get MIME type from filename
	 *
	 * Uses WordPress's wp_check_filetype() which handles a comprehensive list
	 * of file types and extensions.
	 *
	 * @param string $filename Filename
	 *
	 * @return string MIME type
	 */
	public static function mime_type( string $filename ): string {
		if ( empty( $filename ) ) {
			return 'application/octet-stream';
		}

		// WordPress handles this comprehensively
		$filetype = wp_check_filetype( $filename );

		return $filetype['type'] ?: 'application/octet-stream';
	}

	/**
	 * Get file extension that WordPress recognizes for this filename
	 *
	 * @param string $filename Filename
	 *
	 * @return string WordPress-recognized extension
	 */
	public static function wp_extension( string $filename ): string {
		if ( empty( $filename ) ) {
			return '';
		}

		$filetype = wp_check_filetype( $filename );

		return $filetype['ext'] ?: '';
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
		$mime_type = self::mime_type( $filename );

		return str_starts_with( $mime_type, 'image/' );
	}

	/**
	 * Check if the file is a video type
	 *
	 * @param string $filename Filename
	 *
	 * @return bool True if a file is a video
	 */
	public static function is_video( string $filename ): bool {
		$mime_type = self::mime_type( $filename );

		return str_starts_with( $mime_type, 'video/' );
	}

	/**
	 * Check if the file is an audio type
	 *
	 * @param string $filename Filename
	 *
	 * @return bool True if a file is audio
	 */
	public static function is_audio( string $filename ): bool {
		$mime_type = self::mime_type( $filename );

		return str_starts_with( $mime_type, 'audio/' );
	}

	/**
	 * Check if the file is a document type
	 *
	 * @param string $filename Filename
	 *
	 * @return bool True if a file is a document
	 */
	public static function is_document( string $filename ): bool {
		$mime_type = self::mime_type( $filename );

		$document_types = [
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
		];

		return in_array( $mime_type, $document_types, true );
	}

	/**
	 * Get file category (image, video, audio, document, archive, other)
	 *
	 * @param string $filename Filename
	 *
	 * @return string File category
	 */
	public static function category( string $filename ): string {
		if ( self::is_image( $filename ) ) {
			return 'image';
		}

		if ( self::is_video( $filename ) ) {
			return 'video';
		}

		if ( self::is_audio( $filename ) ) {
			return 'audio';
		}

		if ( self::is_document( $filename ) ) {
			return 'document';
		}

		$mime_type = self::mime_type( $filename );

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
	 * Convert MIME type to user-friendly description
	 *
	 * @param string $mime_type MIME type
	 *
	 * @return string Friendly description
	 */
	private static function mime_to_description( string $mime_type ): string {
		// Handle broad categories first
		if ( str_starts_with( $mime_type, 'image/' ) ) {
			switch ( $mime_type ) {
				case 'image/jpeg':
					return __( 'JPEG Image', 'arraypress' );
				case 'image/png':
					return __( 'PNG Image', 'arraypress' );
				case 'image/gif':
					return __( 'GIF Image', 'arraypress' );
				case 'image/webp':
					return __( 'WebP Image', 'arraypress' );
				case 'image/svg+xml':
					return __( 'SVG Image', 'arraypress' );
				case 'image/bmp':
					return __( 'BMP Image', 'arraypress' );
				case 'image/tiff':
					return __( 'TIFF Image', 'arraypress' );
				default:
					return __( 'Image', 'arraypress' );
			}
		}

		if ( str_starts_with( $mime_type, 'video/' ) ) {
			switch ( $mime_type ) {
				case 'video/mp4':
					return __( 'MP4 Video', 'arraypress' );
				case 'video/quicktime':
					return __( 'QuickTime Video', 'arraypress' );
				case 'video/x-msvideo':
					return __( 'AVI Video', 'arraypress' );
				case 'video/webm':
					return __( 'WebM Video', 'arraypress' );
				default:
					return __( 'Video', 'arraypress' );
			}
		}

		if ( str_starts_with( $mime_type, 'audio/' ) ) {
			switch ( $mime_type ) {
				case 'audio/mpeg':
					return __( 'MP3 Audio', 'arraypress' );
				case 'audio/wav':
					return __( 'WAV Audio', 'arraypress' );
				case 'audio/ogg':
					return __( 'OGG Audio', 'arraypress' );
				case 'audio/mp4':
					return __( 'M4A Audio', 'arraypress' );
				default:
					return __( 'Audio', 'arraypress' );
			}
		}

		// Handle specific application types
		switch ( $mime_type ) {
			case 'application/pdf':
				return __( 'PDF Document', 'arraypress' );
			case 'application/msword':
				return __( 'Word Document', 'arraypress' );
			case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
				return __( 'Word Document', 'arraypress' );
			case 'application/vnd.ms-excel':
				return __( 'Excel Spreadsheet', 'arraypress' );
			case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
				return __( 'Excel Spreadsheet', 'arraypress' );
			case 'application/vnd.ms-powerpoint':
				return __( 'PowerPoint Presentation', 'arraypress' );
			case 'application/vnd.openxmlformats-officedocument.presentationml.presentation':
				return __( 'PowerPoint Presentation', 'arraypress' );
			case 'application/zip':
				return __( 'ZIP Archive', 'arraypress' );
			case 'application/x-rar-compressed':
				return __( 'RAR Archive', 'arraypress' );
			case 'application/x-tar':
				return __( 'TAR Archive', 'arraypress' );
			case 'application/gzip':
				return __( 'GZIP Archive', 'arraypress' );
			case 'text/plain':
				return __( 'Text File', 'arraypress' );
			case 'text/csv':
				return __( 'CSV File', 'arraypress' );
			case 'text/html':
				return __( 'HTML Document', 'arraypress' );
			case 'text/css':
				return __( 'CSS File', 'arraypress' );
			case 'application/javascript':
				return __( 'JavaScript File', 'arraypress' );
			case 'application/json':
				return __( 'JSON File', 'arraypress' );
			case 'application/xml':
				return __( 'XML File', 'arraypress' );
			case 'application/rtf':
				return __( 'RTF Document', 'arraypress' );
			case 'application/octet-stream':
				return __( 'Binary File', 'arraypress' );
			default:
				return __( 'File', 'arraypress' );
		}
	}

}