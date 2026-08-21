<?php
/**
 * S3 Object Model - Streamlined Edition
 *
 * Represents an S3 object with consolidated methods for better performance.
 *
 * @package     ArrayPress\S3\Models
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Models;

use ArrayPress\S3\Utils\File;
use WP_Error;

/**
 * Class S3Object
 */
class S3Object {

	/**
	 * Object key
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Object size in bytes
	 *
	 * @var int
	 */
	private int $size;

	/**
	 * Last modified timestamp
	 *
	 * @var string
	 */
	private string $last_modified;

	/**
	 * ETag value (cleaned, without quotes)
	 *
	 * @var string
	 */
	private string $etag;

	/**
	 * Storage class
	 *
	 * @var string
	 */
	private string $storage_class;


	/**
	 * Constructor
	 *
	 * @param array $data Object data from S3 API
	 */
	public function __construct( array $data ) {
		$this->key           = $data['Key'] ?? '';
		$this->size          = (int) ( $data['Size'] ?? 0 );
		$this->last_modified = $data['LastModified'] ?? '';
		$this->etag          = isset( $data['ETag'] ) ? trim( $data['ETag'], '"' ) : '';
		$this->storage_class = $data['StorageClass'] ?? 'STANDARD';
	}


	/**
	 * Check if this object should be excluded from display
	 *
	 * @param string $current_prefix The current prefix/path being browsed
	 *
	 * @return bool True if the object should be excluded, false otherwise
	 */
	public function should_be_excluded( string $current_prefix = '' ): bool {
		// Always exclude objects with empty keys
		if ( empty( $this->key ) ) {
			return true;
		}

		// Exclude folder markers (keys ending with '/')
		if ( substr( $this->key, - 1 ) === '/' ) {
			return true;
		}

		// If this object is the current prefix (folder), don't exclude it
		if ( $this->key === $current_prefix ) {
			return false;
		}

		// Exclude zero-size files (empty files)
		if ( $this->size === 0 ) {
			return true;
		}

		// Exclude system or hidden files
		$filename     = $this->get_filename();
		$hidden_files = [
			'.DS_Store',
			'Thumbs.db',
			'.htaccess',
			'.git',
			'.svn',
			'.tmp',
			'.gitignore',
			'.gitkeep',
			'desktop.ini',
			'Icon\r',
			'.localized',
			'__MACOSX',
			'.fseventsd',
			'.Spotlight-V100',
			'.Trashes',
			'._.DS_Store',
			'$RECYCLE.BIN'
		];

		$hidden_files = apply_filters( 's3_object_hidden_files', $hidden_files, $this->key, $current_prefix );

		if ( in_array( $filename, $hidden_files, true ) ) {
			return true;
		}

		// Exclude files that start with . (hidden files)
		if ( strpos( $filename, '.' ) === 0 && strlen( $filename ) > 1 ) {
			return true;
		}

		return false;
	}

	/**
	 * Get HTML data attributes for modal/JavaScript use
	 *
	 * @return string HTML data attributes string
	 */
	public function get_data_attributes(): string {
		$data_attrs = [
			'data-filename'           => esc_attr( $this->get_filename() ),
			'data-key'                => esc_attr( $this->get_key() ),
			'data-size-bytes'         => esc_attr( $this->get_size() ), // Raw bytes
			'data-size-formatted'     => esc_attr( $this->get_size( true ) ), // Formatted
			'data-modified'           => esc_attr( $this->get_last_modified() ), // Raw
			'data-modified-formatted' => esc_attr( $this->get_last_modified( true ) ), // Formatted
			'data-etag'               => esc_attr( $this->get_etag() ),
			'data-md5'                => esc_attr( $this->get_md5_checksum() ?: '' ),
			'data-is-multipart'       => $this->is_multipart() ? 'true' : 'false',
			'data-storage-class'      => esc_attr( $this->get_storage_class() ),
			'data-mime-type'          => esc_attr( $this->get_mime_type() ),
			'data-category'           => esc_attr( $this->get_category() ),
		];

		// Add multipart info if applicable
		if ( $this->is_multipart() ) {
			$multipart_info = $this->get_multipart_info();
			if ( $multipart_info ) {
				$data_attrs['data-part-count'] = esc_attr( (string) $multipart_info['part_count'] );
			}
		}

		return implode( ' ', array_map( function ( $key, $value ) {
			return sprintf( '%s="%s"', $key, $value );
		}, array_keys( $data_attrs ), $data_attrs ) );
	}

	/**
	 * Convert to array
	 *
	 * @return array
	 */
	public function to_array(): array {
		return [
			'Key'           => $this->key,
			'Filename'      => $this->get_filename(),
			'LastModified'  => $this->last_modified,
			'FormattedDate' => $this->get_last_modified( true ),
			'ETag'          => $this->etag,
			'Size'          => $this->size,
			'FormattedSize' => $this->get_size( true ),
			'StorageClass'  => $this->storage_class,
			'MimeType'      => $this->get_mime_type(),
			'Category'      => $this->get_category(),
			'IsMultipart'   => $this->is_multipart(),
			'MD5Checksum'   => $this->get_md5_checksum()
		];
	}

}