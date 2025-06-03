<?php
/**
 * S3 Object Model - Enhanced with Checksum Support
 *
 * Represents an S3 object with enhanced functionality including checksum verification.
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
	 * Custom metadata from x-amz-meta headers
	 *
	 * @var array
	 */
	private array $metadata;

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
		$this->metadata      = $data['Metadata'] ?? [];
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
	 * Get object size in bytes
	 *
	 * @return int
	 */
	public function get_size(): int {
		return $this->size;
	}

	/**
	 * Get formatted size
	 *
	 * @param int $precision Number of decimal places
	 *
	 * @return string
	 */
	public function get_formatted_size( int $precision = 2 ): string {
		return size_format( $this->size, $precision );
	}

	/**
	 * Get last modified date
	 *
	 * @return string
	 */
	public function get_last_modified(): string {
		return $this->last_modified;
	}

	/**
	 * Get formatted last modified date
	 *
	 * @param string $format PHP date format
	 *
	 * @return string
	 */
	public function get_formatted_date( string $format = 'Y-m-d H:i:s' ): string {
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
	 * Get MD5 checksum from ETag
	 *
	 * For single-part uploads, the ETag IS the MD5 hash.
	 * For multipart uploads, it's a composite hash with a suffix like "-2".
	 *
	 * @return string|null MD5 hash if available, null if multipart
	 */
	public function get_md5_checksum(): ?string {
		// If ETag contains a dash, it's a multipart upload
		if ( strpos( $this->etag, '-' ) !== false ) {
			return null; // Multipart uploads don't have simple MD5
		}

		// For single-part uploads, ETag IS the MD5
		return $this->etag;
	}

	/**
	 * Check if this is a multipart upload
	 *
	 * @return bool
	 */
	public function is_multipart(): bool {
		return strpos( $this->etag, '-' ) !== false;
	}

	/**
	 * Get multipart information if applicable
	 *
	 * @return array|null Array with 'parts' count if multipart, null otherwise
	 */
	public function get_multipart_info(): ?array {
		if ( ! $this->is_multipart() ) {
			return null;
		}

		// Extract parts count from ETag like "hash-2"
		$parts = explode( '-', $this->etag );
		if ( count( $parts ) === 2 && is_numeric( $parts[1] ) ) {
			return [
				'parts'         => (int) $parts[1],
				'composite_etag' => $parts[0]
			];
		}

		return null;
	}

	/**
	 * Get custom metadata value
	 *
	 * @param string $key     Metadata key (without x-amz-meta- prefix)
	 * @param mixed  $default Default value if not found
	 *
	 * @return mixed Metadata value
	 */
	public function get_metadata( string $key, $default = null ) {
		return $this->metadata[ $key ] ?? $default;
	}

	/**
	 * Get all custom metadata
	 *
	 * @return array All metadata key-value pairs
	 */
	public function get_all_metadata(): array {
		return $this->metadata;
	}

	/**
	 * Check if object has custom metadata
	 *
	 * @param string $key Optional specific key to check
	 *
	 * @return bool
	 */
	public function has_metadata( string $key = '' ): bool {
		if ( empty( $key ) ) {
			return ! empty( $this->metadata );
		}

		return isset( $this->metadata[ $key ] );
	}

	/**
	 * Get SHA256 checksum from metadata (if stored during upload)
	 *
	 * @return string|null SHA256 hash if stored, null otherwise
	 */
	public function get_sha256_checksum(): ?string {
		return $this->get_metadata( 'sha256-checksum' ) ?: $this->get_metadata( 'sha256' );
	}

	/**
	 * Get SHA1 checksum from metadata (if stored during upload)
	 *
	 * @return string|null SHA1 hash if stored, null otherwise
	 */
	public function get_sha1_checksum(): ?string {
		return $this->get_metadata( 'sha1-checksum' ) ?: $this->get_metadata( 'sha1' );
	}

	/**
	 * Get upload timestamp from metadata
	 *
	 * @return string|null Upload timestamp if stored, null otherwise
	 */
	public function get_upload_timestamp(): ?string {
		return $this->get_metadata( 'upload-time' ) ?: $this->get_metadata( 'uploaded-at' );
	}

	/**
	 * Get uploader information from metadata
	 *
	 * @return string|null Uploader info if stored, null otherwise
	 */
	public function get_uploader_info(): ?string {
		return $this->get_metadata( 'uploaded-by' ) ?: $this->get_metadata( 'uploader' );
	}

	/**
	 * Get best available checksum
	 *
	 * Returns the most reliable checksum available, preferring:
	 * 1. SHA256 from metadata (most secure)
	 * 2. SHA1 from metadata
	 * 3. MD5 from ETag (for single-part uploads)
	 *
	 * @return array|null Array with 'type' and 'hash', or null if none available
	 */
	public function get_best_checksum(): ?array {
		// Prefer SHA256 if available
		$sha256 = $this->get_sha256_checksum();
		if ( $sha256 ) {
			return [
				'type' => 'sha256',
				'hash' => $sha256
			];
		}

		// Fall back to SHA1
		$sha1 = $this->get_sha1_checksum();
		if ( $sha1 ) {
			return [
				'type' => 'sha1',
				'hash' => $sha1
			];
		}

		// Use MD5 from ETag if single-part
		$md5 = $this->get_md5_checksum();
		if ( $md5 ) {
			return [
				'type' => 'md5',
				'hash' => $md5
			];
		}

		return null;
	}

	/**
	 * Verify object integrity against downloaded content
	 *
	 * @param string $content Downloaded file content
	 *
	 * @return array Verification result with 'verified', 'method', 'expected', 'calculated'
	 */
	public function verify_integrity( string $content ): array {
		$checksum = $this->get_best_checksum();

		if ( ! $checksum ) {
			return [
				'verified'   => null,
				'method'     => 'none',
				'message'    => 'No checksum available for verification',
				'expected'   => null,
				'calculated' => null
			];
		}

		$calculated = hash( $checksum['type'], $content );
		$expected   = $checksum['hash'];
		$verified   = ( $calculated === $expected );

		return [
			'verified'   => $verified,
			'method'     => $checksum['type'],
			'expected'   => $expected,
			'calculated' => $calculated,
			'message'    => $verified
				? sprintf( 'Integrity verified using %s', strtoupper( $checksum['type'] ) )
				: sprintf( 'Integrity check failed using %s', strtoupper( $checksum['type'] ) )
		];
	}

	/**
	 * Get checksum display information for admin tables
	 *
	 * @return array Array with display info
	 */
	public function get_checksum_display(): array {
		$checksum = $this->get_best_checksum();

		if ( ! $checksum ) {
			return [
				'has_checksum' => false,
				'type'         => 'none',
				'short_hash'   => '-',
				'full_hash'    => '',
				'tooltip'      => 'No checksum available'
			];
		}

		$type      = strtoupper( $checksum['type'] );
		$hash      = $checksum['hash'];
		$short     = substr( $hash, 0, 8 ) . '...';

		if ( $this->is_multipart() ) {
			$multipart_info = $this->get_multipart_info();
			$parts_text = $multipart_info ? sprintf( ' (%d parts)', $multipart_info['parts'] ) : '';
			$tooltip = sprintf( 'Multipart %s%s: %s', $type, $parts_text, $hash );
		} else {
			$tooltip = sprintf( '%s: %s', $type, $hash );
		}

		return [
			'has_checksum' => true,
			'type'         => $type,
			'short_hash'   => $short,
			'full_hash'    => $hash,
			'tooltip'      => $tooltip,
			'is_multipart' => $this->is_multipart()
		];
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
	 * Get file type description
	 *
	 * @return string
	 */
	public function get_file_type(): string {
		return File::type( $this->get_filename() );
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
	 * Get file category (image, video, audio, document, archive, other)
	 *
	 * @return string
	 */
	public function get_category(): string {
		return File::category( $this->get_filename() );
	}

	/**
	 * Check if object is an image
	 *
	 * @return bool
	 */
	public function is_image(): bool {
		return File::is_image( $this->get_filename() );
	}

	/**
	 * Check if object is a video
	 *
	 * @return bool
	 */
	public function is_video(): bool {
		return File::is_video( $this->get_filename() );
	}

	/**
	 * Check if object is audio
	 *
	 * @return bool
	 */
	public function is_audio(): bool {
		return File::is_audio( $this->get_filename() );
	}

	/**
	 * Check if object is a document
	 *
	 * @return bool
	 */
	public function is_document(): bool {
		return File::is_document( $this->get_filename() );
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
	 * Get dashicon class for this file type
	 *
	 * @return string Dashicon class
	 */
	public function get_dashicon_class(): string {
		$category = $this->get_category();

		switch ( $category ) {
			case 'image':
				return 'dashicons-format-image';
			case 'video':
				return 'dashicons-media-video';
			case 'audio':
				return 'dashicons-media-audio';
			case 'document':
				return 'dashicons-media-document';
			case 'archive':
				return 'dashicons-media-archive';
			default:
				return 'dashicons-media-default';
		}
	}

	/**
	 * Get CSS class for icon styling
	 *
	 * @return string CSS class
	 */
	public function get_icon_class(): string {
		$category = $this->get_category();

		switch ( $category ) {
			case 'image':
				return 's3-image-icon';
			case 'video':
				return 's3-video-icon';
			case 'audio':
				return 's3-audio-icon';
			case 'document':
				return 's3-document-icon';
			case 'archive':
				return 's3-archive-icon';
			default:
				return '';
		}
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

		// Merge provided query args with required ones
		$args = array_merge( [
			'bucket' => $bucket,
			'object' => $this->key,
			'action' => 'view'
		], $query_args );

		// Add query parameters
		return add_query_arg( $args, $admin_url );
	}

	/**
	 * Check if this object should be excluded from display
	 *
	 * An object should be excluded if:
	 * - It has an empty key
	 * - It has a zero size (empty file), unless it's the current prefix
	 * - It's a system/hidden file like .DS_Store
	 * - It ends with '/' (folder marker)
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

		// Make hidden files list filterable
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
	 * Convert to array
	 *
	 * @return array
	 */
	public function to_array(): array {
		$checksum_info = $this->get_checksum_display();

		return [
			'Key'           => $this->key,
			'Filename'      => $this->get_filename(),
			'LastModified'  => $this->last_modified,
			'FormattedDate' => $this->get_formatted_date(),
			'ETag'          => $this->etag,
			'Size'          => $this->size,
			'FormattedSize' => $this->get_formatted_size(),
			'StorageClass'  => $this->storage_class,
			'Type'          => $this->get_file_type(),
			'MimeType'      => $this->get_mime_type(),
			'Category'      => $this->get_category(),
			'Metadata'      => $this->metadata,
			'IsMultipart'   => $this->is_multipart(),
			'Checksum'      => $checksum_info,
			'BestChecksum'  => $this->get_best_checksum()
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