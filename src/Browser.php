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

use ArrayPress\S3\Admin\Assets;
use ArrayPress\S3\Admin\Config;
use ArrayPress\S3\Admin\MediaLibrary;
use ArrayPress\S3\Admin\Screen;
use ArrayPress\S3\Admin\Templates;
use ArrayPress\S3\Rest\Controller as RestController;
use ArrayPress\S3\Traits\Shared\Debug;

// Load WP_List_Table if not loaded
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class Browser
 *
 * S3 Media Browser for WordPress that provides a clean interface for browsing and selecting
 * S3-compatible storage files directly within the WordPress media uploader.
 */
class Browser {
	use Debug;

	/**
	 * S3 client the browser reads and writes through.
	 *
	 * @var Client
	 */
	protected Client $client;

	/**
	 * What this browser instance is.
	 *
	 * @var Config
	 */
	protected Config $config;

	/**
	 * Buckets this browser may address; empty means no restriction.
	 *
	 * @var array
	 */
	protected array $allowed_buckets = [];

	/**
	 * Admin pages this browser loads its assets on.
	 *
	 * @var array
	 */
	private array $admin_hook = [];

	/**
	 * Screen tests.
	 *
	 * @var Screen
	 */
	protected Screen $screen;

	/**
	 * Asset loader.
	 *
	 * @var Assets
	 */
	protected Assets $assets;

	/**
	 * Underscore templates for the browser's JavaScript.
	 *
	 * @var Templates
	 */
	protected Templates $templates;

	/**
	 * Media modal tab.
	 *
	 * @var MediaLibrary
	 */
	protected MediaLibrary $media;

	/**
	 * REST surface.
	 *
	 * @var RestController
	 */
	protected RestController $rest;

	/**
	 * Build a browser.
	 *
	 * @param Provider          $provider           Storage provider.
	 * @param string            $access_key         Access key.
	 * @param string            $secret_key         Secret key.
	 * @param array             $allowed_post_types Post types this browser appears for; empty means all.
	 * @param string            $default_bucket     Bucket to open on.
	 * @param string            $capability         Capability required to use it.
	 * @param string|null       $context            Which integration this instance serves.
	 * @param bool              $debug              Whether to emit debug output.
	 * @param string|array|null $admin_hook         Admin page hook(s) to load assets on.
	 */
	public function __construct(
		Provider $provider,
		string $access_key,
		string $secret_key,
		array $allowed_post_types = [],
		string $default_bucket = '',
		string $capability = 'upload_files',
		?string $context = null,
		bool $debug = false,
		$admin_hook = null
	) {
		$this->config = new Config(
			$provider->get_id(),
			$provider->get_label(),
			$capability,
			$default_bucket,
			$allowed_post_types,
			$context
		);

		if ( null !== $admin_hook ) {
			$this->set_admin_hook( $admin_hook );
		}

		$this->client = new Client( $provider, $access_key, $secret_key, true, HOUR_IN_SECONDS, $debug, $context );

		// A scoped token cannot list buckets, so give the client the names we
		// already know: the allow-list if one is set, otherwise the default
		// bucket. Without this the browser shows a listing error for what is a
		// perfectly good, and recommended, configuration.
		$known = $this->get_allowed_buckets() ?: array_filter( [ $default_bucket ] );

		if ( $known ) {
			$this->client->set_known_buckets( $known );
		}

		$this->set_debug( $debug );

		$this->rest = new RestController(
			$this->client,
			$this->config->provider_id,
			$capability,
			$this->config->hook_suffix(),
			// A closure rather than the resolved array: get_allowed_buckets()
			// runs through a filter, and set_allowed_buckets() may be called
			// after construction, so the controller has to ask each time.
			fn(): array => $this->get_allowed_buckets()
		);

		$this->screen    = new Screen( $this->config );
		$this->assets    = new Assets( $this->config, $this->screen, $this->rest, fn(): array => $this->admin_hook );
		$this->templates = new Templates( $capability );
		$this->media     = new MediaLibrary( $this->config, $this->screen, $this->assets, $this->client );

		$this->register_hooks();
	}

