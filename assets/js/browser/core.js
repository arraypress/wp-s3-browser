/**
 * S3 Browser Core - Lightweight initialization and core functionality
 * Handles setup, search, navigation, and state management
 */
(function ($) {
    'use strict';

    // Prevent double initialization
    if (window.S3BrowserInitialized) return;
    window.S3BrowserInitialized = true;

    // Main S3Browser object - streamlined
    window.S3Browser = {

        // Core state
        state: {
            originalTableData: null,
            totalLoadedItems: 0,
            isLoading: false,
            hasActiveUploads: false,
            searchTimeout: null
        },

        // ===========================================
        // INITIALIZATION
        // ===========================================

        /**
         * Initialize the S3 Browser
         */
        init: function () {
            this.setupEventHandlers();
            this.initializeSearch();
            this.setupInfiniteScroll();
            this.countInitialItems();
            this.initializeUploadToggle();
        },

        /**
         * Setup all event handlers with proper namespacing
         */
        setupEventHandlers: function () {
            // Row actions - delegate from container for performance
            S3.on('.wp-list-table .row-actions a', 'click', this.handleRowAction.bind(this));

            // File and folder interactions
            S3.on('.s3-insert-file', 'click', this.handleFileSelection.bind(this));
            S3.on('.s3-open-folder', 'click', this.handleFolderOpen.bind(this));

            // Folder navigation (specific to folder links, not bucket names)
            S3.on('.s3-folder-link', 'click', this.handleFolderNavigation.bind(this));

            // Search functionality
            S3.on('#s3-js-search', 'input', this.handleSearchInput.bind(this));
            S3.on('#s3-js-search-clear', 'click', this.clearSearch.bind(this));

            // Load more and refresh
            S3.on('#s3-load-more', 'click', this.handleLoadMore.bind(this));
            S3.on('.s3-refresh-button', 'click', this.handleRefresh.bind(this));

            // Folder creation
            S3.on('#s3-create-folder', 'click', this.handleCreateFolder.bind(this));
        },

        // ===========================================
        // EVENT HANDLERS
        // ===========================================

        /**
         * Unified row action handler
         */
        handleRowAction: function (e) {
            e.preventDefault();
            const $link = $(e.target);
            const action = this.getActionFromClasses($link);

            switch (action) {
                case 'download':
                    window.open($link.data('url'), '_blank');
                    break;
                case 'delete-file':
                    window.S3Files?.deleteFile($link);
                    break;
                case 'delete-folder':
                    window.S3Folders?.deleteFolderConfirm($link);
                    break;
                case 'rename':
                    window.S3Files?.openRenameModal($link);
                    break;
                case 'copy-link':
                    window.S3Files?.openCopyLinkModal($link);
                    break;
                case 'details':
                    window.S3Files?.openDetailsModal($link);
                    break;
            }
        },

        /**
         * Extract action from element classes
         */
        getActionFromClasses: function ($element) {
            const classes = $element.attr('class') || '';

            if (classes.includes('s3-download-file')) return 'download';
            if (classes.includes('s3-delete-file')) return 'delete-file';
            if (classes.includes('s3-delete-folder')) return 'delete-folder';
            if (classes.includes('s3-rename-file')) return 'rename';
            if (classes.includes('s3-copy-link')) return 'copy-link';
            if (classes.includes('s3-show-details')) return 'details';

            return 'unknown';
        },

        /**
         * Handle file selection for integrations
         */
        handleFileSelection: function (e) {
            e.preventDefault();
            window.S3Integrations?.handleFileSelection($(e.target));
        },

        /**
         * Handle folder opening
         */
        handleFolderOpen: function (e) {
            e.preventDefault();
            window.S3Folders?.handleFolderOpen($(e.target));
        },

        /**
         * Handle folder navigation
         */
        handleFolderNavigation: function (e) {
            e.preventDefault();
            const $link = $(e.target);
            const bucket = $link.data('bucket') || $('#s3-load-more').data('bucket') || S3BrowserGlobalConfig.defaultBucket;
            const prefix = $link.data('prefix');

            S3.navigate({ bucket, prefix });
        },

        /**
         * Handle search input with debouncing
         */
        handleSearchInput: function (e) {
            const $input = $(e.target);
            const value = $input.val();

            $('#s3-js-search-clear').toggle(Boolean(value));

            clearTimeout(this.state.searchTimeout);
            this.state.searchTimeout = setTimeout(() => {
                this.filterTable(value);
            }, 200);
        },

        /**
         * Clear search
         */
        clearSearch: function (e) {
            e.preventDefault();
            $('#s3-js-search').val('').trigger('input');
        },

        /**
         * Handle load more button
         */
        handleLoadMore: function (e) {
            e.preventDefault();
            if (this.state.isLoading) return;

            const $button = $(e.target);
            this.loadMoreItems(
                $button.data('token'),
                $button.data('bucket'),
                $button.data('prefix'),
                $button
            );
        },

        /**
         * Handle refresh button
         */
        handleRefresh: function (e) {
            e.preventDefault();
            this.refreshCache($(e.target));
        },

        /**
         * Handle create folder button
         */
        handleCreateFolder: function (e) {
            e.preventDefault();
            const $button = $(e.target);
            window.S3Folders?.openCreateFolderModal($button.data('bucket'), $button.data('prefix'));
        }

        // ===========================================
        // SEARCH FUNCTIONALITY
        // ===========================================

        /**
         * Initialize search functionality
         */
        initializeSearch: function () {
            const $table = $('.wp-list-table tbody');
            if ($table.length) {
                this.state.originalTableData = $table.find('tr:not(.s3-no-results)').clone();
            }
        },

        /**
         * Filter table based on search term
         */
        filterTable: function (searchTerm) {
            const $tbody = $('.wp-list-table tbody');
            const $stats = $('.s3-search-stats');
            const $bottomNav = $('.tablenav.bottom');

            // Clear existing results
            $tbody.find('.s3-no-results').remove();

            if (!searchTerm) {
                this.resetTable($tbody, $stats, $bottomNav);
                return;
            }

            this.performSearch(searchTerm, $tbody, $stats, $bottomNav);
        },

        /**
         * Reset table to original state
         */
        resetTable: function ($tbody, $stats, $bottomNav) {
            $tbody.empty().append(this.state.originalTableData.clone());
            $stats.text('');
            $bottomNav.show();
        },

        /**
         * Perform search and update display
         */
        performSearch: function (searchTerm, $tbody, $stats, $bottomNav) {
            $bottomNav.hide();

            const normalizedTerm = searchTerm.toLowerCase();
            let visibleRows = 0;
            let totalRows = 0;

            $tbody.empty();

            this.state.originalTableData.each(function () {
                totalRows++;
                const $row = $(this);
                const fileName = $row.find('.column-name').text().toLowerCase();

                if (fileName.includes(normalizedTerm)) {
                    $tbody.append($row);
                    visibleRows++;
                }
            });

            this.updateSearchResults(visibleRows, totalRows, searchTerm, $tbody, $stats);
        },

        /**
         * Update search results display
         */
        updateSearchResults: function (visibleRows, totalRows, searchTerm, $tbody, $stats) {
            if (visibleRows === 0) {
                $stats.text(s3BrowserConfig.i18n.search.noMatchesFound);
                const colCount = $('.wp-list-table thead th').length;
                const noResultsText = s3BrowserConfig.i18n.search.noFilesFound.replace('{term}', S3.escapeHtml(searchTerm));
                $tbody.append(`<tr class="s3-no-results"><td colspan="${colCount}">${noResultsText}</td></tr>`);
            } else {
                const matchText = s3BrowserConfig.i18n.search.itemsMatch
                    .replace('{visible}', visibleRows)
                    .replace('{total}', totalRows);
                $stats.text(matchText);
            }
        },

        /**
         * Refresh search data after table changes
         */
        refreshSearch: function () {
            const $table = $('.wp-list-table tbody');
            if (!$table.length) return;

            this.state.originalTableData = $table.find('tr:not(.s3-no-results)').clone();

            const currentSearch = $('#s3-js-search').val();
            if (currentSearch) {
                this.filterTable(currentSearch);
            } else {
                $('.s3-search-stats').text('');
            }
        },

        // ===========================================
        // LOAD MORE FUNCTIONALITY
        // ===========================================

        /**
         * Setup infinite scroll if enabled
         */
        setupInfiniteScroll: function () {
            if (!s3BrowserConfig.autoLoad) return;

            const throttledScroll = S3.throttle(() => {
                if (this.state.isLoading) return;

                const $loadMore = $('#s3-load-more');
                if (!$loadMore.length || !$loadMore.is(':visible')) return;

                const windowBottom = $(window).scrollTop() + $(window).height();
                const buttonTop = $loadMore.offset().top;

                if (windowBottom > buttonTop - 200) {
                    $loadMore.trigger('click');
                }
            }, 100);

            $(window).off('scroll.s3browser').on('scroll.s3browser', throttledScroll);
        },

        /**
         * Load more items via AJAX
         */
        loadMoreItems: function (token, bucket, prefix, $button) {
            if (!token) return;

            this.state.isLoading = true;
            this.updateLoadMoreButton($button, true);

            S3.ajax('s3_load_more_', {
                bucket: bucket,
                prefix: prefix || '',
                continuation_token: token
            }, {
                success: (response) => this.handleLoadMoreSuccess(response, $button),
                error: (message) => this.handleLoadMoreError(message, $button),
                complete: () => { this.state.isLoading = false; }
            });
        },

        /**
         * Handle successful load more response
         */
        handleLoadMoreSuccess: function (response, $button) {
            const $tbody = $('.wp-list-table tbody');
            $tbody.append(response.data.html);

            this.state.originalTableData = $tbody.find('tr:not(.s3-no-results)').clone();
            this.state.totalLoadedItems += response.data.count;

            if (response.data.has_more && response.data.continuation_token) {
                this.updateLoadMoreButton($button, false, response.data.continuation_token);
                S3.updateCount(this.state.totalLoadedItems, true);
            } else {
                $button.closest('.pagination-links').fadeOut(300);
                S3.updateCount(this.state.totalLoadedItems, false);
            }

            // Refresh search if active
            const currentSearch = $('#s3-js-search').val();
            if (currentSearch) {
                this.filterTable(currentSearch);
            }
        },

        /**
         * Handle load more error
         */
        handleLoadMoreError: function (message, $button) {
            S3.notify(message, 'error');
            this.updateLoadMoreButton($button, false);
        },

        /**
         * Update load more button state
         */
        updateLoadMoreButton: function ($button, isLoading, newToken = null) {
            const $text = $button.find('.s3-button-text');
            const $spinner = $button.find('.spinner');

            if (isLoading) {
                $button.prop('disabled', true);
                $text.text(s3BrowserConfig.i18n.loading.loadingText);
                $spinner.show();
            } else {
                $button.prop('disabled', false);
                $text.text(s3BrowserConfig.i18n.loading.loadMoreItems);
                $spinner.hide();

                if (newToken) {
                    $button.data('token', newToken);
                }
            }
        },

        // ===========================================
        // CACHE & REFRESH
        // ===========================================

        /**
         * Refresh cache via AJAX
         */
        refreshCache: function ($button) {
            if ($button.hasClass('refreshing')) return;

            $button.addClass('refreshing').find('.dashicons').addClass('spin');

            S3.ajax('s3_clear_cache_', {
                type: $button.data('type'),
                bucket: $button.data('bucket') || '',
                prefix: $button.data('prefix') || ''
            }, {
                success: (response) => {
                    S3.notify(response.data.message || s3BrowserConfig.i18n.cache.cacheRefreshed, 'success');
                    setTimeout(() => window.location.reload(), 1500);
                },
                error: (message) => {
                    S3.notify(message, 'error');
                    $button.removeClass('refreshing').find('.dashicons').removeClass('spin');
                }
            });
        },

        // ===========================================
        // UPLOAD TOGGLE & STATE
        // ===========================================

        /**
         * Initialize upload toggle functionality
         */
        initializeUploadToggle: function () {
            // Upload toggle button
            $('#s3-toggle-upload').on('click', () => {
                const $container = $('#s3-upload-container');
                $container.slideToggle(300);

                const isVisible = $container.is(':visible');
                $('#s3-toggle-upload').toggleClass('active', isVisible);

                if (!isVisible && !this.state.hasActiveUploads) {
                    setTimeout(() => $('.s3-upload-list').empty(), 300);
                }
            });

            // Close upload panel
            $('.s3-close-upload').on('click', () => {
                if (!this.state.hasActiveUploads) {
                    $('#s3-upload-container').slideUp(300);
                    $('#s3-toggle-upload').removeClass('active');
                    setTimeout(() => $('.s3-upload-list').empty(), 300);
                } else {
                    S3.notify(s3BrowserConfig.i18n.ui.waitForUploads, 'info');
                }
            });

            // Listen for upload events
            $(document)
                .on('s3UploadStarted', () => {
                    this.state.hasActiveUploads = true;
                    $('#s3-upload-container').slideDown(300);
                    $('#s3-toggle-upload').addClass('active');
                })
                .on('s3UploadComplete s3AllUploadsComplete', () => {
                    this.state.hasActiveUploads = false;
                });
        },

        // ===========================================
        // UTILITY FUNCTIONS
        // ===========================================

        /**
         * Count initial items in the table
         */
        countInitialItems: function () {
            this.state.totalLoadedItems = $('.wp-list-table tbody tr:not(.s3-no-results)').length;
            const hasMore = $('#s3-load-more').length && $('#s3-load-more').is(':visible');
            S3.updateCount(this.state.totalLoadedItems, hasMore);
        }
    };

    // Initialize when document is ready
    $(document).ready(() => S3Browser.init());

    // Refresh search on window load for cached data
    $(window).on('load', () => S3Browser.refreshSearch?.());

})(jQuery);