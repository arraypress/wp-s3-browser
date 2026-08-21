<?php
/**
 * Browser Assets
 *
 * Decides what the browser loads on a given admin page, and hands its
 * JavaScript the configuration it needs.
 *
 * @package     ArrayPress\S3\Admin
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Admin;

use ArrayPress\S3\Rest\Controller;
use ArrayPress\S3\Utils\Mime;

/**
 * Class Assets
 */
class Assets {

	/**
	 * Handle for the shared configuration script.
	 *
	 * One per page regardless of how many browsers are on it, which is why it
	 * carries no instance-specific state beyond the identifiers.
	 */
	private const GLOBAL_CONFIG = 's3-browser-global-config';

	/**
	 * Admin pages that edit a single post.
	 */
	private const EDIT_HOOKS = [ 'post.php', 'post-new.php' ];

	/**
	 * Integration scripts, by the post type whose editor they belong to.
	 */
	// Named for the post type rather than the plugin: the handles and
	// filenames end up in page source and in a distributed ZIP, and neither
	// needs to carry someone else's trademark.
	private const INTEGRATIONS = [
		'product'  => [ 's3-browser-wc', 'js/integrations/wc.js' ],
		'download' => [ 's3-browser-edd', 'js/integrations/edd.js' ],
	];

	/**
	 * The browser scripts, in dependency order.
	 *
	 * @return array Handle => [ file, dependencies ].
	 */
	private static function browser_scripts( string $config_handle ): array {
		return [
			's3-browser-core'         => [ 'js/browser/core.js', [ 'jquery', 'wp-util', $config_handle ] ],
			's3-browser-modals'       => [ 'js/browser/modal.js', [ 'jquery', 'wp-util', 's3-browser-core' ] ],
			's3-browser-files'        => [ 'js/browser/files.js', [ 'jquery', 'wp-util', 's3-browser-core', 's3-browser-modals' ] ],
			's3-browser-folders'      => [ 'js/browser/folders.js', [ 'jquery', 'wp-util', 's3-browser-core', 's3-browser-modals' ] ],
			's3-browser-integrations' => [ 'js/browser/integrations.js', [ 'jquery', 'wp-util', 's3-browser-core' ] ],
			's3-browser-cors'         => [ 'js/browser/buckets.js', [ 'jquery', 'wp-util', 's3-browser-core', 's3-browser-modals' ] ],
			's3-upload-script'        => [ 'js/browser/upload.js', [ 'jquery', 'wp-util', $config_handle, 's3-browser-core' ] ],
		];
	}

	/**
	 * Whether the browser scripts made it onto this page.
	 *
	 * @var bool
	 */
	private bool $browser_loaded = false;

	/**
	 * Build the asset loader for one browser instance.
	 *
	 * @param Config     $config      Browser configuration.
	 * @param Screen     $screen      Screen tests.
	 * @param Controller $rest        REST controller, for the route the JavaScript calls.
	 * @param callable   $admin_hooks Resolver for the admin pages this browser loads on.
	 */
	public function __construct(
		private Config $config,
		private Screen $screen,
		private Controller $rest,
		private $admin_hooks
	) {
	}

	/**
	 * Load the settings-screen assets.
	 *
	 * @param string $hook Current admin page hook suffix.
	 *
	 * @return void
	 */
	public function enqueue_settings_assets( string $hook ): void {
		if ( ! $this->matches_admin_hook( $hook ) || ! $this->config->user_is_allowed() ) {
			return;
		}

		$config_handle = $this->enqueue_global_config();

		arraypress_enqueue_composer_style( 's3-admin-components', __FILE__, 'css/admin.css' );
		arraypress_enqueue_composer_script(
			's3-connection-test',
			__FILE__,
			'js/admin/connection.js',
			[ 'jquery', 'wp-util', $config_handle ]
		);
	}

	/**
	 * Load whatever this admin page needs.
	 *
	 * @param string $hook Current admin page hook suffix.
	 *
	 * @return void
	 */
	public function enqueue_browser_assets( string $hook ): void {
		if ( ! $this->config->user_is_allowed() ) {
			return;
		}

		// The media upload iframe and a custom admin page both host the
		// browser itself; a post editor only needs the script that opens it.
		if ( 'media-upload-popup' === $hook || $this->matches_admin_hook( $hook ) ) {
			$this->enqueue_browser();

			return;
		}

		if ( in_array( $hook, self::EDIT_HOOKS, true ) ) {
			$this->enqueue_integration();
		}
	}

