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
use ArrayPress\S3\Traits\Browser\Content;
use ArrayPress\S3\Traits\Browser\Integrations;
use ArrayPress\S3\Traits\Browser\MediaLibrary;
use ArrayPress\S3\Traits\Browser\Hooks;
use ArrayPress\S3\Traits\Browser\Helpers;
use ArrayPress\S3\Traits\Shared\Context;
use ArrayPress\S3\Traits\Shared\Debug;

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
	use Content;
	use Integrations;
	use MediaLibrary;
	use Hooks;
	use Helpers;
	use Context;
	use Debug;

	/**
	 * Handle for the global S3 browser configuration script
	 *
	 * This script contains shared configuration data used across
	 * all S3 browser instances and components.
	 *
	 * @var string
	 */
	private const GLOBAL_CONFIG_HANDLE = 's3-browser-global-config';

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
	 * @param Provider    $provider           The storage provider instance
	 * @param string      $access_key         Access key for the storage provider
	 * @param string      $secret_key         Secret key for the storage provider
	 * @param array       $allowed_post_types Optional. Array of post types where this browser should appear. Default empty (all).
	 * @param string      $default_bucket     Optional. Default bucket to display. Default empty.
	 * @param string      $default_prefix     Optional. Default prefix for the default bucket. Default empty.
	 * @param string      $capability         Optional. Capability required to use this browser. Default 'upload_files'.
	 * @param string|null $context            Optional. Context identifier for filtering and customization. Default null.
	 * @param bool        $debug              Optional. Whether to enable debug mode. Default false.
	 */
	public function __construct(
		Provider $provider,
		string $access_key,
		string $secret_key,
		array $allowed_post_types = [],
		string $default_bucket = '',
		string $default_prefix = '',
		string $capability = 'upload_files',
		?string $context = null,
		bool $debug = false
	) {
		$this->provider           = $provider;
		$this->provider_id        = $provider->get_id();
		$this->provider_name      = $provider->get_label();
		$this->allowed_post_types = $allowed_post_types;
		$this->default_bucket     = $default_bucket;
		$this->default_prefix     = $default_prefix;
		$this->capability         = $capability;

		// Set context if provided
		if ( $context !== null ) {
			$this->set_context( $context );
		}

		// Initialize S3 client with debug setting
		$this->client = new Client(
			$provider,
			$access_key,
			$secret_key,
			true, // Use cache
			HOUR_IN_SECONDS,
			$debug,
			$this->get_context()
		);

		// Set debug on Browser instance too
		$this->set_debug( $debug );

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

}