<?php
/**
 * Base Screen Trait
 *
 * Provides common screen and post type detection functionality that can be shared
 * across multiple traits.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      ArrayPress Team
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits;

use WP_Screen;

/**
 * Trait BaseScreen
 */
trait BaseScreen {

	/**
	 * Get the current post ID from various sources
	 *
	 * @return int Post ID or 0 if not found
	 */
	protected function get_current_post_id(): int {
		// Check request parameters first
		if ( isset( $_REQUEST['post_id'] ) ) {
			return intval( $_REQUEST['post_id'] );
		}

		// Try to get from global post object
		if ( is_admin() ) {
			global $post;
			if ( $post && is_object( $post ) ) {
				return $post->ID;
			}
		}

		return 0;
	}

	/**
	 * Check if post type is in allowed list
	 *
	 * @param string $post_type          The post type to check
	 * @param array  $allowed_post_types Array of allowed post types
	 *
	 * @return bool True if post type is allowed, false otherwise
	 */
	protected function is_allowed_post_type( string $post_type, array $allowed_post_types = [] ): bool {
		// If no restrictions, allow all
		if ( empty( $allowed_post_types ) ) {
			return true;
		}

		return in_array( $post_type, $allowed_post_types, true );
	}

	/**
	 * Check if user has required capability
	 *
	 * @param string $capability The capability to check
	 *
	 * @return bool True if user has capability, false otherwise
	 */
	protected function user_has_capability( string $capability ): bool {
		return current_user_can( $capability );
	}

	/**
	 * Get the URL for the buckets view
	 *
	 * @param string $provider_id The provider ID
	 *
	 * @return string Buckets view URL
	 */
	protected function get_buckets_url( string $provider_id ): string {
		return add_query_arg(
			[
				'tab'  => 's3_' . $provider_id,
				'view' => 'buckets'
			],
			remove_query_arg( [ 'bucket', 'prefix', 's', 'continuation_token' ] )
		);
	}

}