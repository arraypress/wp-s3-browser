<?php
/**
 * Assets Handler Trait
 *
 * Provides common asset management functionality for S3 browser.
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
 * Trait AssetsHandler
 */
trait AssetsHandler {
	// Use BaseScreen trait for shared functionality
	use BaseScreen;

	/**
	 * Provider ID
	 *
	 * @var string
	 */
	private string $provider_id;

	/**
	 * Provider name/label
	 *
	 * @var string
	 */
	private string $provider_name;

	/**
	 * Default bucket
	 *
	 * @var string
	 */
	private string $default_bucket = '';

	/**
	 * Default prefix
	 *
	 * @var string
	 */
	private string $default_prefix = '';

	/**
	 * Capability required to use the browser
	 *
	 * @var string
	 */
	private string $capability = 'upload_files';

	/**
	 * Initialize assets
	 *
	 * @param string $provider_id     Provider ID
	 * @param string $provider_name   Provider name/label
	 * @param string $default_bucket  Optional. Default bucket. Default empty.
	 * @param string $default_prefix  Optional. Default prefix. Default empty.
	 * @param string $capability      Optional. Required capability. Default 'upload_files'.
	 *
	 * @return void
	 */
	protected function init_assets(
		string $provider_id,
		string $provider_name,
		string $default_bucket = '',
		string $default_prefix = '',
		string $capability = 'upload_files'
	): void {
		$this->provider_id = $provider_id;
		$this->provider_name = $provider_name;
		$this->default_bucket = $default_bucket;
		$this->default_prefix = $default_prefix;
		$this->capability = $capability;

		// Register assets for this browser
//		register_library_assets( __NAMESPACE__ );

		// Initialize WordPress hooks
		$this->init_asset_hooks();
	}

	/**
	 * Initialize WordPress hooks and filters for assets
	 *
	 * @return void
	 */
	private function init_asset_hooks(): void {
		// Enqueue admin scripts and styles
		add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ] );

		// Add media view strings for all post types
		add_filter( 'media_view_strings', [ $this, 'add_media_view_strings' ], 20, 1 );
	}

	/**
	 * Enqueue global configuration script
	 *
	 * @return string Script handle
	 */
	protected function enqueue_global_config(): string {
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
		if ( ! $this->user_has_capability( $this->capability ) ) {
			return;
		}

		// For media upload popup
		if ( $hook_suffix === 'media-upload-popup' ) {
			// First enqueue the global config
			$config_handle = $this->enqueue_global_config();

			// Enqueue main styles and scripts with dependency on config
			// Let AssetLoader handle duplicate prevention
			$css_handle    = enqueue_library_style( 'css/s3-browser.css' );
			$script_handle = enqueue_library_script( 'js/s3-browser.js', [ 'jquery', $config_handle ] );

			// Localize script data - AssetLoader will prevent duplicate localization
			if ( $script_handle ) {
				$post_id = $this->get_current_post_id();

				// For the main browser script, add minimal required config
				$browser_config = [
					'postId'   => $post_id,
					'autoLoad' => apply_filters( 's3_browser_auto_load', false, $this->provider_id )
				];

				// Localize the main browser script
				localize_library_script( $script_handle, 's3BrowserConfig', $browser_config );
			}
		}
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
	 * Check if a screen is a post edit screen for a specific post type
	 *
	 * @param string $hook_suffix       Current admin page hook suffix
	 * @param string $post_type         Post type to check for
	 * @param array  $allowed_post_types Array of allowed post types
	 *
	 * @return bool True if on correct screen and post type, false otherwise
	 */
	protected function is_post_edit_screen(
		string $hook_suffix,
		string $post_type,
		array $allowed_post_types = []
	): bool {
		// Only apply on post editing screens
		if ( ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
			return false;
		}

		// Check user capability
		if ( ! $this->user_has_capability( $this->capability ) ) {
			return false;
		}

		// Check screen post type
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== $post_type ) {
			return false;
		}

		// Check if post type is in allowed list
		return $this->is_allowed_post_type( $post_type, $allowed_post_types );
	}

	/**
	 * Get the preferred bucket based on favorites or defaults
	 *
	 * @param string $post_type The current post type context
	 *
	 * @return array Array with 'bucket' and optional 'prefix'
	 */
	protected function get_preferred_bucket( string $post_type = 'default' ): array {
		$user_id = get_current_user_id();
		$result  = [ 'bucket' => '', 'prefix' => '' ];

		if ( $user_id ) {
			// Check for post-type specific favorite
			$meta_key        = "s3_favorite_{$this->provider_id}_{$post_type}";
			$favorite_bucket = get_user_meta( $user_id, $meta_key, true );

			// If no post-type specific favorite, check default favorite
			if ( empty( $favorite_bucket ) && $post_type !== 'default' ) {
				$default_meta_key = "s3_favorite_{$this->provider_id}_default";
				$favorite_bucket  = get_user_meta( $user_id, $default_meta_key, true );
			}

			// If we have a favorite, use it
			if ( ! empty( $favorite_bucket ) ) {
				$result['bucket'] = $favorite_bucket;

				// Note: we could also store favorite prefixes if needed
				return $result;
			}
		}

		// Otherwise use the default bucket if set
		if ( ! empty( $this->default_bucket ) ) {
			$result['bucket'] = $this->default_bucket;
			if ( ! empty( $this->default_prefix ) ) {
				$result['prefix'] = $this->default_prefix;
			}
		}

		return $result;
	}

}