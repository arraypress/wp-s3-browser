<?php
/**
 * Browser Hooks Trait
 *
 * Handles WordPress hooks and filters registration for the S3 Browser.
 *
 * @package     ArrayPress\S3\Traits\Browser
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Browser;

/**
 * Trait Hooks
 */
trait Hooks {

	/**
	 * Initialize WordPress hooks and filters
	 *
	 * @return void
	 */
	private function init_hooks(): void {
		// Register media library handlers
		$this->register_media_library_handlers();

		// Register asset enqueue handlers
		$this->register_asset_handlers();

		// Register AJAX handlers using consistent action names
		$this->register_ajax_handlers();

		// Add plugin integrations
		$this->add_edd_integration();
		$this->add_woocommerce_integration();
	}

	/**
	 * Register media library related handlers
	 *
	 * @return void
	 */
	private function register_media_library_handlers(): void {
		// Add tab to media uploader
		add_filter( 'media_upload_tabs', [ $this, 'add_media_tab' ] );

		// Register tab content handler using the full tab ID
		add_action( 'media_upload_' . $this->get_tab_id(), [ $this, 'handle_media_tab' ] );

		// Add media view strings for all post types
		add_filter( 'media_view_strings', [ $this, 'add_media_view_strings' ], 20 );
	}

	/**
	 * Register asset enqueue handlers
	 *
	 * @return void
	 */
	private function register_asset_handlers(): void {
		// Enqueue admin scripts and styles
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_settings_assets' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_browser_assets' ] );
	}

	/**
	 * Register all AJAX handlers with consistent naming
	 *
	 * @return void
	 */
	private function register_ajax_handlers(): void {
		// Core operations
		add_action( 'wp_ajax_' . $this->get_action_name( 's3_load_more' ), [ $this, 'handle_ajax_load_more' ] );
		add_action( 'wp_ajax_' . $this->get_action_name( 's3_toggle_favorite' ), [ $this, 'handle_ajax_toggle_favorite' ] );
		add_action( 'wp_ajax_' . $this->get_action_name( 's3_clear_cache' ), [ $this, 'handle_ajax_clear_cache' ] );
		add_action( 'wp_ajax_' . $this->get_action_name( 's3_get_upload_url' ), [ $this, 'handle_ajax_get_upload_url' ] );

		// Object operations
		add_action( 'wp_ajax_' . $this->get_action_name( 's3_delete_object' ), [ $this, 'handle_ajax_delete_object' ] );
		add_action( 'wp_ajax_' . $this->get_action_name( 's3_rename_object' ), [ $this, 'handle_ajax_rename_object' ] );
		add_action( 'wp_ajax_' . $this->get_action_name( 's3_get_presigned_url' ), [ $this, 'handle_ajax_get_presigned_url' ] );

		// Folder operations
		add_action( 'wp_ajax_' . $this->get_action_name( 's3_create_folder' ), [ $this, 'handle_ajax_create_folder' ] );
		add_action( 'wp_ajax_' . $this->get_action_name( 's3_delete_folder' ), [ $this, 'handle_ajax_delete_folder' ] );

		// Bucket operations
		add_action( 'wp_ajax_' . $this->get_action_name( 's3_get_bucket_details' ), [ $this, 'handle_ajax_get_bucket_details' ] );

		// CORS operations
		add_action( 'wp_ajax_' . $this->get_action_name( 's3_setup_cors' ), [ $this, 'handle_ajax_setup_cors_upload' ] );
		add_action( 'wp_ajax_' . $this->get_action_name( 's3_delete_cors_configuration' ), [ $this, 'handle_ajax_delete_cors_configuration' ] );

		// Connection test
		add_action( 'wp_ajax_' . $this->get_action_name( 's3_connection_test' ), [ $this, 'handle_ajax_connection_test' ] );
	}

}