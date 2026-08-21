<?php
/**
 * Browser Translations
 *
 * The strings the browser's JavaScript needs, grouped the way it reads them:
 * S3BrowserGlobalConfig.i18n.<group>.<key>.
 *
 * @package     ArrayPress\S3\Admin
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Admin;

/**
 * Class Translations
 */
class Translations {

	/**
	 * Build the translation table.
	 *
	 * Called once per page load that enqueues the browser, so the strings are
	 * assembled on demand rather than held as a constant -- __() needs the
	 * text domain loaded, which it is not at class-definition time.
	 *
	 * @return array Strings grouped by area.
	 */
	public static function all(): array {
		return [
			'ui' => [
				'uploadFiles'    => __( 'Upload Files', 'arraypress' ),
				'or'             => __( 'or', 'arraypress' ),
				'waitForUploads' => __( 'Please wait for uploads to complete before closing', 'arraypress' ),
				'cancel'         => __( 'Cancel', 'arraypress' ),
				'close'          => __( 'Close', 'arraypress' ),
				'deleting'       => __( 'Deleting...', 'arraypress' ),
				'refreshing'     => __( 'Refreshing...', 'arraypress' ),
			],
			'files' => [
				'confirmDelete'    => implode( "\n\n", [
					__( 'Are you sure you want to delete "{filename}"?', 'arraypress' ),
					__( 'This action cannot be undone.', 'arraypress' ),
				] ),
				'deleteSuccess'    => __( 'File successfully deleted', 'arraypress' ),
				'renameFile'       => __( 'Rename File', 'arraypress' ),
				'filenameLabel'    => __( 'Enter the new filename:', 'arraypress' ),
				'filenameHelp'     => __( 'Enter a new filename. The file extension will be preserved.', 'arraypress' ),
				'renameSuccess'    => __( 'File renamed successfully', 'arraypress' ),
				'renamingFile'     => __( 'Renaming file...', 'arraypress' ),
				'filenameRequired' => __( 'Filename is required', 'arraypress' ),
				'filenameInvalid'  => __( 'Filename contains invalid characters', 'arraypress' ),
				'filenameTooLong'  => __( 'Filename is too long', 'arraypress' ),
				'filenameSame'     => __( 'The new filename is the same as the current filename', 'arraypress' ),
			],
			'folders' => [
				'newFolder'                 => __( 'New Folder', 'arraypress' ),
				'createFolder'              => __( 'Create Folder', 'arraypress' ),
				'folderName'                => __( 'Folder Name', 'arraypress' ),
				'folderNamePlaceholder'     => __( 'Enter folder name', 'arraypress' ),
				'folderNameHelp'            => __( 'Enter a name for the new folder. Use only letters, numbers, spaces, dots, hyphens, and underscores.', 'arraypress' ),
				'createFolderSuccess'       => __( 'Folder "{name}" created successfully', 'arraypress' ),
				'creatingFolder'            => __( 'Creating folder...', 'arraypress' ),
				'folderNameRequired'        => __( 'Folder name is required', 'arraypress' ),
				'folderNameTooLong'         => __( 'Folder name cannot exceed 63 characters', 'arraypress' ),
				'folderNameInvalidChars'    => __( 'Folder name can only contain letters, numbers, spaces, dots, hyphens, and underscores', 'arraypress' ),
				'folderNameStartEnd'        => __( 'Folder name cannot start or end with dots or hyphens', 'arraypress' ),
				'folderNameConsecutiveDots' => __( 'Folder name cannot contain consecutive dots', 'arraypress' ),
				'confirmDeleteFolder'       => implode( "\n\n", [
					__( 'Are you sure you want to delete the folder "{foldername}" and all its contents?', 'arraypress' ),
					__( 'This action cannot be undone.', 'arraypress' ),
				] ),
				'deleteFolderSuccess'       => __( 'Folder successfully deleted', 'arraypress' ),
				'deletingFolderProgress'    => __( 'Deleting folder "{name}"...', 'arraypress' ),
				'folderDeletedSuccess'      => __( 'Folder deleted successfully!', 'arraypress' ),
				'opening'                   => __( 'Opening...', 'arraypress' ),
				'folderOpenError'           => __( 'Failed to open folder', 'arraypress' ),
			],
			'buckets' => [
				// Modal titles and actions
				'detailsTitle'           => __( 'Bucket Details: {bucket}', 'arraypress' ),
				'browseBucket'           => __( 'Browse Bucket', 'arraypress' ),
				'revokeCorsRules'        => __( 'Revoke CORS Rules', 'arraypress' ),
				'loadingDetails'         => __( 'Loading bucket details...', 'arraypress' ),
				'loadDetailsError'       => __( 'Failed to load bucket details: {message}', 'arraypress' ),
				'manualCorsSetup'        => __( 'Manual CORS Setup Instructions', 'arraypress' ),
				'refreshPage'            => __( 'Refresh Page', 'arraypress' ),

				// Basic information
				'region'                 => __( 'Region:', 'arraypress' ),
				'created'                => __( 'Created:', 'arraypress' ),
				'provider'               => __( 'Provider:', 'arraypress' ),
				'uploadReady'            => __( 'Upload Ready:', 'arraypress' ),
				'yes'                    => __( 'Yes', 'arraypress' ),
				'no'                     => __( 'No', 'arraypress' ),

				// CORS configuration
				'permissions'            => __( 'Permissions', 'arraypress' ),
				'recommendations'        => __( 'Recommendations', 'arraypress' ),

				// CORS setup process
				'corsSetupConfirm'       => implode( "\n", [
					__( 'Set up CORS (Cross-Origin Resource Sharing) for bucket "{bucket}"?', 'arraypress' ),
					'',
					__( 'This will:', 'arraypress' ),
					__( '• Enable file uploads from web browsers', 'arraypress' ),
					__( '• Allow cross-origin access from this domain: {origin}', 'arraypress' ),
					__( '• Configure secure upload permissions', 'arraypress' ),
					'',
					__( 'This is required for uploads to work.', 'arraypress' ),
				] ),
				'settingUpCors'          => __( 'Setting up CORS configuration...', 'arraypress' ),
				'corsSetupSuccess'       => __( 'CORS successfully configured for bucket "{bucket}"', 'arraypress' ),
				'corsSetupError'         => __( 'Failed to setup CORS: {message}', 'arraypress' ),

				// Manual CORS setup
				's3CompatibleProvider'   => __( 'S3 Compatible Provider', 'arraypress' ),
				'autoSetupFailed'        => __( 'CORS needs to be set up in your provider\'s console.', 'arraypress' ),
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
				'revokeConfirm'          => implode( "\n", [
					__( 'Are you sure you want to revoke all CORS rules for bucket "{bucket}"?', 'arraypress' ),
					'',
					__( 'This will:', 'arraypress' ),
					__( '• Disable file uploads from web browsers', 'arraypress' ),
					__( '• Prevent cross-origin access to bucket resources', 'arraypress' ),
					__( '• Require CORS to be reconfigured before uploads work again', 'arraypress' ),
					'',
					__( 'This cannot be undone automatically.', 'arraypress' ),
				] ),
				'revokingCors'           => __( 'Revoking CORS rules...', 'arraypress' ),
				'revokeSuccess'          => __( 'CORS rules successfully revoked for bucket "{bucket}"', 'arraypress' ),
				'revokeError'            => __( 'Failed to revoke CORS rules: {message}', 'arraypress' ),
			],
			'upload' => [
				'cancelUploadConfirm' => __( 'Are you sure you want to cancel "{filename}"?', 'arraypress' ),
				'uploadFailed'        => __( 'Upload failed:', 'arraypress' ),
				'uploadComplete'      => __( 'Uploads completed. Refreshing file listing...', 'arraypress' ),
				'corsError'           => __( 'CORS configuration error - Your bucket needs proper CORS settings to allow uploads from this domain.', 'arraypress' ),
				'networkError'        => __( 'Network error detected. Please check your internet connection and try again.', 'arraypress' ),
				'failedPresignedUrl'  => __( 'Failed to get upload URL', 'arraypress' ),
				'uploadFailedStatus'  => __( 'Upload failed with status', 'arraypress' ),
				'uploadCancelled'     => __( 'Upload cancelled', 'arraypress' ),
			],
			'validation' => [
				'validationFailed'    => __( 'File Validation Failed', 'arraypress' ),
				'invalidFileType'     => __( 'File type "{extension}" is not allowed', 'arraypress' ),
				'invalidMimeType'     => __( 'MIME type "{mimeType}" is not allowed', 'arraypress' ),
				'someFilesRejected'   => __( 'Uploading {accepted} files. {rejected} files were rejected due to validation errors.', 'arraypress' ),
				'connectionSuccess'   => __( 'Connection successful!', 'arraypress' ),
				'connectionFailed'    => __( 'Connection test failed', 'arraypress' ),
			],
			'loading' => [
				'loadingText'    => __( 'Loading...', 'arraypress' ),
				'loadMoreItems'  => __( 'Load More Items', 'arraypress' ),
				'networkError'   => __( 'Network error. Please try again.', 'arraypress' ),
				'testing'        => __( 'Testing...', 'arraypress' ),
				'testConnection' => __( 'Test Connection', 'arraypress' ),
			],
			'search' => [
				'noMatchesFound' => __( 'No matches found', 'arraypress' ),
				'noFilesFound'   => __( 'No files or folders found matching "{term}"', 'arraypress' ),
				'itemsMatch'     => __( '{visible} of {total} items match', 'arraypress' ),
			],
			'display' => [
				'singleItem'    => __( 'item', 'arraypress' ),
				'multipleItems' => __( 'items', 'arraypress' ),
				'moreAvailable' => __( ' (more available)', 'arraypress' ),
			],
			'cache' => [
				'cacheRefreshed' => __( 'Cache refreshed successfully', 'arraypress' ),
			],
			'copyLink' => [
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
			'fileDetails' => [
				'title'         => __( 'File Details', 'arraypress' ),
				'filename'      => __( 'Filename:', 'arraypress' ),
				'objectKey'     => __( 'Object Key:', 'arraypress' ),
				'size'          => __( 'Size:', 'arraypress' ),
				'bytes'         => __( 'bytes', 'arraypress' ),
				'mimeType'      => __( 'MIME Type:', 'arraypress' ),
				'category'      => __( 'Category:', 'arraypress' ),
				'storageClass'  => __( 'Storage Class:', 'arraypress' ),
				'etag'          => __( 'ETag:', 'arraypress' ),
				'multipart'     => __( 'Multipart', 'arraypress' ),
				'parts'         => __( 'parts', 'arraypress' ),
				'checksumInfo'  => __( 'Checksum Information', 'arraypress' ),
			],
			'checksum' => [
				'noChecksumAvailable' => __( 'No checksum available', 'arraypress' ),
				'none'                => __( 'None', 'arraypress' ),
				'md5Composite'        => __( 'MD5 (Composite)', 'arraypress' ),
				'md5'                 => __( 'MD5', 'arraypress' ),
				'compositeNote'       => __( 'Hash of hashes from {parts} - not directly verifiable against file content', 'arraypress' ),
				'directNote'          => __( 'Direct MD5 of file content - can be verified after download', 'arraypress' ),
				'multipleParts'       => __( 'multiple parts', 'arraypress' ),
			],
			'cors' => [
				'corsSetup'              => __( 'Setup CORS', 'arraypress' ),
				'corsError'              => __( 'Failed to configure CORS', 'arraypress' ),
			],
		];
	}
}
