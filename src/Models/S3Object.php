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

use ArrayPress\S3\Client;
use ArrayPress\S3\Utils\File;
use ArrayPress\S3\Responses\PresignedUrlResponse;
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
	 * Presigned URL (cached)
	 *
	 * @var PresignedUrlResponse|string|null
	 */
	private $presigned_url = null;

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
	 * Get object key
	 *
	 * @return string
	 */
	public function get_key(): string {
		return $this->key;
	}

	/**
	 * Get object filename (without path)
	 *
	 * @return string
	 */
	public function get_filename(): string {
		return File::name( $this->key );
	}

	/**
	 * Get object size
	 *
	 * @param bool $formatted Whether to return formatted size or raw bytes
	 * @param int  $precision Number of decimal places for formatted size
	 *
	 * @return string|int Formatted size string or raw bytes
	 */
	public function get_size( bool $formatted = false, int $precision = 2 ) {
		return $formatted ? size_format( $this->size, $precision ) : $this->size;
	}

	/**
	 * Get last modified date
	 *
	 * @param bool   $formatted Whether to return formatted date or raw timestamp
	 * @param string $format    PHP date format for formatted output
	 *
	 * @return string Raw timestamp or formatted date
	 */
	public function get_last_modified( bool $formatted = false, string $format = 'Y-m-d H:i:s' ): string {
		if ( ! $formatted ) {
			return $this->last_modified;
		}

		return empty( $this->last_modified ) ? '' : date( $format, strtotime( $this->last_modified ) );
	}

	/**
	 * Get ETag (already cleaned of quotes)
	 *
	 * @return string
	 */
	public function get_etag(): string {
		return $this->etag;
	}

	/**
	 * Get MD5 checksum with caveats
	 *
	 * @return string|null MD5 hash if available and reliable, null otherwise
	 */
	public function get_md5_checksum(): ?string {
		if ( empty( $this->etag ) ) {
			return null;
		}

		// For multipart uploads, return the composite hash part
		if ( $this->is_multipart() ) {
			$parts = explode( '-', $this->etag );
			return $parts[0] ?? null;
		}

		// For single-part uploads, ETag IS the MD5 (unless encrypted)
		return $this->etag;
	}

	/**
	 * Check if ETag is likely a reliable MD5 hash
	 *
	 * @return bool True if ETag appears to be a valid MD5 hash
	 */
	public function has_reliable_md5(): bool {
		$md5 = $this->get_md5_checksum();

		if ( ! $md5 ) {
			return false;
		}

		// MD5 hashes are exactly 32 hexadecimal characters
		return preg_match( '/^[a-f0-9]{32}$/i', $md5 ) === 1;
	}

	/**
	 * Check if this is a multipart upload
	 *
	 * @return bool
	 */
	public function is_multipart(): bool {
		return ! empty( $this->etag ) && strpos( $this->etag, '-' ) !== false;
	}

	/**
	 * Get multipart information if applicable
	 *
	 * @return array|null Array with parts info if multipart, null otherwise
	 */
	public function get_multipart_info(): ?array {
		if ( ! $this->is_multipart() ) {
			return null;
		}

		$parts = explode( '-', $this->etag );
		if ( count( $parts ) === 2 && is_numeric( $parts[1] ) ) {
			return [
				'composite_hash' => $parts[0],
				'part_count'     => (int) $parts[1],
				'full_etag'      => $this->etag
			];
		}

		return null;
	}

	/**
	 * Get storage class
	 *
	 * @return string
	 */
	public function get_storage_class(): string {
		return $this->storage_class;
	}

	/**
	 * Get file category (image, video, audio, document, archive, other)
	 *
	 * @return string
	 */
	public function get_category(): string {
		return File::category( $this->get_filename() );
	}

	/**
	 * Get MIME type
	 *
	 * @return string
	 */
	public function get_mime_type(): string {
		return File::mime_type( $this->get_filename() );
	}

	/**
	 * Check if file type is allowed by WordPress
	 *
	 * @return bool
	 */
	public function is_allowed_type(): bool {
		return File::is_allowed_type( $this->get_filename() );
	}

	/**
	 * Get presigned URL for this object
	 *
	 * @param Client $client  S3 Client
	 * @param string $bucket  Bucket name
	 * @param int    $expires Expiry time in minutes
	 *
	 * @return string|WP_Error Presigned URL or error
	 */
	public function get_presigned_url( Client $client, string $bucket, int $expires = 60 ) {
		// Check cache
		if ( $this->presigned_url instanceof PresignedUrlResponse && ! $this->presigned_url->has_expired() ) {
			return $this->presigned_url->get_url();
		}

		// Get new URL and cache
		$response            = $client->get_presigned_url( $bucket, $this->key, $expires );
		$this->presigned_url = $response;

		return $response instanceof PresignedUrlResponse ? $response->get_url() : $response;
	}

	/**
	 * Get admin URL for viewing or downloading this object
	 *
	 * @param string $bucket     Bucket name
	 * @param string $admin_url  Base admin URL (required)
	 * @param array  $query_args Additional query args to add
	 *
	 * @return string URL for this object
	 */
	public function get_admin_url( string $bucket, string $admin_url, array $query_args = [] ): string {
		if ( empty( $admin_url ) ) {
			return '';
		}

		$args = array_merge( [
			'bucket' => $bucket,
			'object' => $this->key,
			'action' => 'view'
		], $query_args );

		return add_query_arg( $args, $admin_url );
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
			'data-size-bytes'         => $this->get_size(), // Raw bytes
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
				$data_attrs['data-part-count'] = $multipart_info['part_count'];
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

	/**
	 * Create from array
	 *
	 * @param array $data Object data
	 *
	 * @return self
	 */
	public static function from_array( array $data ): self {
		return new self( $data );
	}

}