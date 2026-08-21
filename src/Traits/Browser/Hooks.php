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

		// Register the REST API routes.
		$this->register_rest_handlers();

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
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_settings_assets' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_browser_assets' ] );

		// wp.template() reads its markup from script tags in the document, so
		// they have to be printed even on the media-upload iframe, which does
		// not fire admin_footer.
		add_action( 'admin_footer', [ $this, 'print_templates' ] );
		add_action( 'admin_print_footer_scripts', [ $this, 'print_templates' ] );
	}

	/**
	 * Register the REST API routes
	 *
	 * @return void
	 */
	private function register_rest_handlers(): void {
		// If rest_api_init has already fired, adding a callback to it now would
		// never run. That happens whenever a consumer builds the Browser late —
		// inside rest_api_init itself, or on a hook that fires after it — so
		// register straight away instead of silently producing 404s.
		if ( did_action( 'rest_api_init' ) ) {
			$this->register_rest_routes();

			return;
		}

		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
	}


}