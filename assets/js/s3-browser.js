/**
 * S3 Browser Core Functionality - Organized Edition
 * Handles browsing, searching, file operations, folder management, and WordPress integrations
 *
 * ========================================
 * TABLE OF CONTENTS
 * ========================================
 * 1. INITIALIZATION & SETUP (line 50)
 * 2. EVENT BINDING SYSTEM (line 150)
 * 3. PROGRESS INDICATORS & OVERLAYS (line 350)
 * 4. MODAL SYSTEM (line 450)
 * 5. FOLDER MANAGEMENT (line 650)
 * 6. FILE OPERATIONS (line 900)
 * 7. SEARCH & FILTERING (line 1200)
 * 8. LOAD MORE & PAGINATION (line 1400)
 * 9. CACHE & REFRESH (line 1550)
 * 10. UPLOAD INTEGRATION (line 1600)
 * 11. UTILITY FUNCTIONS (line 1700)
 * ========================================
 */
(function ($) {
    'use strict';

    // Prevent double initialization
    if (window.S3BrowserInitialized) return;
    window.S3BrowserInitialized = true;

    // Main S3Browser object
    window.S3Browser = {
        // State variables
        originalTableData: null,
        searchTimeout: null,
        totalLoadedItems: 0,
        isLoading: false,
        hasActiveUploads: false,
        currentBucket: null,
        currentPrefix: null,
        currentRename: null,
        currentCopyLink: null,

        // Translation strings (populated by PHP)
        i18n: {},

        /* ========================================
         * 1. INITIALIZATION & SETUP
         * ======================================== */

        /**
         * Initialize the S3 Browser
         */
        init: function () {
            this.loadTranslations();
            this.bindEvents();
            this.setupJSSearch();
            this.setupAjaxLoading();
            this.countInitialItems();
            this.initUploadToggle();
            this.initRowActions();
        },

        /**
         * Load translation strings from PHP
         */
        loadTranslations: function () {
            if (typeof s3BrowserConfig !== 'undefined' && s3BrowserConfig.i18n) {
                this.i18n = s3BrowserConfig.i18n;
            }
        },

        /**
         * Initialize WordPress-style row actions and action buttons
         */
        initRowActions: function () {
            var self = this;

            // Handle row action clicks with event delegation
            $(document).off('click.s3rowactions').on('click.s3rowactions', '.wp-list-table .row-actions a', function (e) {
                e.preventDefault();
                var $link = $(this);

                // Handle different actions
                if ($link.hasClass('s3-download-file')) {
                    window.open($link.data('url'), '_blank');
                } else if ($link.hasClass('s3-delete-file')) {
                    self.deleteFile($link);
                } else if ($link.hasClass('s3-delete-folder')) {
                    self.deleteFolderConfirm($link);
                } else if ($link.hasClass('s3-rename-file')) {
                    self.openRenameModal($link);
                } else if ($link.hasClass('s3-copy-link')) {
                    self.openCopyLinkModal($link);
                } else if ($link.hasClass('s3-show-details')) {
                    self.openDetailsModal($link);
                }
            });

            // Handle Insert File button clicks
            $(document).off('click.s3insertfile').on('click.s3insertfile', '.s3-insert-file', function (e) {
                e.preventDefault();
                self.handleFileSelection($(this));
            });

            // Handle Open Folder button clicks
            $(document).off('click.s3openfolder').on('click.s3openfolder', '.s3-open-folder', function (e) {
                e.preventDefault();
                self.handleFolderOpen($(this));
            });

            // Show/hide row actions on hover - WordPress standard behavior
            $(document).off('mouseenter.s3rowactions mouseleave.s3rowactions')
                .on('mouseenter.s3rowactions', '.wp-list-table tbody tr', function () {
                    $(this).find('.row-actions').css('visibility', 'visible');
                })
                .on('mouseleave.s3rowactions', '.wp-list-table tbody tr', function () {
                    $(this).find('.row-actions').css('visibility', 'hidden');
                });
        },

        /* ========================================
         * 2. EVENT BINDING SYSTEM
         * ======================================== */

        /**
         * Bind all event handlers
         */
        bindEvents: function () {
            this.bindNavigationEvents();
            this.bindFileActionEvents();
            this.bindSearchEvents();
            this.bindLoadMoreEvents();
            this.bindFolderEvents();
            this.bindRenameEvents();
            this.bindCopyLinkEvents();
            this.bindRefreshEvents();
            this.bindModalEvents();
            this.bindDetailsEvents();
        },

        /**
         * Bind navigation event handlers
         */
        bindNavigationEvents: function () {
            var self = this;

            $(document).off('click.s3nav').on('click.s3nav', '.s3-browser-container a', function (e) {
                var $link = $(this);

                if ($link.hasClass('bucket-name') || $link.hasClass('browse-bucket-button')) {
                    e.preventDefault();
                    self.navigateTo({bucket: $link.data('bucket')});
                }

                if ($link.hasClass('s3-folder-link')) {
                    e.preventDefault();
                    var config = S3BrowserGlobalConfig;
                    self.navigateTo({
                        bucket: $link.data('bucket') || $('#s3-load-more').data('bucket') || config.defaultBucket,
                        prefix: $link.data('prefix')
                    });
                }
            });
        },

        /**
         * Bind details modal event handlers
         */
        bindDetailsEvents: function () {
            var self = this;

            // Handle details link clicks
            $(document).off('click.s3details').on('click.s3details', '.s3-show-details', function (e) {
                e.preventDefault();
                self.openDetailsModal($(this));
            });
        },

        /**
         * Bind file action event handlers (for buttons outside table)
         */
        bindFileActionEvents: function () {
            var self = this;

            $(document).off('click.s3files').on('click.s3files', '.s3-browser-container', function (e) {
                var $target = $(e.target);

                // Check if clicked element is the star or has star classes
                if ($target.hasClass('s3-favorite-bucket') || $target.hasClass('s3-favorite-star')) {
                    e.preventDefault();
                    e.stopPropagation();
                    self.toggleFavoriteBucket($target);
                    return;
                }

                // Also check closest in case of nested elements
                var $starTarget = $target.closest('.s3-favorite-bucket, .s3-favorite-star');
                if ($starTarget.length) {
                    e.preventDefault();
                    e.stopPropagation();
                    self.toggleFavoriteBucket($starTarget);
                }
            });
        },

        /**
         * Bind search event handlers
         */
        bindSearchEvents: function () {
            var self = this;

            $('#s3-js-search').off('input.s3browser').on('input.s3browser', function () {
                var $this = $(this);
                $('#s3-js-search-clear').toggle(Boolean($this.val()));

                clearTimeout(self.searchTimeout);
                self.searchTimeout = setTimeout(function () {
                    self.filterTable($this.val());
                }, 200);
            });

            $('#s3-js-search-clear').off('click.s3browser').on('click.s3browser', function () {
                $('#s3-js-search').val('').trigger('input');
            });
        },

        /**
         * Bind load more event handlers
         */
        bindLoadMoreEvents: function () {
            var self = this;

            $(document).off('click.s3loadmore').on('click.s3loadmore', '#s3-load-more', function (e) {
                e.preventDefault();
                if (self.isLoading) return;

                var $button = $(this);
                self.loadMoreItems($button.data('token'), $button.data('bucket'), $button.data('prefix'), $button);
            });
        },

        /**
         * Bind refresh event handlers
         */
        bindRefreshEvents: function () {
            var self = this;

            $(document).off('click.s3refresh').on('click.s3refresh', '.s3-refresh-button', function (e) {
                e.preventDefault();
                self.refreshCache($(this));
            });
        },

        /**
         * Bind copy link event handlers
         */
        bindCopyLinkEvents: function () {
            // No additional binding needed - handled by row actions
        },

        /* ========================================
         * 3. PROGRESS INDICATORS & OVERLAYS
         * ======================================== */

        /**
         * Show progress overlay for long operations
         */
        showProgressOverlay: function (message, canCancel) {
            var self = this;

            // Remove any existing overlay
            $('.s3-progress-overlay').remove();

            var overlay = $([
                '<div class="s3-progress-overlay">',
                '  <div class="s3-progress-modal">',
                '    <div class="s3-progress-content">',
                '      <div class="s3-progress-spinner">',
                '        <div class="spinner is-active"></div>',
                '      </div>',
                '      <div class="s3-progress-message">' + message + '</div>',
                '      <div class="s3-progress-details"></div>',
                canCancel ? '      <button type="button" class="button s3-progress-cancel">Cancel</button>' : '',
                '    </div>',
                '  </div>',
                '</div>'
            ].join(''));

            $('body').append(overlay);
            overlay.fadeIn(200);

            // Handle cancel if enabled
            if (canCancel) {
                overlay.find('.s3-progress-cancel').on('click', function () {
                    self.hideProgressOverlay();
                    // You could add actual cancellation logic here if needed
                });
            }

            return overlay;
        },

        /**
         * Update progress overlay message
         */
        updateProgressOverlay: function (message, details) {
            var $overlay = $('.s3-progress-overlay');
            if ($overlay.length) {
                $overlay.find('.s3-progress-message').text(message);
                if (details) {
                    $overlay.find('.s3-progress-details').text(details);
                }
            }
        },

        /**
         * Hide progress overlay
         */
        hideProgressOverlay: function () {
            $('.s3-progress-overlay').fadeOut(200, function () {
                $(this).remove();
            });
        },

        /* ========================================
         * 4. MODAL SYSTEM
         * ======================================== */

        /**
         * Create a generic modal
         */
        createModal: function (options) {
            var defaults = {
                id: 's3Modal',
                title: 'Modal',
                width: '500px',
                fields: [],
                buttons: [],
                onOpen: null,
                onClose: null
            };

            var config = $.extend({}, defaults, options);

            // Remove existing modal with same ID
            $('#' + config.id).remove();

            // Build field HTML
            var fieldsHtml = '';
            config.fields.forEach(function (field) {
                fieldsHtml += '<div class="s3-modal-field">';

                if (field.type === 'custom') {
                    // Custom HTML field
                    fieldsHtml += field.html || '';
                } else {
                    if (field.label) {
                        fieldsHtml += '<label for="' + field.id + '">' + field.label + '</label>';
                    }

                    if (field.type === 'text') {
                        fieldsHtml += '<input type="text" id="' + field.id + '" ' +
                            'placeholder="' + (field.placeholder || '') + '" ' +
                            'maxlength="' + (field.maxlength || '') + '" autocomplete="off">';
                    } else if (field.type === 'number') {
                        fieldsHtml += '<input type="number" id="' + field.id + '" ' +
                            'placeholder="' + (field.placeholder || '') + '" ' +
                            'min="' + (field.min || '') + '" ' +
                            'max="' + (field.max || '') + '" ' +
                            'value="' + (field.value || '') + '">';
                    } else if (field.type === 'select') {
                        fieldsHtml += '<select id="' + field.id + '">';
                        if (field.options) {
                            field.options.forEach(function (option) {
                                var selected = option.value === field.value ? 'selected' : '';
                                fieldsHtml += '<option value="' + option.value + '" ' + selected + '>' + option.label + '</option>';
                            });
                        }
                        fieldsHtml += '</select>';
                    } else if (field.type === 'textarea') {
                        fieldsHtml += '<textarea id="' + field.id + '" rows="' + (field.rows || 3) + '" ' +
                            'placeholder="' + (field.placeholder || '') + '" readonly>' + (field.value || '') + '</textarea>';
                    }

                    if (field.description) {
                        fieldsHtml += '<p class="description">' + field.description + '</p>';
                    }
                }

                fieldsHtml += '</div>';
            });

            // Build buttons HTML
            var buttonsHtml = '';
            config.buttons.forEach(function (button) {
                var classes = 'button ' + (button.classes || '');
                var disabled = button.disabled ? 'disabled' : '';
                buttonsHtml += '<button type="button" class="' + classes + '" ' +
                    'data-action="' + button.action + '" ' + disabled + '>' +
                    button.text + '</button>';
            });

            // Create modal HTML
            var modalHtml = [
                '<div id="' + config.id + '" class="s3-modal-overlay" style="display: none;">',
                '<div class="s3-modal" style="max-width: ' + config.width + ';">',
                '<div class="s3-modal-header">',
                '<h2>' + config.title + '</h2>',
                '<button type="button" class="s3-modal-close">&times;</button>',
                '</div>',
                '<div class="s3-modal-body">',
                '<div class="s3-modal-error" style="display: none;"></div>',
                fieldsHtml,
                '<div class="s3-modal-loading" style="display: none;">',
                '<span class="spinner is-active"></span><span class="loading-text"></span>',
                '</div>',
                '</div>',
                '<div class="s3-modal-footer">',
                buttonsHtml,
                '</div>',
                '</div>',
                '</div>'
            ].join('');

            $('body').append(modalHtml);

            var $modal = $('#' + config.id);

            // Store config and callbacks on modal
            $modal.data('config', config);

            return $modal;
        },

        /**
         * Show modal
         */
        showModal: function (modalId, onOpenCallback) {
            var $modal = $('#' + modalId);
            if (!$modal.length) return;

            $modal.fadeIn(200);

            if (onOpenCallback) {
                setTimeout(onOpenCallback, 250);
            }
        },

        /**
         * Hide modal
         */
        hideModal: function (modalId, onCloseCallback) {
            var $modal = $('#' + modalId);
            if (!$modal.length) return;

            $modal.fadeOut(200);

            if (onCloseCallback) {
                onCloseCallback();
            }
        },

        /**
         * Set modal loading state
         */
        setModalLoading: function (modalId, isLoading, loadingText) {
            var $modal = $('#' + modalId);
            if (!$modal.length) return;

            var $loading = $modal.find('.s3-modal-loading');
            var $buttons = $modal.find('.s3-modal-footer button');
            var $error = $modal.find('.s3-modal-error');

            if (isLoading) {
                $loading.find('.loading-text').text(loadingText || 'Loading...');
                $loading.show();
                $buttons.prop('disabled', true);
                $error.hide();
            } else {
                $loading.hide();
                $buttons.prop('disabled', false);
            }
        },

        /**
         * Show modal error
         */
        showModalError: function (modalId, message) {
            var $modal = $('#' + modalId);
            if (!$modal.length) return;

            $modal.find('.s3-modal-error').text(message).show();
            this.setModalLoading(modalId, false);
        },

        /**
         * Bind generic modal events
         */
        bindModalEvents: function () {
            var self = this;

            // Modal close events
            $(document).off('click.s3modal').on('click.s3modal', '.s3-modal-overlay, .s3-modal-close', function (e) {
                if (e.target === this) {
                    var $modal = $(this).closest('.s3-modal-overlay');
                    var config = $modal.data('config');
                    self.hideModal($modal.attr('id'), config ? config.onClose : null);
                }
            });

            // Escape key to close modal
            $(document).off('keydown.s3modal').on('keydown.s3modal', function (e) {
                if (e.key === 'Escape') {
                    var $visibleModal = $('.s3-modal-overlay:visible');
                    if ($visibleModal.length) {
                        var config = $visibleModal.data('config');
                        self.hideModal($visibleModal.attr('id'), config ? config.onClose : null);
                    }
                }
            });

            // Button click events
            $(document).off('click.s3modalbutton').on('click.s3modalbutton', '.s3-modal-footer button[data-action]', function () {
                var $button = $(this);
                var $modal = $button.closest('.s3-modal-overlay');
                var action = $button.data('action');
                var config = $modal.data('config');

                if (config && config.buttons) {
                    var buttonConfig = config.buttons.find(function (btn) {
                        return btn.action === action;
                    });
                    if (buttonConfig && buttonConfig.callback) {
                        buttonConfig.callback($modal, $button);
                    }
                }
            });
        },

        /* ========================================
         * 5. FOLDER MANAGEMENT
         * ======================================== */

        /**
         * Bind folder creation events
         */
        bindFolderEvents: function () {
            var self = this;

            // Create folder button
            $(document).off('click.s3folder').on('click.s3folder', '#s3-create-folder', function (e) {
                e.preventDefault();
                var $button = $(this);
                self.openCreateFolderModal($button.data('bucket'), $button.data('prefix'));
            });
        },

        /**
         * Handle folder open button click
         */
        handleFolderOpen: function ($button) {
            var prefix = $button.data('prefix');
            var bucket = $button.data('bucket');

            // Add loading state to the button
            var originalHtml = $button.html();
            $button.html('<span class="dashicons dashicons-update s3-spin"></span> ' + (this.i18n.opening || 'Opening...'));
            $button.prop('disabled', true);

            // Navigate to the folder using existing navigation method
            try {
                this.navigateTo({
                    bucket: bucket,
                    prefix: prefix
                });
            } catch (error) {
                // Reset button state if navigation fails
                $button.html(originalHtml);
                $button.prop('disabled', false);
                this.showNotification(
                    this.i18n.folderOpenError || 'Failed to open folder',
                    'error'
                );
            }
        },

        /**
         * Confirm folder deletion with proper message formatting
         */
        deleteFolderConfirm: function ($button) {
            var folderName = $button.data('folder-name');

            // Fix the \n\n issue by using proper line breaks
            var confirmMessage = this.i18n.confirmDeleteFolder
                ? this.i18n.confirmDeleteFolder.replace('{foldername}', folderName).replace(/\\n/g, '\n')
                : 'Are you sure you want to delete the folder "' + folderName + '" and all its contents?\n\nThis action cannot be undone.';

            if (!window.confirm(confirmMessage)) return;

            this.deleteFolder($button);
        },

        /**
         * Delete a folder from S3 with progress indicator
         */
        deleteFolder: function ($button) {
            var self = this;
            var folderName = $button.data('folder-name');
            var bucket = $button.data('bucket');
            var folderPath = $button.data('prefix');

            // Show progress overlay for bulk operations
            this.showProgressOverlay(
                'Deleting folder "' + folderName + '"...',
                false // Don't allow cancel for now
            );

            this.makeAjaxRequest('s3_delete_folder_', {
                bucket: bucket,
                folder_path: folderPath,
                recursive: true
            }, {
                success: function (response) {
                    self.updateProgressOverlay('Folder deleted successfully!');

                    setTimeout(function () {
                        self.hideProgressOverlay();
                        self.showNotification(
                            response.data.message || self.i18n.deleteFolderSuccess || 'Folder deleted successfully',
                            'success'
                        );

                        // Reload the page after successful deletion
                        setTimeout(function () {
                            window.location.reload();
                        }, 1500);
                    }, 500);
                },
                error: function (message) {
                    self.hideProgressOverlay();
                    self.showNotification(message, 'error');
                    self.setButtonLoading($button, false);
                }
            });
        },

        /**
         * Open folder creation modal
         */
        openCreateFolderModal: function (bucket, prefix) {
            var self = this;
            this.currentBucket = bucket;
            this.currentPrefix = prefix || '';

            var modal = this.createModal({
                id: 's3FolderModal',
                title: this.i18n.newFolder || 'New Folder',
                fields: [{
                    id: 's3FolderNameInput',
                    type: 'text',
                    label: this.i18n.folderName || 'Folder Name',
                    placeholder: this.i18n.folderNamePlaceholder || 'Enter folder name',
                    maxlength: 63,
                    description: this.i18n.folderNameHelp || 'Enter a name for the new folder. Use only letters, numbers, dots, hyphens, and underscores.'
                }],
                buttons: [{
                    text: this.i18n.cancel || 'Cancel',
                    action: 'cancel',
                    callback: function () {
                        self.hideModal('s3FolderModal');
                    }
                }, {
                    text: this.i18n.createFolder || 'Create Folder',
                    action: 'submit',
                    classes: 'button-primary',
                    disabled: true,
                    callback: function () {
                        self.submitFolderForm();
                    }
                }],
                onClose: function () {
                    // Cleanup when modal closes
                }
            });

            // Bind folder-specific validation
            modal.on('keyup', '#s3FolderNameInput', function (e) {
                self.validateFolderInput(e, modal);
            }).on('keydown', '#s3FolderNameInput', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    self.submitFolderForm();
                }
            });

            this.showModal('s3FolderModal', function () {
                $('#s3FolderNameInput').focus();
            });
        },

        /**
         * Validate folder input
         */
        validateFolderInput: function (e, $modal) {
            var folderName = e.target.value.trim();
            var $submit = $modal.find('button[data-action="submit"]');
            var $error = $modal.find('.s3-modal-error');

            $error.hide();
            var validation = this.validateFolderName(folderName);

            if (!validation.valid && folderName.length > 0) {
                $error.text(validation.message).show();
            }

            $submit.prop('disabled', !validation.valid);
        },

        /**
         * Validate folder name
         */
        validateFolderName: function (folderName) {
            var i18n = this.i18n;

            if (folderName.length === 0) {
                return {valid: false, message: i18n.folderNameRequired || 'Folder name is required'};
            }
            if (folderName.length > 63) {
                return {valid: false, message: i18n.folderNameTooLong || 'Folder name cannot exceed 63 characters'};
            }
            if (!/^[a-zA-Z0-9._-]+$/.test(folderName)) {
                return {
                    valid: false,
                    message: i18n.folderNameInvalidChars || 'Folder name can only contain letters, numbers, dots, hyphens, and underscores'
                };
            }
            if (['.', '-'].includes(folderName[0]) || ['.', '-'].includes(folderName[folderName.length - 1])) {
                return {valid: false, message: 'Folder name cannot start or end with dots or hyphens'};
            }
            if (folderName.includes('..')) {
                return {valid: false, message: 'Folder name cannot contain consecutive dots'};
            }

            return {valid: true, message: ''};
        },

        /**
         * Submit folder creation form
         */
        submitFolderForm: function () {
            var folderName = $('#s3FolderNameInput').val().trim();
            var validation = this.validateFolderName(folderName);

            if (!validation.valid) {
                this.showModalError('s3FolderModal', validation.message);
                return;
            }

            this.createFolder(folderName);
        },

        /**
         * Create folder via AJAX
         */
        createFolder: function (folderName) {
            var self = this;

            this.setModalLoading('s3FolderModal', true, this.i18n.creatingFolder || 'Creating folder...');

            this.makeAjaxRequest('s3_create_folder_', {
                bucket: this.currentBucket,
                prefix: this.currentPrefix,
                folder_name: folderName
            }, {
                success: function (response) {
                    var successMessage = response.data.message ||
                        (self.i18n.createFolderSuccess && self.i18n.createFolderSuccess.replace('{name}', folderName)) ||
                        'Folder "' + folderName + '" created successfully';

                    self.showNotification(successMessage, 'success');
                    self.hideModal('s3FolderModal');
                    setTimeout(function () {
                        window.location.reload();
                    }, 1500);
                },
                error: function (message) {
                    self.showModalError('s3FolderModal', message);
                }
            });
        },

        /* ========================================
         * 6. FILE OPERATIONS
         * ======================================== */

        /**
         * Bind rename event handlers
         */
        bindRenameEvents: function () {
            // No additional binding needed - handled by row actions
        },

        /**
         * Open file details modal
         */
        openDetailsModal: function ($trigger) {
            var self = this;
            var key = $trigger.data('key');

            // Find the row with the file data
            var $row = $trigger.closest('tr');
            if (!$row.length) {
                // Try to find by key if not in same row
                $row = $('.wp-list-table tr').find('[data-key="' + key + '"]').closest('tr');
            }

            if (!$row.length) {
                this.showNotification('Could not find file data', 'error');
                return;
            }

            // Extract file data from row data attributes
            var $fileElement = $row.find('[data-key]');
            var fileData = {
                filename: $fileElement.data('filename'),
                key: $fileElement.data('key'),
                sizeBytes: $fileElement.data('size-bytes'),
                sizeFormatted: $fileElement.data('size-formatted'),
                modified: $fileElement.data('modified'),
                modifiedFormatted: $fileElement.data('modified-formatted'),
                etag: $fileElement.data('etag'),
                md5: $fileElement.data('md5'),
                isMultipart: $fileElement.data('is-multipart') === 'true',
                storageClass: $fileElement.data('storage-class'),
                mimeType: $fileElement.data('mime-type'),
                category: $fileElement.data('category'),
                partCount: $fileElement.data('part-count') || null
            };

            this.showDetailsModal(fileData);
        },

        /**
         * Show file details modal
         */
        showDetailsModal: function (fileData) {
            var self = this;

            // Format checksum information
            var checksumInfo = this.formatChecksumInfo(fileData);

            // Build details HTML
            var detailsHtml = this.buildDetailsHtml(fileData, checksumInfo);

            var modal = this.createModal({
                id: 's3DetailsModal',
                title: fileData.filename,
                width: '600px',
                fields: [{
                    id: 's3DetailsContent',
                    type: 'custom',
                    html: detailsHtml
                }],
                buttons: [{
                    text: this.i18n.cancel || 'Close',
                    action: 'close',
                    classes: 'button-secondary',
                    callback: function () {
                        self.hideModal('s3DetailsModal');
                    }
                }, {
                    text: this.i18n.copyLink || 'Copy Link',
                    action: 'copy_link',
                    classes: 'button-primary',
                    callback: function () {
                        self.hideModal('s3DetailsModal');
                        // Trigger copy link modal with same data
                        setTimeout(function () {
                            var $mockButton = $('<div>').data({
                                filename: fileData.filename,
                                bucket: self.currentBucket || S3BrowserGlobalConfig.defaultBucket,
                                key: fileData.key
                            });
                            self.openCopyLinkModal($mockButton);
                        }, 100);
                    }
                }],
                onClose: function () {
                    // Cleanup if needed
                }
            });

            this.showModal('s3DetailsModal');
        },

        /**
         * Format checksum information for display
         */
        formatChecksumInfo: function (fileData) {
            if (!fileData.md5) {
                return {
                    display: 'No checksum available',
                    type: 'None',
                    class: 's3-checksum-none'
                };
            }

            if (fileData.isMultipart) {
                var partText = fileData.partCount ?
                    fileData.partCount + ' parts' :
                    'multiple parts';
                return {
                    display: fileData.md5,
                    type: 'MD5 (Composite)',
                    note: 'Hash of hashes from ' + partText + ' - not directly verifiable against file content',
                    class: 's3-checksum-multipart'
                };
            } else {
                return {
                    display: fileData.md5,
                    type: 'MD5',
                    note: 'Direct MD5 of file content - can be verified after download',
                    class: 's3-checksum-single'
                };
            }
        },

        /**
         * Build details HTML content
         */
        buildDetailsHtml: function (fileData, checksumInfo) {
            return [
                '<div class="s3-details-content">',
                '  <div class="s3-details-section">',
                '    <h4>Basic Information</h4>',
                '    <table class="s3-details-table">',
                '      <tr><td><strong>Filename:</strong></td><td>' + this.escapeHtml(fileData.filename) + '</td></tr>',
                '      <tr><td><strong>Object Key:</strong></td><td><code>' + this.escapeHtml(fileData.key) + '</code></td></tr>',
                '      <tr><td><strong>Size:</strong></td><td>' + fileData.sizeFormatted + ' (' + this.numberFormat(fileData.sizeBytes) + ' bytes)</td></tr>',
                '      <tr><td><strong>Last Modified:</strong></td><td>' + fileData.modifiedFormatted + '</td></tr>',
                '      <tr><td><strong>MIME Type:</strong></td><td>' + this.escapeHtml(fileData.mimeType) + '</td></tr>',
                '      <tr><td><strong>Category:</strong></td><td>' + this.escapeHtml(fileData.category) + '</td></tr>',
                '    </table>',
                '  </div>',
                '  <div class="s3-details-section">',
                '    <h4>Storage Information</h4>',
                '    <table class="s3-details-table">',
                '      <tr><td><strong>Storage Class:</strong></td><td>' + this.escapeHtml(fileData.storageClass) + '</td></tr>',
                '      <tr><td><strong>ETag:</strong></td><td><code>' + this.escapeHtml(fileData.etag) + '</code></td></tr>',
                fileData.isMultipart ?
                    '      <tr><td><strong>Upload Type:</strong></td><td>Multipart' + (fileData.partCount ? ' (' + fileData.partCount + ' parts)' : '') + '</td></tr>' :
                    '      <tr><td><strong>Upload Type:</strong></td><td>Single-part</td></tr>',
                '    </table>',
                '  </div>',
                '  <div class="s3-details-section">',
                '    <h4>Checksum Information</h4>',
                '    <table class="s3-details-table">',
                '      <tr><td><strong>Type:</strong></td><td><span class="' + checksumInfo.class + '">' + checksumInfo.type + '</span></td></tr>',
                '      <tr><td><strong>Value:</strong></td><td><code class="' + checksumInfo.class + '">' + checksumInfo.display + '</code></td></tr>',
                checksumInfo.note ?
                    '      <tr><td colspan="2"><small class="description">' + checksumInfo.note + '</small></td></tr>' : '',
                '    </table>',
                '  </div>',
                '</div>'
            ].join('');
        },

        /**
         * Open copy link modal for a file
         */
        openCopyLinkModal: function ($button) {
            var self = this;
            var filename = $button.data('filename');
            var bucket = $button.data('bucket');
            var key = $button.data('key');

            // Store current copy link context
            this.currentCopyLink = {
                filename: filename,
                bucket: bucket,
                key: key,
                $button: $button
            };

            var modal = this.createModal({
                id: 's3CopyLinkModal',
                title: this.i18n.copyLink || 'Copy Link',
                width: '600px',
                fields: [{
                    id: 's3ExpiresInput',
                    type: 'number',
                    label: this.i18n.linkDuration || 'Link Duration (minutes)',
                    placeholder: '60',
                    min: 1,
                    max: 10080,
                    value: 60,
                    description: this.i18n.linkDurationHelp || 'Enter how long the link should remain valid (1 minute to 7 days).'
                }, {
                    id: 's3GeneratedUrl',
                    type: 'textarea',
                    label: this.i18n.generatedLink || 'Generated Link',
                    placeholder: this.i18n.generateLinkFirst || 'Click "Generate Link" to create a shareable URL',
                    rows: 4,
                    value: ''
                }],
                buttons: [{
                    text: this.i18n.cancel || 'Cancel',
                    action: 'cancel',
                    callback: function () {
                        self.hideModal('s3CopyLinkModal');
                        self.currentCopyLink = null;
                    }
                }, {
                    text: this.i18n.generateLink || 'Generate Link',
                    action: 'generate',
                    classes: 'button-primary',
                    callback: function () {
                        self.generatePresignedUrl();
                    }
                }, {
                    text: this.i18n.copyToClipboard || 'Copy to Clipboard',
                    action: 'copy',
                    classes: 'button-secondary',
                    disabled: true,
                    callback: function () {
                        self.copyLinkToClipboard();
                    }
                }],
                onClose: function () {
                    self.currentCopyLink = null;
                }
            });

            // Bind copy link specific validation
            modal.on('keyup', '#s3ExpiresInput', function (e) {
                self.validateCopyLinkInput(e, modal);
            });

            this.showModal('s3CopyLinkModal', function () {
                $('#s3ExpiresInput').focus().select();
            });
        },

        /**
         * Validate copy link input
         */
        validateCopyLinkInput: function (e, $modal) {
            var minutes = parseInt(e.target.value, 10);
            var $generateBtn = $modal.find('button[data-action="generate"]');
            var $error = $modal.find('.s3-modal-error');

            $error.hide();

            if (isNaN(minutes) || minutes < 1 || minutes > 10080) {
                if (e.target.value.length > 0) {
                    $error.text(this.i18n.invalidDuration || 'Duration must be between 1 minute and 7 days (10080 minutes)').show();
                }
                $generateBtn.prop('disabled', true);
            } else {
                $generateBtn.prop('disabled', false);
            }
        },

        /**
         * Generate presigned URL via AJAX
         */
        generatePresignedUrl: function () {
            if (!this.currentCopyLink) return;

            var self = this;
            var expiresMinutes = parseInt($('#s3ExpiresInput').val(), 10) || 60;

            this.setModalLoading('s3CopyLinkModal', true, this.i18n.generatingLink || 'Generating link...');

            this.makeAjaxRequest('s3_get_presigned_url_', {
                bucket: this.currentCopyLink.bucket,
                object_key: this.currentCopyLink.key,
                expires_minutes: expiresMinutes
            }, {
                success: function (response) {
                    self.handlePresignedUrlSuccess(response, expiresMinutes);
                },
                error: function (message) {
                    self.showModalError('s3CopyLinkModal', message);
                }
            });
        },

        /**
         * Handle successful presigned URL generation
         */
        handlePresignedUrlSuccess: function (response, expiresMinutes) {
            var self = this;
            var url = response.data.url;
            var expiresAt = new Date(response.data.expires_at * 1000);

            // Update the textarea with the URL
            $('#s3GeneratedUrl').val(url);

            // Enable the copy button
            $('#s3CopyLinkModal button[data-action="copy"]').prop('disabled', false);

            // Show success message
            this.setModalLoading('s3CopyLinkModal', false);

            // Update the description to show expiration info
            var expirationText = this.i18n.linkExpiresAt || 'Link expires at: {time}';
            expirationText = expirationText.replace('{time}', expiresAt.toLocaleString());

            $('#s3CopyLinkModal .description').last().html(
                '<strong>' + (this.i18n.linkGenerated || 'Link generated successfully!') + '</strong><br>' +
                expirationText
            );

            this.showNotification(
                response.data.message || this.i18n.linkGeneratedSuccess || 'Link generated successfully',
                'success'
            );
        },

        /**
         * Copy generated link to clipboard
         */
        copyLinkToClipboard: function () {
            var url = $('#s3GeneratedUrl').val();
            if (!url) return;

            var self = this;

            // Try modern clipboard API first
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function () {
                    self.showNotification(
                        self.i18n.linkCopied || 'Link copied to clipboard!',
                        'success'
                    );
                }).catch(function () {
                    self.fallbackCopyToClipboard(url);
                });
            } else {
                self.fallbackCopyToClipboard(url);
            }
        },

        /**
         * Fallback clipboard copy method
         */
        fallbackCopyToClipboard: function (text) {
            var textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.top = '-999px';
            textArea.style.left = '-999px';
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();

            try {
                var successful = document.execCommand('copy');
                if (successful) {
                    this.showNotification(
                        this.i18n.linkCopied || 'Link copied to clipboard!',
                        'success'
                    );
                } else {
                    this.showNotification(
                        this.i18n.copyFailed || 'Failed to copy link. Please copy manually.',
                        'error'
                    );
                }
            } catch (err) {
                this.showNotification(
                    this.i18n.copyFailed || 'Failed to copy link. Please copy manually.',
                    'error'
                );
            }

            document.body.removeChild(textArea);
        },

        /**
         * Open rename modal for a file
         */
        openRenameModal: function ($button) {
            var self = this;
            var filename = $button.data('filename');
            var bucket = $button.data('bucket');
            var key = $button.data('key');

            // Store current rename context
            this.currentRename = {
                filename: filename,
                bucket: bucket,
                key: key,
                $button: $button
            };

            var modal = this.createModal({
                id: 's3RenameModal',
                title: this.i18n.renameFile || 'Rename File',
                fields: [{
                    id: 's3RenameInput',
                    type: 'text',
                    label: this.i18n.filenameLabel || 'Enter the new filename:',
                    placeholder: this.i18n.newFilename || 'New Filename',
                    maxlength: 255,
                    description: this.i18n.filenameHelp || 'Enter a new filename. The file extension will be preserved.'
                }],
                buttons: [{
                    text: this.i18n.cancel || 'Cancel',
                    action: 'cancel',
                    callback: function () {
                        self.hideModal('s3RenameModal');
                        self.currentRename = null;
                    }
                }, {
                    text: this.i18n.renameFile || 'Rename File',
                    action: 'submit',
                    classes: 'button-primary',
                    disabled: true,
                    callback: function () {
                        self.submitRenameForm();
                    }
                }],
                onClose: function () {
                    self.currentRename = null;
                }
            });

            // Bind rename-specific validation
            modal.on('keyup', '#s3RenameInput', function (e) {
                self.validateRenameInput(e, modal);
            }).on('keydown', '#s3RenameInput', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    self.submitRenameForm();
                }
            });

            // Set current filename without extension for editing
            var nameWithoutExt = this.getFilenameWithoutExtension(filename);

            this.showModal('s3RenameModal', function () {
                $('#s3RenameInput').val(nameWithoutExt).focus().select();
            });
        },

        /**
         * Validate rename input
         */
        validateRenameInput: function (e, $modal) {
            var newName = e.target.value.trim();
            var $submit = $modal.find('button[data-action="submit"]');
            var $error = $modal.find('.s3-modal-error');

            $error.hide();

            if (!this.currentRename) return;

            // Get original extension
            var originalExt = this.getFileExtension(this.currentRename.filename);
            var fullNewName = newName + (originalExt ? '.' + originalExt : '');

            var validation = this.validateFilename(newName, fullNewName);

            if (!validation.valid && newName.length > 0) {
                $error.text(validation.message).show();
            }

            $submit.prop('disabled', !validation.valid);
        },

        /**
         * Validate filename for renaming - keep it simple and safe
         */
        validateFilename: function (nameWithoutExt, fullName) {
            var i18n = this.i18n;

            if (nameWithoutExt.length === 0) {
                return {valid: false, message: i18n.filenameRequired || 'Filename is required'};
            }

            if (fullName.length > 255) {
                return {valid: false, message: i18n.filenameTooLong || 'Filename is too long'};
            }

            // Check for invalid characters
            if (/[<>:"|?*\/\\]/.test(nameWithoutExt)) {
                return {valid: false, message: i18n.filenameInvalid || 'Filename contains invalid characters'};
            }

            // Check if starts with problematic characters
            if (/^[.\-_]/.test(nameWithoutExt)) {
                return {valid: false, message: i18n.filenameInvalid || 'Filename contains invalid characters'};
            }

            // Check for relative path indicators
            if (nameWithoutExt.includes('..')) {
                return {valid: false, message: i18n.filenameInvalid || 'Filename contains invalid characters'};
            }

            // Check if the new name is the same as current
            if (this.currentRename && fullName === this.currentRename.filename) {
                return {
                    valid: false,
                    message: i18n.filenameSame || 'The new filename is the same as the current filename'
                };
            }

            return {valid: true, message: ''};
        },

        /**
         * Submit rename form
         */
        submitRenameForm: function () {
            if (!this.currentRename) return;

            var newNameWithoutExt = $('#s3RenameInput').val().trim();
            var originalExt = this.getFileExtension(this.currentRename.filename);
            var fullNewName = newNameWithoutExt + (originalExt ? '.' + originalExt : '');

            var validation = this.validateFilename(newNameWithoutExt, fullNewName);
            if (!validation.valid) {
                this.showModalError('s3RenameModal', validation.message);
                return;
            }

            this.renameFile(fullNewName);
        },

        /**
         * Perform file rename via AJAX
         */
        renameFile: function (newFilename) {
            if (!this.currentRename) return;

            var self = this;

            this.setModalLoading('s3RenameModal', true, this.i18n.renamingFile || 'Renaming file...');

            this.makeAjaxRequest('s3_rename_object_', {
                bucket: this.currentRename.bucket,
                current_key: this.currentRename.key,
                new_filename: newFilename
            }, {
                success: function (response) {
                    self.handleRenameSuccess(response);
                },
                error: function (message) {
                    self.showModalError('s3RenameModal', message);
                }
            });
        },

        /**
         * Handle successful rename response
         */
        handleRenameSuccess: function (response) {
            var self = this;

            // Show success message immediately
            this.showNotification(
                response.data.message || this.i18n.renameSuccess || 'File renamed successfully',
                'success'
            );

            // Close modal
            this.hideModal('s3RenameModal');

            // Always refresh the page after rename to ensure everything is in sync
            // This is the most reliable approach for handling file renames with special characters
            setTimeout(function () {
                window.location.reload();
            }, 1500);
        },

        /**
         * Get file extension from filename
         */
        getFileExtension: function (filename) {
            var lastDot = filename.lastIndexOf('.');
            return lastDot > 0 ? filename.substring(lastDot + 1) : '';
        },

        /**
         * Get filename without extension
         */
        getFilenameWithoutExtension: function (filename) {
            var lastDot = filename.lastIndexOf('.');
            return lastDot > 0 ? filename.substring(0, lastDot) : filename;
        },

        /**
         * Handle file selection and integration with WordPress
         */
        handleFileSelection: function ($button) {
            var parent = window.parent;
            var fileData = {
                fileName: $button.data('filename'),
                bucket: $button.data('bucket'),
                key: $button.data('key'),
                url: $button.data('bucket') + '/' + $button.data('key')
            };

            var context = this.detectCallingContext(parent);
            var handlers = {
                'edd': function () {
                    parent.jQuery(parent.edd_filename).val(fileData.fileName);
                    parent.jQuery(parent.edd_fileurl).val(fileData.url);
                    parent.tb_remove();
                },
                'woocommerce_file': function () {
                    parent.jQuery(parent.wc_target_input).val(fileData.url);
                    var $filenameInput = parent.jQuery(parent.wc_target_input)
                        .closest('tr').find('input[name="_wc_file_names[]"]');
                    if ($filenameInput.length) {
                        $filenameInput.val(fileData.fileName);
                    }
                    parent.wp.media.frame.close();
                },
                'wp_editor': function () {
                    try {
                        if (parent.wp.media.editor.activeEditor) {
                            parent.wp.media.editor.insert(fileData.url);
                        } else if (parent.wpActiveEditor) {
                            parent.wp.media.editor.insert(fileData.url, parent.wpActiveEditor);
                        } else {
                            throw new Error('No active editor found');
                        }
                        if (parent.wp.media.frame) {
                            parent.wp.media.frame.close();
                        }
                    } catch (e) {
                        console.error('Editor insertion error:', e);
                        alert('File URL: ' + fileData.url);
                    }
                }
            };

            if (handlers[context]) {
                handlers[context]();
            } else {
                alert('File URL: ' + fileData.url);
            }
        },

        /**
         * Detect which context called the browser
         */
        detectCallingContext: function (parent) {
            if (parent.edd_fileurl && parent.edd_filename) {
                return 'edd';
            } else if (parent.wc_target_input && parent.wc_media_frame_context === 'product_file') {
                return 'woocommerce_file';
            } else if (parent.wp && parent.wp.media && parent.wp.media.editor) {
                return 'wp_editor';
            }
            return 'unknown';
        },

        /**
         * Delete a file from S3
         */
        deleteFile: function ($button) {
            var self = this;
            var filename = $button.data('filename');
            var confirmMessage = this.i18n.confirmDelete
                ? this.i18n.confirmDelete.replace('{filename}', filename).replace(/\\n/g, '\n')
                : 'Are you sure you want to delete "' + filename + '"?\n\nThis action cannot be undone.';

            if (!window.confirm(confirmMessage)) return;

            this.setButtonLoading($button, true);

            this.makeAjaxRequest('s3_delete_object_', {
                bucket: $button.data('bucket'),
                key: $button.data('key')
            }, {
                success: function (response) {
                    self.showNotification(
                        response.data.message || self.i18n.deleteSuccess || 'File successfully deleted',
                        'success'
                    );
                    $button.closest('tr').fadeOut(300, function () {
                        $(this).remove();
                        self.totalLoadedItems--;
                        self.updateTotalCount(false);
                        self.refreshSearch();
                    });
                },
                error: function (message) {
                    self.showNotification(message, 'error');
                    self.setButtonLoading($button, false);
                }
            });
        },

        /**
         * Toggle favorite bucket status
         */
        toggleFavoriteBucket: function ($button) {
            var self = this;

            // Add processing class for visual feedback
            $button.addClass('s3-processing');

            this.makeAjaxRequest('s3_toggle_favorite_', {
                bucket: $button.data('bucket'),
                favorite_action: $button.data('action'),
                post_type: $button.data('post-type')
            }, {
                success: function (response) {
                    self.updateFavoriteButtons(response, $button);
                    self.showNotification(response.data.message, 'success');
                },
                error: function (message) {
                    self.showNotification(message, 'error');
                },
                complete: function () {
                    $button.removeClass('s3-processing');
                }
            });
        },

        /**
         * Update favorite buttons after favorite change
         */
        updateFavoriteButtons: function (response, $button) {
            var self = this;

            // Reset all star buttons to empty/inactive
            $('.s3-favorite-bucket, .s3-favorite-star').each(function () {
                var $otherButton = $(this);
                $otherButton.removeClass('dashicons-star-filled s3-favorite-active')
                    .addClass('dashicons-star-empty')
                    .data('action', 'add')
                    .attr('title', self.i18n.setDefault || 'Set as default bucket');
            });

            // Update clicked button if it was added as favorite
            if (response.data.status === 'added') {
                $button.removeClass('dashicons-star-empty')
                    .addClass('dashicons-star-filled s3-favorite-active')
                    .data('action', 'remove')
                    .attr('title', self.i18n.removeDefault || 'Remove as default bucket');
            }
        },

        /* ========================================
         * 7. SEARCH & FILTERING
         * ======================================== */

        /**
         * Initialize client-side search functionality
         */
        setupJSSearch: function () {
            var $table = $('.wp-list-table tbody');
            if (!$table.length) return;

            this.originalTableData = $table.find('tr:not(.s3-no-results)').clone();
        },

        /**
         * Filter table based on search term
         */
        filterTable: function (searchTerm) {
            var $tbody = $('.wp-list-table tbody');
            var $stats = $('.s3-search-stats');
            var $bottomNav = $('.tablenav.bottom');

            $tbody.find('.s3-no-results').remove();

            if (!searchTerm) {
                $tbody.empty().append(this.originalTableData.clone());
                $stats.text('');
                $bottomNav.show();
                return;
            }

            $bottomNav.hide();
            searchTerm = searchTerm.toLowerCase();
            var visibleRows = 0;
            var totalRows = 0;

            $tbody.empty();
            this.originalTableData.each(function () {
                totalRows++;
                var $row = $(this);
                var fileName = $row.find('.column-name').text().toLowerCase();

                if (fileName.includes(searchTerm)) {
                    $tbody.append($row);
                    visibleRows++;
                }
            });

            if (visibleRows === 0) {
                this.showNoSearchResults($tbody, $stats, searchTerm);
            } else {
                var matchText = this.i18n.itemsMatch
                    ? this.i18n.itemsMatch.replace('{visible}', visibleRows).replace('{total}', totalRows)
                    : visibleRows + ' of ' + totalRows + ' items match';
                $stats.text(matchText);
            }
        },

        /**
         * Show no results message when search finds nothing
         */
        showNoSearchResults: function ($tbody, $stats, searchTerm) {
            $stats.text(this.i18n.noMatchesFound || 'No matches found');
            var colCount = $('.wp-list-table thead th').length;
            var noResultsText = this.i18n.noFilesFound
                ? this.i18n.noFilesFound.replace('{term}', $('<div>').text(searchTerm).html())
                : 'No files or folders found matching "' + $('<div>').text(searchTerm).html() + '"';

            $tbody.append(
                '<tr class="s3-no-results"><td colspan="' + colCount + '">' + noResultsText + '</td></tr>'
            );
        },

        /**
         * Refresh search data after table changes
         */
        refreshSearch: function () {
            var $table = $('.wp-list-table tbody');
            if (!$table.length) return;

            this.originalTableData = $table.find('tr:not(.s3-no-results)').clone();

            var currentSearch = $('#s3-js-search').val();
            if (currentSearch) {
                this.filterTable(currentSearch);
            } else {
                $('.s3-search-stats').text('');
            }
        },

        /* ========================================
         * 8. LOAD MORE & PAGINATION
         * ======================================== */

        /**
         * Setup AJAX loading for infinite scroll
         */
        setupAjaxLoading: function () {
            var self = this;

            if (!s3BrowserConfig.autoLoad) return;

            $(window).off('scroll.s3browser').on('scroll.s3browser', function () {
                if (self.isLoading) return;

                var $loadMore = $('#s3-load-more');
                if (!$loadMore.length || !$loadMore.is(':visible')) return;

                var windowBottom = $(window).scrollTop() + $(window).height();
                var buttonTop = $loadMore.offset().top;

                if (windowBottom > buttonTop - 200) {
                    $loadMore.click();
                }
            });
        },

        /**
         * Load more items via AJAX
         */
        loadMoreItems: function (token, bucket, prefix, $button) {
            var self = this;

            if (self.isLoading || !token) return;
            self.isLoading = true;

            $button.prop('disabled', true)
                .find('.s3-button-text').text(self.i18n.loadingText || 'Loading...')
                .end().find('.spinner').show();

            this.makeAjaxRequest('s3_load_more_', {
                bucket: bucket,
                prefix: prefix || '',
                continuation_token: token
            }, {
                success: function (response) {
                    self.handleLoadMoreSuccess(response, $button);
                },
                error: function (message) {
                    self.showError(message);
                    self.resetLoadMoreButton($button);
                },
                complete: function () {
                    self.isLoading = false;
                }
            });
        },

        /**
         * Handle successful load more response
         */
        handleLoadMoreSuccess: function (response, $button) {
            var $tbody = $('.wp-list-table tbody');

            $tbody.append(response.data.html);
            this.originalTableData = $tbody.find('tr:not(.s3-no-results)').clone();
            this.totalLoadedItems += response.data.count;

            if (response.data.has_more && response.data.continuation_token) {
                this.updateLoadMoreButton($button, response.data.continuation_token);
                this.updateTotalCount(true);
            } else {
                $button.closest('.pagination-links').fadeOut(300);
                this.updateTotalCount(false);
            }

            var currentSearch = $('#s3-js-search').val();
            if (currentSearch) {
                this.filterTable(currentSearch);
            }
        },

        /**
         * Update load more button with new token
         */
        updateLoadMoreButton: function ($button, token) {
            $button.data('token', token)
                .prop('disabled', false)
                .find('.s3-button-text').text(this.i18n.loadMoreItems || 'Load More Items')
                .end().find('.spinner').hide();
        },

        /**
         * Reset load more button to default state
         */
        resetLoadMoreButton: function ($button) {
            $button.prop('disabled', false)
                .find('.s3-button-text').text(this.i18n.loadMoreItems || 'Load More Items')
                .end().find('.spinner').hide();
        },

        /* ========================================
         * 9. CACHE & REFRESH
         * ======================================== */

        /**
         * Refresh cache via AJAX
         */
        refreshCache: function ($button) {
            var self = this;

            if ($button.hasClass('refreshing')) return;

            $button.addClass('refreshing').find('.dashicons').addClass('spin');

            this.makeAjaxRequest('s3_clear_cache_', {
                type: $button.data('type'),
                bucket: $button.data('bucket') || '',
                prefix: $button.data('prefix') || ''
            }, {
                success: function (response) {
                    self.showNotification(
                        response.data.message || self.i18n.cacheRefreshed || 'Cache refreshed successfully',
                        'success'
                    );
                    setTimeout(function () {
                        window.location.reload();
                    }, 1500);
                },
                error: function (message) {
                    self.showNotification(message, 'error');
                    $button.removeClass('refreshing').find('.dashicons').removeClass('spin');
                }
            });
        },

        /* ========================================
         * 10. UPLOAD INTEGRATION
         * ======================================== */

        /**
         * Initialize collapsible upload section
         */
        initUploadToggle: function () {
            var self = this;

            $('#s3-toggle-upload').on('click', function () {
                $('#s3-upload-container').slideToggle(300);
                var isVisible = $('#s3-upload-container').is(':visible');
                $(this).toggleClass('active', isVisible);

                if (!isVisible && !self.hasActiveUploads) {
                    setTimeout(function () {
                        $('.s3-upload-list').empty();
                    }, 300);
                }
            });

            $('.s3-close-upload').on('click', function () {
                if (!self.hasActiveUploads) {
                    $('#s3-upload-container').slideUp(300);
                    $('#s3-toggle-upload').removeClass('active');
                    setTimeout(function () {
                        $('.s3-upload-list').empty();
                    }, 300);
                } else {
                    self.showNotification(self.i18n.waitForUploads || 'Please wait for uploads to complete before closing', 'info');
                }
            });

            $(document)
                .on('s3UploadStarted', function () {
                    self.hasActiveUploads = true;
                    $('#s3-upload-container').slideDown(300);
                    $('#s3-toggle-upload').addClass('active');
                })
                .on('s3UploadComplete s3AllUploadsComplete', function () {
                    self.hasActiveUploads = false;
                });
        },

        /* ========================================
         * 11. UTILITY FUNCTIONS
         * ======================================== */

        /**
         * Navigate to a new location within the browser
         */
        navigateTo: function (params) {
            params.chromeless = 1;
            params.post_id = s3BrowserConfig.postId || 0;
            params.tab = 's3_' + S3BrowserGlobalConfig.providerId;

            var queryString = $.param(params);
            window.location.href = window.location.href.split('?')[0] + '?' + queryString;
        },

        /**
         * Escape HTML for safe display
         */
        escapeHtml: function (text) {
            if (!text) return '';
            return $('<div>').text(text).html();
        },

        /**
         * Format numbers with thousands separators
         */
        numberFormat: function (num) {
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        },

        /**
         * Generic AJAX request handler
         */
        makeAjaxRequest: function (actionSuffix, data, callbacks) {
            var requestData = $.extend({
                action: actionSuffix + S3BrowserGlobalConfig.providerId,
                nonce: S3BrowserGlobalConfig.nonce
            }, data);

            $.ajax({
                url: S3BrowserGlobalConfig.ajaxUrl,
                type: 'POST',
                data: requestData,
                dataType: 'json',
                success: function (response) {
                    if (response.success) {
                        callbacks.success && callbacks.success(response);
                    } else {
                        callbacks.error && callbacks.error(response.data.message || 'Unknown error occurred');
                    }
                },
                error: function () {
                    callbacks.error && callbacks.error('Network error occurred');
                },
                complete: callbacks.complete
            });
        },

        /**
         * Set button loading state
         */
        setButtonLoading: function ($button, isLoading) {
            $button.prop('disabled', isLoading);
            var $icon = $button.find('.dashicons');

            if (isLoading) {
                $icon.addClass('spin');
            } else {
                $icon.removeClass('spin');
            }
        },

        /**
         * Count initial items in the table
         */
        countInitialItems: function () {
            this.totalLoadedItems = $('.wp-list-table tbody tr:not(.s3-no-results)').length;
            var hasMore = $('#s3-load-more').length && $('#s3-load-more').is(':visible');
            this.updateTotalCount(hasMore);
        },

        /**
         * Update the total items count display
         */
        updateTotalCount: function (hasMore) {
            var $countSpan = $('#s3-total-count');
            if (!$countSpan.length) return;

            var itemText = this.totalLoadedItems === 1
                ? (this.i18n.singleItem || 'item')
                : (this.i18n.multipleItems || 'items');
            var text = this.totalLoadedItems + ' ' + itemText;
            if (hasMore) text += (this.i18n.moreAvailable || ' (more available)');

            $countSpan.text(text);
        },

        /**
         * Show error message
         */
        showError: function (message) {
            var $notice = $('.s3-ajax-error');
            if (!$notice.length) {
                $notice = $('<div class="notice notice-error s3-ajax-error"><p></p></div>');
                $('.s3-load-more-wrapper').before($notice);
            }

            $notice.find('p').text(message).end().show();
            setTimeout(function () {
                $notice.fadeOut();
            }, 5000);
        },

        /**
         * Show notification message with automatic fade-out
         */
        showNotification: function (message, type) {
            $('.s3-notification').remove();

            var $notification = $('<div class="s3-notification s3-notification-' + type + '">' + message + '</div>');
            $('.s3-browser-container').prepend($notification);

            if ($notification.length) {
                $('html, body').animate({
                    scrollTop: $notification.offset().top - 50
                }, 200);
            }

            setTimeout(function () {
                $notification.fadeOut(300, function () {
                    $(this).remove();
                });
            }, 5000);
        },

        /**
         * Debug helper function
         */
        debug: function (message, data) {
            if (console && console.log) {
                console.log('[S3Browser] ' + message, data || '');
            }
        }
    };

    // Initialize when document is ready
    $(document).ready(function () {
        S3Browser.init();
    });

    // Refresh search on window load (fixes issues with cached data)
    $(window).on('load', function () {
        if (window.S3Browser) {
            S3Browser.refreshSearch();
        }
    });

})(jQuery);