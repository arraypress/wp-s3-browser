<?php
/**
 * WordPress Helpers Trait
 *
 * Provides common WordPress utility methods for post handling and context detection.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Browser;

/**
 * Trait WordPressHelpers
 *
 * Common WordPress utility methods
 */
trait Helpers {

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
	 * Check if post type is allowed based on restrictions
	 *
	 * @param int   $post_id            Post ID to check
	 * @param array $allowed_post_types Array of allowed post types
	 *
	 * @return bool True if post type is allowed
	 */
	protected function is_post_type_allowed( int $post_id, array $allowed_post_types = [] ): bool {
		// If no restrictions, allow all
		if ( empty( $allowed_post_types ) ) {
			return true;
		}

		// No post ID means we can't check
		if ( ! $post_id ) {
			return false;
		}

		$post_type = get_post_type( $post_id );

		return in_array( $post_type, $allowed_post_types, true );
	}

	/**
	 * Check if current context should show functionality based on post type restrictions
	 *
	 * @param array $allowed_post_types Array of allowed post types (empty = allow all)
	 *
	 * @return bool True if context is allowed
	 */
	protected function is_context_allowed( array $allowed_post_types = [] ): bool {
		$post_id = $this->get_current_post_id();

		// If we have post-type restrictions and no post ID, don't allow
		if ( ! $post_id && ! empty( $allowed_post_types ) ) {
			return false;
		}

		// Check post type restrictions if we have a post ID
		if ( $post_id ) {
			return $this->is_post_type_allowed( $post_id, $allowed_post_types );
		}

		// No restrictions and no post ID means allow
		return true;
	}

	/**
	 * Check if a specific post type is in the allowed list
	 *
	 * @param string $post_type         Post type to check
	 * @param array  $allowed_post_types Array of allowed post types (empty = allow all)
	 *
	 * @return bool True if post type is allowed
	 */
	protected function is_specific_post_type_allowed( string $post_type, array $allowed_post_types = [] ): bool {
		return empty( $allowed_post_types ) || in_array( $post_type, $allowed_post_types, true );
	}

	/**
	 * Check if current admin screen is for a specific post type with capability check
	 *
	 * @param string $hook_suffix       Current admin page hook suffix
	 * @param string $post_type         Post type to check for
	 * @param string $capability        Required capability
	 * @param array  $allowed_post_types Array of allowed post types (empty = allow all)
	 *
	 * @return bool True if on correct screen and post-type, false otherwise
	 */
	protected function is_post_type_admin_screen( string $hook_suffix, string $post_type, string $capability = 'edit_posts', array $allowed_post_types = [] ): bool {
		// Only apply on post editing screens
		if ( ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
			return false;
		}

		// Check user capability
		if ( ! current_user_can( $capability ) ) {
			return false;
		}

		// Check screen post-type
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== $post_type ) {
			return false;
		}

		// Check if post type is allowed
		return $this->is_specific_post_type_allowed( $post_type, $allowed_post_types );
	}

	/**
	 * Check if current screen is for a specific post type with capability check
	 *
	 * @param string $post_type         Post type to check for
	 * @param string $capability        Required capability
	 * @param array  $allowed_post_types Array of allowed post types (empty = allow all)
	 *
	 * @return bool True if on correct screen and post-type, false otherwise
	 */
	protected function is_current_post_type_screen( string $post_type, string $capability = 'edit_posts', array $allowed_post_types = [] ): bool {
		$screen = get_current_screen();

		// Check screen post-type
		if ( ! $screen || $screen->post_type !== $post_type ) {
			return false;
		}

		// Check user capability
		if ( ! current_user_can( $capability ) ) {
			return false;
		}

		// Check if post type is allowed
		return $this->is_specific_post_type_allowed( $post_type, $allowed_post_types );
	}

}