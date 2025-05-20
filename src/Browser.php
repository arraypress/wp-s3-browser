<?php
/**
 * S3 Media Browser - Reorganized Implementation with Traits
 *
 * @package     ArrayPress\S3
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      ArrayPress
 */

declare( strict_types=1 );

namespace ArrayPress\S3;

use ArrayPress\S3\Abstracts\Provider;
use ArrayPress\S3\Traits\AjaxHandler;
use ArrayPress\S3\Traits\AssetsHandler;
use ArrayPress\S3\Traits\IntegrationHandler;
use ArrayPress\S3\Traits\UiHandler;
use ArrayPress\S3\Traits\BaseScreen;
use ArrayPress\S3\Tables\ObjectsTable;

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
	// Use the base screen trait first
	use BaseScreen;

	// Include all our specialized traits
	use AjaxHandler;
	use AssetsHandler;
	use IntegrationHandler;
	use UiHandler;

	/**
	 * S3 Client instance
	 *
	 * @var Client
	 */
	private Client $client;

	/**
	 * Storage provider instance
	 *
	 * @var Provider
	 */
	private Provider $provider;

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

		// Initialize all trait components
		$this->init_ajax_handlers( $this->provider_id, $this->capability );
		$this->init_assets( $this->provider_id, $this->provider_name, $this->default_bucket, $this->default_prefix, $this->capability );
		$this->init_integrations( $this->provider_id, $this->allowed_post_types, $this->capability );
		$this->init_ui( $this->provider_id, $this->provider_name );
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
	 * Handle AJAX load more request
	 *
	 * Implementation for the abstract method defined in AjaxHandler trait
	 *
	 * @return void
	 */
	public function handle_ajax_load_more(): void {
		if ( ! $this->user_has_capability( $this->capability ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action', 'arraypress' ) ] );

			return;
		}

		// Delegate to the table static method with the client
		ObjectsTable::ajax_load_more( $this->client, $this->provider_id );
	}
}