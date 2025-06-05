/**
 * S3 Browser Folders - Lightweight folder operations
 * Handles folder creation, deletion, and navigation with enhanced UX
 */
(function ($) {
    'use strict';

    // Folders namespace
    window.S3Folders = {

        // ===========================================
        // FOLDER NAVIGATION
        // ===========================================

        /**
         * Handle folder opening with enhanced feedback
         */
        handleFolderOpen: function ($button) {
            const prefix = $button.data('prefix');
            const bucket = $button.data('bucket');

            if (!bucket || !prefix) {
                S3.notify('Invalid folder data', 'error');
                return;
            }

            // Visual feedback during navigation
            const originalHtml = $button.html();
            $button.html('<span class="dashicons dashicons-update s3-spin"></span> ' + s3BrowserConfig.i18n.folders.opening)
                .prop('disabled', true)
                .addClass('navigating');

            try {
                S3.navigate({ bucket, prefix });
            } catch (error) {
                // Reset button state on error
                $button.html(originalHtml)
                    .prop('disabled', false)
                    .removeClass('navigating');
                S3.notify(s3BrowserConfig.i18n.folders.folderOpenError, 'error');
            }
        },

        // ===========================================
        // FOLDER DELETION
        // ===========================================

        /**
         * Confirm folder deletion with detailed warning
         */
        deleteFolderConfirm: function ($button) {
            const folderName = $button.data('folder-name');
            const folderPath = $button.data('prefix');

            if (!folderName) {
                S3.notify('Invalid folder data', 'error');
                return;
            }

            // Enhanced confirmation with folder info
            const confirmContent = `
                <div class="s3-folder-delete-confirm">
                    <p><strong>You are about to delete:</strong></p>
                    <div class="folder-info">
                        <span class="dashicons dashicons-category"></span>
                        <code>${S3.escapeHtml(folderName)}</code>
                    </div>
                    <div class="warning-box">
                        <p><strong>⚠️ Warning:</strong></p>
                        <ul>
                            <li>This will delete the folder and <strong>all its contents</strong></li>
                            <li>All files and subfolders will be permanently removed</li>
                            <li>This action <strong>cannot be undone</strong></li>
                        </ul>
                    </div>
                    <p>Type <strong>"${folderName}"</strong> to confirm deletion:</p>
                    <input type="text" id="confirmFolderName" class="confirm-input" placeholder="Type folder name here">
                </div>
            `;

            const modal = S3M.show(
                'confirmDeleteFolder',
                `Delete Folder: ${folderName}`,
                confirmContent,
                [
                    {
                        text: s3BrowserConfig.i18n.ui.cancel,
                        action: 'cancel',
                        classes: 'button-secondary',
                        callback: () => S3M.hide('confirmDeleteFolder')
                    },
                    {
                        text: 'Delete Folder',
                        action: 'delete',
                        classes: 'button-primary button-destructive',
                        disabled: true,
                        callback: () => this.performFolderDeletion($button, folderName, folderPath)
                    }
                ],
                { closeOnOverlay: false }
            );

            // Setup confirmation validation
            this.setupDeleteConfirmation(modal, folderName);
        },

        /**
         * Setup deletion confirmation validation
         */
        setupDeleteConfirmation: function (modal, folderName) {
            modal.on('input', '#confirmFolderName', (e) => {
                const $deleteBtn = modal.find('button[data-action="delete"]');
                const isMatch = e.target.value.trim() === folderName;
                $deleteBtn.prop('disabled', !isMatch);
            });

            modal.on('keydown', '#confirmFolderName', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const $deleteBtn = modal.find('button[data-action="delete"]');
                    if (!$deleteBtn.prop('disabled')) {
                        $deleteBtn.trigger('click');
                    }
                }
            });

            // Focus the confirmation input
            setTimeout(() => modal.find('#confirmFolderName').focus(), 300);
        },

        /**
         * Perform folder deletion with progress tracking
         */
        performFolderDeletion: function ($button, folderName, folderPath) {
            const bucket = $button.data('bucket');

            S3M.hide('confirmDeleteFolder');

            // Show progress modal
            const progressModal = S3M.progress(
                'Deleting Folder',
                s3BrowserConfig.i18n.folders.deletingFolderProgress.replace('{name}', folderName)
            );

            S3.ajax('s3_delete_folder_', {
                bucket: bucket,
                folder_path: folderPath,
                recursive: true
            }, {
                success: (response) => {
                    progressModal.update(s3BrowserConfig.i18n.folders.folderDeletedSuccess);

                    setTimeout(() => {
                        progressModal.close();
                        S3.notify(
                            response.data.message || s3BrowserConfig.i18n.folders.deleteFolderSuccess,
                            'success'
                        );

                        // Smooth page refresh
                        setTimeout(() => window.location.reload(), 1500);
                    }, 1000);
                },
                error: (message) => {
                    progressModal.close();
                    S3.notify(message, 'error');
                }
            });
        },

        // ===========================================
        // FOLDER CREATION
        // ===========================================

        /**
         * Open enhanced folder creation modal
         */
        openCreateFolderModal: function (bucket, prefix = '') {
            if (!bucket) {
                S3.notify('Bucket information is required', 'error');
                return;
            }

            const content = this.buildCreateFolderContent(prefix);
            const buttons = this.buildCreateFolderButtons(bucket, prefix);

            const modal = S3M.show(
                's3FolderModal',
                s3BrowserConfig.i18n.folders.newFolder,
                content,
                buttons
            );

            // Setup validation and interactions
            this.setupCreateFolderValidation(modal, bucket, prefix);

            // Focus the input
            setTimeout(() => modal.find('#s3FolderNameInput').focus(), 250);
        },

        /**
         * Build create folder modal content
         */
        buildCreateFolderContent: function (prefix) {
            const currentPath = prefix ? `Current location: ${prefix}` : 'Creating in root directory';

            return `
                <div class="s3-folder-create-content">
                    <div class="current-location">
                        <small>${currentPath}</small>
                    </div>
                    
                    <div class="s3-modal-field">
                        <label for="s3FolderNameInput">${s3BrowserConfig.i18n.folders.folderName}</label>
                        <input type="text" id="s3FolderNameInput" maxlength="63" 
                               placeholder="${s3BrowserConfig.i18n.folders.folderNamePlaceholder}"
                               autocomplete="off">
                        <p class="description">${s3BrowserConfig.i18n.folders.folderNameHelp}</p>
                    </div>

                    <div class="folder-preview" style="display: none;">
                        <p><strong>Folder will be created as:</strong></p>
                        <div class="preview-path">
                            <span class="dashicons dashicons-category"></span>
                            <code id="folderPreviewPath"></code>
                        </div>
                    </div>
                </div>
            `;
        },

        /**
         * Build create folder modal buttons
         */
        buildCreateFolderButtons: function (bucket, prefix) {
            return [
                {
                    text: s3BrowserConfig.i18n.ui.cancel,
                    action: 'cancel',
                    classes: 'button-secondary',
                    callback: () => S3M.hide('s3FolderModal')
                },
                {
                    text: s3BrowserConfig.i18n.folders.createFolder,
                    action: 'submit',
                    classes: 'button-primary',
                    disabled: true,
                    callback: () => this.submitFolderCreation(bucket, prefix)
                }
            ];
        },

        /**
         * Setup create folder validation with real-time feedback
         */
        setupCreateFolderValidation: function (modal, bucket, prefix) {
            const $input = modal.find('#s3FolderNameInput');
            const $submitBtn = modal.find('button[data-action="submit"]');
            const $preview = modal.find('.folder-preview');
            const $previewPath = modal.find('#folderPreviewPath');

            const validateInput = S3.debounce((folderName) => {
                S3M.clearMessages('s3FolderModal');

                if (!folderName) {
                    $submitBtn.prop('disabled', true);
                    $preview.hide();
                    return;
                }

                // Validate folder name
                const validation = S3.validateFolderName(folderName);

                if (!validation.valid) {
                    S3M.showError('s3FolderModal', validation.message);
                    $submitBtn.prop('disabled', true);
                    $preview.hide();
                } else {
                    // Show preview of final path
                    const fullPath = prefix ? `${prefix}${folderName}/` : `${folderName}/`;
                    $previewPath.text(fullPath);
                    $preview.show();
                    $submitBtn.prop('disabled', false);
                }
            }, 300);

            // Real-time validation
            $input.on('input', (e) => {
                const folderName = e.target.value.trim();
                validateInput(folderName);
            });

            // Enter key handling
            $input.on('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    if (!$submitBtn.prop('disabled')) {
                        $submitBtn.trigger('click');
                    }
                }
            });

            // Auto-format folder name (replace spaces with dashes if desired)
            $input.on('blur', (e) => {
                const value = e.target.value.trim();
                if (value && value !== e.target.value) {
                    e.target.value = value;
                    validateInput(value);
                }
            });
        },

        /**
         * Submit folder creation
         */
        submitFolderCreation: function (bucket, prefix) {
            const folderName = $('#s3FolderNameInput').val().trim();

            // Final validation
            const validation = S3.validateFolderName(folderName);
            if (!validation.valid) {
                S3M.showError('s3FolderModal', validation.message);
                return;
            }

            this.performFolderCreation(bucket, prefix, folderName);
        },

        /**
         * Perform folder creation with progress feedback
         */
        performFolderCreation: function (bucket, prefix, folderName) {
            S3M.setLoading('s3FolderModal', true, s3BrowserConfig.i18n.folders.creatingFolder);

            S3.ajax('s3_create_folder_', {
                bucket: bucket,
                prefix: prefix,
                folder_name: folderName
            }, {
                success: (response) => {
                    const successMessage = response.data.message ||
                        s3BrowserConfig.i18n.folders.createFolderSuccess.replace('{name}', folderName);

                    S3.notify(successMessage, 'success');
                    S3M.hide('s3FolderModal');

                    // Navigate to new folder or refresh
                    setTimeout(() => {
                        if (response.data.folder_key) {
                            // Navigate to the new folder
                            S3.navigate({
                                bucket: bucket,
                                prefix: response.data.folder_key
                            });
                        } else {
                            // Fallback to page refresh
                            window.location.reload();
                        }
                    }, 1500);
                },
                error: (message) => S3M.showError('s3FolderModal', message)
            });
        },

        // ===========================================
        // UTILITY FUNCTIONS
        // ===========================================

        /**
         * Get folder hierarchy for breadcrumbs
         */
        getFolderHierarchy: function (prefix) {
            if (!prefix || prefix === '/') return [];

            const parts = prefix.split('/').filter(part => part.length > 0);
            const hierarchy = [];
            let currentPath = '';

            parts.forEach((part, index) => {
                currentPath += part + '/';
                hierarchy.push({
                    name: part,
                    path: currentPath,
                    isLast: index === parts.length - 1
                });
            });

            return hierarchy;
        },

        /**
         * Format folder size for display
         */
        formatFolderInfo: function (itemCount, totalSize) {
            const items = itemCount === 1 ? 'item' : 'items';
            const size = totalSize ? ` (${S3.formatSize(totalSize)})` : '';
            return `${itemCount} ${items}${size}`;
        },

        /**
         * Check if folder name conflicts with existing items
         */
        checkFolderNameConflict: function (folderName, existingItems = []) {
            const normalizedName = folderName.toLowerCase();

            return existingItems.some(item => {
                const itemName = item.name || item.filename || item.key;
                return itemName && itemName.toLowerCase() === normalizedName;
            });
        },

        /**
         * Generate folder suggestions based on context
         */
        generateFolderSuggestions: function (context = 'general') {
            const suggestions = {
                general: ['documents', 'images', 'downloads', 'uploads'],
                media: ['photos', 'videos', 'audio', 'graphics'],
                backup: ['daily', 'weekly', 'monthly', 'archive'],
                project: ['assets', 'resources', 'output', 'temp']
            };

            return suggestions[context] || suggestions.general;
        }
    };

    // Global shorthand
    window.S3Folder = window.S3Folders;

})(jQuery);