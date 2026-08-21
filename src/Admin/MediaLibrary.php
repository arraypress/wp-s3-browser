<?php
/**
 * Media Library Tab
 *
 * The browser as it appears inside WordPress's media modal: the tab itself,
 * and everything rendered in the iframe behind it.
 *
 * @package     ArrayPress\S3\Admin
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Admin;

use ArrayPress\Breadcrumbs\Breadcrumbs;
use ArrayPress\S3\Client;
use ArrayPress\S3\Tables\Buckets;
use ArrayPress\S3\Tables\Objects;
use Exception;

/**
 * Class MediaLibrary
 */
class MediaLibrary {

	/*
	 * The nonce warnings below are read-only navigation state. This screen
	 * renders whatever bucket and prefix the query string names; it changes
	 * nothing, and every operation that does change something goes through the
	 * REST controller, which checks a capability and a bucket allow-list on
	 * every route. A nonce here would protect against a cross-site request
	 * that causes a listing to be displayed to the person who was already
	 * looking at listings.
	 *
	 * phpcs:disable WordPress.Security.NonceVerification.Recommended
	 */

	/**
	 * Build the media tab for one browser instance.
	 *
	 * @param Config $config Browser configuration.
	 * @param Screen $screen Screen tests.
	 * @param Assets $assets Asset loader.
	 * @param Client $client Client the listings read through.
	 */
	public function __construct(
		private Config $config,
		private Screen $screen,
		private Assets $assets,
		private Client $client
	) {
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

		$tabs[ $this->config->tab_id() ] = $this->config->provider_name;

		return $tabs;
	}

