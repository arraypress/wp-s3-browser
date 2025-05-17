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
	 * Constructor
	 *
	 * @param Provider $provider           The storage provider instance
	 * @param string   $access_key         Access key for the storage provider
	 * @param string   $secret_key         Secret key for the storage provider
	 * @param array    $allowed_post_types Optional. Array of post types where this browser should appear. Default empty (all).
	 * @param string   $default_bucket     Optional. Default bucket to display. Default empty.
	 * @param string   $default_prefix     Optional. Default prefix for the default bucket. Default empty.
	 */
	public function __construct(
		Provider $provider,
		string $access_key,
		string $secret_key,
		array $allowed_post_types = [],
		string $default_bucket = '',
		string $default_prefix = ''
	) {
		$this->provider           = $provider;
		$this->provider_id        = $provider->get_id();
		$this->provider_name      = $provider->get_label();
		$this->allowed_post_types = $allowed_post_types;
		$this->default_bucket     = $default_bucket;
		$this->default_prefix     = $default_prefix;

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

		// Add plugin integrations
		$this->add_edd_integration();
		$this->add_woocommerce_integration();
	}

	/**
	 * Handle AJAX load more request
	 *
	 * @return void
	 */
	public function handle_ajax_load_more(): void {
		// Delegate to the table static method with the client
		ObjectsTable::ajax_load_more( $this->client, $this->provider_id );
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
	 * Enqueue admin scripts and styles for the S3 browser
	 *
	 * @param string $hook_suffix Current admin page hook suffix
	 *
	 * @return void
	 */
	public function admin_enqueue_scripts( string $hook_suffix ): void {
		// For media upload popup
		if ( $hook_suffix === 'media-upload-popup' ) {
			// Enqueue main styles and scripts
			enqueue_library_style( 'css/s3-browser.css' );
			$script_handle = enqueue_library_script( 'js/s3-browser.js' );

			// Localize script data
			if ( $script_handle ) {
				localize_library_script( $script_handle, 's3BrowserConfig', [
					'providerId'    => $this->provider_id,
					'providerName'  => $this->provider_name,
					'baseUrl'       => admin_url( 'media-upload.php' ),
					'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
					'postId'        => $this->get_current_post_id(),
					'defaultBucket' => $this->default_bucket,
					'defaultPrefix' => $this->default_prefix,
					'nonce'         => wp_create_nonce( 's3_browser_nonce_' . $this->provider_id ),
					'ajaxAction'    => 's3_load_more_' . $this->provider_id,
					'autoLoad'      => apply_filters( 's3_browser_auto_load', false, $this->provider_id )
				] );
			}
		}
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
		if ( ! empty( $this->allowed_post_types ) && ! in_array( 'download', $this->allowed_post_types, true ) ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', function ( string $hook_suffix ) {
			if ( ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
				return;
			}

			$screen = get_current_screen();
			if ( ! $screen || $screen->post_type !== 'download' ) {
				return;
			}

			// Additional check for allowed post types
			if ( ! empty( $this->allowed_post_types ) && ! in_array( 'download', $this->allowed_post_types, true ) ) {
				return;
			}

			// Enqueue EDD-specific script
			$script_handle = enqueue_library_script( 'js/s3-browser-edd.js' );

			if ( $script_handle ) {
				localize_library_script( $script_handle, 's3BrowserEDD', [
					'providerId'    => $this->provider_id,
					'providerName'  => $this->provider_name,
					'type'          => 'edd',
					'adminUrl'      => admin_url( 'media-upload.php' ),
					'defaultBucket' => $this->default_bucket,
					'defaultPrefix' => $this->default_prefix,
				] );
			}
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
		if ( ! empty( $this->allowed_post_types ) && ! in_array( 'product', $this->allowed_post_types, true ) ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', function ( string $hook_suffix ) {
			if ( ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
				return;
			}

			$screen = get_current_screen();
			if ( ! $screen || $screen->post_type !== 'product' ) {
				return;
			}

			// Additional check for allowed post types
			if ( ! empty( $this->allowed_post_types ) && ! in_array( 'product', $this->allowed_post_types, true ) ) {
				return;
			}

			// Enqueue WooCommerce-specific script
			$script_handle = enqueue_library_script( 'js/s3-browser-woocommerce.js' );

			if ( $script_handle ) {
				localize_library_script( $script_handle, 's3BrowserWooCommerce', [
					'providerId'    => $this->provider_id,
					'providerName'  => $this->provider_name,
					'type'          => 'woocommerce',
					'adminUrl'      => admin_url( 'media-upload.php' ),
					'defaultBucket' => $this->default_bucket,
					'defaultPrefix' => $this->default_prefix,
				] );
			}
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
		if ( ! $screen || $screen->post_type !== 'product' ) {
			return;
		}

		// Additional check for allowed post types
		if ( ! empty( $this->allowed_post_types ) && ! in_array( 'product', $this->allowed_post_types, true ) ) {
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

		// Handle default bucket logic
		if ( empty( $bucket ) && empty( $view ) && ! empty( $this->default_bucket ) ) {
			$bucket = $this->default_bucket;
			if ( ! empty( $this->default_prefix ) ) {
				$prefix = $this->default_prefix;
			}
			$view = 'objects';
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

		echo '</div>';
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
			$parts = explode( '/', rtrim( $prefix, '/' ) );
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
//			'per_page'    => 50 // Better for AJAX loading
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

}