<?php
/**
 * S3 Media Browser - Clean AJAX Implementation
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
use ArrayPress\S3\Components\Notice;
use ArrayPress\S3\Components\Breadcrumb;
use ArrayPress\S3\Tables\BucketsTable;
use ArrayPress\S3\Tables\ObjectsTable;
use Exception;
use WP_Screen;

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
	 * Storage provider ID
	 *
	 * @var string
	 */
	private string $provider_id;

	/**
	 * Storage provider name
	 *
	 * @var string
	 */
	private string $provider_name;

	/**
	 * List of allowed post types for this browser
	 *
	 * @var array
	 */
	private array $allowed_post_types = [];

	/**
	 * Default bucket name (if empty, will show bucket selection)
	 *
	 * @var string
	 */
	private string $default_bucket;

	/**
	 * Default prefix for the default bucket
	 *
	 * @var string
	 */
	private string $default_prefix;

	/**
	 * Capability required to use this browser
	 *
	 * @var string
	 */
	private string $capability;

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

		// Register AJAX handler - this is the key difference
		add_action( 'wp_ajax_s3_load_more_' . $this->provider_id, [ $this, 'handle_ajax_load_more' ] );

		// Register AJAX handler for favoriting buckets
		add_action( 'wp_ajax_s3_toggle_favorite_' . $this->provider_id, [ $this, 'handle_ajax_toggle_favorite' ] );

		// Register AJAX handler for clearing cache
		add_action( 'wp_ajax_s3_clear_cache_' . $this->provider_id, [ $this, 'handle_ajax_clear_cache' ] );

		add_action( 'wp_ajax_s3_get_upload_url_' . $this->provider_id, [ $this, 'handle_ajax_get_upload_url' ] );

		add_action( 'wp_ajax_s3_delete_object_' . $this->provider_id, [ $this, 'handle_ajax_delete_object' ] );

		// Add plugin integrations
		$this->add_edd_integration();
		$this->add_woocommerce_integration();
	}

	/**
	 * Handle AJAX delete object request
	 *
	 * @return void
	 */
	public function handle_ajax_delete_object(): void {
		// Verify nonce
		if ( ! check_ajax_referer( 's3_browser_nonce_' . $this->provider_id, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed', 'arraypress' ) ] );

			return;
		}

		// Check user capability
		if ( ! current_user_can( $this->capability ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action', 'arraypress' ) ] );

			return;
		}

		// Get parameters
		$bucket     = isset( $_POST['bucket'] ) ? sanitize_text_field( $_POST['bucket'] ) : '';
		$object_key = isset( $_POST['key'] ) ? sanitize_text_field( $_POST['key'] ) : '';

		if ( empty( $bucket ) || empty( $object_key ) ) {
			wp_send_json_error( [ 'message' => __( 'Bucket and object key are required', 'arraypress' ) ] );

			return;
		}

		// Delete the object
		$result = $this->client->delete_object( $bucket, $object_key );

		// Handle WP_Error case
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );

			return;
		}

		// Check if operation was successful using is_successful() method
		// Should always return true for SuccessResponse objects
		if ( $result instanceof \ArrayPress\S3\Interfaces\Response ) {
			if ( ! $result->is_successful() ) {
				wp_send_json_error( [ 'message' => __( 'Failed to delete object', 'arraypress' ) ] );

				return;
			}
		} else {
			// Not a Response object - this should never happen but adding as a fallback
			wp_send_json_error( [ 'message' => __( 'Invalid response from S3 client', 'arraypress' ) ] );

			return;
		}

		// Send successful response
		wp_send_json_success( [
			'message' => __( 'File successfully deleted', 'arraypress' ),
			'bucket'  => $bucket,
			'key'     => $object_key
		] );
	}

	/**
	 * Handle AJAX load more request
	 *
	 * @return void
	 */
	public function handle_ajax_load_more(): void {
		if ( ! current_user_can( $this->capability ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action', 'arraypress' ) ] );

			return;
		}

		// Delegate to the table static method with the client
		ObjectsTable::ajax_load_more( $this->client, $this->provider_id );
	}


	/**
	 * Handle AJAX request for presigned upload URL
	 *
	 * @return void
	 */
	public function handle_ajax_get_upload_url() {
		// Verify nonce and user capability
		if (!check_ajax_referer('s3_browser_nonce_' . $this->provider_id, 'nonce', false)) {
			wp_send_json_error(['message' => __('Security check failed', 'arraypress')]);
			return;
		}

		if (!current_user_can($this->capability)) {
			wp_send_json_error(['message' => __('You do not have permission to perform this action', 'arraypress')]);
			return;
		}

		// Get parameters
		$bucket = isset($_POST['bucket']) ? sanitize_text_field($_POST['bucket']) : '';
		$object_key = isset($_POST['object_key']) ? sanitize_text_field($_POST['object_key']) : '';

		if (empty($bucket) || empty($object_key)) {
			wp_send_json_error(['message' => __('Bucket and object key are required', 'arraypress')]);
			return;
		}

		// Generate a pre-signed PUT URL for uploading
		$response = $this->client->get_presigned_upload_url($bucket, $object_key, 15); // 15 minute expiry

		if (is_wp_error($response)) {
			wp_send_json_error(['message' => $response->get_error_message()]);
			return;
		}

		// Send back the URL
		wp_send_json_success([
			'url' => $response->get_url(),
			'expires' => time() + (15 * 60) // Expiry timestamp
		]);
	}

	/**
	 * Handle AJAX cache clear request
	 *
	 * @return void
	 */
	public function handle_ajax_clear_cache(): void {
		// Verify nonce
		if ( ! check_ajax_referer( 's3_browser_nonce_' . $this->provider_id, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed', 'arraypress' ) ] );

			return;
		}

		// Check user capability
		if ( ! current_user_can( $this->capability ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied', 'arraypress' ) ] );

			return;
		}

		// For simplicity, always clear all cache regardless of type
		$success = $this->client->clear_all_cache();

		if ( $success ) {
			wp_send_json_success( [
				'message' => __( 'Cache cleared successfully', 'arraypress' ),
				'status'  => 'success'  // Add status for notification styling
			] );
		} else {
			wp_send_json_error( [
				'message' => __( 'Failed to clear cache', 'arraypress' ),
				'status'  => 'error'    // Add status for notification styling
			] );
		}
	}

	/**
	 * Handle AJAX toggle favorite request
	 *
	 * @return void
	 */
	public function handle_ajax_toggle_favorite(): void {
		// Verify nonce and user capability
		if ( ! check_ajax_referer( 's3_browser_nonce_' . $this->provider_id, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed', 'arraypress' ) ] );

			return;
		}

		if ( ! current_user_can( $this->capability ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action', 'arraypress' ) ] );

			return;
		}

		// Get and validate parameters
		$bucket = isset( $_POST['bucket'] ) ? sanitize_text_field( $_POST['bucket'] ) : '';
		if ( empty( $bucket ) ) {
			wp_send_json_error( [ 'message' => __( 'Bucket name is required', 'arraypress' ) ] );

			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( [ 'message' => __( 'User not logged in', 'arraypress' ) ] );

			return;
		}

		// Get action and post-type
		$action           = isset( $_POST['favorite_action'] ) ? sanitize_text_field( $_POST['favorite_action'] ) : '';
		$post_type        = isset( $_POST['post_type'] ) ? sanitize_text_field( $_POST['post_type'] ) : 'default';
		$meta_key         = "s3_favorite_{$this->provider_id}_{$post_type}";
		$current_favorite = get_user_meta( $user_id, $meta_key, true );

		// Determine if we're adding or removing
		$should_add = $action === 'add' ||
		              ( $action !== 'remove' && $current_favorite !== $bucket );

		// Always clear existing favorite first
		delete_user_meta( $user_id, $meta_key );

		// Add new favorite if needed
		$result = true;
		if ( $should_add ) {
			$result = update_user_meta( $user_id, $meta_key, $bucket );
			$status = 'added';
		} else {
			$status = 'removed';
		}

		// Send response
		if ( $result ) {
			wp_send_json_success( [
				'message' => $status === 'added'
					? __( 'Bucket set as default', 'arraypress' )
					: __( 'Default bucket removed', 'arraypress' ),
				'status'  => $status,
				'bucket'  => $bucket
			] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Failed to update default bucket', 'arraypress' ) ] );
		}
	}

	/**
	 * Add the S3 tab to the media uploader
	 *
	 * @param array $tabs Current media uploader tabs
	 *
	 * @return array Modified tabs array
	 */
	public function add_media_tab( array $tabs ): array {
		// Check if this tab should be shown for the current context
		if ( ! $this->should_show_tab() ) {
			return $tabs;
		}

		$tabs[ 's3_' . $this->provider_id ] = $this->provider_name;

		return $tabs;
	}

	/**
	 * Check if the S3 tab should be shown for the current context
	 *
	 * @return bool True if tab should be shown, false otherwise
	 */
	private function should_show_tab(): bool {
		// Check if user has the required capability
		if ( ! current_user_can( $this->capability ) ) {
			return false;
		}

		// Get post-ID from request
		$post_id = $this->get_current_post_id();

		// If we have post-type restrictions and no post ID, don't add the tab
		if ( ! $post_id && ! empty( $this->allowed_post_types ) ) {
			return false;
		}

		// Check post type restrictions
		if ( $post_id ) {
			$post_type = get_post_type( $post_id );

			// If allowed post-types are set, only show on those types
			if ( ! empty( $this->allowed_post_types ) && ! in_array( $post_type, $this->allowed_post_types, true ) ) {
				return false;
			}
		}

		// Don't add in specific contexts
		if ( wp_script_is( 'fes_form' ) || wp_script_is( 'cfm_form' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Get the current post ID from various sources
	 *
	 * @return int Post ID or 0 if not found
	 */
	private function get_current_post_id(): int {
		// Check request parameters first
		if ( isset( $_REQUEST['post_id'] ) ) {
			return intval( $_REQUEST['post_id'] );
		}

		// Try to get from global post object
		if ( is_admin() ) {
			global $post;
			if ( $post && is_object( $post ) ) {
				return $post->ID;
			}
		}

		return 0;
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
			// Let AssetLoader handle duplicate prevention
			$css_handle    = enqueue_library_style( 'css/s3-browser.css' );
			$script_handle = enqueue_library_script( 'js/s3-browser.js', [ 'jquery', $config_handle ] );

			// Enqueue the uploader script and styles
			enqueue_library_script( 'js/s3-upload-old.js', [ 'jquery', $config_handle, $script_handle ] );

			// Localize script data - AssetLoader will prevent duplicate localization
			if ( $script_handle ) {
				$post_id = $this->get_current_post_id();

				// For the main browser script, add minimal required config
				$browser_config = [
					'postId'   => $post_id,
					'autoLoad' => apply_filters( 's3_browser_auto_load', false, $this->provider_id ),
					'i18n'     => [
						'uploadFiles'   => __( 'Upload Files', 'arraypress' ),
						'dropFilesHere' => __( 'Drop files here to upload', 'arraypress' ),
						'or'            => __( 'or', 'arraypress' ),
						'chooseFiles'   => __( 'Choose Files', 'arraypress' )
					]
				];

				// Localize the main browser script
				localize_library_script( $script_handle, 's3BrowserConfig', $browser_config );
			}
		}
	}

	/**
	 * Check if integration should be enabled for a specific post type
	 *
	 * @param string $post_type The post type to check
	 *
	 * @return bool True if integration should be enabled, false otherwise
	 */
	private function should_enable_integration_for_post_type( string $post_type ): bool {
		// Check if post type is allowed
		if ( ! empty( $this->allowed_post_types ) && ! in_array( $post_type, $this->allowed_post_types, true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if current screen is for a specific post type
	 *
	 * @param string $hook_suffix Current admin page hook suffix
	 * @param string $post_type   Post type to check for
	 *
	 * @return bool True if on correct screen and post type, false otherwise
	 */
	private function is_post_type_admin_screen( string $hook_suffix, string $post_type ): bool {
		// Only apply on post editing screens
		if ( ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
			return false;
		}

		// Check user capability
		if ( ! current_user_can( $this->capability ) ) {
			return false;
		}

		// Check screen post type
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== $post_type ) {
			return false;
		}

		// Additional check for allowed post types
		return $this->should_enable_integration_for_post_type( $post_type );
	}

	/**
	 * Check if current screen is for a specific post type
	 *
	 * @param WP_Screen|null $screen    The WordPress screen object
	 * @param string         $post_type Post type to check for
	 *
	 * @return bool True if on correct screen and post type, false otherwise
	 */
	private function is_post_type_screen( ?WP_Screen $screen, string $post_type ): bool {
		// Check screen post type
		if ( ! $screen || $screen->post_type !== $post_type ) {
			return false;
		}

		// Check user capability
		if ( ! current_user_can( $this->capability ) ) {
			return false;
		}

		// Additional check for allowed post types
		return $this->should_enable_integration_for_post_type( $post_type );
	}

	/**
	 * Add EDD integration for download post type
	 *
	 * @return void
	 */
	private function add_edd_integration(): void {
		// Check if EDD is active
		if ( ! class_exists( 'Easy_Digital_Downloads' ) ) {
			return;
		}

		// Check if EDD downloads are allowed
		if ( ! $this->should_enable_integration_for_post_type( 'download' ) ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', function ( string $hook_suffix ) {
			if ( ! $this->is_post_type_admin_screen( $hook_suffix, 'download' ) ) {
				return;
			}

			// First enqueue the global config
			$config_handle = $this->enqueue_global_config();

			// Enqueue EDD-specific script with dependency on the config
			enqueue_library_script( 'js/s3-browser-edd.js', [ 'jquery', $config_handle ] );
		} );
	}

	/**
	 * Add WooCommerce integration for product post type
	 *
	 * @return void
	 */
	private function add_woocommerce_integration(): void {
		// Check if WooCommerce is active
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Check if WooCommerce products are allowed
		if ( ! $this->should_enable_integration_for_post_type( 'product' ) ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', function ( string $hook_suffix ) {
			if ( ! $this->is_post_type_admin_screen( $hook_suffix, 'product' ) ) {
				return;
			}

			// First enqueue the global config
			$config_handle = $this->enqueue_global_config();

			// Enqueue WooCommerce-specific script with dependency on the config
			enqueue_library_script( 'js/s3-browser-woocommerce.js', [ 'jquery', $config_handle ] );
		} );

		// Add WooCommerce-specific footer templates
		add_action( 'admin_footer-post.php', [ $this, 'maybe_add_woocommerce_template' ] );
		add_action( 'admin_footer-post-new.php', [ $this, 'maybe_add_woocommerce_template' ] );
	}

	/**
	 * Add WooCommerce media template if on product page
	 *
	 * @return void
	 */
	public function maybe_add_woocommerce_template(): void {
		$screen = get_current_screen();
		if ( ! $this->is_post_type_screen( $screen, 'product' ) ) {
			return;
		}

		$this->add_woocommerce_media_template();
	}

	/**
	 * Add WooCommerce-specific media template
	 *
	 * @return void
	 */
	private function add_woocommerce_media_template(): void {
		?>
        <script type="text/template" id="tmpl-s3-<?php echo esc_attr( $this->provider_id ); ?>-tab">
            <div class="s3-browser-frame-wrapper">
                <iframe src="{{ data.url }}" class="s3-browser-frame"></iframe>
            </div>
        </script>
		<?php
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
	 * Handle the media tab content
	 *
	 * @return void
	 */
	public function handle_media_tab(): void {
		wp_iframe( [ $this, 'render_tab_content' ] );
	}

	/**
	 * Render the main tab content
	 *
	 * @return void
	 */
	public function render_tab_content(): void {
		// Load required WordPress styles and scripts
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'list-tables' );
		wp_enqueue_style( 'common' );
		wp_enqueue_style( 'wp-admin' );
		wp_enqueue_style( 'buttons' );
		wp_enqueue_script( 'jquery' );

		// Load our specific styles and scripts
		$this->admin_enqueue_scripts( 'media-upload-popup' );

		// Get request parameters
		$view   = isset( $_GET['view'] ) ? sanitize_text_field( $_GET['view'] ) : '';
		$bucket = isset( $_GET['bucket'] ) ? sanitize_text_field( $_GET['bucket'] ) : '';
		$prefix = isset( $_GET['prefix'] ) ? sanitize_text_field( $_GET['prefix'] ) : '';

		// If no bucket is specified, determine which bucket to use
		if ( empty( $bucket ) && empty( $view ) ) {
			// Get post type context
			$post_id   = isset( $_REQUEST['post_id'] ) ? intval( $_REQUEST['post_id'] ) : 0;
			$post_type = $post_id ? get_post_type( $post_id ) : 'default';

			// Get the preferred bucket and potentially its prefix
			$bucket_info = $this->get_preferred_bucket( $post_type );
			$bucket      = $bucket_info['bucket'];

			// Set the prefix if we have a bucket and a prefix
			if ( ! empty( $bucket ) ) {
				$view = 'objects';
				if ( ! empty( $bucket_info['prefix'] ) ) {
					$prefix = $bucket_info['prefix'];
				}
			}
		}

		// Determine view type
		$view = ( $view === 'buckets' || empty( $bucket ) ) ? 'buckets' : 'objects';

		// Output content
		echo '<div class="s3-browser-container">';

		if ( $view === 'buckets' ) {
			$this->display_buckets_list();
		} else {
			$this->display_objects_view( $bucket, $prefix );
		}

		// Add upload zone here, after breadcrumbs/navigation
		$this->render_upload_zone();

		echo '</div>';
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

	/**
	 * Display the objects view with breadcrumbs and navigation
	 *
	 * @param string $bucket The bucket name
	 * @param string $prefix The object prefix/path
	 *
	 * @return void
	 */
	private function display_objects_view( string $bucket, string $prefix = '' ): void {
		// Show breadcrumbs
		$this->display_breadcrumbs( $bucket, $prefix );

		// Display objects list
		$this->display_objects_list( $bucket, $prefix );
	}

	/**
	 * Generate breadcrumb navigation
	 *
	 * @param string $bucket Bucket name
	 * @param string $prefix Object prefix/path
	 *
	 * @return void
	 */
	private function display_breadcrumbs( string $bucket, string $prefix = '' ): void {
		$base_url = add_query_arg( [
			'tab'    => 's3_' . $this->provider_id,
			'bucket' => $bucket
		], remove_query_arg( [ 'prefix', 's', 'continuation_token' ] ) );

		$breadcrumb = new Breadcrumb( '›', [ 's3-browser-breadcrumbs' ] );

		// Add root buckets link
		$breadcrumb->add_link(
			$this->get_buckets_url(),
			__( 'Buckets', 'arraypress' ),
			'database',
			[ 's3-breadcrumb-root' ]
		);

		// Add bucket link
		$breadcrumb->add_link(
			$base_url,
			$bucket,
			'category',
			[ 's3-breadcrumb' ]
		);

		// Add prefix path segments
		if ( ! empty( $prefix ) ) {
			$parts        = explode( '/', rtrim( $prefix, '/' ) );
			$current_path = '';

			foreach ( $parts as $i => $part ) {
				if ( empty( $part ) ) {
					continue;
				}

				$current_path .= $part . '/';

				if ( $i === count( $parts ) - 1 ) {
					// Last part is current
					$breadcrumb->set_current( $part, 'category', [ 's3-breadcrumb-current' ] );
				} else {
					// Intermediate parts are links
					$url = add_query_arg( [
						'tab'    => 's3_' . $this->provider_id,
						'bucket' => $bucket,
						'prefix' => $current_path
					] );
					$breadcrumb->add_link( $url, $part, 'category', [ 's3-breadcrumb' ] );
				}
			}
		}

		$breadcrumb->echo();
	}

	/**
	 * Get the URL for the buckets view
	 *
	 * @return string Buckets view URL
	 */
	private function get_buckets_url(): string {
		return add_query_arg(
			[
				'tab'  => 's3_' . $this->provider_id,
				'view' => 'buckets'
			],
			remove_query_arg( [ 'bucket', 'prefix', 's', 'continuation_token' ] )
		);
	}

	/**
	 * Display the buckets list
	 *
	 * @return void
	 */
	private function display_buckets_list(): void {
		// Create and prepare the list table
		$list_table = new BucketsTable( [
			'client'      => $this->client,
			'provider_id' => $this->provider_id
		] );

		// Prepare items
		try {
			$list_table->prepare_items();
		} catch ( Exception $e ) {
			echo Notice::error(
				__( 'Error loading buckets. Please try again.', 'arraypress' ),
				false,
				[ 'class' => 's3-error' ]
			);

			return;
		}

		// Display the list table
		$list_table->display();
	}

	/**
	 * Display the objects list for a specific bucket
	 *
	 * @param string $bucket Bucket name
	 * @param string $prefix Object prefix
	 *
	 * @return void
	 */
	private function display_objects_list( string $bucket, string $prefix = '' ): void {
		// Create and prepare the list table
		$list_table = new ObjectsTable( [
			'client'      => $this->client,
			'bucket'      => $bucket,
			'prefix'      => $prefix,
			'provider_id' => $this->provider_id,
		] );

		// Prepare items
		try {
			$list_table->prepare_items();
		} catch ( Exception $e ) {
			echo Notice::error(
				__( 'Error loading files. Please try again.', 'arraypress' ),
				false,
				[ 'class' => 's3-error' ]
			);

			return;
		}

		// Display the list table
		$list_table->display();
	}

	public function render_upload_zone() {
		// Only show upload zone on object views (not bucket listing)
		if (empty($_GET['bucket'])) {
			return;
		}

		$bucket = sanitize_text_field($_GET['bucket']);
		$prefix = isset($_GET['prefix']) ? sanitize_text_field($_GET['prefix']) : '';

		?>
        <div class="s3-upload-container">
            <div class="s3-upload-header">
                <h3 class="s3-upload-title"><?php esc_html_e('Upload Files', 'arraypress'); ?></h3>
            </div>
            <div class="s3-upload-zone" data-bucket="<?php echo esc_attr($bucket); ?>" data-prefix="<?php echo esc_attr($prefix); ?>">
                <div class="s3-upload-message">
                    <span class="dashicons dashicons-upload"></span>
                    <p><?php esc_html_e('Drop files here to upload', 'arraypress'); ?></p>
                    <p class="s3-upload-or"><?php esc_html_e('or', 'arraypress'); ?></p>
                    <input type="file" multiple class="s3-file-input" id="s3FileUpload">
                    <label for="s3FileUpload" class="button"><?php esc_html_e('Choose Files', 'arraypress'); ?></label>
                </div>
            </div>
            <div class="s3-upload-list"></div>
        </div>
		<?php
	}

}