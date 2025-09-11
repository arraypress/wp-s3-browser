<?php
/**
 * Browser I18n Trait - Organized Translation Methods
 *
 * Provides translation strings for the S3 Browser organized by functionality.
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
 * Trait I18n
 */
trait I18n {

	/**
	 * Get all browser translation strings organized by functionality
	 *
	 * @return array Grouped translation array
	 */
	private function get_browser_translations(): array {
		$translations = [
			'ui'          => $this->get_ui_strings(),
			'files'       => $this->get_file_strings(),
			'folders'     => $this->get_folder_strings(),
			'buckets'     => $this->get_bucket_strings(),
			'upload'      => $this->get_upload_strings(),
			'validation'  => $this->get_validation_strings(),
			'loading'     => $this->get_loading_strings(),
			'search'      => $this->get_search_strings(),
			'display'     => $this->get_display_strings(),
			'cache'       => $this->get_cache_strings(),
			'copyLink'    => $this->get_copy_link_strings(),
			'fileDetails' => $this->get_file_details_strings(),
			'checksum'    => $this->get_checksum_strings(),
			'cors'        => $this->get_cors_strings(),
		];

		// Apply contextual filters
		return $this->apply_contextual_filters( 's3_browser_translations', $translations, $this->provider_id );
	}

	/**
	 * Get UI interface strings
	 *
	 * @return array UI strings
	 */
	private function get_ui_strings(): array {
		return [
			'uploadFiles'    => __( 'Upload Files', 'arraypress' ),
			'dropFilesHere'  => __( 'Drop files here to upload', 'arraypress' ),
			'or'             => __( 'or', 'arraypress' ),
			'chooseFiles'    => __( 'Choose Files', 'arraypress' ),
			'waitForUploads' => __( 'Please wait for uploads to complete before closing', 'arraypress' ),
			'cancel'         => __( 'Cancel', 'arraypress' ),
			'close'          => __( 'Close', 'arraypress' ),
		];
	}

	/**
	 * Get file operation strings
	 *
	 * @return array File operation strings
	 */
	private function get_file_strings(): array {
		return [
			'confirmDelete'    => __( 'Are you sure you want to delete "{filename}"?\n\nThis action cannot be undone.', 'arraypress' ),
			'deleteSuccess'    => __( 'File successfully deleted', 'arraypress' ),
			'deleteError'      => __( 'Failed to delete file', 'arraypress' ),
			'renameFile'       => __( 'Rename File', 'arraypress' ),
			'filenameLabel'    => __( 'Enter the new filename:', 'arraypress' ),
			'filenameHelp'     => __( 'Enter a new filename. The file extension will be preserved.', 'arraypress' ),
			'renameSuccess'    => __( 'File renamed successfully', 'arraypress' ),
			'renameError'      => __( 'Failed to rename file', 'arraypress' ),
			'renamingFile'     => __( 'Renaming file...', 'arraypress' ),
			'filenameRequired' => __( 'Filename is required', 'arraypress' ),
			'filenameInvalid'  => __( 'Filename contains invalid characters', 'arraypress' ),
			'filenameTooLong'  => __( 'Filename is too long', 'arraypress' ),
			'filenameSame'     => __( 'The new filename is the same as the current filename', 'arraypress' ),
		];
	}

	/**
	 * Get folder operation strings
	 *
	 * @return array Folder operation strings
	 */
	private function get_folder_strings(): array {
		return [
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
		];
	}