	/**
	* Check if the S3 tab should be shown for the current context
	*
	* @return bool True if tab should be shown, false otherwise
	*/
	private function should_show_tab(): bool {
		// Check if user has the required capability
		if ( ! $this->config->user_is_allowed() ) {
			return false;
		}

		// Check context and post type restrictions
		if ( ! $this->screen->allows_current_post() ) {
			return false;
		}

		// Don't add in specific contexts
		if ( wp_script_is( 'fes_form' ) || wp_script_is( 'cfm_form' ) ) {
			return false;
		}

		return true;
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

		$strings['tabs'][ $this->config->tab_id() ] = $this->config->provider_name;

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
		$this->assets->enqueue_browser_assets( 'media-upload-popup' );

		// Get request parameters
		$view   = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : '';
		$bucket = isset( $_GET['bucket'] ) ? sanitize_text_field( wp_unslash( $_GET['bucket'] ) ) : '';
		$prefix = isset( $_GET['prefix'] ) ? sanitize_text_field( wp_unslash( $_GET['prefix'] ) ) : '';

		// If no bucket is specified, use the default bucket if configured
		if ( empty( $bucket ) && empty( $view ) ) {
			// Use the default bucket configuration
			if ( ! empty( $this->config->default_bucket ) ) {
				$bucket = $this->config->default_bucket;
				$view   = 'objects';
			}
		}

		// Determine a view type
		$view = ( $view === 'buckets' || empty( $bucket ) ) ? 'buckets' : 'objects';

		// Output content
		echo '<div class="s3-browser-container">';

		// Pass the resolved bucket rather than letting the upload zone read
		// $_GET again: when a default bucket is configured the browser enters
		// it with no ?bucket= in the URL, and the zone would find nothing and
		// render no toolbar at all — no Upload, no New Folder.
		$this->render_upload_zone( $view === 'objects' ? $bucket : '', $prefix );

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
	* Enhanced ContentRendering Trait - Modify render_upload_zone() method
	*/
	public function render_upload_zone( string $bucket = '', string $prefix = '' ) {
		// Fall back to the query string for any caller that still relies on it.
		if ( '' === $bucket ) {
			$bucket = isset( $_GET['bucket'] ) ? sanitize_text_field( wp_unslash( $_GET['bucket'] ) ) : '';
			$prefix = isset( $_GET['prefix'] ) ? sanitize_text_field( wp_unslash( $_GET['prefix'] ) ) : $prefix;
		}

		// The toolbar belongs to a bucket view, not the bucket listing.
		if ( '' === $bucket ) {
			return;
		}

		?>
		<div class="s3-upload-wrapper">
			<div class="s3-toolbar-buttons">
				<!-- Use WordPress native button classes -->
				<button type="button" id="s3-toggle-upload" class="button button-primary">
					<?php esc_html_e( 'Upload Files', 'arraypress' ); ?>
				</button>

				<button type="button" id="s3-create-folder" class="button button-secondary"
						data-bucket="<?php echo esc_attr( $bucket ); ?>"
						data-prefix="<?php echo esc_attr( $prefix ); ?>">
					<?php esc_html_e( 'New Folder', 'arraypress' ); ?>
				</button>

				<?php
				// Bucket details — and with it CORS setup, which browser
				// uploads require. It was previously reachable only from the
				// bucket listing, which a site with a default bucket
				// configured never sees: the browser opens straight into the
				// bucket, so there was no route to configuring CORS at all.
				?>
				<button type="button" class="button button-secondary s3-bucket-details"
						data-bucket="<?php echo esc_attr( $bucket ); ?>"
						data-provider="<?php echo esc_attr( $this->config->provider_id ); ?>">
					<?php esc_html_e( 'Bucket Settings', 'arraypress' ); ?>
				</button>
			</div>

			<div id="s3-upload-container" class="s3-upload-container" style="display: none;">
				<div class="s3-upload-header">
					<h3 class="s3-upload-title"><?php esc_html_e( 'Upload Files', 'arraypress' ); ?></h3>
					<button type="button" class="s3-close-upload"
							aria-label="<?php esc_attr_e( 'Close', 'arraypress' ); ?>">
						<span class="dashicons dashicons-no-alt"></span>
					</button>
				</div>
				<div class="s3-upload-zone" data-bucket="<?php echo esc_attr( $bucket ); ?>"
					data-prefix="<?php echo esc_attr( $prefix ); ?>">
					<div class="s3-upload-message">
						<span class="dashicons dashicons-upload"></span>
						<p><?php esc_html_e( 'Drop files to upload', 'arraypress' ); ?></p>
						<p class="s3-upload-or"><?php esc_html_e( 'or', 'arraypress' ); ?></p>
						<input type="file" multiple class="s3-file-input" id="s3FileUpload">
						<!-- WordPress native button styling -->
						<label for="s3FileUpload" class="button button-secondary">
							<?php esc_html_e( 'Select Files', 'arraypress' ); ?>
						</label>
					</div>
				</div>
				<div class="s3-upload-list"></div>
			</div>
		</div>
		<?php
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
		$breadcrumbs = Breadcrumbs::create( [
				'separator'       => '›',
				'nav_class'       => 's3-browser-breadcrumbs',
				'list_class'      => 'breadcrumb',
				'item_class'      => '',
				'separator_class' => 'separator',
				'active_class'    => 'active',
		] );

		// Add root buckets link
		$breadcrumbs->add(
				__( 'Buckets', 'arraypress' ),
				$this->get_buckets_url(),
				'dashicons-database'
		);

		// Add bucket link
		$base_url = add_query_arg( [
				'tab'    => $this->config->tab_id(),
				'bucket' => $bucket
		], remove_query_arg( [ 'prefix', 's', 'continuation_token' ] ) );

		$breadcrumbs->add( $bucket, $base_url, 'dashicons-category' );

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
					$breadcrumbs->add_current( $part );
				} else {
					$url = add_query_arg( [
							'tab'    => $this->config->tab_id(),
							'bucket' => $bucket,
							'prefix' => $current_path
					] );
					$breadcrumbs->add( $part, $url, 'dashicons-category' );
				}
			}
		}

		$breadcrumbs->display();
	}

	/**
	* Get the URL for the buckets view
	*
	* @return string Buckets view URL
	*/
	private function get_buckets_url(): string {
		return add_query_arg(
				[
						'tab'  => $this->config->tab_id(),
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
		$list_table = new Buckets( [
				'client'      => $this->client,
				'provider_id' => $this->config->provider_id
		] );

		// Prepare items
		try {
			$list_table->prepare_items();
		} catch ( Exception $e ) {
			printf(
					'<div class="notice notice-error s3-error"><p>%s</p></div>',
					esc_html__( 'Error loading buckets. Please try again.', 'arraypress' )
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
		$list_table = new Objects( [
				'client'      => $this->client,
				'bucket'      => $bucket,
				'prefix'      => $prefix,
				'provider_id' => $this->config->provider_id,
		] );

		// Prepare items
		try {
			$list_table->prepare_items();
		} catch ( Exception $e ) {
			printf(
					'<div class="notice notice-error s3-error"><p>%s</p></div>',
					esc_html__( 'Error loading files. Please try again.', 'arraypress' )
			);
			return;
		}

		// Display the list table
		$list_table->display();
	}

}