	/**
	 * Hook the browser into WordPress.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		add_filter( 'media_upload_tabs', [ $this->media, 'add_media_tab' ] );
		add_action( 'media_upload_' . $this->config->tab_id(), [ $this->media, 'handle_media_tab' ] );
		add_filter( 'media_view_strings', [ $this->media, 'add_media_view_strings' ], 20 );

		add_action( 'admin_enqueue_scripts', [ $this->assets, 'enqueue_settings_assets' ] );
		add_action( 'admin_enqueue_scripts', [ $this->assets, 'enqueue_browser_assets' ] );

		// wp.template() reads its markup from script tags in the document, so
		// they have to be printed even on the media-upload iframe, which does
		// not fire admin_footer.
		add_action( 'admin_footer', [ $this->templates, 'print_templates' ] );
		add_action( 'admin_print_footer_scripts', [ $this->templates, 'print_templates' ] );

		// If rest_api_init has already fired, adding a callback to it now would
		// never run. That happens whenever a consumer builds the Browser late --
		// inside rest_api_init itself, or on a hook that fires after it -- so
		// register straight away instead of silently producing 404s.
		if ( did_action( 'rest_api_init' ) ) {
			$this->rest->register_rest_routes();

			return;
		}

		add_action( 'rest_api_init', [ $this->rest, 'register_rest_routes' ] );
	}

	/**
	 * Restrict this browser to a specific set of buckets
	 *
	 * The bucket arrives with each AJAX request, so this is the only thing
	 * standing between an Author-level user and every bucket the configured
	 * credentials can reach. Set it whenever the browser is pointed at a known
	 * bucket.
	 *
	 * @param string[] $buckets Bucket names. Empty array removes the restriction.
	 *
	 * @return self
	 */
	public function set_allowed_buckets( array $buckets ): self {
		$this->allowed_buckets = array_values( array_filter( array_map( 'strval', $buckets ) ) );

		// Consumers call this after construction, so the client's fallback list
		// has to be updated here too — otherwise a scoped token still fails to
		// list buckets despite the browser knowing exactly which one to show.
		if ( ! empty( $this->allowed_buckets ) ) {
			$this->client->set_known_buckets( $this->allowed_buckets );
		}

		return $this;
	}

	/**
	 * Get the buckets this browser may address
	 *
	 * Filterable so integrations can scope access per user or per context —
	 * e.g. returning a single bucket for non-administrators.
	 *
	 * @return string[] Allowed bucket names; empty means no restriction
	 */
	public function get_allowed_buckets(): array {
		/**
		 * Filter the buckets this browser instance may address.
		 *
		 * @param string[] $allowed_buckets Allowed bucket names. Empty array means no restriction.
		 * @param string   $provider_id     Provider identifier.
		 * @param string   $context         Browser context.
		 */
		return (array) apply_filters(
			's3_browser_allowed_buckets',
			$this->allowed_buckets,
			$this->config->provider_id,
			$this->config->context
		);
	}

	/**
	 * Get a suffix distinguishing this browser instance from another
	 *
	 * An EDD browser and a WooCommerce browser on the same site must not
	 * share REST route bases, asset handles or media tab ids.
	 *
	 * @return string
	 */
	public function get_hook_suffix(): string {
		return $this->config->hook_suffix();
	}

	/**
	 * Get the media uploader tab id for this browser
	 *
	 * @return string
	 */
	public function get_tab_id(): string {
		return $this->config->tab_id();
	}

	/**
	 * Set the admin page hook(s) this browser loads its assets on
	 *
	 * @param string|array $hook Hook suffix, or several.
	 *
	 * @return void
	 */
	public function set_admin_hook( $hook ): void {
		if ( is_string( $hook ) && '' !== $hook ) {
			$this->admin_hook = [ $hook ];

			return;
		}

		$this->admin_hook = is_array( $hook ) ? array_filter( $hook ) : [];
	}

	/**
	 * Whether the current admin page is one this browser loads on
	 *
	 * @param string $hook Hook suffix to test.
	 *
	 * @return bool
	 */
	public function matches_admin_hook( string $hook ): bool {
		return in_array( $hook, $this->admin_hook, true );
	}

	/**
	 * Set an explicit REST namespace for this browser
	 *
	 * Only needed when the automatic derivation is not distinct enough.
	 *
	 * @param string $namespace Namespace, e.g. 'my-plugin/v1'.
	 *
	 * @return self
	 */
	public function set_rest_namespace( string $namespace ): self {
		$this->rest->set_rest_namespace( $namespace );

		return $this;
	}

	/**
	 * Get the REST namespace this browser registers under
	 *
	 * @return string
	 */
	public function get_rest_namespace(): string {
		return $this->rest->get_rest_namespace();
	}

	/**
	 * Get this browser's REST controller
	 *
	 * @return RestController
	 */
	public function rest(): RestController {
		return $this->rest;
	}

	/**
	 * Get the provider ID
	 *
	 * @return string Provider ID
	 */
	public function get_provider_id(): string {
		return $this->config->provider_id;
	}
}
