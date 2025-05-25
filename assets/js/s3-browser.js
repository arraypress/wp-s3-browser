/**
 * S3 Browser Core Functionality
 * Handles browsing, searching, file operations, folder creation and integrations with WordPress
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
        folderModal: null,
        FolderCreationView: null,

        // Translation strings
        i18n: {
            // Default strings (fallbacks)
            confirmDelete: 'Are you sure you want to delete "{filename}"?\n\nThis action cannot be undone.',
            deleteSuccess: 'File successfully deleted',
            deleteError: 'Failed to delete file',
            networkError: 'Network error. Please try again.',
            waitForUploads: 'Please wait for uploads to complete before closing',
            loadingText: 'Loading...',
            loadMoreItems: 'Load More Items',
            loadMoreError: 'Failed to load more items. Please try again.',
            networkLoadError: 'Network error. Please check your connection and try again.',
            noMatchesFound: 'No matches found',
            noFilesFound: 'No files or folders found matching "{term}"',
            itemsMatch: '{visible} of {total} items match',
            singleItem: 'item',
            multipleItems: 'items',
            moreAvailable: ' (more available)',
            cacheRefreshed: 'Cache refreshed successfully',
            refreshError: 'Failed to refresh data',
            favoritesError: 'Error updating default bucket',
            setDefault: 'Set Default',
            defaultText: 'Default',
            // Folder creation strings
            newFolder: 'New Folder',
            createFolder: 'Create Folder',
            folderName: 'Folder Name',
            folderNamePlaceholder: 'Enter folder name',
            folderNameHelp: 'Enter a name for the new folder. Use only letters, numbers, dots, hyphens, and underscores.',
            createFolderSuccess: 'Folder "{name}" created successfully',
            createFolderError: 'Failed to create folder',
            creatingFolder: 'Creating folder...',
            folderNameRequired: 'Folder name is required',
            folderNameTooLong: 'Folder name cannot exceed 63 characters',
            folderNameInvalidChars: 'Folder name can only contain letters, numbers, dots, hyphens, and underscores',
            cancel: 'Cancel'
        },

        /**
         * Initialize the S3 Browser
         */
        init: function () {
            // Override default strings with those provided from PHP
            this.loadTranslations();

            this.bindEvents();
            this.setupJSSearch();
            this.setupAjaxLoading();
            this.countInitialItems();
            this.initUploadToggle();
            this.initFolderCreation();
        },

        /**
         * Load translation strings from PHP
         */
        loadTranslations: function () {
            // If s3BrowserConfig.i18n exists, override defaults
            if (typeof s3BrowserConfig !== 'undefined' && s3BrowserConfig.i18n) {
                $.extend(this.i18n, s3BrowserConfig.i18n);
            }
        },

        /**
         * Bind all event handlers
         */
        bindEvents: function () {
            this.bindClickHandlers();
            this.bindSearchHandlers();
            this.bindLoadMoreHandler();
            this.bindFolderEvents();
        },

        /**
         * Bind click event handlers for navigation and file actions
         */
        bindClickHandlers: function () {
            var self = this;

            // Main click handler for links in the browser
            $(document).off('click.s3browser').on('click.s3browser', '.s3-browser-container a', function (e) {
                var $link = $(this);

                // Handle navigation links
                if ($link.hasClass('bucket-name') || $link.hasClass('browse-bucket-button')) {
                    e.preventDefault();
                    self.navigateTo({bucket: $link.data('bucket')});
                    return;
                }

                if ($link.hasClass('s3-folder-link')) {
                    e.preventDefault();
                    var config = S3BrowserGlobalConfig;
                    self.navigateTo({
                        bucket: $link.data('bucket') || $('#s3-load-more').data('bucket') || config.defaultBucket,
                        prefix: $link.data('prefix')
                    });
                    return;
                }

                // Handle file actions
                if ($link.hasClass('s3-select-file')) {
                    e.preventDefault();
                    self.handleFileSelection($link);
                    return;
                }

                if ($link.hasClass('s3-download-file')) {
                    e.preventDefault();
                    window.open($link.data('url'), '_blank');
                    return;
                }

                if ($link.hasClass('s3-delete-file')) {
                    e.preventDefault();
                    self.deleteFile($link);
                    return;
                }

                if ($link.hasClass('s3-favorite-bucket')) {
                    e.preventDefault();
                    e.stopPropagation();
                    self.toggleFavoriteBucket($link);
                    return;
                }
            });

            // Refresh button handler
            $(document).off('click.s3refresh').on('click.s3refresh', '.s3-refresh-button', function (e) {
                e.preventDefault();
                self.refreshCache($(this));
            });
        },

        /**
         * Bind search input handlers
         */
        bindSearchHandlers: function () {
            var self = this;

            // Search input with debounce
            $('#s3-js-search').off('input.s3browser').on('input.s3browser', function () {
                var $this = $(this);
                var $clearBtn = $('#s3-js-search-clear');

                // Show/hide clear button
                $clearBtn.toggle(Boolean($this.val()));

                // Debounce search to avoid excessive filtering
                clearTimeout(self.searchTimeout);
                self.searchTimeout = setTimeout(function () {
                    self.filterTable($this.val());
                }, 200);
            });

            // Clear search button
            $('#s3-js-search-clear').off('click.s3browser').on('click.s3browser', function () {
                $('#s3-js-search').val('').trigger('input');
            });
        },

        /**
         * Bind load more handler for paginated content
         */
        bindLoadMoreHandler: function () {
            var self = this;

            // Load more button
            $(document).off('click.s3loadmore').on('click.s3loadmore', '#s3-load-more', function (e) {
                e.preventDefault();
                if (self.isLoading) return;

                var $button = $(this);
                self.loadMoreItems(
                    $button.data('token'),
                    $button.data('bucket'),
                    $button.data('prefix'),
                    $button
                );
            });
        },

        /**
         * Initialize folder creation functionality
         */
        initFolderCreation: function () {
            this.createFolderModal();
        },

        /**
         * Bind folder creation events
         */
        bindFolderEvents: function () {
            var self = this;

            // Create folder button
            $(document).off('click.s3createfolder').on('click.s3createfolder', '#s3-create-folder', function (e) {
                e.preventDefault();
                var $button = $(this);
                self.openCreateFolderModal($button.data('bucket'), $button.data('prefix'));
            });
        },

        /**
         * Create folder modal using WordPress media modal framework
         */
        createFolderModal: function () {
            var self = this;

            // Only create if wp.media is available and we haven't created it yet
            if (typeof wp === 'undefined' || typeof wp.media === 'undefined' || this.folderModal) {
                return;
            }

            // Create the modal
            this.folderModal = new wp.media.view.Modal({
                controller: {
                    trigger: function () {
                    }
                }
            });

            // Create content view
            var FolderCreationView = wp.media.View.extend({
                className: 's3-folder-creation-view',
                template: wp.template('s3-folder-creation'),

                events: {
                    'click .s3-create-folder-submit': 'submitForm',
                    'click .s3-create-folder-cancel': 'closeModal',
                    'keyup #s3-folder-name-input': 'validateInput',
                    'keydown #s3-folder-name-input': 'handleEnterKey'
                },

                initialize: function (options) {
                    this.bucket = options.bucket || '';
                    this.prefix = options.prefix || '';
                },

                render: function () {
                    this.$el.html(this.template({
                        bucket: this.bucket,
                        prefix: this.prefix,
                        i18n: window.S3Browser.i18n
                    }));
                    return this;
                },

                validateInput: function (e) {
                    var folderName = e.target.value.trim();
                    var $submit = this.$('.s3-create-folder-submit');
                    var $error = this.$('.s3-folder-error');

                    // Clear previous errors
                    $error.hide();

                    // Validate folder name
                    var validation = this.validateFolderName(folderName);

                    if (!validation.valid) {
                        if (folderName.length > 0) {
                            $error.text(validation.message).show();
                        }
                        $submit.prop('disabled', true);
                    } else {
                        $submit.prop('disabled', false);
                    }
                },

                validateFolderName: function (folderName) {
                    var i18n = window.S3Browser.i18n;

                    if (folderName.length === 0) {
                        return {valid: false, message: i18n.folderNameRequired};
                    }

                    if (folderName.length > 63) {
                        return {valid: false, message: i18n.folderNameTooLong};
                    }

                    if (!/^[a-zA-Z0-9._-]+$/.test(folderName)) {
                        return {valid: false, message: i18n.folderNameInvalidChars};
                    }

                    if (['.', '-'].includes(folderName[0]) || ['.', '-'].includes(folderName[folderName.length - 1])) {
                        return {valid: false, message: 'Folder name cannot start or end with dots or hyphens'};
                    }

                    if (folderName.includes('..')) {
                        return {valid: false, message: 'Folder name cannot contain consecutive dots'};
                    }

                    return {valid: true, message: ''};
                },

                handleEnterKey: function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        this.submitForm();
                    }
                },

                submitForm: function () {
                    var folderName = this.$('#s3-folder-name-input').val().trim();
                    var validation = this.validateFolderName(folderName);

                    if (!validation.valid) {
                        this.$('.s3-folder-error').text(validation.message).show();
                        return;
                    }

                    this.createFolder(folderName);
                },

                createFolder: function (folderName) {
                    var self = this;
                    var $submit = this.$('.s3-create-folder-submit');
                    var $cancel = this.$('.s3-create-folder-cancel');
                    var $loading = this.$('.s3-folder-loading');
                    var $error = this.$('.s3-folder-error');

                    // Show loading state
                    $submit.prop('disabled', true);
                    $cancel.prop('disabled', true);
                    $loading.show();
                    $error.hide();

                    // Send AJAX request
                    $.ajax({
                        url: S3BrowserGlobalConfig.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 's3_create_folder_' + S3BrowserGlobalConfig.providerId,
                            bucket: this.bucket,
                            prefix: this.prefix,
                            folder_name: folderName,
                            nonce: S3BrowserGlobalConfig.nonce
                        },
                        success: function (response) {
                            if (response.success) {
                                // Show success notification
                                window.S3Browser.showNotification(
                                    response.data.message || window.S3Browser.i18n.createFolderSuccess.replace('{name}', folderName),
                                    'success'
                                );

                                // Close modal
                                window.S3Browser.folderModal.close();

                                // Refresh the page to show the new folder
                                setTimeout(function () {
                                    window.location.reload();
                                }, 1500);
                            } else {
                                // Show error
                                $error.text(response.data.message || window.S3Browser.i18n.createFolderError).show();
                                self.resetForm();
                            }
                        },
                        error: function () {
                            $error.text(window.S3Browser.i18n.networkError).show();
                            self.resetForm();
                        }
                    });
                },

                resetForm: function () {
                    this.$('.s3-create-folder-submit').prop('disabled', false);
                    this.$('.s3-create-folder-cancel').prop('disabled', false);
                    this.$('.s3-folder-loading').hide();
                },

                closeModal: function () {
                    window.S3Browser.folderModal.close();
                }
            });

            // Store the view class for later use
            this.FolderCreationView = FolderCreationView;
        },

        /**
         * Open the create folder modal
         */
        openCreateFolderModal: function (bucket, prefix) {
            if (!this.folderModal) {
                this.createFolderModal();
            }

            if (!this.folderModal) {
                console.error('Could not create folder modal - wp.media not available');
                return;
            }

            // Create new content view
            var contentView = new this.FolderCreationView({
                bucket: bucket,
                prefix: prefix || ''
            });

            // Set modal content
            this.folderModal.content(contentView);

            // Open modal
            this.folderModal.open();

            // Focus the input field
            setTimeout(function () {
                contentView.$('#s3-folder-name-input').focus();
            }, 100);
        },

        /**
         * Navigate to a new location within the browser
         * @param {Object} params Navigation parameters
         */
        navigateTo: function (params) {
            params.chromeless = 1;
            params.post_id = s3BrowserConfig.postId || 0;
            params.tab = 's3_' + S3BrowserGlobalConfig.providerId;

            var url = window.location.href.split('?')[0] + '?' + $.param(params);
            window.location.href = url;
        },

        /**
         * Initialize collapsible upload section
         */
        initUploadToggle: function () {
            var self = this;

            // Toggle button handler
            $('#s3-toggle-upload').on('click', function () {
                $('#s3-upload-container').slideToggle(300);

                // Update button state
                var isVisible = $('#s3-upload-container').is(':visible');
                $(this).toggleClass('active', isVisible);

                // Clear upload list when closing (if no active uploads)
                if (!isVisible && !self.hasActiveUploads) {
                    setTimeout(function () {
                        $('.s3-upload-list').empty();
                    }, 300);
                }
            });

            // Close button handler
            $('.s3-close-upload').on('click', function () {
                if (!self.hasActiveUploads) {
                    $('#s3-upload-container').slideUp(300);
                    $('#s3-toggle-upload').removeClass('active');

                    // Clear the list after animation
                    setTimeout(function () {
                        $('.s3-upload-list').empty();
                    }, 300);
                } else {
                    // Prevent closing if uploads are in progress
                    self.showNotification(self.i18n.waitForUploads, 'info');
                }
            });

            // Upload event listeners
            $(document)
                .on('s3UploadStarted', function () {
                    self.hasActiveUploads = true;
                    // Ensure upload container is visible
                    $('#s3-upload-container').slideDown(300);
                    $('#s3-toggle-upload').addClass('active');
                })
                .on('s3UploadComplete', function () {
                    self.hasActiveUploads = false;
                })
                .on('s3AllUploadsComplete', function () {
                    self.hasActiveUploads = false;
                });
        },

        /**
         * Handle file selection and integration with WordPress
         * @param {jQuery} $button The clicked select button
         */
        handleFileSelection: function ($button) {
            var parent = window.parent;

            // Prepare file data
            var fileData = {
                fileName: $button.data('filename'),
                bucket: $button.data('bucket'),
                key: $button.data('key'),
                url: $button.data('bucket') + '/' + $button.data('key')
            };

            // Determine which context called the browser
            var callingContext = this.detectCallingContext(parent);

            // Handle selection based on context
            switch (callingContext) {
                case 'edd':
                    this.handleEddSelection(parent, fileData);
                    break;

                case 'woocommerce_file':
                    this.handleWooCommerceSelection(parent, fileData);
                    break;

                case 'wp_editor':
                    this.handleWordPressEditorSelection(parent, fileData);
                    break;

                default:
                    // Fallback for unknown contexts
                    alert('File URL: ' + fileData.url);
            }
        },

        /**
         * Detect which context called the browser
         * @param {Window} parent The parent window
         * @return {string} The detected context
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
         * Handle selection in EDD context
         * @param {Window} parent The parent window
         * @param {Object} fileData The selected file data
         */
        handleEddSelection: function (parent, fileData) {
            parent.jQuery(parent.edd_filename).val(fileData.fileName);
            parent.jQuery(parent.edd_fileurl).val(fileData.url);
            parent.tb_remove();
        },

        /**
         * Handle selection in WooCommerce context
         * @param {Window} parent The parent window
         * @param {Object} fileData The selected file data
         */
        handleWooCommerceSelection: function (parent, fileData) {
            parent.jQuery(parent.wc_target_input).val(fileData.url);
            var $filenameInput = parent.jQuery(parent.wc_target_input)
                .closest('tr')
                .find('input[name="_wc_file_names[]"]');

            if ($filenameInput.length) {
                $filenameInput.val(fileData.fileName);
            }
            parent.wp.media.frame.close();
        },

        /**
         * Handle selection in WordPress editor context
         * @param {Window} parent The parent window
         * @param {Object} fileData The selected file data
         */
        handleWordPressEditorSelection: function (parent, fileData) {
            try {
                // Try active editor first
                if (parent.wp.media.editor.activeEditor) {
                    parent.wp.media.editor.insert(fileData.url);
                } else {
                    // Fallback to wpActiveEditor
                    var wpActiveEditor = parent.wpActiveEditor;
                    if (wpActiveEditor) {
                        parent.wp.media.editor.insert(fileData.url, parent.wpActiveEditor);
                    } else {
                        alert('File URL: ' + fileData.url);
                    }
                }

                // Close the media frame
                if (parent.wp.media.frame) {
                    parent.wp.media.frame.close();
                }
            } catch (e) {
                console.error('Editor insertion error:', e);
                alert('File URL: ' + fileData.url);
            }
        },

        /**
         * Delete a file from S3
         * @param {jQuery} $button The clicked delete button
         */
        deleteFile: function ($button) {
            var self = this;
            var filename = $button.data('filename');
            var bucket = $button.data('bucket');
            var key = $button.data('key');

            // Confirm deletion - using translatable confirmation message
            var confirmMessage = this.i18n.confirmDelete.replace('{filename}', filename);
            if (!window.confirm(confirmMessage)) {
                return; // User cancelled
            }

            // Update button state
            $button.prop('disabled', true)
                .find('.dashicons').addClass('spin');

            // Send delete request
            $.ajax({
                url: S3BrowserGlobalConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 's3_delete_object_' + S3BrowserGlobalConfig.providerId,
                    bucket: bucket,
                    key: key,
                    nonce: S3BrowserGlobalConfig.nonce
                },
                success: function (response) {
                    if (response.success) {
                        // Show success notification
                        self.showNotification(response.data.message || self.i18n.deleteSuccess, 'success');

                        // Remove the row from the table
                        $button.closest('tr').fadeOut(300, function () {
                            $(this).remove();
                            self.totalLoadedItems--;
                            self.updateTotalCount(false);
                            self.refreshSearch();
                        });

                        // Clear cache to ensure deleted file doesn't reappear
                        self.clearS3Cache(bucket, key);
                    } else {
                        // Show error notification
                        self.showNotification(response.data.message || self.i18n.deleteError, 'error');
                        self.resetButtonState($button);
                    }
                },
                error: function () {
                    self.showNotification(self.i18n.networkError, 'error');
                    self.resetButtonState($button);
                }
            });
        },

        /**
         * Clear S3 cache for objects after file operations
         * @param {string} bucket Bucket name
         * @param {string} key Object key (optional, used to determine prefix)
         */
        clearS3Cache: function (bucket, key) {
            var self = this;

            // Extract prefix from key (everything before the last slash)
            var prefix = '';
            if (key && key.includes('/')) {
                prefix = key.substring(0, key.lastIndexOf('/') + 1);
            }

            // Clear cache using the same pattern as uploads
            $.ajax({
                url: S3BrowserGlobalConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 's3_clear_cache_' + S3BrowserGlobalConfig.providerId,
                    type: 'objects',
                    bucket: bucket,
                    prefix: prefix,
                    nonce: S3BrowserGlobalConfig.nonce
                },
                success: function (response) {
                    console.log('Cache cleared successfully after file deletion');
                },
                error: function () {
                    console.error('Failed to clear cache after file deletion');
                }
            });
        },

        /**
         * Reset a button to its default state
         * @param {jQuery} $button The button to reset
         */
        resetButtonState: function ($button) {
            $button.prop('disabled', false)
                .find('.dashicons').removeClass('spin');
        },

        /**
         * Initialize client-side search functionality
         */
        setupJSSearch: function () {
            var $table = $('.wp-list-table tbody');
            if (!$table.length) return;

            // Store original table data for filtering
            this.originalTableData = $table.find('tr:not(.s3-no-results)').clone();
        },

        /**
         * Setup AJAX loading for infinite scroll
         */
        setupAjaxLoading: function () {
            var self = this;

            // Only setup if autoLoad is enabled
            if (!s3BrowserConfig.autoLoad) return;

            $(window).off('scroll.s3browser').on('scroll.s3browser', function () {
                if (self.isLoading) return;

                var $loadMore = $('#s3-load-more');
                if (!$loadMore.length || !$loadMore.is(':visible')) return;

                // Check if load more button is in view
                var windowBottom = $(window).scrollTop() + $(window).height();
                var buttonTop = $loadMore.offset().top;

                if (windowBottom > buttonTop - 200) {
                    $loadMore.click();
                }
            });
        },

        /**
         * Refresh cache via AJAX
         * @param {jQuery} $button The refresh button
         */
        refreshCache: function ($button) {
            var self = this;
            var provider = $button.data('provider');
            var type = $button.data('type');
            var bucket = $button.data('bucket') || '';
            var prefix = $button.data('prefix') || '';

            // Prevent multiple clicks
            if ($button.hasClass('refreshing')) return;

            // Update button state
            $button.addClass('refreshing')
                .find('.dashicons').addClass('spin');

            // Send cache clear request
            $.ajax({
                url: S3BrowserGlobalConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 's3_clear_cache_' + provider,
                    type: type,
                    bucket: bucket,
                    prefix: prefix,
                    nonce: S3BrowserGlobalConfig.nonce
                },
                success: function (response) {
                    if (response.success) {
                        self.showNotification(response.data.message || self.i18n.cacheRefreshed, 'success');

                        // Wait before reloading to show message
                        setTimeout(function () {
                            window.location.reload();
                        }, 1500);
                    } else {
                        self.showNotification(response.data.message || self.i18n.refreshError, 'error');
                        self.resetRefreshButton($button);
                    }
                },
                error: function () {
                    self.showNotification(self.i18n.networkError, 'error');
                    self.resetRefreshButton($button);
                }
            });
        },

        /**
         * Reset refresh button state
         * @param {jQuery} $button The button to reset
         */
        resetRefreshButton: function ($button) {
            $button.removeClass('refreshing')
                .find('.dashicons').removeClass('spin');
        },

        /**
         * Load more items via AJAX
         * @param {string} token Continuation token
         * @param {string} bucket Bucket name
         * @param {string} prefix Folder prefix
         * @param {jQuery} $button Load more button
         */
        loadMoreItems: function (token, bucket, prefix, $button) {
            var self = this;
            var config = S3BrowserGlobalConfig;

            if (self.isLoading || !token) return;
            self.isLoading = true;

            // Update button state
            $button.prop('disabled', true)
                .find('.s3-button-text').text(self.i18n.loadingText)
                .end()
                .find('.spinner').show();

            // Load more items via AJAX
            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: config.ajaxAction,
                    bucket: bucket,
                    prefix: prefix || '',
                    continuation_token: token,
                    nonce: config.nonce
                },
                dataType: 'json',
                success: function (response) {
                    if (response.success && response.data) {
                        self.handleLoadMoreSuccess(response, $button);
                    } else {
                        self.showError(self.i18n.loadMoreError);
                        self.resetLoadMoreButton($button);
                    }
                },
                error: function (xhr, status, error) {
                    console.error('AJAX Error:', error);
                    self.showError(self.i18n.networkLoadError);
                    self.resetLoadMoreButton($button);
                },
                complete: function () {
                    self.isLoading = false;
                }
            });
        },

        /**
         * Handle successful load more response
         * @param {Object} response AJAX response
         * @param {jQuery} $button Load more button
         */
        handleLoadMoreSuccess: function (response, $button) {
            var $tbody = $('.wp-list-table tbody');

            // Append new rows
            $tbody.append(response.data.html);

            // Update search data
            this.originalTableData = $tbody.find('tr:not(.s3-no-results)').clone();

            // Update counters
            this.totalLoadedItems += response.data.count;
            this.updateTotalCount(response.data.has_more);

            // Update button state
            if (response.data.has_more && response.data.continuation_token) {
                this.updateLoadMoreButton($button, response.data.continuation_token);
            } else {
                // No more items
                $button.closest('.s3-load-more-wrapper').fadeOut(300);
                this.updateTotalCount(false);
            }

            // Re-apply search if active
            var currentSearch = $('#s3-js-search').val();
            if (currentSearch) {
                this.filterTable(currentSearch);
            }
        },

        /**
         * Update load more button with new token
         * @param {jQuery} $button The button to update
         * @param {string} token The new continuation token
         */
        updateLoadMoreButton: function ($button, token) {
            $button.data('token', token)
                .find('.s3-button-text').text(this.i18n.loadMoreItems)
                .end()
                .find('.spinner').hide()
                .end()
                .prop('disabled', false);
        },

        /**
         * Reset load more button to default state
         * @param {jQuery} $button The button to reset
         */
        resetLoadMoreButton: function ($button) {
            $button.find('.s3-button-text').text(this.i18n.loadMoreItems)
                .end()
                .find('.spinner').hide()
                .end()
                .prop('disabled', false);
        },

        /**
         * Filter table based on search term
         * @param {string} searchTerm The term to search for
         */
        filterTable: function (searchTerm) {
            var $tbody = $('.wp-list-table tbody');
            var $stats = $('.s3-search-stats');
            var $bottomNav = $('.tablenav.bottom');

            // Remove any existing no-results rows
            $tbody.find('.s3-no-results').remove();

            // If search is empty, restore original table
            if (!searchTerm) {
                $tbody.empty().append(this.originalTableData.clone());
                $stats.text('');
                $bottomNav.show();
                return;
            }

            // Hide bottom navigation during search
            $bottomNav.hide();

            // Perform search
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

            // Update UI based on results
            if (visibleRows === 0) {
                this.showNoSearchResults($tbody, $stats, searchTerm);
            } else {
                // Use translated string with placeholders
                var matchText = this.i18n.itemsMatch
                    .replace('{visible}', visibleRows)
                    .replace('{total}', totalRows);
                $stats.text(matchText);
            }
        },

        /**
         * Show no results message when search finds nothing
         * @param {jQuery} $tbody Table body
         * @param {jQuery} $stats Stats element
         * @param {string} searchTerm Search term
         */
        showNoSearchResults: function ($tbody, $stats, searchTerm) {
            $stats.text(this.i18n.noMatchesFound);
            var colCount = $('.wp-list-table thead th').length;

            // Use translated string with placeholder
            var noResultsText = this.i18n.noFilesFound.replace('{term}', $('<div>').text(searchTerm).html());

            $tbody.append(
                '<tr class="s3-no-results"><td colspan="' + colCount + '">' + noResultsText + '</td></tr>'
            );
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
         * @param {boolean} hasMore Whether more items are available
         */
        updateTotalCount: function (hasMore) {
            var $countSpan = $('#s3-total-count');
            if (!$countSpan.length) return;

            // Use translated strings for singular/plural and "more available"
            var itemText = this.totalLoadedItems === 1 ? this.i18n.singleItem : this.i18n.multipleItems;
            var text = this.totalLoadedItems + ' ' + itemText;
            if (hasMore) text += this.i18n.moreAvailable;

            $countSpan.text(text);
        },

        /**
         * Show error message
         * @param {string} message Error message to display
         */
        showError: function (message) {
            var $notice = $('.s3-ajax-error');
            if (!$notice.length) {
                $notice = $('<div class="notice notice-error s3-ajax-error"><p></p></div>');
                $('.s3-load-more-wrapper').before($notice);
            }

            $notice.find('p').text(message).end().show();

            // Auto-hide after delay
            setTimeout(function () {
                $notice.fadeOut();
            }, 5000);
        },

        /**
         * Show notification message with automatic fade-out
         * @param {string} message Message to display
         * @param {string} type Notification type: 'success', 'error', 'info'
         */
        showNotification: function (message, type) {
            // Remove any existing notifications
            $('.s3-notification').remove();

            // Create notification
            var $notification = $('<div class="s3-notification s3-notification-' + type + '">' + message + '</div>');
            $('.s3-browser-container').prepend($notification);

            // Scroll to notification
            if ($notification.length) {
                $('html, body').animate({
                    scrollTop: $notification.offset().top - 50
                }, 200);
            }

            // Auto-hide all notifications after 5 seconds
            setTimeout(function () {
                $notification.fadeOut(300, function () {
                    $(this).remove();
                });
            }, 5000);
        },

        /**
         * Refresh search data after table changes
         */
        refreshSearch: function () {
            var $table = $('.wp-list-table tbody');
            if (!$table.length) return;

            // Update stored table data
            this.originalTableData = $table.find('tr:not(.s3-no-results)').clone();

            // Re-apply current search if any
            var currentSearch = $('#s3-js-search').val();
            if (currentSearch) {
                this.filterTable(currentSearch);
            } else {
                $('.s3-search-stats').text('');
            }
        },

        /**
         * Toggle favorite bucket status
         * @param {jQuery} $button Favorite toggle button
         */
        toggleFavoriteBucket: function ($button) {
            var self = this;
            var bucket = $button.data('bucket');
            var provider = $button.data('provider');
            var action = $button.data('action');
            var postType = $button.data('post-type');

            // Disable button during operation
            $button.addClass('s3-processing');

            // Send favorite toggle request
            $.ajax({
                url: S3BrowserGlobalConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 's3_toggle_favorite_' + provider,
                    bucket: bucket,
                    favorite_action: action,
                    post_type: postType,
                    nonce: S3BrowserGlobalConfig.nonce
                },
                dataType: 'json',
                success: function (response) {
                    if (response.success) {
                        self.updateFavoriteButtons(response, $button);
                        self.showNotification(response.data.message, 'success');
                    } else {
                        self.showNotification(response.data.message || self.i18n.favoritesError, 'error');
                    }
                },
                error: function () {
                    self.showNotification(self.i18n.networkError, 'error');
                },
                complete: function () {
                    $button.removeClass('s3-processing');
                }
            });
        },

        /**
         * Update favorite buttons after favorite change
         * @param {Object} response AJAX response
         * @param {jQuery} $button The clicked button
         */
        updateFavoriteButtons: function (response, $button) {
            var self = this;

            // Reset all buttons
            $('.s3-favorite-bucket').each(function () {
                var $otherButton = $(this);
                var $otherIcon = $otherButton.find('.dashicons');

                $otherIcon.removeClass('dashicons-star-filled s3-favorite-active')
                    .addClass('dashicons-star-empty');
                $otherButton.data('action', 'add');

                // Update text to translated "Set Default"
                $otherButton.contents().filter(function () {
                    return this.nodeType === 3; // Text nodes only
                }).replaceWith(self.i18n.setDefault);
            });

            // Update clicked button if it was added as favorite
            if (response.data.status === 'added') {
                var $icon = $button.find('.dashicons');
                $icon.removeClass('dashicons-star-empty')
                    .addClass('dashicons-star-filled s3-favorite-active');
                $button.data('action', 'remove');

                // Update text to translated "Default"
                $button.contents().filter(function () {
                    return this.nodeType === 3; // Text nodes only
                }).replaceWith(self.i18n.defaultText);
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