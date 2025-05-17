<?php
/**
 * S3 Prefix Model
 *
 * Represents an S3 prefix (folder) with enhanced functionality.
 *
 * @package     ArrayPress\S3\Models
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      ArrayPress Team
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Models;

use ArrayPress\S3\Utils\Path;

/**
 * Class S3Prefix
 */
class S3Prefix {

	/**
	 * Prefix path
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Constructor
	 *
	 * @param string $prefix Prefix path
	 */
	public function __construct( string $prefix ) {
		$this->prefix = rtrim( $prefix, '/' ) . '/';
	}

	/**
	 * Get full prefix path
	 *
	 * @return string
	 */
	public function get_prefix(): string {
		return $this->prefix;
	}

	/**
	 * Get folder name (last segment of the path)
	 *
	 * @return string
	 */
	public function get_folder_name(): string {
		return Path::get_folder_name( $this->prefix );
	}

	/**
	 * Get parent prefix
	 *
	 * @return string
	 */
	public function get_parent_prefix(): string {
		return Path::get_parent_directory( $this->prefix );
	}

	/**
	 * Get path parts for breadcrumbs
	 *
	 * @return array
	 */
	public function get_path_parts(): array {
		return Path::get_path_parts( $this->prefix );
	}

	/**
	 * Check if this is a root-level prefix
	 *
	 * @return bool
	 */
	public function is_root_level(): bool {
		return substr_count( $this->prefix, '/' ) <= 1;
	}

	/**
	 * Get admin URL for browsing this prefix
	 *
	 * @param string $bucket     Bucket name
	 * @param string $admin_url  Base admin URL (required)
	 * @param array  $query_args Additional query args to add
	 *
	 * @return string URL for browsing this prefix
	 */
	public function get_admin_url( string $bucket, string $admin_url, array $query_args = [] ): string {
		if ( empty( $admin_url ) ) {
			return '';
		}

		// Merge provided query args with required ones
		$args = array_merge( [
			'bucket' => $bucket,
			'prefix' => $this->prefix
		], $query_args );

		// Add query parameters
		return add_query_arg( $args, $admin_url );
	}

	/**
	 * Convert to array
	 *
	 * @return array
	 */
	public function to_array(): array {
		return [
			'Prefix'       => $this->prefix,
			'FolderName'   => $this->get_folder_name(),
			'ParentPrefix' => $this->get_parent_prefix(),
			'IsRootLevel'  => $this->is_root_level(),
			'PathParts'    => $this->get_path_parts()
		];
	}

	/**
	 * Create from string
	 *
	 * @param string $prefix Prefix string
	 *
	 * @return self
	 */
	public static function from_string( string $prefix ): self {
		return new self( $prefix );
	}

}