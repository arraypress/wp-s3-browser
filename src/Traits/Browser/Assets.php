<?php
/**
 * Browser Assets Management Trait - Updated with I18n Trait and Cleaned Config
 *
 * Handles asset loading and configuration for the S3 Browser using
 * the new simplified JavaScript file structure and organized translations.
 *
 * @package     ArrayPress\S3\Traits\Browser
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Browser;

use ArrayPress\S3\Utils\Mime;

/**
 * Trait Assets
 */
trait Assets {

	use I18n;

	/**
	 * Store the browser script handles for later reference
	 *
	 * @var array
	 */
	private array $browser_script_handles = [];

	/**
	 * Enqueue global configuration script
	 *
	 * @return string Script handle
	 */
	private function enqueue_global_config(): string {
		// Use a consistent handle
		$handle = self::GLOBAL_CONFIG_HANDLE;

		// Register empty script if not already registered
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script( $handle, false );
		}

		// Enqueue if not already enqueued
		if ( ! wp_script_is( $handle ) ) {
			wp_enqueue_script( $handle, false, [ 'jquery' ], '1.0', true );
		}

		// Only localize if not already done
		if ( ! wp_script_is( $handle, 'localized' ) ) {
			// Create minimal shared config with only what's needed
			$shared_config = [
				'providerId'        => $this->get_hook_suffix(),
				'providerName'      => $this->provider_name,
				'baseUrl'           => admin_url( 'media-upload.php' ),
				'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
				'defaultBucket'     => $this->default_bucket,
				'nonce'             => wp_create_nonce( 's3_browser_nonce_' . $this->provider_id ),
				'allowedExtensions' => Mime::get_allowed_extensions( $this->get_context() ),
				'allowedMimeTypes'  => Mime::get_allowed_types( $this->get_context() ),
			];

			// Apply contextual filters
			$shared_config = $this->apply_contextual_filters( 's3_browser_global_config', $shared_config, $this->provider_id );

			// Localize the script
			wp_localize_script( $handle, 'S3BrowserGlobalConfig', $shared_config );
		}

