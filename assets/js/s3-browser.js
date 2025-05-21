(function ($) {
    'use strict';

    // Check if S3Browser is already initialized to prevent double initialization
    if (window.S3BrowserInitialized) {
        return;
    }

    // Set initialization flag
    window.S3BrowserInitialized = true;

    window.S3Browser = {
        originalTableData: null,
        searchTimeout: null,
        totalLoadedItems: 0,
        isLoading: false,
        hasActiveUploads: false,

        init: function () {
            this.bindEvents();
            this.setupJSSearch();
            this.setupAjaxLoading();
            this.countInitialItems();
            this.initFavorites();
            this.initUploadToggle();
            this.improveButtonStyles();
        },

        bindEvents: function () {
            var self = this;
            var config = S3BrowserGlobalConfig;
            var postId = s3BrowserConfig.postId || 0;

            // Use namespaced events to prevent duplicates
            $(document).off('click.s3browser').on('click.s3browser', '.s3-browser-container a', function (e) {
                var $link = $(this);

                // Navigation handlers
                if ($link.hasClass('bucket-name') || $link.hasClass('browse-bucket-button')) {
                    e.preventDefault();
                    self.navigateTo({
                        bucket: $link.data('bucket')
                    });
                    return;
                }

                if ($link.hasClass('s3-folder-link')) {
                    e.preventDefault();
                    self.navigateTo({
                        bucket: $link.data('bucket') || $('#s3-load-more').data('bucket') || config.defaultBucket,
                        prefix: $link.data('prefix')
                    });
                    return;
                }

                // File action handlers
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

            // Set up refresh button separately
            $(document).off('click.s3refresh').on('click.s3refresh', '.s3-refresh-button', function (e) {
                e.preventDefault();
                self.refreshCache($(this));
            });

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

            // Search input and clear button
            $('#s3-js-search').off('input.s3browser').on('input.s3browser', function () {
                var $this = $(this);
                var $clearBtn = $('#s3-js-search-clear');

                $clearBtn.toggle(Boolean($this.val()));

                clearTimeout(self.searchTimeout);
                self.searchTimeout = setTimeout(function () {
                    self.filterTable($this.val());
                }, 200);
            });

            $('#s3-js-search-clear').off('click.s3browser').on('click.s3browser', function () {
                $('#s3-js-search').val('').trigger('input');
            });
        },

        // Navigation helper
        navigateTo: function (params) {
            params.chromeless = 1;
            params.post_id = s3BrowserConfig.postId || 0;
            params.tab = 's3_' + S3BrowserGlobalConfig.providerId;

            var url = window.location.href.split('?')[0] + '?' + $.param(params);
            window.location.href = url;
        },

        // Initialize upload toggle functionality
        initUploadToggle: function () {
            var self = this;

            // Toggle upload container visibility
            $('#s3-toggle-upload').on('click', function () {
                $('#s3-upload-container').slideToggle(300);

                // Track if it's being opened or closed
                var isVisible = $('#s3-upload-container').is(':visible');
                $(this).toggleClass('active', isVisible);

                // If closing and no active uploads, clear the list
                if (!isVisible && !self.hasActiveUploads) {
                    setTimeout(function () {
                        $('.s3-upload-list').empty();
                    }, 300);
                }
            });

            // Close button functionality
            $('.s3-close-upload').on('click', function () {
                if (!self.hasActiveUploads) {
                    $('#s3-upload-container').slideUp(300);
                    $('#s3-toggle-upload').removeClass('active');

                    // Clear the list after animation
                    setTimeout(function () {
                        $('.s3-upload-list').empty();
                    }, 300);
                } else {
                    // If uploads are active, just show a message
                    self.showNotification('Please wait for uploads to complete before closing', 'info');
                }
            });

            // Listen for upload start/complete events from the upload module
            $(document).on('s3UploadStarted', function () {
                self.hasActiveUploads = true;
                // Make sure upload container is visible when upload starts
                $('#s3-upload-container').slideDown(300);
                $('#s3-toggle-upload').addClass('active');
            });

            $(document).on('s3UploadComplete', function () {
                self.hasActiveUploads = false;
            });

            $(document).on('s3AllUploadsComplete', function () {
                self.hasActiveUploads = false;
            });
        },

        // File selection handler
        handleFileSelection: function ($button) {
            var parent = window.parent;
            var fileData = {
                fileName: $button.data('filename'),
                bucket: $button.data('bucket'),
                key: $button.data('key'),
                url: $button.data('bucket') + '/' + $button.data('key')
            };

            // Determine which context called the media browser
            var callingContext = '';
            if (parent.edd_fileurl && parent.edd_filename) {
                callingContext = 'edd';
            } else if (parent.wc_target_input && parent.wc_media_frame_context === 'product_file') {
                callingContext = 'woocommerce_file';
            } else if (parent.wp && parent.wp.media && parent.wp.media.editor) {
                callingContext = 'wp_editor';
            }

            // Insert based on context
            switch (callingContext) {
                case 'edd':
                    // EDD integration
                    parent.jQuery(parent.edd_filename).val(fileData.fileName);
                    parent.jQuery(parent.edd_fileurl).val(fileData.url);
                    parent.tb_remove();
                    break;

                case 'woocommerce_file':
                    // WooCommerce downloadable file integration
                    parent.jQuery(parent.wc_target_input).val(fileData.url);
                    var $filenameInput = parent.jQuery(parent.wc_target_input).closest('tr').find('input[name="_wc_file_names[]"]');
                    if ($filenameInput.length) {
                        $filenameInput.val(fileData.fileName);
                    }
                    parent.wp.media.frame.close();
                    break;

                case 'wp_editor':
                    // WordPress editor integration
                    try {
                        // Check if there's an active editor instance
                        if (parent.wp.media.editor.activeEditor) {
                            parent.wp.media.editor.insert(fileData.url);
                        } else {
                            // Fallback for when activeEditor is not set
                            var wpActiveEditor = parent.wpActiveEditor;
                            if (wpActiveEditor) {
                                parent.wp.media.editor.insert(fileData.url, parent.wpActiveEditor);
                            } else {
                                alert('File URL: ' + fileData.url);
                            }
                        }
                        if (parent.wp.media.frame) parent.wp.media.frame.close();
                    } catch (e) {
                        console.log('Editor insertion error:', e);
                        alert('File URL: ' + fileData.url);
                    }
                    break;

                default:
                    // Fallback
                    alert('File URL: ' + fileData.url);
            }
        },

        // File deletion handler
        deleteFile: function ($button) {
            var self = this;
            var filename = $button.data('filename');
            var bucket = $button.data('bucket');
            var key = $button.data('key');

            // Enhanced confirmation with more details
            if (!window.confirm('Are you sure you want to delete "' + filename + '"?\n\nThis action cannot be undone.')) {
                return; // User cancelled
            }

            // User confirmed, proceed with deletion
            $button.prop('disabled', true)
                .find('.dashicons').addClass('spin');

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
                        self.showNotification(response.data.message || 'File successfully deleted', 'success');

                        // Remove the row from the table
                        $button.closest('tr').fadeOut(300, function () {
                            $(this).remove();

                            // Update total count
                            self.totalLoadedItems--;
                            self.updateTotalCount(false);

                            // Also update the search data
                            self.refreshSearch();
                        });
                    } else {
                        // Show error notification
                        self.showNotification(response.data.message || 'Failed to delete file', 'error');

                        // Reset button state
                        $button.prop('disabled', false)
                            .find('.dashicons').removeClass('spin');
                    }
                },
                error: function () {
                    // Show network error notification
                    self.showNotification('Network error. Please try again.', 'error');

                    // Reset button state
                    $button.prop('disabled', false)
                        .find('.dashicons').removeClass('spin');
                }
            });
        },

        // Initialize search functionality
        setupJSSearch: function () {
            var $table = $('.wp-list-table tbody');
            if (!$table.length) return;

            // Store original table data
            this.originalTableData = $table.find('tr:not(.s3-no-results)').clone();
        },

        setupAjaxLoading: function () {
            var self = this;
            var config = S3BrowserGlobalConfig;

            // Optional auto-load
            if (s3BrowserConfig.autoLoad) {
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
            }
        },

        // Cache refresh handling
        refreshCache: function ($button) {
            var self = this;
            const provider = $button.data('provider');
            const type = $button.data('type');
            const bucket = $button.data('bucket') || '';
            const prefix = $button.data('prefix') || '';

            // Prevent multiple clicks
            if ($button.hasClass('refreshing')) {
                return;
            }

            // Update button state
            $button.addClass('refreshing')
                .find('.dashicons').addClass('spin');

            // AJAX request to clear cache
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
                        // Show success notification
                        self.showNotification(response.data.message || 'Cache refreshed successfully', 'success');

                        // Wait 1.5 seconds to show the notification before reloading
                        setTimeout(function () {
                            window.location.reload();
                        }, 1500);
                    } else {
                        // Show error notification
                        self.showNotification(response.data.message || 'Failed to refresh data', 'error');

                        // Reset button state
                        $button.removeClass('refreshing')
                            .find('.dashicons').removeClass('spin');
                    }
                },
                error: function () {
                    // Show network error notification
                    self.showNotification('Network error. Please try again.', 'error');

                    // Reset button state
                    $button.removeClass('refreshing')
                        .find('.dashicons').removeClass('spin');
                }
            });
        },

        // Load more items
        loadMoreItems: function (token, bucket, prefix, $button) {
            var self = this;
            var config = S3BrowserGlobalConfig;

            if (self.isLoading || !token) return;
            self.isLoading = true;

            // Update button state
            $button.prop('disabled', true)
                .find('.s3-button-text').text('Loading...')
                .end()
                .find('.spinner').show();

            // AJAX request
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
                        // Append new rows
                        var $tbody = $('.wp-list-table tbody');
                        $tbody.append(response.data.html);

                        // Update search data
                        self.originalTableData = $tbody.find('tr:not(.s3-no-results)').clone();

                        // Update total count
                        self.totalLoadedItems += response.data.count;
                        self.updateTotalCount(response.data.has_more);

                        // Handle button state
                        if (response.data.has_more && response.data.continuation_token) {
                            $button.data('token', response.data.continuation_token)
                                .find('.s3-button-text').text('Load More Items')
                                .end()
                                .find('.spinner').hide()
                                .end()
                                .prop('disabled', false);
                        } else {
                            // No more items
                            $button.closest('.s3-load-more-wrapper').fadeOut(300);
                            self.updateTotalCount(false);
                        }

                        // Re-apply search
                        var currentSearch = $('#s3-js-search').val();
                        if (currentSearch) {
                            self.filterTable(currentSearch);
                        }

                        // Update button styles for new elements
                        // self.improveButtonStyles();
                    } else {
                        self.showError('Failed to load more items. Please try again.');
                        self.resetButton($button);
                    }
                },
                error: function (xhr, status, error) {
                    console.error('AJAX Error:', error);
                    self.showError('Network error. Please check your connection and try again.');
                    self.resetButton($button);
                },
                complete: function () {
                    self.isLoading = false;
                }
            });
        },

        resetButton: function ($button) {
            $button.find('.s3-button-text').text('Load More Items')
                .end()
                .find('.spinner').hide()
                .end()
                .prop('disabled', false);
        },

        filterTable: function (searchTerm) {
            var $tbody = $('.wp-list-table tbody');
            var $stats = $('.s3-search-stats');
            var $bottomNav = $('.tablenav.bottom');

            $tbody.find('.s3-no-results').remove();

            if (!searchTerm) {
                $tbody.empty().append(this.originalTableData.clone());
                $stats.text('');
                $bottomNav.show();

                // Update button styles for restored elements
                // this.improveButtonStyles();
                return;
            }

            // Hide bottom navigation during search
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
                $stats.text('No matches found');
                var colCount = $('.wp-list-table thead th').length;
                $tbody.append(
                    '<tr class="s3-no-results"><td colspan="' + colCount + '">' +
                    'No files or folders found matching "' + $('<div>').text(searchTerm).html() + '"' +
                    '</td></tr>'
                );
            } else {
                $stats.text(visibleRows + ' of ' + totalRows + ' items match');

                // Update button styles for filtered elements
                this.improveButtonStyles();
            }
        },

        countInitialItems: function () {
            this.totalLoadedItems = $('.wp-list-table tbody tr:not(.s3-no-results)').length;
            var hasMore = $('#s3-load-more').length && $('#s3-load-more').is(':visible');
            this.updateTotalCount(hasMore);
        },

        updateTotalCount: function (hasMore) {
            var $countSpan = $('#s3-total-count');

            if ($countSpan.length) {
                var itemText = this.totalLoadedItems === 1 ? 'item' : 'items';
                var text = this.totalLoadedItems + ' ' + itemText;
                if (hasMore) text += ' (more available)';
                $countSpan.text(text);
            }
        },

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

        showNotification: function (message, type) {
            // First, remove any existing notifications
            $('.s3-notification').remove();

            // Create the notification with the message and type
            var $notification = $('<div class="s3-notification s3-notification-' + type + '">' + message + '</div>');

            // Add to the top of the container for maximum visibility
            $('.s3-browser-container').prepend($notification);

            // Ensure the notification is visible by scrolling to it
            if ($notification.length) {
                $('html, body').animate({
                    scrollTop: $notification.offset().top - 50
                }, 200);
            }

            // For success notifications that will be followed by reload, don't auto-hide
            if (type !== 'success') {
                // For other notifications, auto-hide after delay
                setTimeout(function () {
                    $notification.fadeOut(300, function () {
                        $(this).remove();
                    });
                }, 5000);
            }
        },

        refreshSearch: function () {
            var $table = $('.wp-list-table tbody');
            if ($table.length) {
                this.originalTableData = $table.find('tr:not(.s3-no-results)').clone();

                var currentSearch = $('#s3-js-search').val();
                if (currentSearch) {
                    this.filterTable(currentSearch);
                } else {
                    $('.s3-search-stats').text('');
                }
            }
        },

        // Toggle favorite bucket
        toggleFavoriteBucket: function ($button) {
            var self = this;
            var bucket = $button.data('bucket');
            var provider = $button.data('provider');
            var action = $button.data('action');
            var postType = $button.data('post-type');

            // Disable button temporarily
            $button.addClass('s3-processing');

            // Send AJAX request
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
                        // Reset all buttons
                        $('.s3-favorite-bucket').each(function () {
                            var $otherButton = $(this);
                            var $otherIcon = $otherButton.find('.dashicons');

                            $otherIcon.removeClass('dashicons-star-filled s3-favorite-active')
                                .addClass('dashicons-star-empty');
                            $otherButton.data('action', 'add');

                            // Update text to "Set Default"
                            $otherButton.contents().filter(function () {
                                return this.nodeType === 3; // Text nodes only
                            }).replaceWith('Set Default');
                        });

                        // Update clicked button if necessary
                        if (response.data.status === 'added') {
                            var $icon = $button.find('.dashicons');
                            $icon.removeClass('dashicons-star-empty')
                                .addClass('dashicons-star-filled s3-favorite-active');
                            $button.data('action', 'remove');

                            // Update text to "Default"
                            $button.contents().filter(function () {
                                return this.nodeType === 3; // Text nodes only
                            }).replaceWith('Default');
                        }

                        self.showNotification(response.data.message, 'success');
                    } else {
                        self.showNotification(response.data.message || 'Error updating default bucket', 'error');
                    }
                },
                error: function () {
                    self.showNotification('Network error. Please try again.', 'error');
                },
                complete: function () {
                    $button.removeClass('s3-processing');
                }
            });
        },

        // Initialize favoriting system
        initFavorites: function () {
            // All handled by our click handler
        }
    };

    // Initialize
    $(document).ready(function () {
        S3Browser.init();
    });

    $(window).on('load', function () {
        if (window.S3Browser) {
            S3Browser.refreshSearch();
        }
    });

})(jQuery);