	/**
	 * Get bucket operation strings
	 *
	 * @return array Bucket operation strings
	 */
	private function get_bucket_strings(): array {
		return [
			// Modal titles and actions
			'detailsTitle'           => __( 'Bucket Details: {bucket}', 'arraypress' ),
			'browseBucket'           => __( 'Browse Bucket', 'arraypress' ),
			'revokeCorsRules'        => __( 'Revoke CORS Rules', 'arraypress' ),
			'loadingDetails'         => __( 'Loading bucket details...', 'arraypress' ),
			'loadDetailsError'       => __( 'Failed to load bucket details: {message}', 'arraypress' ),
			'manualCorsSetup'        => __( 'Manual CORS Setup Instructions', 'arraypress' ),
			'refreshPage'            => __( 'Refresh Page', 'arraypress' ),

			// Basic information
			'bucketInformation'      => __( 'Bucket Information', 'arraypress' ),
			'bucketName'             => __( 'Bucket Name:', 'arraypress' ),
			'region'                 => __( 'Region:', 'arraypress' ),
			'created'                => __( 'Created:', 'arraypress' ),
			'provider'               => __( 'Provider:', 'arraypress' ),
			's3Compatible'           => __( 'S3 Compatible', 'arraypress' ),

			// Upload capability
			'uploadCapability'       => __( 'Upload Capability', 'arraypress' ),
			'uploadReady'            => __( 'Upload Ready:', 'arraypress' ),
			'currentDomain'          => __( 'Current Domain:', 'arraypress' ),
			'yes'                    => __( 'Yes', 'arraypress' ),
			'no'                     => __( 'No', 'arraypress' ),

			// CORS configuration
			'corsConfiguration'      => __( 'CORS Configuration', 'arraypress' ),
			'hasCors'                => __( 'Has CORS:', 'arraypress' ),
			'rulesCount'             => __( 'Rules Count:', 'arraypress' ),
			'securityWarnings'       => __( 'Security Warnings:', 'arraypress' ),
			'warningCount'           => __( '{count} warning(s)', 'arraypress' ),

			// Permissions
			'permissions'            => __( 'Permissions', 'arraypress' ),
			'readAccess'             => __( 'Read Access:', 'arraypress' ),
			'writeAccess'            => __( 'Write Access:', 'arraypress' ),
			'deleteAccess'           => __( 'Delete Access:', 'arraypress' ),
			'recommendations'        => __( 'Recommendations', 'arraypress' ),

			// CORS setup process
			'corsSetupConfirm'       => __( 'Set up CORS (Cross-Origin Resource Sharing) for bucket "{bucket}"?\n\nThis will:\n• Enable file uploads from web browsers\n• Allow cross-origin access from this domain: {origin}\n• Configure secure upload permissions\n\nThis is required for the upload functionality to work properly.', 'arraypress' ),
			'settingUpCors'          => __( 'Setting up CORS configuration...', 'arraypress' ),
			'corsSetupSuccess'       => __( 'CORS successfully configured for bucket "{bucket}"', 'arraypress' ),
			'corsSetupError'         => __( 'Failed to setup CORS: {message}', 'arraypress' ),

			// Manual CORS setup
			's3CompatibleProvider'   => __( 'S3 Compatible Provider', 'arraypress' ),
			'autoSetupFailed'        => __( 'Automatic CORS setup failed.', 'arraypress' ),
			'manualSetupInstruction' => __( 'You can set up CORS manually through your {provider} console or API.', 'arraypress' ),
			'requiredCorsConfig'     => __( 'Required CORS Configuration:', 'arraypress' ),
			'addCorsRule'            => __( 'Add this minimal CORS rule to bucket {bucket}:', 'arraypress' ),
			'whatRuleDoes'           => __( 'What This Rule Does:', 'arraypress' ),
			'putMethodOnly'          => __( 'PUT method only:', 'arraypress' ),
			'putMethodDesc'          => __( 'Enables secure file uploads via presigned URLs', 'arraypress' ),
			'minimalHeaders'         => __( 'Minimal headers:', 'arraypress' ),
			'minimalHeadersDesc'     => __( 'Only Content-Type and Content-Length for security', 'arraypress' ),
			'singleOrigin'           => __( 'Single origin:', 'arraypress' ),
			'singleOriginDesc'       => __( 'Restricts access to your domain only', 'arraypress' ),
			'oneHourCache'           => __( '1-hour cache:', 'arraypress' ),
			'oneHourCacheDesc'       => __( 'Reduces preflight requests', 'arraypress' ),
			'note'                   => __( 'Note:', 'arraypress' ),
			'configOptimized'        => __( 'This configuration is optimized for browser uploads only. All other operations (delete, list, etc.) are handled server-side and don\'t require additional CORS permissions.', 'arraypress' ),

			// CORS revocation
			'revokeConfirm'          => __( 'Are you sure you want to revoke all CORS rules for bucket "{bucket}"?\n\nThis will:\n• Disable file uploads from web browsers\n• Prevent cross-origin access to bucket resources\n• Require manual CORS reconfiguration to restore upload capability\n\nThis action cannot be undone automatically.', 'arraypress' ),
			'revokingCors'           => __( 'Revoking CORS rules...', 'arraypress' ),
			'revokeSuccess'          => __( 'CORS rules successfully revoked for bucket "{bucket}"', 'arraypress' ),
			'revokeError'            => __( 'Failed to revoke CORS rules: {message}', 'arraypress' ),
		];
	}

	/**
	 * Get upload operation strings
	 *
	 * @return array Upload strings
	 */
	private function get_upload_strings(): array {
		return [
			'cancelUploadConfirm' => __( 'Are you sure you want to cancel "{filename}"?', 'arraypress' ),
			'uploadFailed'        => __( 'Upload failed:', 'arraypress' ),
			'uploadComplete'      => __( 'Uploads completed. Refreshing file listing...', 'arraypress' ),
			'corsError'           => __( 'CORS configuration error - Your bucket needs proper CORS settings to allow uploads from this domain.', 'arraypress' ),
			'networkError'        => __( 'Network error detected. Please check your internet connection and try again.', 'arraypress' ),
			'failedPresignedUrl'  => __( 'Failed to get upload URL', 'arraypress' ),
			'uploadFailedStatus'  => __( 'Upload failed with status', 'arraypress' ),
			'uploadCancelled'     => __( 'Upload cancelled', 'arraypress' )
		];
	}