		return $handle;
	}

	/**
	 * Enqueue admin assets for connection testing and admin screens
	 *
	 * @param string $current_hook Current admin page hook suffix
	 */
	public function enqueue_settings_assets( string $current_hook ): void {
		// Only load on the specified admin hook(s)
		if ( empty( $this->admin_hook ) || ! $this->matches_admin_hook( $current_hook ) ) {
			return;
		}

		// Check user capability
		if ( ! current_user_can( $this->capability ) ) {
			return;
		}

		// Enqueue global config first
		$config_handle = $this->enqueue_global_config();

		// Enqueue admin styles
		wp_enqueue_composer_style(
			's3-admin-components',
			__FILE__,
			'css/admin.css'
		);

		// Enqueue connection test script
		wp_enqueue_composer_script(
			's3-connection-test',
			__FILE__,
			'js/admin/connection.js',
			[ 'jquery', $config_handle ]
		);
	}

	/**
	 * Enqueue admin scripts and styles for the S3 browser
	 *
	 * @param string $current_hook Current admin page hook suffix
	 *
	 * @return void
	 */
	public function enqueue_browser_assets( string $current_hook ): void {
		// Check user capability
		if ( ! current_user_can( $this->capability ) ) {
			return;
		}

		// For media upload popup
		if ( $current_hook === 'media-upload-popup' ) {
			$this->enqueue_media_upload_assets();

			return;
		}

		// For post edit pages with allowed post types
		if ( in_array( $current_hook, [ 'post.php', 'post-new.php' ] ) ) {
			$this->enqueue_integration_assets();

			return;
		}

		// For custom admin pages - check if it matches any registered admin hooks
		if ( $this->matches_admin_hook( $current_hook ) ) {
			$this->enqueue_custom_admin_page_assets();
		}
	}

	/**
	 * Enqueue assets for custom admin pages (non-post-type pages)
	 *
	 * @return void
	 */
	private function enqueue_custom_admin_page_assets(): void {
		// First enqueue the global config
		$config_handle = $this->enqueue_global_config();

		// Enqueue main styles and scripts (includes upload and CORS)
		$this->enqueue_core_browser_assets( $config_handle );

		// Localize the main browser script
		$this->localize_browser_script();
	}

	/**
	 * Enqueue assets specifically for media upload popup
	 *
	 * @return void
	 */
	private function enqueue_media_upload_assets(): void {
		// First enqueue the global config
		$config_handle = $this->enqueue_global_config();

		// Enqueue main styles and scripts (includes upload and CORS)
		$this->enqueue_core_browser_assets( $config_handle );

		// Localize the main browser script
		$this->localize_browser_script();
	}

	/**
	 * Enqueue integration assets for post edit pages
	 *
	 * @return void
	 */
	private function enqueue_integration_assets(): void {
		// First enqueue the global config
		$config_handle = $this->enqueue_global_config();

		// Enqueue integration-specific scripts based on context
		$post_type = get_post_type();

		if ( $post_type === 'product' ) {
			// WooCommerce integration
			wp_enqueue_composer_script(
				's3-browser-woocommerce',
				__FILE__,
				'js/integrations/woocommerce.js',
				[ 'jquery', $config_handle ]
			);
		} elseif ( $post_type === 'download' ) {
			// EDD integration
			wp_enqueue_composer_script(
				's3-browser-edd',
				__FILE__,
				'js/integrations/easy-digital-downloads.js',
				[ 'jquery', $config_handle ]
			);
		}
	}

	/**
	 * Enqueue core browser assets (CSS and main JS files)
	 *
	 * @param string $config_handle Global config script handle
	 *
	 * @return bool True on success, false on failure
	 */
	private function enqueue_core_browser_assets( string $config_handle ): bool {
		// Enqueue main browser styles
		wp_enqueue_composer_style(
			's3-browser-style',
			__FILE__,
			'css/browser.css'
		);

		// Enqueue upload styles
		wp_enqueue_composer_style(
			's3-upload-style',
			__FILE__,
			'css/upload.css'
		);

		// Define script loading order and dependencies
		$scripts = [
			's3-browser-core'         => [
				'file' => 'js/browser/core.js',
				'deps' => [ 'jquery', $config_handle ]
			],
			's3-browser-modals'       => [
				'file' => 'js/browser/modal.js',
				'deps' => [ 'jquery', 's3-browser-core' ]
			],
			's3-browser-files'        => [
				'file' => 'js/browser/files.js',
				'deps' => [ 'jquery', 's3-browser-core', 's3-browser-modals' ]
			],
			's3-browser-folders'      => [
				'file' => 'js/browser/folders.js',
				'deps' => [ 'jquery', 's3-browser-core', 's3-browser-modals' ]
			],
			's3-browser-integrations' => [
				'file' => 'js/browser/integrations.js',
				'deps' => [ 'jquery', 's3-browser-core' ]
			],
			's3-browser-cors'         => [
				'file' => 'js/browser/buckets.js',
				'deps' => [ 'jquery', 's3-browser-core', 's3-browser-modals' ]
			],
			's3-upload-script'        => [
				'file' => 'js/browser/upload.js',
				'deps' => [ 'jquery', $config_handle, 's3-browser-core' ]
			]
		];

		$all_enqueued = true;

		// Enqueue scripts in order
		foreach ( $scripts as $handle => $script_config ) {
			$success = wp_enqueue_composer_script(
				$handle,
				__FILE__,
				$script_config['file'],
				$script_config['deps']
			);

			if ( $success ) {
				$this->browser_script_handles[] = $handle;
			} else {
				$all_enqueued = false;
			}
		}

		return $all_enqueued;
	}

	/**
	 * Localize the main browser script with configuration and translations
	 *
	 * @return void
	 */
	private function localize_browser_script(): void {
		// Check if core script was enqueued
		if ( ! in_array( 's3-browser-core', $this->browser_script_handles ) ) {
			return;
		}

		$post_id = $this->get_current_post_id();

		// Build browser configuration
		$browser_config = [
			'postId'   => $post_id,
			'autoLoad' => apply_filters( 's3_browser_auto_load', false, $this->provider_id ),
			'i18n'     => $this->get_browser_translations()
		];

		// Apply contextual filters
		$browser_config = $this->apply_contextual_filters( 's3_browser_config', $browser_config, $this->provider_id );

		// Localize the core script
		wp_localize_script( 's3-browser-core', 's3BrowserConfig', $browser_config );
	}

}