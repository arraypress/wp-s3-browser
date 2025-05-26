/**
 * S3 Browser Core Functionality
 * Handles browsing, searching, file operations, folder creation and WordPress integrations
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

        // Translation strings (populated by PHP)
        i18n: {},

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
            this.initFolderCreation();
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
         * Bind all event handlers
         */
        bindEvents: function () {
            this.bindNavigationEvents();
            this.bindFileActionEvents();
            this.bindSearchEvents();
            this.bindLoadMoreEvents();
            this.bindFolderEvents();
            this.bindRefreshEvents();
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
            });
        },

        /**
         * Bind file action event handlers
         */
        bindFileActionEvents: function () {
            var self = this;

            $(document).off('click.s3files').on('click.s3files', '.s3-browser-container', function (e) {
                var $target = $(e.target).closest('a');

                if ($target.hasClass('s3-select-file')) {
                    e.preventDefault();
                    self.handleFileSelection($target);
                } else if ($target.hasClass('s3-download-file')) {
                    e.preventDefault();
                    window.open($target.data('url'), '_blank');
                } else if ($target.hasClass('s3-delete-file')) {
                    e.preventDefault();
                    self.deleteFile($target);
                } else if ($target.hasClass('s3-favorite-bucket')) {
                    e.preventDefault();
                    e.stopPropagation();
                    self.toggleFavoriteBucket($target);
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

        /* ========================================
         * FOLDER MANAGEMENT
         * ======================================== */

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
            $(document).off('click.s3folder').on('click.s3folder', '#s3-create-folder', function (e) {
                e.preventDefault();
                var $button = $(this);
                self.openCreateFolderModal($button.data('bucket'), $button.data('prefix'));
            });

            // Modal close events
            $(document).off('click.s3modal').on('click.s3modal', '.s3-folder-modal-overlay, .s3-folder-modal-close', function (e) {
                if (e.target === this) {
                    self.closeFolderModal();
                }
            });

            // Escape key to close modal
            $(document).off('keydown.s3modal').on('keydown.s3modal', function (e) {
                if (e.key === 'Escape' && $('#s3FolderModal').is(':visible')) {
                    self.closeFolderModal();
                }
            });
        },

        /**
         * Create folder modal HTML structure
         */
        createFolderModal: function () {
            if ($('#s3FolderModal').length > 0) return;

            var i18n = this.i18n;
            var modalHtml = [
                '<div id="s3FolderModal" class="s3-folder-modal-overlay" style="display: none;">',
                '<div class="s3-folder-modal">',
                '<div class="s3-folder-modal-header">',
                '<h2>' + i18n.newFolder + '</h2>',
                '<button type="button" class="s3-folder-modal-close">&times;</button>',
                '</div>',
                '<div class="s3-folder-modal-body">',
                '<div class="s3-folder-error" style="display: none;"></div>',
                '<div class="s3-folder-field">',
                '<label for="s3FolderNameInput">' + i18n.folderName + '</label>',
                '<input type="text" id="s3FolderNameInput" placeholder="' + i18n.folderNamePlaceholder + '" maxlength="63" autocomplete="off">',
                '<p class="description">' + i18n.folderNameHelp + '</p>',
                '</div>',
                '<div class="s3-folder-loading" style="display: none;">',
                '<span class="spinner is-active"></span>' + i18n.creatingFolder,
                '</div>',
                '</div>',
                '<div class="s3-folder-modal-footer">',
                '<button type="button" class="button s3-folder-cancel">' + i18n.cancel + '</button>',
                '<button type="button" class="button button-primary s3-folder-submit" disabled>' + i18n.createFolder + '</button>',
                '</div>',
                '</div>',
                '</div>'
            ].join('');

            $('body').append(modalHtml);
            this.bindModalEvents();
        },

        /**
         * Bind modal-specific events
         */
        bindModalEvents: function () {
            var self = this;

            $('#s3FolderModal')
                .on('click', '.s3-folder-submit', function () {
                    self.submitFolderForm();
                })
                .on('click', '.s3-folder-cancel', function () {
                    self.closeFolderModal();
                })
                .on('keyup', '#s3FolderNameInput', function (e) {
                    self.validateFolderInput(e);
                })
                .on('keydown', '#s3FolderNameInput', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        self.submitFolderForm();
                    }
                });
        },

        /**
         * Validate folder name input
         */
        validateFolderInput: function (e) {
            var folderName = e.target.value.trim();
            var $submit = $('.s3-folder-submit');
            var $error = $('.s3-folder-error');

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

        /**
         * Submit folder creation form
         */
        submitFolderForm: function () {
            var folderName = $('#s3FolderNameInput').val().trim();
            var validation = this.validateFolderName(folderName);

            if (!validation.valid) {
                $('.s3-folder-error').text(validation.message).show();
                return;
            }

            this.createFolder(folderName);
        },

        /**
         * Create folder via AJAX
         */
        createFolder: function (folderName) {
            var self = this;
            var $modal = $('#s3FolderModal');
            var $elements = {
                submit: $modal.find('.s3-folder-submit'),
                cancel: $modal.find('.s3-folder-cancel'),
                loading: $modal.find('.s3-folder-loading'),
                error: $modal.find('.s3-folder-error')
            };

            // Show loading state
            $elements.submit.prop('disabled', true);
            $elements.cancel.prop('disabled', true);
            $elements.loading.show();
            $elements.error.hide();

            this.makeAjaxRequest('s3_create_folder_', {
                bucket: this.currentBucket,
                prefix: this.currentPrefix,
                folder_name: folderName
            }, {
                success: function (response) {
                    self.showNotification(
                        response.data.message || self.i18n.createFolderSuccess.replace('{name}', folderName),
                        'success'
                    );
                    self.closeFolderModal();
                    setTimeout(function () {
                        window.location.reload();
                    }, 1500);
                },
                error: function (message) {
                    $elements.error.text(message).show();
                    self.resetFolderForm();
                }
            });
        },

        /**
         * Reset folder form
         */
        resetFolderForm: function () {
            var $modal = $('#s3FolderModal');
            $modal.find('.s3-folder-submit, .s3-folder-cancel').prop('disabled', false);
            $modal.find('.s3-folder-loading').hide();
        },

        /**
         * Open the create folder modal
         */
        openCreateFolderModal: function (bucket, prefix) {
            this.currentBucket = bucket;
            this.currentPrefix = prefix || '';

            $('#s3FolderNameInput').val('');
            $('.s3-folder-error').hide();
            $('.s3-folder-submit').prop('disabled', true);
            $('.s3-folder-loading').hide();

            $('#s3FolderModal').fadeIn(200);
            setTimeout(function () {
                $('#s3FolderNameInput').focus();
            }, 250);
        },

        /**
         * Close the folder modal
         */
        closeFolderModal: function () {
            $('#s3FolderModal').fadeOut(200);
        },

        /* ========================================
         * FILE OPERATIONS
         * ======================================== */

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
            var confirmMessage = this.i18n.confirmDelete.replace('{filename}', filename);

            if (!window.confirm(confirmMessage)) return;

            this.setButtonLoading($button, true);

            this.makeAjaxRequest('s3_delete_object_', {
                bucket: $button.data('bucket'),
                key: $button.data('key')
            }, {
                success: function (response) {
                    self.showNotification(response.data.message || self.i18n.deleteSuccess, 'success');
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

            // Reset all buttons
            $('.s3-favorite-bucket').each(function () {
                var $otherButton = $(this);
                var $otherIcon = $otherButton.find('.dashicons');

                $otherIcon.removeClass('dashicons-star-filled s3-favorite-active')
                    .addClass('dashicons-star-empty');
                $otherButton.data('action', 'add');

                $otherButton.contents().filter(function () {
                    return this.nodeType === 3;
                }).replaceWith(self.i18n.setDefault);
            });

            // Update clicked button if it was added as favorite
            if (response.data.status === 'added') {
                var $icon = $button.find('.dashicons');
                $icon.removeClass('dashicons-star-empty')
                    .addClass('dashicons-star-filled s3-favorite-active');
                $button.data('action', 'remove');

                $button.contents().filter(function () {
                    return this.nodeType === 3;
                }).replaceWith(self.i18n.defaultText);
            }
        },

        /* ========================================
         * CACHE & REFRESH
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
                    self.showNotification(response.data.message || self.i18n.cacheRefreshed, 'success');
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
         * SEARCH & FILTERING
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
                    .replace('{visible}', visibleRows)
                    .replace('{total}', totalRows);
                $stats.text(matchText);
            }
        },

        /**
         * Show no results message when search finds nothing
         */
        showNoSearchResults: function ($tbody, $stats, searchTerm) {
            $stats.text(this.i18n.noMatchesFound);
            var colCount = $('.wp-list-table thead th').length;
            var noResultsText = this.i18n.noFilesFound.replace('{term}', $('<div>').text(searchTerm).html());

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
         * LOAD MORE & PAGINATION
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
         * Load more items via AJAX - FIXED VERSION
         */
        loadMoreItems: function (token, bucket, prefix, $button) {
            var self = this;

            if (self.isLoading || !token) return;
            self.isLoading = true;

            $button.prop('disabled', true)
                .find('.s3-button-text').text(self.i18n.loadingText)
                .end().find('.spinner').show();

            // Use the consistent action name pattern like other AJAX calls
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
                // Hide just the load more button wrapper, not entire tablenav
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
                .find('.s3-button-text').text(this.i18n.loadMoreItems)
                .end().find('.spinner').hide();
        },

        /**
         * Reset load more button to default state
         */
        resetLoadMoreButton: function ($button) {
            $button.prop('disabled', false)
                .find('.s3-button-text').text(this.i18n.loadMoreItems)
                .end().find('.spinner').hide();
        },

        /* ========================================
         * UPLOAD INTEGRATION
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
                    self.showNotification(self.i18n.waitForUploads, 'info');
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
         * UTILITY FUNCTIONS
         * ======================================== */

        /**
         * Navigate to a new location within the browser
         */
        navigateTo: function (params) {
            params.chromeless = 1;
            params.post_id = s3BrowserConfig.postId || 0;
            params.tab = 's3_' + S3BrowserGlobalConfig.providerId;

            var url = window.location.href.split('?')[0] + '?' + $.param(params);
            window.location.href = url;
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

            var itemText = this.totalLoadedItems === 1 ? this.i18n.singleItem : this.i18n.multipleItems;
            var text = this.totalLoadedItems + ' ' + itemText;
            if (hasMore) text += this.i18n.moreAvailable;

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