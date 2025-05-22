<?php
/**
 * Browser Assets Management Trait
 *
 * Handles asset loading and configuration for the S3 Browser.
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
 * Trait Assets
 */
trait Assets {

	/**
	 * Enqueue global configuration script
	 *
	 * @return string Script handle
	 */
	private function enqueue_global_config(): string {
		// Use a consistent handle
		$handle = 's3-browser-global-config';

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
			// Get current context
			$post_id   = $this->get_current_post_id();
			$post_type = $post_id ? get_post_type( $post_id ) : 'default';

			// Get favorite bucket info
			$preferred_bucket = $this->get_preferred_bucket( $post_type );
			$bucket_to_use    = $preferred_bucket['bucket'] ?: $this->default_bucket;
			$prefix_to_use    = $preferred_bucket['prefix'] ?: $this->default_prefix;

			// Create minimal shared config with only what's needed
			$shared_config = [
				'providerId'    => $this->provider_id,
				'providerName'  => $this->provider_name,
				'baseUrl'       => admin_url( 'media-upload.php' ),
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'defaultBucket' => $bucket_to_use,
				'nonce'         => wp_create_nonce( 's3_browser_nonce_' . $this->provider_id ),
				'ajaxAction'    => 's3_load_more_' . $this->provider_id,
			];

			// Only add favorite and prefix if relevant
			if ( ! empty( $preferred_bucket['bucket'] ) ) {
				$shared_config['favoriteBucket'] = $preferred_bucket['bucket'];
			}

			if ( ! empty( $prefix_to_use ) ) {
				$shared_config['defaultPrefix'] = $prefix_to_use;
			}

			// Localize the script
			wp_localize_script( $handle, 'S3BrowserGlobalConfig', $shared_config );
		}