	/**
	 * Get validation strings
	 *
	 * @return array Validation strings
	 */
	private function get_validation_strings(): array {
		return [
			'validationFailed'    => __( 'File Validation Failed', 'arraypress' ),
			'invalidFileType'     => __( 'File type "{extension}" is not allowed', 'arraypress' ),
			'invalidMimeType'     => __( 'MIME type "{mimeType}" is not allowed', 'arraypress' ),
			'someFilesRejected'   => __( 'Uploading {accepted} files. {rejected} files were rejected due to validation errors.', 'arraypress' ),
			'connectionSuccess'   => __( 'Connection successful!', 'arraypress' ),
			'connectionFailed'    => __( 'Connection test failed', 'arraypress' ),
			'securityCheckFailed' => __( 'Security check failed', 'arraypress' ),
			'noCredentials'       => __( 'No credentials configured', 'arraypress' ),
			'insufficientPerms'   => __( 'Insufficient permissions', 'arraypress' ),
		];
	}

	/**
	 * Get loading and progress strings
	 *
	 * @return array Loading strings
	 */
	private function get_loading_strings(): array {
		return [
			'loadingText'    => __( 'Loading...', 'arraypress' ),
			'loadMoreItems'  => __( 'Load More Items', 'arraypress' ),
			'loadMoreError'  => __( 'Failed to load more items. Please try again.', 'arraypress' ),
			'networkError'   => __( 'Network error. Please try again.', 'arraypress' ),
			'testing'        => __( 'Testing...', 'arraypress' ),
			'testConnection' => __( 'Test Connection', 'arraypress' ),
		];
	}

	/**
	 * Get search functionality strings
	 *
	 * @return array Search strings
	 */
	private function get_search_strings(): array {
		return [
			'noMatchesFound' => __( 'No matches found', 'arraypress' ),
			'noFilesFound'   => __( 'No files or folders found matching "{term}"', 'arraypress' ),
			'itemsMatch'     => __( '{visible} of {total} items match', 'arraypress' ),
		];
	}

	/**
	 * Get display and count strings
	 *
	 * @return array Display strings
	 */
	private function get_display_strings(): array {
		return [
			'singleItem'    => __( 'item', 'arraypress' ),
			'multipleItems' => __( 'items', 'arraypress' ),
			'moreAvailable' => __( ' (more available)', 'arraypress' ),
		];
	}

	/**
	 * Get cache operation strings
	 *
	 * @return array Cache strings
	 */
	private function get_cache_strings(): array {
		return [
			'cacheRefreshed' => __( 'Cache refreshed successfully', 'arraypress' ),
			'refreshError'   => __( 'Failed to refresh data', 'arraypress' ),
		];
	}

	/**
	 * Get copy link strings
	 *
	 * @return array Copy link strings
	 */
	private function get_copy_link_strings(): array {
		return [
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
		];
	}

	/**
	 * Get file details strings
	 *
	 * @return array File details strings
	 */
	private function get_file_details_strings(): array {
		return [
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
		];
	}

	/**
	 * Get checksum information strings
	 *
	 * @return array Checksum strings
	 */
	private function get_checksum_strings(): array {
		return [
			'noChecksumAvailable' => __( 'No checksum available', 'arraypress' ),
			'none'                => __( 'None', 'arraypress' ),
			'md5Composite'        => __( 'MD5 (Composite)', 'arraypress' ),
			'md5'                 => __( 'MD5', 'arraypress' ),
			'compositeNote'       => __( 'Hash of hashes from {parts} - not directly verifiable against file content', 'arraypress' ),
			'directNote'          => __( 'Direct MD5 of file content - can be verified after download', 'arraypress' ),
			'multipleParts'       => __( 'multiple parts', 'arraypress' ),
		];
	}

	/**
	 * Get CORS operation strings
	 *
	 * @return array CORS strings
	 */
	private function get_cors_strings(): array {
		return [
			'corsInfo'               => __( 'CORS Information', 'arraypress' ),
			'corsSetup'              => __( 'Setup CORS', 'arraypress' ),
			'corsSetupForUploads'    => __( 'Setup CORS for Uploads', 'arraypress' ),
			'loadingCorsInfo'        => __( 'Loading CORS information...', 'arraypress' ),
			'configuringCors'        => __( 'Configuring CORS...', 'arraypress' ),
			'corsConfigured'         => __( 'CORS configured successfully!', 'arraypress' ),
			'corsVerificationFailed' => __( 'CORS configured, but verification failed. Please check manually.', 'arraypress' ),
			'corsError'              => __( 'Failed to configure CORS', 'arraypress' ),
			'corsNone'               => __( 'No CORS', 'arraypress' ),
			'corsUploadOk'           => __( 'Upload OK', 'arraypress' ),
			'corsLimited'            => __( 'Limited', 'arraypress' ),
			'corsAllowsUploads'      => __( 'CORS allows uploads from this domain', 'arraypress' ),
			'corsNoUploads'          => __( 'CORS configured but uploads not allowed from this domain', 'arraypress' ),
			'corsSetupNote'          => __( 'This will configure CORS to allow file uploads from your current domain to the bucket.', 'arraypress' ),
			'corsSetupWarning'       => __( 'This will replace any existing CORS configuration on this bucket. The configuration is minimal and focused only on upload functionality.', 'arraypress' ),
		];
	}

}