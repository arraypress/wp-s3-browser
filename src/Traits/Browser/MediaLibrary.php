<?php
/**
 * Browser WordPress Integration Trait
 *
 * Handles WordPress media uploader integration for the S3 Browser.
 *
 * @package     ArrayPress\S3\Traits\Browser
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Browser;

use ArrayPress\S3\Tables\Buckets;
use ArrayPress\S3\Tables\Objects;
use Exception;
use Elementify\Create;

/**
 * Trait MediaLibrary
 */
trait MediaLibrary {

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

        $tabs[ $this->get_tab_id() ] = $this->provider_name;

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

        // Check context and post type restrictions
        if ( ! $this->is_context_allowed( $this->allowed_post_types ) ) {
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

        $strings['tabs'][ $this->get_tab_id() ] = $this->provider_name;

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
        $this->enqueue_browser_assets( 'media-upload-popup' );

        // Get request parameters
        $view   = isset( $_GET['view'] ) ? sanitize_text_field( $_GET['view'] ) : '';
        $bucket = isset( $_GET['bucket'] ) ? sanitize_text_field( $_GET['bucket'] ) : '';
        $prefix = isset( $_GET['prefix'] ) ? sanitize_text_field( $_GET['prefix'] ) : '';

        // If no bucket is specified, use the default bucket if configured
        if ( empty( $bucket ) && empty( $view ) ) {
            // Use the default bucket configuration
            if ( ! empty( $this->default_bucket ) ) {
                $bucket = $this->default_bucket;
                $view   = 'objects';

                // Also use default prefix if configured
                if ( ! empty( $this->default_prefix ) ) {
                    $prefix = $this->default_prefix;
                }
            }
        }

        // Determine a view type
        $view = ( $view === 'buckets' || empty( $bucket ) ) ? 'buckets' : 'objects';

        // Output content
        echo '<div class="s3-browser-container">';

        // Add upload zone here, after breadcrumbs/navigation
        $this->render_upload_zone();

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
        // Show breadcrumbs using Elementify
        $this->display_breadcrumbs( $bucket, $prefix );

        // Display objects list
        $this->display_objects_list( $bucket, $prefix );
    }

    /**
     * Enhanced ContentRendering Trait - Modify render_upload_zone() method
     */
    public function render_upload_zone() {
        // Only show the upload zone on object views (not bucket listing)
        if ( empty( $_GET['bucket'] ) ) {
            return;
        }

        $bucket = sanitize_text_field( $_GET['bucket'] );
        $prefix = isset( $_GET['prefix'] ) ? sanitize_text_field( $_GET['prefix'] ) : '';

        ?>
        <div class="s3-upload-wrapper">
            <div class="s3-toolbar-buttons">
                <!-- Use WordPress native button classes -->
                <button type="button" id="s3-toggle-upload" class="button button-primary">
                    <span class="dashicons dashicons-upload"></span>
                    <?php esc_html_e( 'Upload Files', 'arraypress' ); ?>
                </button>

                <button type="button" id="s3-create-folder" class="button button-secondary"
                        data-bucket="<?php echo esc_attr( $bucket ); ?>"
                        data-prefix="<?php echo esc_attr( $prefix ); ?>">
                    <span class="dashicons dashicons-plus-alt"></span>
                    <?php esc_html_e( 'New Folder', 'arraypress' ); ?>
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
     * Generate breadcrumb navigation using Elementify
     *
     * @param string $bucket Bucket name
     * @param string $prefix Object prefix/path
     *
     * @return void
     */
    private function display_breadcrumbs( string $bucket, string $prefix = '' ): void {
        // Build breadcrumb items array
        $items = [];

        // Add root buckets link
        $items[] = [
                'text' => __( 'Buckets', 'arraypress' ),
                'url'  => $this->get_buckets_url(),
                'icon' => 'database'
        ];

        // Add bucket link
        $base_url = add_query_arg( [
                'tab'    => $this->get_tab_param(),
                'bucket' => $bucket
        ], remove_query_arg( [ 'prefix', 's', 'continuation_token' ] ) );

        $items[] = [
                'text' => $bucket,
                'url'  => $base_url,
                'icon' => 'category'
        ];

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
                    // Last part is current (no URL)
                    $items[] = $part;
                } else {
                    // Intermediate parts are links
                    $url     = add_query_arg( [
                            'tab'    => $this->get_tab_param(),
                            'bucket' => $bucket,
                            'prefix' => $current_path
                    ] );
                    $items[] = [
                            'text' => $part,
                            'url'  => $url,
                            'icon' => 'category'
                    ];
                }
            }
        }

        $breadcrumbs = Create::breadcrumbs( $items, '›', [ 'class' => 's3-browser-breadcrumbs' ] );
        echo $breadcrumbs->render();
    }

    /**
     * Get the URL for the buckets view
     *
     * @return string Buckets view URL
     */
    private function get_buckets_url(): string {
        return add_query_arg(
                [
                        'tab'  => $this->get_tab_param(),
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
                'provider_id' => $this->provider_id
        ] );

        // Prepare items
        try {
            $list_table->prepare_items();
        } catch ( Exception $e ) {
            $error_notice = Create::error_notice(
                    __( 'Error loading buckets. Please try again.', 'arraypress' ),
                    false,
                    [ 'class' => 's3-error' ]
            );
            echo $error_notice->render();

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
                'provider_id' => $this->provider_id,
        ] );

        // Prepare items
        try {
            $list_table->prepare_items();
        } catch ( Exception $e ) {
            $error_notice = Create::error_notice(
                    __( 'Error loading files. Please try again.', 'arraypress' ),
                    false,
                    [ 'class' => 's3-error' ]
            );
            echo $error_notice->render();

            return;
        }

        // Display the list table
        $list_table->display();
    }
}