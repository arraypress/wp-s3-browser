/**
 * S3 Browser Core - Main functionality with search, navigation, and utilities
 * Handles initialization, search, navigation, and common utilities
 */
(function ($) {
    'use strict';

    // Prevent double initialization
    if (window.S3BrowserInitialized) return;
    window.S3BrowserInitialized = true;

    // Main S3Browser object
    window.S3Browser = {
        // Essential state only
        originalTableData: null,
        totalLoadedItems: 0,
        isLoading: false,
        hasActiveUploads: false,

        /**
         * Initialize the S3 Browser
         */
        init: function () {
            this.bindAllEvents();
            this.setupJSSearch();
            this.setupAjaxLoading();
            this.countInitialItems();
            this.initUploadToggle();
            this.initRowActions();
        },

        /**
         * Bind all event handlers in one place
         */
        bindAllEvents: function () {
            var self = this;

            // Row actions
            $(document).off('click.s3rowactions').on('click.s3rowactions', '.wp-list-table .row-actions a', function (e) {
                e.preventDefault();
                var $link = $(this);

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

            // File selection and folder navigation
            $(document).off('click.s3insertfile').on('click.s3insertfile', '.s3-insert-file', function (e) {
                e.preventDefault();
                self.handleFileSelection($(this));
            });

            $(document).off('click.s3openfolder').on('click.s3openfolder', '.s3-open-folder', function (e) {
                e.preventDefault();
                self.handleFolderOpen($(this));
            });

            // Navigation
            $(document).off('click.s3nav').on('click.s3nav', '.s3-browser-container a', function (e) {
                var $link = $(this);

                if ($link.hasClass('bucket-name') || $link.hasClass('browse-bucket-button')) {
                    e.preventDefault();
                    self.navigateTo({bucket: $link.data('bucket')});
                }

                if ($link.hasClass('s3-folder-link')) {
                    e.preventDefault();
                    self.navigateTo({
                        bucket: $link.data('bucket') || $('#s3-load-more').data('bucket') || S3BrowserGlobalConfig.defaultBucket,
                        prefix: $link.data('prefix')
                    });
                }
            });

            // Search
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

            // Load more
            $(document).off('click.s3loadmore').on('click.s3loadmore', '#s3-load-more', function (e) {
                e.preventDefault();
                if (self.isLoading) return;

                var $button = $(this);
                self.loadMoreItems($button.data('token'), $button.data('bucket'), $button.data('prefix'), $button);
            });

            // Refresh cache
            $(document).off('click.s3refresh').on('click.s3refresh', '.s3-refresh-button', function (e) {
                e.preventDefault();
                self.refreshCache($(this));
            });

            // Folder creation
            $(document).off('click.s3folder').on('click.s3folder', '#s3-create-folder', function (e) {
                e.preventDefault();
                var $button = $(this);
                self.openCreateFolderModal($button.data('bucket'), $button.data('prefix'));
            });

            // Favorites
            $(document).off('click.s3files').on('click.s3files', '.s3-browser-container', function (e) {
                var $target = $(e.target);
                var $starTarget = $target.hasClass('s3-favorite-bucket') || $target.hasClass('s3-favorite-star')
                    ? $target : $target.closest('.s3-favorite-bucket, .s3-favorite-star');

                if ($starTarget.length) {
                    e.preventDefault();
                    e.stopPropagation();
                    self.toggleFavoriteBucket($starTarget);
                }
            });

            // Row hover effects
            $(document).off('mouseenter.s3rowactions mouseleave.s3rowactions')
                .on('mouseenter.s3rowactions', '.wp-list-table tbody tr', function () {
                    $(this).find('.row-actions').css('visibility', 'visible');
                })
                .on('mouseleave.s3rowactions', '.wp-list-table tbody tr', function () {
                    $(this).find('.row-actions').css('visibility', 'hidden');
                });
        },

        /**
         * Initialize WordPress-style row actions
         */
        initRowActions: function () {
            // Already handled in bindAllEvents
        },

        /**
         * Initialize client-side search functionality
         */
        setupJSSearch: function () {
            var $table = $('.wp-list-table tbody');
            if ($table.length) {
                this.originalTableData = $table.find('tr:not(.s3-no-results)').clone();
            }
        },

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
         * Count initial items in the table
         */
        countInitialItems: function () {
            this.totalLoadedItems = $('.wp-list-table tbody tr:not(.s3-no-results)').length;
            var hasMore = $('#s3-load-more').length && $('#s3-load-more').is(':visible');
            this.updateTotalCount(hasMore);
        },

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
                    self.showNotification(s3BrowserConfig.i18n.ui.waitForUploads, 'info');
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

        // ===========================================
        // SEARCH & FILTERING
        // ===========================================

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
                $stats.text(s3BrowserConfig.i18n.search.noMatchesFound);
                var colCount = $('.wp-list-table thead th').length;
                var noResultsText = s3BrowserConfig.i18n.search.noFilesFound.replace('{term}', $('<div>').text(searchTerm).html());
                $tbody.append('<tr class="s3-no-results"><td colspan="' + colCount + '">' + noResultsText + '</td></tr>');
            } else {
                var matchText = s3BrowserConfig.i18n.search.itemsMatch
                    .replace('{visible}', visibleRows)
                    .replace('{total}', totalRows);
                $stats.text(matchText);
            }
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

        // ===========================================
        // NAVIGATION & LOAD MORE
        // ===========================================

        /**
         * Load more items via AJAX
         */
        loadMoreItems: function (token, bucket, prefix, $button) {
            var self = this;

            if (!token) return;
            self.isLoading = true;

            $button.prop('disabled', true)
                .find('.s3-button-text').text(s3BrowserConfig.i18n.loading.loadingText)
                .end().find('.spinner').show();

            this.makeAjaxRequest('s3_load_more_', {
                bucket: bucket,
                prefix: prefix || '',
                continuation_token: token
            }, {
                success: function (response) {
                    var $tbody = $('.wp-list-table tbody');
                    $tbody.append(response.data.html);
                    self.originalTableData = $tbody.find('tr:not(.s3-no-results)').clone();
                    self.totalLoadedItems += response.data.count;

                    if (response.data.has_more && response.data.continuation_token) {
                        $button.data('token', response.data.continuation_token)
                            .prop('disabled', false)
                            .find('.s3-button-text').text(s3BrowserConfig.i18n.loading.loadMoreItems)
                            .end().find('.spinner').hide();
                        self.updateTotalCount(true);
                    } else {
                        $button.closest('.pagination-links').fadeOut(300);
                        self.updateTotalCount(false);
                    }

                    var currentSearch = $('#s3-js-search').val();
                    if (currentSearch) {
                        self.filterTable(currentSearch);
                    }
                },
                error: function (message) {
                    self.showError(message);
                    $button.prop('disabled', false)
                        .find('.s3-button-text').text(s3BrowserConfig.i18n.loading.loadMoreItems)
                        .end().find('.spinner').hide();
                },
                complete: function () {
                    self.isLoading = false;
                }
            });
        },

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
                    self.showNotification(response.data.message || s3BrowserConfig.i18n.cache.cacheRefreshed, 'success');
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

        // ===========================================
        // UTILITY FUNCTIONS
        // ===========================================

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
         * Update the total items count display
         */
        updateTotalCount: function (hasMore) {
            var $countSpan = $('#s3-total-count');
            if (!$countSpan.length) return;

            var itemText = this.totalLoadedItems === 1
                ? s3BrowserConfig.i18n.display.singleItem
                : s3BrowserConfig.i18n.display.multipleItems;
            var text = this.totalLoadedItems + ' ' + itemText;
            if (hasMore) text += s3BrowserConfig.i18n.display.moreAvailable;

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
        if (window.S3Browser && window.S3Browser.refreshSearch) {
            S3Browser.refreshSearch();
        }
    });

})(jQuery);