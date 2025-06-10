<?php
/**
 * Browser Plugin Integrations Trait
 *
 * Handles third-party plugin integrations for the S3 Browser using
 * the new WP Composer Assets library for simplified asset management.
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
 * Trait Integrations
 */
trait Integrations {
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
		if ( ! $this->is_specific_post_type_allowed( 'download', $this->allowed_post_types ) ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', function ( string $hook_suffix ) {
			if ( ! $this->is_post_type_admin_screen( $hook_suffix, 'download', $this->capability, $this->allowed_post_types ) ) {
				return;
			}

			// First enqueue the global config
			$config_handle = $this->enqueue_global_config();

			// Enqueue EDD-specific script using WP Composer Assets
			wp_enqueue_script_from_composer_file(
				's3-browser-edd',
				__FILE__,
				'js/s3-browser-edd.js',
				[ 'jquery', $config_handle ]
			);
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
		if ( ! $this->is_specific_post_type_allowed( 'product', $this->allowed_post_types ) ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', function ( string $hook_suffix ) {
			if ( ! $this->is_post_type_admin_screen( $hook_suffix, 'product', $this->capability, $this->allowed_post_types ) ) {
				return;
			}

			// First enqueue the global config
			$config_handle = $this->enqueue_global_config();

			// Enqueue WooCommerce-specific script using WP Composer Assets
			wp_enqueue_script_from_composer_file(
				's3-browser-woocommerce',
				__FILE__,
				'js/s3-browser-woocommerce.js',
				[ 'jquery', $config_handle ]
			);
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
		if ( ! $this->is_current_post_type_screen( 'product', $this->capability, $this->allowed_post_types ) ) {
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