<?php
/**
 * Integration Handler Trait
 *
 * Provides common integration functionality for S3 browser with other plugins.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      ArrayPress Team
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Browser;

use WP_Screen;

/**
 * Trait IntegrationHandler
 */
trait IntegrationHandler {
	// Use BaseScreen trait for shared functionality
	use BaseScreen;

	/**
	 * Provider ID
	 *
	 * @var string
	 */
	private string $provider_id;

	/**
	 * List of allowed post types for this browser
	 *
	 * @var array
	 */
	private array $allowed_post_types = [];

	/**
	 * Capability required to use this browser
	 *
	 * @var string
	 */
	private string $capability = 'upload_files';

	/**
	 * Initialize integrations
	 *
	 * @param string $provider_id        Provider ID
	 * @param array  $allowed_post_types Allowed post types
	 * @param string $capability         Required capability
	 *
	 * @return void
	 */
	protected function init_integrations(
		string $provider_id,
		array $allowed_post_types = [],
		string $capability = 'upload_files'
	): void {
		$this->provider_id = $provider_id;
		$this->allowed_post_types = $allowed_post_types;
		$this->capability = $capability;

		// Add plugin integrations
		$this->add_edd_integration();
		$this->add_woocommerce_integration();
	}

	/**
	 * Add EDD integration for download post type
	 *
	 * @return void
	 */
	protected function add_edd_integration(): void {
		// Check if EDD is active
		if ( ! class_exists( 'Easy_Digital_Downloads' ) ) {
			return;
		}

		// Check if EDD downloads are allowed
		if ( ! $this->is_plugin_enabled_for_post_type( 'download' ) ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', function ( string $hook_suffix ) {
			if ( ! $this->is_plugin_screen( $hook_suffix, 'download' ) ) {
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
	protected function add_woocommerce_integration(): void {
		// Check if WooCommerce is active
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Check if WooCommerce products are allowed
		if ( ! $this->is_plugin_enabled_for_post_type( 'product' ) ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', function ( string $hook_suffix ) {
			if ( ! $this->is_plugin_screen( $hook_suffix, 'product' ) ) {
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
		if ( ! $this->is_plugin_wp_screen( $screen, 'product' ) ) {
			return;
		}

		$this->add_woocommerce_media_template();
	}

	/**
	 * Add WooCommerce-specific media template
	 *
	 * @return void
	 */
	protected function add_woocommerce_media_template(): void {
		?>
        <script type="text/template" id="tmpl-s3-<?php echo esc_attr( $this->provider_id ); ?>-tab">
            <div class="s3-browser-frame-wrapper">
                <iframe src="{{ data.url }}" class="s3-browser-frame"></iframe>
            </div>
        </script>
		<?php
	}

	/**
	 * Check if plugin is enabled for a specific post type
	 *
	 * @param string $post_type The post type to check
	 *
	 * @return bool True if plugin is enabled, false otherwise
	 */
	protected function is_plugin_enabled_for_post_type( string $post_type ): bool {
		return $this->is_allowed_post_type( $post_type, $this->allowed_post_types );
	}

	/**
	 * Check if current screen is valid for plugin integration
	 *
	 * @param string $hook_suffix Current admin page hook suffix
	 * @param string $post_type   Post type to check for
	 *
	 * @return bool True if on correct screen and post type, false otherwise
	 */
	protected function is_plugin_screen( string $hook_suffix, string $post_type ): bool {
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

		// Additional check for allowed post types
		return $this->is_plugin_enabled_for_post_type( $post_type );
	}

	/**
	 * Check if provided screen is valid for plugin integration
	 *
	 * @param WP_Screen|null $screen    The WordPress screen object
	 * @param string         $post_type Post type to check for
	 *
	 * @return bool True if on correct screen and post type, false otherwise
	 */
	protected function is_plugin_wp_screen( ?WP_Screen $screen, string $post_type ): bool {
		// Check screen post type
		if ( ! $screen || $screen->post_type !== $post_type ) {
			return false;
		}

		// Check user capability
		if ( ! $this->user_has_capability( $this->capability ) ) {
			return false;
		}

		// Additional check for allowed post types
		return $this->is_plugin_enabled_for_post_type( $post_type );
	}
}