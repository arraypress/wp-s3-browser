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
class FileNew {

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
	 * Get file type based on extension
	 *
	 * @param string $filename Filename
	 *
	 * @return string File type description
	 */
	public static function type( string $filename ): string {
		// Use WordPress functions if available
		if ( function_exists( 'wp_check_filetype' ) ) {
			$filetype  = wp_check_filetype( $filename );
			$mime_type = $filetype['type'];

			if ( ! empty( $mime_type ) ) {
				return self::mime_to_friendly_name( $mime_type );
			}
		}

		// Fallback to extension-based detection
		$ext = self::extension( $filename );

		return self::extension_to_friendly_name( $ext );
	}

	/**
	 * Get mime type from filename
	 *
	 * @param string $filename Filename
	 *
	 * @return string MIME type
	 */
	public static function mime_type( string $filename ): string {
		// Handle empty filenames
		if ( empty( $filename ) ) {
			return 'application/octet-stream';
		}

		// Use WordPress functions if available
		if ( function_exists( 'wp_check_filetype' ) ) {
			$filetype = wp_check_filetype( $filename );

			return (string) ( $filetype['type'] ?? 'application/octet-stream' );
		}

		// Simple fallback based on extension
		$ext = self::extension( $filename );

		return self::extension_to_mime_type( $ext );
	}

	/**
	 * Convert MIME type to user-friendly name
	 *
	 * @param string $mime_type MIME type
	 *
	 * @return string Friendly name
	 */
	private static function mime_to_friendly_name( string $mime_type ): string {
		$types_map = [
			'image/jpeg'                                                                => 'Image (JPEG)',
			'image/png'                                                                 => 'Image (PNG)',
			'image/gif'                                                                 => 'Image (GIF)',
			'image/webp'                                                                => 'Image (WebP)',
			'image/svg+xml'                                                             => 'Image (SVG)',
			'application/pdf'                                                           => 'PDF Document',
			'application/msword'                                                        => 'Word Document',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => 'Word Document',
			'application/vnd.ms-excel'                                                  => 'Excel Spreadsheet',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'         => 'Excel Spreadsheet',
			'application/vnd.ms-powerpoint'                                             => 'PowerPoint Presentation',
			'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'PowerPoint Presentation',
			'text/plain'                                                                => 'Text File',
			'application/zip'                                                           => 'ZIP Archive',
			'application/x-rar-compressed'                                              => 'RAR Archive',
			'audio/mpeg'                                                                => 'Audio (MP3)',
			'video/mp4'                                                                 => 'Video (MP4)',
			'text/html'                                                                 => 'HTML Document',
			'text/css'                                                                  => 'CSS File',
			'application/javascript'                                                    => 'JavaScript File',
			'application/json'                                                          => 'JSON File',
			'application/xml'                                                           => 'XML File',
			'text/csv'                                                                  => 'CSV File'
		];

		return $types_map[ $mime_type ] ?? 'File';
	}

	/**
	 * Convert file extension to friendly name
	 *
	 * @param string $ext File extension
	 *
	 * @return string Friendly name
	 */
	private static function extension_to_friendly_name( string $ext ): string {
		$types = [
			'jpg'  => 'Image (JPEG)',
			'jpeg' => 'Image (JPEG)',
			'png'  => 'Image (PNG)',
			'gif'  => 'Image (GIF)',
			'webp' => 'Image (WebP)',
			'svg'  => 'Image (SVG)',
			'pdf'  => 'PDF Document',
			'doc'  => 'Word Document',
			'docx' => 'Word Document',
			'xls'  => 'Excel Spreadsheet',
			'xlsx' => 'Excel Spreadsheet',
			'ppt'  => 'PowerPoint Presentation',
			'pptx' => 'PowerPoint Presentation',
			'txt'  => 'Text File',
			'zip'  => 'ZIP Archive',
			'rar'  => 'RAR Archive',
			'mp3'  => 'Audio (MP3)',
			'mp4'  => 'Video (MP4)',
			'html' => 'HTML Document',
			'css'  => 'CSS File',
			'js'   => 'JavaScript File',
			'json' => 'JSON File',
			'xml'  => 'XML File',
			'csv'  => 'CSV File'
		];

		return $types[ $ext ] ?? 'File';
	}

	/**
	 * Convert file extension to MIME type
	 *
	 * @param string $ext File extension
	 *
	 * @return string MIME type
	 */
	private static function extension_to_mime_type( string $ext ): string {
		$mime_types = [
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'svg'  => 'image/svg+xml',
			'pdf'  => 'application/pdf',
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xls'  => 'application/vnd.ms-excel',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'ppt'  => 'application/vnd.ms-powerpoint',
			'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'txt'  => 'text/plain',
			'zip'  => 'application/zip',
			'rar'  => 'application/x-rar-compressed',
			'mp3'  => 'audio/mpeg',
			'mp4'  => 'video/mp4',
			'html' => 'text/html',
			'css'  => 'text/css',
			'js'   => 'application/javascript',
			'json' => 'application/json',
			'xml'  => 'application/xml',
			'csv'  => 'text/csv'
		];

		return $mime_types[ $ext ] ?? 'application/octet-stream';
	}
}