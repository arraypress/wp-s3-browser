<?php
/**
 * Browser Assets Management Trait - Final with Grouped Translations
 *
 * Handles asset loading and configuration for the S3 Browser using
 * the new simplified JavaScript file structure and grouped translations.
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
 * Trait Assets
 */
trait Assets {

	/**
	 * Store the browser script handles for later reference
	 *
	 * @var array
	 */
	private array $browser_script_handles = [];

	/**
	 * Enqueue global configuration script
	 *
	 * @return string Script handle
	 */
	private function enqueue_global_config(): string {
		// Use a consistent handle
		$handle = self::GLOBAL_CONFIG_HANDLE;

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
				'nonce'         => wp_create_nonce( 's3_browser_nonce_' . $this->provider_id )
			];

			// Only add favorite and prefix if relevant
			if ( ! empty( $preferred_bucket['bucket'] ) ) {
				$shared_config['favoriteBucket'] = $preferred_bucket['bucket'];
			}

			if ( ! empty( $prefix_to_use ) ) {
				$shared_config['defaultPrefix'] = $prefix_to_use;
			}

			// Apply contextual filters
			$shared_config = $this->apply_contextual_filters( 's3_browser_global_config', $shared_config, $this->provider_id );

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
			$this->enqueue_media_upload_assets();
		} elseif ( in_array( $hook_suffix, [ 'post.php', 'post-new.php' ] ) ) {
			// For post edit pages (WooCommerce products, etc.)
			$this->enqueue_integration_assets();
		}
	}

	/**
	 * Enqueue assets specifically for media upload popup
	 *
	 * @return void
	 */
	private function enqueue_media_upload_assets(): void {
		// First enqueue the global config
		$config_handle = $this->enqueue_global_config();

		// Enqueue main styles and scripts (includes upload)
		$this->enqueue_core_browser_assets( $config_handle );

		// Localize the main browser script
		$this->localize_browser_script();
	}

	/**
	 * Enqueue integration assets for post edit pages
	 *
	 * @return void
	 */
	private function enqueue_integration_assets(): void {
		// First enqueue the global config
		$config_handle = $this->enqueue_global_config();

		// Enqueue integration-specific scripts based on context
		$post_type = get_post_type();

		if ( $post_type === 'product' ) {
			// WooCommerce integration
			wp_enqueue_script_from_composer_file(
				's3-browser-woocommerce',
				__FILE__,
				'js/integrations/s3-browser-woocommerce.js',
				[ 'jquery', $config_handle ]
			);
		} elseif ( $post_type === 'download' ) {
			// EDD integration
			wp_enqueue_script_from_composer_file(
				's3-browser-edd',
				__FILE__,
				'js/integrations/s3-browser-edd.js',
				[ 'jquery', $config_handle ]
			);
		}
	}

	/**
	 * Enqueue core browser assets (CSS and main JS files)
	 *
	 * @param string $config_handle Global config script handle
	 *
	 * @return bool True on success, false on failure
	 */
	private function enqueue_core_browser_assets( string $config_handle ): bool {
		// Enqueue main browser styles
		wp_enqueue_style_from_composer_file(
			's3-browser-style',
			__FILE__,
			'css/s3-browser.css'
		);

		// Enqueue upload styles
		wp_enqueue_style_from_composer_file(
			's3-upload-style',
			__FILE__,
			'css/s3-upload.css'
		);

		// Define script loading order and dependencies
		$scripts = [
			's3-browser-core'         => [
				'file' => 'js/s3-browser-core.js',
				'deps' => [ 'jquery', $config_handle ]
			],
			's3-browser-modals'       => [
				'file' => 'js/s3-browser-modals.js',
				'deps' => [ 'jquery', 's3-browser-core' ]
			],
			's3-browser-files'        => [
				'file' => 'js/s3-browser-files.js',
				'deps' => [ 'jquery', 's3-browser-core', 's3-browser-modals' ]
			],
			's3-browser-folders'      => [
				'file' => 'js/s3-browser-folders.js',
				'deps' => [ 'jquery', 's3-browser-core', 's3-browser-modals' ]
			],
			's3-browser-integrations' => [
				'file' => 'js/s3-browser-integrations.js',
				'deps' => [ 'jquery', 's3-browser-core' ]
			],
			's3-upload-script'        => [
				'file' => 'js/s3-browser-upload.js',
				'deps' => [ 'jquery', $config_handle, 's3-browser-core' ]
			]
		];

		$all_enqueued = true;

		// Enqueue scripts in order
		foreach ( $scripts as $handle => $script_config ) {
			$success = wp_enqueue_script_from_composer_file(
				$handle,
				__FILE__,
				$script_config['file'],
				$script_config['deps']
			);

			if ( $success ) {
				$this->browser_script_handles[] = $handle;
			} else {
				$all_enqueued = false;
			}
		}

		return $all_enqueued;
	}

	/**
	 * Localize the main browser script with configuration and translations
	 *
	 * @return void
	 */
	private function localize_browser_script(): void {
		// Check if core script was enqueued
		if ( ! in_array( 's3-browser-core', $this->browser_script_handles ) ) {
			return;
		}

		$post_id = $this->get_current_post_id();

		// Build browser configuration
		$browser_config = [
			'postId'   => $post_id,
			'autoLoad' => apply_filters( 's3_browser_auto_load', false, $this->provider_id ),
			'i18n'     => $this->get_browser_translations()
		];

		// Apply contextual filters
		$browser_config = $this->apply_contextual_filters( 's3_browser_config', $browser_config, $this->provider_id );

		// Localize the core script
		wp_localize_script( 's3-browser-core', 's3BrowserConfig', $browser_config );
	}

	/**
	 * Get all browser translation strings organized in logical groups
	 *
	 * @return array Grouped translation array
	 */
	private function get_browser_translations(): array {
		$default_translations = [
			// Browser UI strings
			'ui'          => [
				'uploadFiles'    => __( 'Upload Files', 'arraypress' ),
				'dropFilesHere'  => __( 'Drop files here to upload', 'arraypress' ),
				'or'             => __( 'or', 'arraypress' ),
				'chooseFiles'    => __( 'Choose Files', 'arraypress' ),
				'waitForUploads' => __( 'Please wait for uploads to complete before closing', 'arraypress' ),
				'cancel'         => __( 'Cancel', 'arraypress' ),
			],

			// File operations
			'files'       => [
				'confirmDelete'    => __( 'Are you sure you want to delete "{filename}"?\n\nThis action cannot be undone.', 'arraypress' ),
				'deleteSuccess'    => __( 'File successfully deleted', 'arraypress' ),
				'deleteError'      => __( 'Failed to delete file', 'arraypress' ),
				'renameFile'       => __( 'Rename File', 'arraypress' ),
				'newFilename'      => __( 'New Filename', 'arraypress' ),
				'filenameLabel'    => __( 'Enter the new filename:', 'arraypress' ),
				'filenameHelp'     => __( 'Enter a new filename. The file extension will be preserved.', 'arraypress' ),
				'renameSuccess'    => __( 'File renamed successfully', 'arraypress' ),
				'renameError'      => __( 'Failed to rename file', 'arraypress' ),
				'renamingFile'     => __( 'Renaming file...', 'arraypress' ),
				'filenameRequired' => __( 'Filename is required', 'arraypress' ),
				'filenameInvalid'  => __( 'Filename contains invalid characters', 'arraypress' ),
				'filenameTooLong'  => __( 'Filename is too long', 'arraypress' ),
				'filenameExists'   => __( 'A file with this name already exists', 'arraypress' ),
				'filenameSame'     => __( 'The new filename is the same as the current filename', 'arraypress' ),
			],

			// Copy link operations
			'copyLink'    => [
				'copyLink'             => __( 'Copy Link', 'arraypress' ),
				'linkDuration'         => __( 'Link Duration (minutes)', 'arraypress' ),
				'linkDurationHelp'     => __( 'Enter how long the link should remain valid (1 minute to 7 days).', 'arraypress' ),
				'generatedLink'        => __( 'Generated Link', 'arraypress' ),
				'generateLinkFirst'    => __( 'Click Generate Link to create a shareable URL', 'arraypress' ),
				'generateLink'         => __( 'Generate Link', 'arraypress' ),
				'copyToClipboard'      => __( 'Copy to Clipboard', 'arraypress' ),
				'generatingLink'       => __( 'Generating link...', 'arraypress' ),
				'linkGenerated'        => __( 'Link generated successfully!', 'arraypress' ),
				'linkGeneratedSuccess' => __( 'Link generated successfully', 'arraypress' ),
				'linkExpiresAt'        => __( 'Link expires at: {time}', 'arraypress' ),
				'linkCopied'           => __( 'Link copied to clipboard!', 'arraypress' ),
				'copyFailed'           => __( 'Failed to copy link. Please copy manually.', 'arraypress' ),
				'invalidDuration'      => __( 'Duration must be between 1 minute and 7 days (10080 minutes)', 'arraypress' ),
			],

			// File details modal
			'fileDetails' => [
				'title'         => __( 'File Details', 'arraypress' ),
				'basicInfo'     => __( 'Basic Information', 'arraypress' ),
				'filename'      => __( 'Filename:', 'arraypress' ),
				'objectKey'     => __( 'Object Key:', 'arraypress' ),
				'size'          => __( 'Size:', 'arraypress' ),
				'bytes'         => __( 'bytes', 'arraypress' ),
				'lastModified'  => __( 'Last Modified:', 'arraypress' ),
				'mimeType'      => __( 'MIME Type:', 'arraypress' ),
				'category'      => __( 'Category:', 'arraypress' ),
				'storageInfo'   => __( 'Storage Information', 'arraypress' ),
				'storageClass'  => __( 'Storage Class:', 'arraypress' ),
				'etag'          => __( 'ETag:', 'arraypress' ),
				'uploadType'    => __( 'Upload Type:', 'arraypress' ),
				'multipart'     => __( 'Multipart', 'arraypress' ),
				'singlePart'    => __( 'Single-part', 'arraypress' ),
				'parts'         => __( 'parts', 'arraypress' ),
				'checksumInfo'  => __( 'Checksum Information', 'arraypress' ),
				'checksumType'  => __( 'Type:', 'arraypress' ),
				'checksumValue' => __( 'Value:', 'arraypress' ),
			],

			// Checksum information
			'checksum'    => [
				'noChecksumAvailable' => __( 'No checksum available', 'arraypress' ),
				'none'                => __( 'None', 'arraypress' ),
				'md5Composite'        => __( 'MD5 (Composite)', 'arraypress' ),
				'md5'                 => __( 'MD5', 'arraypress' ),
				'compositeNote'       => __( 'Hash of hashes from {parts} - not directly verifiable against file content', 'arraypress' ),
				'directNote'          => __( 'Direct MD5 of file content - can be verified after download', 'arraypress' ),
				'multipleParts'       => __( 'multiple parts', 'arraypress' ),
			],

			// Folder operations
			'folders'     => [
				'newFolder'                 => __( 'New Folder', 'arraypress' ),
				'createFolder'              => __( 'Create Folder', 'arraypress' ),
				'folderName'                => __( 'Folder Name', 'arraypress' ),
				'folderNamePlaceholder'     => __( 'Enter folder name', 'arraypress' ),
				'folderNameHelp'            => __( 'Enter a name for the new folder. Use only letters, numbers, spaces, dots, hyphens, and underscores.', 'arraypress' ),
				'createFolderSuccess'       => __( 'Folder "{name}" created successfully', 'arraypress' ),
				'createFolderError'         => __( 'Failed to create folder', 'arraypress' ),
				'creatingFolder'            => __( 'Creating folder...', 'arraypress' ),
				'folderNameRequired'        => __( 'Folder name is required', 'arraypress' ),
				'folderNameTooLong'         => __( 'Folder name cannot exceed 63 characters', 'arraypress' ),
				'folderNameInvalidChars'    => __( 'Folder name can only contain letters, numbers, spaces, dots, hyphens, and underscores', 'arraypress' ),
				'folderNameStartEnd'        => __( 'Folder name cannot start or end with dots or hyphens', 'arraypress' ),
				'folderNameConsecutiveDots' => __( 'Folder name cannot contain consecutive dots', 'arraypress' ),
				'confirmDeleteFolder'       => __( 'Are you sure you want to delete the folder "{foldername}" and all its contents?\n\nThis action cannot be undone.', 'arraypress' ),
				'deleteFolderSuccess'       => __( 'Folder successfully deleted', 'arraypress' ),
				'deletingFolderProgress'    => __( 'Deleting folder "{name}"...', 'arraypress' ),
				'folderDeletedSuccess'      => __( 'Folder deleted successfully!', 'arraypress' ),
				'opening'                   => __( 'Opening...', 'arraypress' ),
				'folderOpenError'           => __( 'Failed to open folder', 'arraypress' ),
			],

			// Cache and system operations
			'cache'       => [
				'cacheRefreshed' => __( 'Cache refreshed successfully', 'arraypress' ),
				'refreshError'   => __( 'Failed to refresh data', 'arraypress' ),
			],

			// Loading and errors
			'loading'     => [
				'loadingText'   => __( 'Loading...', 'arraypress' ),
				'loadMoreItems' => __( 'Load More Items', 'arraypress' ),
				'loadMoreError' => __( 'Failed to load more items. Please try again.', 'arraypress' ),
				'networkError'  => __( 'Network error. Please try again.', 'arraypress' ),
			],

			// Search functionality
			'search'      => [
				'noMatchesFound' => __( 'No matches found', 'arraypress' ),
				'noFilesFound'   => __( 'No files or folders found matching "{term}"', 'arraypress' ),
				'itemsMatch'     => __( '{visible} of {total} items match', 'arraypress' ),
			],

			// Item counts and display
			'display'     => [
				'singleItem'    => __( 'item', 'arraypress' ),
				'multipleItems' => __( 'items', 'arraypress' ),
				'moreAvailable' => __( ' (more available)', 'arraypress' ),
			],

			// Favorites and navigation
			'navigation'  => [
				'setDefault'    => __( 'Set as default bucket', 'arraypress' ),
				'removeDefault' => __( 'Remove as default bucket', 'arraypress' ),
			],

			// Upload specific translations
			'upload'      => [
				'cancelUploadConfirm' => __( 'Are you sure you want to cancel "{filename}"?', 'arraypress' ),
				'uploadFailed'        => __( 'Upload failed:', 'arraypress' ),
				'uploadComplete'      => __( 'Uploads completed. Refreshing file listing...', 'arraypress' ),
				'corsError'           => __( 'CORS configuration error - Your bucket needs proper CORS settings to allow uploads from this domain.', 'arraypress' ),
				'networkError'        => __( 'Network error detected. Please check your internet connection and try again.', 'arraypress' ),
				'failedPresignedUrl'  => __( 'Failed to get upload URL', 'arraypress' ),
				'uploadFailedStatus'  => __( 'Upload failed with status', 'arraypress' ),
				'uploadCancelled'     => __( 'Upload cancelled', 'arraypress' )
			]
		];

		// Apply contextual filters
		return $this->apply_contextual_filters( 's3_browser_translations', $default_translations, $this->provider_id );
	}

}