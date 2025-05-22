<?php
/**
 * Browser Plugin Integrations Trait
 *
 * Handles third-party plugin integrations for the S3 Browser.
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
 * Trait Integrations
 */
trait Integrations {

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

			// Enqueue EDD-specific script using Assets trait helper
			$this->enqueue_integration_script( 'js/s3-browser-edd.js', [ 'jquery', $config_handle ] );
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

			// Enqueue WooCommerce-specific script using Assets trait helper
			$this->enqueue_integration_script( 'js/s3-browser-woocommerce.js', [ 'jquery', $config_handle ] );
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

}