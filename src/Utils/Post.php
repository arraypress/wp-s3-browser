<?php
/**
 * Post Utility Class
 *
 * WordPress post-related utilities for S3 operations.
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
 * Class Post
 *
 * Handles WordPress post-related operations
 */
class Post {

	/**
	 * Resolve folder name from identifier
	 *
	 * Accepts a post ID, slug, or custom string and returns
	 * a sanitized folder name suitable for S3 paths.
	 *
	 * @param string|int $identifier The identifier (ID, slug, or custom string)
	 *
	 * @return string Sanitized folder name
	 */
	public static function resolve_folder_name( $identifier ): string {
		// If not numeric, sanitize and use as-is
		if ( ! is_numeric( $identifier ) ) {
			return sanitize_title( (string) $identifier );
		}

		// Try to get post slug, fallback to ID
		$post = get_post( $identifier );
		if ( $post && ! empty( $post->post_name ) ) {
			return $post->post_name;
		}

		return (string) $identifier;
	}

	/**
	 * Check if a post has been migrated to S3
	 *
	 * @param int $post_id Post ID
	 *
	 * @return bool True if migrated
	 */
	public static function is_migrated( int $post_id ): bool {
		return ! empty( get_post_meta( $post_id, '_s3_migration_date', true ) );
	}

	/**
	 * Get S3 migration info for a post
	 *
	 * @param int $post_id Post ID
	 *
	 * @return array Migration info or empty array
	 */
	public static function get_migration_info( int $post_id ): array {
		if ( ! self::is_migrated( $post_id ) ) {
			return [];
		}

		return [
			'date'   => get_post_meta( $post_id, '_s3_migration_date', true ),
			'bucket' => get_post_meta( $post_id, '_s3_migration_bucket', true ),
			'folder' => get_post_meta( $post_id, '_s3_migration_folder', true ),
			'files'  => get_post_meta( $post_id, '_s3_migration_files', true ) ?: []
		];
	}

}