<?php
/**
 * Browser WordPress Integration Trait
 *
 * Handles WordPress media uploader integration for the S3 Browser.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      ArrayPress Team
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Browser;

/**
 * Trait Tabs
 */
trait MediaLibrary {

	/**
	 * Add the S3 tab to the media uploader
	 *
	 * @param array $tabs Current media uploader tabs
	 *
	 * @return array Modified tabs array
	 */
//	public function add_media_tab( array $tabs ): array {
//		// Check if this tab should be shown for the current context
//		if ( ! $this->should_show_tab() ) {
//			return $tabs;
//		}
//
//		$tabs[ 's3_' . $this->provider_id ] = $this->provider_name;
//
//		return $tabs;
//	}

	/**
	 * Add the S3 tab to the media uploader
	 */
	public function add_media_tab( array $tabs ): array {
		error_log('Browser Debug - add_media_tab() called');
		error_log('Browser Debug - Current tabs: ' . print_r(array_keys($tabs), true));

		// Check if this tab should be shown for the current context
		$should_show = $this->should_show_tab();
		error_log('Browser Debug - should_show_tab(): ' . ($should_show ? 'YES' : 'NO'));

		if ( ! $should_show ) {
			error_log('Browser Debug - Tab not shown, returning original tabs');
			return $tabs;
		}

		$tab_key = 's3_' . $this->provider_id;
		$tabs[ $tab_key ] = $this->provider_name;

		error_log('Browser Debug - Added tab: ' . $tab_key . ' = ' . $this->provider_name);
		error_log('Browser Debug - Final tabs: ' . print_r(array_keys($tabs), true));

		return $tabs;
	}

	/**
	 * Check if the S3 tab should be shown for the current context
	 *
	 * @return bool True if tab should be shown, false otherwise
	 */
	private function should_show_tab(): bool {
		// Check if user has the required capability
		if ( ! current_user_can( $this->capability ) ) {
			return false;
		}

		// Get post-ID from request
		$post_id = $this->get_current_post_id();

		// If we have post-type restrictions and no post ID, don't add the tab
		if ( ! $post_id && ! empty( $this->allowed_post_types ) ) {
			return false;
		}

		// Check post type restrictions
		if ( $post_id ) {
			$post_type = get_post_type( $post_id );

			// If allowed post-types are set, only show on those types
			if ( ! empty( $this->allowed_post_types ) && ! in_array( $post_type, $this->allowed_post_types, true ) ) {
				return false;
			}
		}

		// Don't add in specific contexts
		if ( wp_script_is( 'fes_form' ) || wp_script_is( 'cfm_form' ) ) {
			return false;
		}

		return true;
	}


	/**
	 * Get the current post ID from various sources
	 *
	 * @return int Post ID or 0 if not found
	 */
	private function get_current_post_id(): int {
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
	 * Add media view strings for all contexts
	 *
	 * @param array $strings Current media view strings
	 *
	 * @return array Modified strings array
	 */
	public function add_media_view_strings( array $strings ): array {
		// Add our tab to all media modals
		if ( ! isset( $strings['tabs'] ) ) {
			$strings['tabs'] = [];
		}

		$strings['tabs'][ 's3_' . $this->provider_id ] = $this->provider_name;

		return $strings;
	}

	/**
	 * Handle the media tab content
	 *
	 * @return void
	 */
	public function handle_media_tab(): void {
		wp_iframe( [ $this, 'render_tab_content' ] );
	}

}