	/**
	 * Register and localize the shared configuration script.
	 *
	 * @return string Handle, for depending on.
	 */
	public function enqueue_global_config(): string {
		$handle = self::GLOBAL_CONFIG;

		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script( $handle, false );
		}

		if ( ! wp_script_is( $handle ) ) {
			wp_enqueue_script( $handle, false, [ 'jquery' ], '1.0', true );
		}

		if ( wp_script_is( $handle, 'localized' ) ) {
			return $handle;
		}

		wp_localize_script( $handle, 'S3BrowserGlobalConfig', $this->config->filter(
			's3_browser_global_config',
			[
				'providerId'        => $this->config->hook_suffix(),
				'providerName'      => $this->config->provider_name,
				'baseUrl'           => admin_url( 'media-upload.php' ),
				// Where to post an insert request, and a token proving it came
				// from this site's own browser. The frame the browser runs in
				// can have an opaque origin, so the receiving page cannot
				// identify the sender by origin alone.
				'adminOrigin'       => $this->admin_origin(),
				'insertToken'       => wp_create_nonce( 's3_browser_insert' ),
				'restUrl'           => esc_url_raw( rest_url( $this->rest->route_path() ) ),
				'restNonce'         => wp_create_nonce( 'wp_rest' ),
				'defaultBucket'     => $this->config->default_bucket,
				'allowedExtensions' => Mime::get_allowed_extensions( $this->config->context ),
				'allowedMimeTypes'  => Mime::get_allowed_types( $this->config->context ),
			],
			$this->config->provider_id
		) );

		return $handle;
	}

	/**
	 * The origin the admin is served from.
	 *
	 * Used as the postMessage target, so an insert request reaches the page
	 * that opened the browser and nowhere else.
	 *
	 * @return string
	 */
	private function admin_origin(): string {
		$parts = wp_parse_url( admin_url() );

		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$origin = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'];

		return empty( $parts['port'] ) ? $origin : $origin . ':' . $parts['port'];
	}

	/**
	 * Whether a hook is one of the admin pages this browser was told about.
	 *
	 * @param string $hook Hook suffix to test.
	 *
	 * @return bool
	 */
	private function matches_admin_hook( string $hook ): bool {
		return in_array( $hook, ( $this->admin_hooks )(), true );
	}

	/**
	 * Load the browser's own styles and scripts, then configure them.
	 *
	 * @return void
	 */
	private function enqueue_browser(): void {
		$config_handle = $this->enqueue_global_config();

		arraypress_enqueue_composer_style( 's3-browser-style', __FILE__, 'css/browser.css' );
		arraypress_enqueue_composer_style( 's3-upload-style', __FILE__, 'css/upload.css' );

		foreach ( self::browser_scripts( $config_handle ) as $handle => [ $file, $deps ] ) {
			if ( arraypress_enqueue_composer_script( $handle, __FILE__, $file, $deps ) && 's3-browser-core' === $handle ) {
				$this->browser_loaded = true;
			}
		}

		$this->localize_browser();
	}

	/**
	 * Load the script that opens the browser from a post editor.
	 *
	 * @return void
	 */
	private function enqueue_integration(): void {
		$post_type = (string) get_post_type();

		if ( ! isset( self::INTEGRATIONS[ $post_type ] ) || ! $this->screen->allows_post_type( $post_type ) ) {
			return;
		}

		[ $handle, $file ] = self::INTEGRATIONS[ $post_type ];

		arraypress_enqueue_composer_script(
			$handle,
			__FILE__,
			$file,
			[ 'jquery', 'wp-util', $this->enqueue_global_config() ]
		);
	}

	/**
	 * Hand the browser script its configuration and translations.
	 *
	 * @return void
	 */
	private function localize_browser(): void {
		if ( ! $this->browser_loaded ) {
			return;
		}

		wp_localize_script( 's3-browser-core', 's3BrowserConfig', $this->config->filter(
			's3_browser_config',
			[
				'postId'   => $this->screen->current_post_id(),
				'autoLoad' => apply_filters( 's3_browser_auto_load', false, $this->config->provider_id ),
				'i18n'     => $this->config->filter( 's3_browser_translations', Translations::all(), $this->config->provider_id ),
			],
			$this->config->provider_id
		) );
	}
}