		return $handle;
	}

	/**
	 * Enqueue admin scripts and styles for the S3 browser
	 *
	 * @param string $hook_suffix Current admin page hook suffix
	 *
	 * @return void
	 */
	public function admin_enqueue_scripts( string $hook_suffix ): void {
		// Check user capability
		if ( ! current_user_can( $this->capability ) ) {
			return;
		}

		// For media upload popup
		if ( $hook_suffix === 'media-upload-popup' ) {
			$this->enqueue_media_upload_assets();
		}
	}

	/**
	 * Enqueue assets specifically for media upload popup
	 *
	 * @return void
	 */
	private function enqueue_media_upload_assets(): void {
		// First enqueue the global config
		$config_handle = $this->enqueue_global_config();

		// Enqueue main styles and scripts
		$this->enqueue_core_browser_assets( $config_handle );

		// Enqueue upload functionality
		$this->enqueue_upload_assets( $config_handle );

		// Localize the main browser script
		$this->localize_browser_script();
	}

	/**
	 * Enqueue core browser assets (CSS and main JS)
	 *
	 * @param string $config_handle Global config script handle
	 *
	 * @return string|false Main script handle on success, false on failure
	 */
	private function enqueue_core_browser_assets( string $config_handle ) {
		// Enqueue main browser styles
		enqueue_library_style( 'css/s3-browser.css', [], null, 'all', '', 'ArrayPress\\S3' );

		// Enqueue main browser script
		$script_handle = enqueue_library_script(
			'js/s3-browser.js',
			[ 'jquery', $config_handle ],
			null,
			true,
			'',
			'ArrayPress\\S3'
		);

		return $script_handle;
	}

	/**
	 * Enqueue upload-related assets
	 *
	 * @param string $config_handle Global config script handle
	 *
	 * @return void
	 */
	private function enqueue_upload_assets( string $config_handle ): void {
		// Get the main browser script handle for dependency
		$main_script_handle = $this->get_browser_script_handle();

		// Enqueue upload script
		enqueue_library_script(
			'js/s3-upload.js',
			[ 'jquery', $config_handle, $main_script_handle ],
			null,
			true,
			'',
			'ArrayPress\\S3'
		);

		// Enqueue upload styles
		enqueue_library_style( 'css/s3-upload.css', [], null, 'all', '', 'ArrayPress\\S3' );
	}

	/**
	 * Get the main browser script handle
	 *
	 * @return string
	 */
	private function get_browser_script_handle(): string {
		// This should match what the AssetLoader generates for js/s3-browser.js
		return 'arraypress-s3-s3-browser';
	}

	/**
	 * Localize the main browser script with configuration and translations
	 *
	 * @return void
	 */
	private function localize_browser_script(): void {
		$script_handle = $this->get_browser_script_handle();

		if ( ! $script_handle || ! wp_script_is( $script_handle, 'enqueued' ) ) {
			return;
		}

		$post_id = $this->get_current_post_id();

		// Build browser configuration
		$browser_config = [
			'postId'   => $post_id,
			'autoLoad' => apply_filters( 's3_browser_auto_load', false, $this->provider_id ),
			'i18n'     => $this->get_browser_translations()
		];

		// Localize the script
		localize_library_script( $script_handle, 's3BrowserConfig', $browser_config );
	}

	/**
	 * Get all browser translation strings
	 *
	 * @return array Comprehensive translation array
	 */
	private function get_browser_translations(): array {
		return [
			// Browser UI strings
			'uploadFiles'      => __( 'Upload Files', 'arraypress' ),
			'dropFilesHere'    => __( 'Drop files here to upload', 'arraypress' ),
			'or'               => __( 'or', 'arraypress' ),
			'chooseFiles'      => __( 'Choose Files', 'arraypress' ),
			'waitForUploads'   => __( 'Please wait for uploads to complete before closing', 'arraypress' ),

			// File operation strings
			'confirmDelete'    => __( 'Are you sure you want to delete "{filename}"?\n\nThis action cannot be undone.', 'arraypress' ),
			'deleteSuccess'    => __( 'File successfully deleted', 'arraypress' ),
			'deleteError'      => __( 'Failed to delete file', 'arraypress' ),

			// Cache and refresh
			'cacheRefreshed'   => __( 'Cache refreshed successfully', 'arraypress' ),
			'refreshError'     => __( 'Failed to refresh data', 'arraypress' ),

			// Loading and errors
			'loadingText'      => __( 'Loading...', 'arraypress' ),
			'loadMoreItems'    => __( 'Load More Items', 'arraypress' ),
			'loadMoreError'    => __( 'Failed to load more items. Please try again.', 'arraypress' ),
			'networkError'     => __( 'Network error. Please try again.', 'arraypress' ),
			'networkLoadError' => __( 'Network error. Please check your connection and try again.', 'arraypress' ),

			// Search results
			'noMatchesFound'   => __( 'No matches found', 'arraypress' ),
			'noFilesFound'     => __( 'No files or folders found matching "{term}"', 'arraypress' ),
			'itemsMatch'       => __( '{visible} of {total} items match', 'arraypress' ),

			// Item counts
			'singleItem'       => __( 'item', 'arraypress' ),
			'multipleItems'    => __( 'items', 'arraypress' ),
			'moreAvailable'    => __( ' (more available)', 'arraypress' ),

			// Favorites
			'favoritesError'   => __( 'Error updating default bucket', 'arraypress' ),
			'setDefault'       => __( 'Set Default', 'arraypress' ),
			'defaultText'      => __( 'Default', 'arraypress' ),

			// Upload specific translations
			'upload'           => [
				'cancelUploadConfirm' => __( 'Are you sure you want to cancel "{filename}"?', 'arraypress' ),
				'uploadFailed'        => __( 'Upload failed:', 'arraypress' ),
				'uploadComplete'      => __( 'Uploads completed. Refreshing file listing...', 'arraypress' ),
				'corsError'           => __( 'CORS configuration error - Your bucket needs proper CORS settings to allow uploads from this domain.', 'arraypress' ),
				'networkError'        => __( 'Network error detected. Please check your internet connection and try again.', 'arraypress' ),
				'failedPresignedUrl'  => __( 'Failed to get upload URL', 'arraypress' ),
				'uploadFailedStatus'  => __( 'Upload failed with status', 'arraypress' ),
				'uploadCancelled'     => __( 'Upload cancelled', 'arraypress' )
			]
		];
	}

	/**
	 * Helper method to enqueue scripts with explicit namespace (for traits)
	 *
	 * @param string $file    Relative path to the JS file
	 * @param array  $deps    Dependencies
	 * @param string $version Version string
	 *
	 * @return string|false Script handle on success, false on failure
	 */
	protected function enqueue_integration_script( string $file, array $deps = [ 'jquery' ], string $version = '1.0' ) {
		return enqueue_library_script_with_namespace( $file, 'ArrayPress\\S3', $deps );
	}

	/**
	 * Helper method to enqueue styles with explicit namespace (for traits)
	 *
	 * @param string $file    Relative path to the CSS file
	 * @param array  $deps    Dependencies
	 * @param string $version Version string
	 *
	 * @return string|false Style handle on success, false on failure
	 */
	protected function enqueue_integration_style( string $file, array $deps = [], string $version = '1.0' ) {
		return enqueue_library_style_with_namespace( $file, 'ArrayPress\\S3', $deps );
	}

}