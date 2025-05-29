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
use ArrayPress\S3\Traits\Browser\Assets;
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
	use AjaxHandlers;
	use Assets;
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
		add_action( 'wp_ajax_s3_create_folder_' . $this->provider_id, [ $this, 'handle_ajax_create_folder' ] );

		// Add plugin integrations
		$this->add_edd_integration();
		$this->add_woocommerce_integration();
	}

}