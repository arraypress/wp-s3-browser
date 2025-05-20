<?php
/**
 * UI Handler Trait
 *
 * Provides common UI functionality for S3 browser.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      ArrayPress Team
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Browser;

use ArrayPress\S3\Components\Notice;
use ArrayPress\S3\Components\Breadcrumb;
use ArrayPress\S3\Tables\BucketsTable;
use ArrayPress\S3\Tables\ObjectsTable;
use Exception;

/**
 * Trait UiHandler
 */
trait UiHandler {
	// Use BaseScreen trait for shared functionality
	use BaseScreen;

	/**
	 * Provider ID
	 *
	 * @var string
	 */
	private string $provider_id;

	/**
	 * Provider name
	 *
	 * @var string
	 */
	private string $provider_name;

	/**
	 * Allowed post types for this browser
	 *
	 * @var array
	 */
	private array $allowed_post_types = [];

	/**
	 * Initialize UI
	 *
	 * @param string $provider_id   Provider ID
	 * @param string $provider_name Provider name/label
	 *
	 * @return void
	 */
	protected function init_ui(
		string $provider_id,
		string $provider_name
	): void {
		$this->provider_id = $provider_id;
		$this->provider_name = $provider_name;

		// Add tab to media uploader
		add_filter( 'media_upload_tabs', [ $this, 'add_media_tab' ] );

		// Register tab content handler
		add_action( 'media_upload_s3_' . $this->provider_id, [ $this, 'handle_media_tab' ] );
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
	protected function should_show_tab(): bool {
		// Check if user has the required capability
		if ( ! $this->user_has_capability( $this->capability ) ) {
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
			if ( ! $this->is_allowed_post_type( $post_type, $this->allowed_post_types ) ) {
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
	protected function display_objects_view( string $bucket, string $prefix = '' ): void {
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
	protected function display_breadcrumbs( string $bucket, string $prefix = '' ): void {
		$base_url = add_query_arg( [
			'tab'    => 's3_' . $this->provider_id,
			'bucket' => $bucket
		], remove_query_arg( [ 'prefix', 's', 'continuation_token' ] ) );

		$breadcrumb = new Breadcrumb( '›', [ 's3-browser-breadcrumbs' ] );

		// Add root buckets link
		$breadcrumb->add_link(
			$this->get_buckets_url($this->provider_id),
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
	 * Display the buckets list
	 *
	 * @return void
	 */
	protected function display_buckets_list(): void {
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
	protected function display_objects_list( string $bucket, string $prefix = '' ): void {
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
}