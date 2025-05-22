<?php
/**
 * S3 Media Browser - Clean Implementation with Traits
 *
 * @package     ArrayPress\S3
 * @copyright   Copyright (c) 2024, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      ArrayPress
 */

declare( strict_types=1 );

namespace ArrayPress\S3;

use ArrayPress\S3\Abstracts\Provider;
use ArrayPress\S3\Traits\Browser\AjaxHandlers;
use ArrayPress\S3\Traits\Browser\ContentRendering;
use ArrayPress\S3\Traits\Browser\Integrations;
use ArrayPress\S3\Traits\Browser\MediaLibrary;

// Load WP_List_Table if not loaded
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

/**
 * Class Browser
 *
 * S3 Media Browser for WordPress that provides a clean interface for browsing and selecting
 * S3-compatible storage files directly within the WordPress media uploader.
 */
class Browser {

	// Use all the traits
	use AjaxHandlers;
	use ContentRendering;
	use Integrations;
	use MediaLibrary;

	/**
	 * S3 Client instance
	 *
	 * @var Client
	 */
	protected Client $client;

	/**
	 * Storage provider instance
	 *
	 * @var Provider
	 */
	protected Provider $provider;

	/**
	 * Storage provider ID
	 *
	 * @var string
	 */
	protected string $provider_id;

	/**
	 * Storage provider name
	 *
	 * @var string
	 */
	protected string $provider_name;

	/**
	 * List of allowed post types for this browser
	 *
	 * @var array
	 */
	protected array $allowed_post_types = [];

	/**
	 * Default bucket name (if empty, will show bucket selection)
	 *
	 * @var string
	 */
	protected string $default_bucket;

	/**
	 * Default prefix for the default bucket
	 *
	 * @var string
	 */
	protected string $default_prefix;

	/**
	 * Capability required to use this browser
	 *
	 * @var string
	 */
	protected string $capability;

	/**
	 * Constructor
	 *
	 * @param Provider $provider           The storage provider instance
	 * @param string   $access_key         Access key for the storage provider
	 * @param string   $secret_key         Secret key for the storage provider
	 * @param array    $allowed_post_types Optional. Array of post types where this browser should appear. Default
	 *                                     empty (all).
	 * @param string   $default_bucket     Optional. Default bucket to display. Default empty.
	 * @param string   $default_prefix     Optional. Default prefix for the default bucket. Default empty.
	 * @param string   $capability         Optional. Capability required to use this browser. Default 'upload_files'.
	 */
	public function __construct(
		Provider $provider,
		string $access_key,
		string $secret_key,
		array $allowed_post_types = [],
		string $default_bucket = '',
		string $default_prefix = '',
		string $capability = 'upload_files'
	) {
		$this->provider           = $provider;
		$this->provider_id        = $provider->get_id();
		$this->provider_name      = $provider->get_label();
		$this->allowed_post_types = $allowed_post_types;
		$this->default_bucket     = $default_bucket;
		$this->default_prefix     = $default_prefix;
		$this->capability         = $capability;

		// Initialize S3 client
		$this->client = new Client(
			$provider,
			$access_key,
			$secret_key,
			true, // Use cache
			HOUR_IN_SECONDS
		);

		// Register assets for this browser
		register_library_assets( __NAMESPACE__ );

		// Initialize WordPress hooks
		$this->init_hooks();
	}

	/**
	 * Get the provider ID
	 *
	 * @return string Provider ID
	 */
	public function get_provider_id(): string {
		return $this->provider_id;
	}

	/**
	 * Initialize WordPress hooks and filters
	 *
	 * @return void
	 */
	private function init_hooks(): void {
		// Add tab to media uploader
		add_filter( 'media_upload_tabs', [ $this, 'add_media_tab' ] );

		// Register tab content handler
		add_action( 'media_upload_s3_' . $this->provider_id, [ $this, 'handle_media_tab' ] );

		// Enqueue admin scripts and styles
		add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ] );

		// Add media view strings for all post types
		add_filter( 'media_view_strings', [ $this, 'add_media_view_strings' ], 20, 1 );

		// Register AJAX handlers
		add_action( 'wp_ajax_s3_load_more_' . $this->provider_id, [ $this, 'handle_ajax_load_more' ] );
		add_action( 'wp_ajax_s3_toggle_favorite_' . $this->provider_id, [ $this, 'handle_ajax_toggle_favorite' ] );
		add_action( 'wp_ajax_s3_clear_cache_' . $this->provider_id, [ $this, 'handle_ajax_clear_cache' ] );
		add_action( 'wp_ajax_s3_get_upload_url_' . $this->provider_id, [ $this, 'handle_ajax_get_upload_url' ] );
		add_action( 'wp_ajax_s3_delete_object_' . $this->provider_id, [ $this, 'handle_ajax_delete_object' ] );

		// Add plugin integrations
		$this->add_edd_integration();
		$this->add_woocommerce_integration();
	}

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
			// First enqueue the global config
			$config_handle = $this->enqueue_global_config();

			// Enqueue main styles and scripts with dependency on config
			enqueue_library_style( 'css/s3-browser.css', [], null, 'all', '', __NAMESPACE__ );
			$script_handle = enqueue_library_script( 'js/s3-browser.js', [
				'jquery',
				$config_handle
			], null, true, '', __NAMESPACE__ );

			// Enqueue the uploader script and styles
			enqueue_library_script( 'js/s3-upload.js', [
				'jquery',
				$config_handle,
				$script_handle
			], null, true, '', __NAMESPACE__ );
			enqueue_library_style( 'css/s3-upload.css', [], null, 'all', '', __NAMESPACE__ );

			// Localize script data - AssetLoader will prevent duplicate localization
			if ( $script_handle ) {
				$post_id = $this->get_current_post_id();

				// For the main browser script, add comprehensive i18n strings
				$browser_config = [
					'postId'   => $post_id,
					'autoLoad' => apply_filters( 's3_browser_auto_load', false, $this->provider_id ),
					'i18n'     => [
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
					]
				];

				// Localize the main browser script
				localize_library_script( $script_handle, 's3BrowserConfig', $browser_config );
			}
		}
	}

}