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
            this.bindUploadEvents();
            this.setupJSSearch();
            this.setupAjaxLoading();
            this.countInitialItems();
            this.initUploadToggle();
            this.bindBucketEvents();
            this.initTooltips();
        },

        /**
         * Initialize tooltip functionality
         */
        initTooltips: function () {
            var self = this;

            // Enhanced tooltip positioning for edge cases
            $(document).on('mouseenter', '.s3-has-tooltip', function() {
                var $tooltip = $(this);
                var rect = this.getBoundingClientRect();
                var tooltipWidth = 200; // Approximate max tooltip width

                // Reset position classes
                $tooltip.removeAttr('data-tooltip-position');

                // Check if tooltip would go off the right edge
                if (rect.left + tooltipWidth/2 > window.innerWidth - 20) {
                    $tooltip.attr('data-tooltip-position', 'right');
                }
                // Check if tooltip would go off the left edge
                else if (rect.left - tooltipWidth/2 < 20) {
                    $tooltip.attr('data-tooltip-position', 'left');
                }
            });
        },

        /**
         * Reinitialize tooltips after content changes
         */
        refreshTooltips: function () {
            // Remove any stuck tooltip positioning
            $('.s3-has-tooltip').removeAttr('data-tooltip-position');
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
                    self.downloadFile($link);
                } else if ($link.hasClass('s3-delete-file')) {
                    self.deleteFile($link);
                } else if ($link.hasClass('s3-delete-folder')) {
                    self.deleteFolderConfirm($link);
                } else if ($link.hasClass('s3-rename-file')) {
                    self.openRenameModal($link);
                } else if ($link.hasClass('s3-move-file')) {
                    self.openMoveModal($link);
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

            // Ticking files is additive: the per-row Insert button still works
            // on its own, so a single file costs no extra clicks.
            $(document).off('change.s3select').on('change.s3select', '.s3-select-file, .wp-list-table thead .check-column input, .wp-list-table tfoot .check-column input', function () {
                var $input = $(this);

                if (!$input.hasClass('s3-select-file')) {
                    $('.s3-select-file').prop('checked', $input.prop('checked'));
                }

                self.updateSelectionBar();
            });

            $(document).off('click.s3clearsel').on('click.s3clearsel', '.s3-clear-selection', function (e) {
                e.preventDefault();
                $('.s3-select-file, .wp-list-table .check-column input').prop('checked', false);
                self.updateSelectionBar();
            });

            $(document).off('click.s3insertsel').on('click.s3insertsel', '.s3-insert-selected', function (e) {
                e.preventDefault();
                self.handleMultipleFileSelection(self.selectedFiles());
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
                this.refreshTooltips(); // Refresh tooltips after content change
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

            this.refreshTooltips(); // Refresh tooltips after filtering
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

            this.refreshTooltips(); // Refresh tooltips after search refresh
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

            self.setButtonBusy($button);
            $button.find('.s3-button-text').text(s3BrowserConfig.i18n.loading.loadingText);

            this.makeAjaxRequest('listObjects', {
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
                        $button.data('token', response.data.continuation_token);
                        self.clearButtonBusy($button);
                        $button.find('.s3-button-text').text(s3BrowserConfig.i18n.loading.loadMoreItems);
                        self.updateTotalCount(true);
                    } else {
                        $button.closest('.pagination-links').fadeOut(300);
                        self.updateTotalCount(false);
                    }

                    var currentSearch = $('#s3-js-search').val();
                    if (currentSearch) {
                        self.filterTable(currentSearch);
                    }

                    self.refreshTooltips(); // Refresh tooltips after loading more items
                },
                error: function (message) {
                    self.showError(message);
                    self.clearButtonBusy($button);
                    $button.find('.s3-button-text').text(s3BrowserConfig.i18n.loading.loadMoreItems);
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

            // setButtonBusy() returns false when the button is already busy,
            // replacing the bespoke 'refreshing' class guard this used to keep.
            if (!self.setButtonBusy($button, s3BrowserConfig.i18n.ui.refreshing)) {
                return;
            }

            this.makeAjaxRequest('clearCache', {
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
                    self.clearButtonBusy($button);
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
         * Fetch a download URL and open it.
         *
         * Minted on click rather than rendered into every row: signing each
         * one up front is a full SigV4 derivation per file, and it would put a
         * working, hour-long URL for every object into the page source.
         */
        downloadFile: function ($link) {
            var self = this;

            if ($link.data('s3-fetching')) {
                return;
            }

            $link.data('s3-fetching', true);

            // Opened before the request so the click is still trusted; popup
            // blockers reject window.open() from an async callback.
            var target = window.open('', '_blank');

            this.makeAjaxRequest('downloadUrl', {
                bucket: $link.data('bucket'),
                object_key: $link.data('key')
            }, {
                success: function (response) {
                    if (target) {
                        target.location = response.data.url;
                    } else {
                        window.location = response.data.url;
                    }
                },
                error: function (message) {
                    if (target) {
                        target.close();
                    }
                    self.showNotification(message, 'error');
                },
                complete: function () {
                    $link.removeData('s3-fetching');
                }
            });
        },

        /**
         * Put a button into its busy state.
         *
         * Uses WordPress core's `updating-message` class, which draws a
         * spinning dashicon through ::before and animates it — the same
         * treatment core gives plugin-update buttons, and what EDD's licence
         * handler uses. The icon exists only while work is in flight, which is
         * precisely why the buttons carry no icon markup of their own.
         *
         * The original label is stashed so it can be restored afterwards.
         *
         * @param {jQuery} $button Button element.
         * @param {string} [label] Optional progress label.
         * @return {boolean} False when the button was already busy.
         */
        setButtonBusy: function ($button, label) {
            if (!$button || !$button.length || $button.prop('disabled')) {
                return false;
            }

            if ($button.data('s3-original-label') === undefined) {
                $button.data('s3-original-label', $button.text());
            }

            if (label) {
                $button.text(label);
            }

            $button
                .prop('disabled', true)
                .addClass('updating-message')
                .attr('aria-busy', 'true');

            return true;
        },

        /**
         * Return a button to its resting state, restoring its label.
         *
         * @param {jQuery} $button Button element.
         */
        clearButtonBusy: function ($button) {
            if (!$button || !$button.length) {
                return;
            }

            var original = $button.data('s3-original-label');

            if (original !== undefined) {
                $button.text(original);
                $button.removeData('s3-original-label');
            }

            $button
                .prop('disabled', false)
                .removeClass('updating-message')
                .removeAttr('aria-busy');
        },

        /**
         * Escape a value for insertion into HTML *text*.
         *
         * Serialises through a text node, which escapes &, < and > — the
         * characters that matter between tags.
         */
        escapeHtml: function (text) {
            return $('<div>').text(text === undefined || text === null ? '' : text).html();
        },

        /**
         * Escape a value for insertion into a quoted HTML *attribute*.
         *
         * escapeHtml() is not sufficient here. Text-node serialisation leaves
         * quotes untouched, because quotes carry no meaning between tags — so
         * a value of `foo" onfocus=alert(1) autofocus` escapes to itself and
         * then breaks straight out of value="...". Anything interpolated into
         * an attribute must go through this instead.
         *
         * Better still, set the value as a property (.val(), .attr()) and skip
         * string concatenation altogether.
         */
        escapeAttr: function (text) {
            return String(text === undefined || text === null ? '' : text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        },

        /**
         * Named REST routes.
         *
         * A lookup rather than routes threaded through every caller, so a call
         * site names what it wants rather than restating a path and a verb.
         */
        restRoutes: {
            'listObjects':               {method: 'GET',    path: '/buckets/{bucket}/objects'},
            'clearCache':             {method: 'DELETE', path: '/cache'},
            'uploadUrl':          {method: 'POST',   path: '/buckets/{bucket}/objects/upload-url', rename: {object_key: 'key'}},
            'deleteObject':           {method: 'DELETE', path: '/buckets/{bucket}/objects'},
            'renameObject':           {method: 'PATCH',  path: '/buckets/{bucket}/objects'},
            'objectReferences':       {method: 'GET',    path: '/buckets/{bucket}/objects/references'},
            'moveObject':             {method: 'POST',   path: '/buckets/{bucket}/objects/move'},
            'listFolders':            {method: 'GET',    path: '/buckets/{bucket}/folders'},
            'downloadUrl':       {method: 'POST',   path: '/buckets/{bucket}/objects/download-url', rename: {object_key: 'key'}},
            'createFolder':           {method: 'POST',   path: '/buckets/{bucket}/folders'},
            'deleteFolder':           {method: 'DELETE', path: '/buckets/{bucket}/folders'},
            'bucketDetails':      {method: 'GET',    path: '/buckets/{bucket}'},
            'setupCors':              {method: 'PUT',    path: '/buckets/{bucket}/cors'},
            'deleteCors': {method: 'DELETE', path: '/buckets/{bucket}/cors'},
            'connectionTest':         {method: 'GET',    path: '/connection'}
        },

        /**
         * The files currently ticked, in the order they appear.
         */
        selectedFiles: function () {
            return $('.s3-select-file:checked').map(function () {
                var $box = $(this);

                return {
                    fileName: $box.data('filename'),
                    bucket: $box.data('bucket'),
                    key: $box.data('key')
                };
            }).get();
        },

        /**
         * Show the bar once something is ticked, and say how many.
         */
        updateSelectionBar: function () {
            var count = $('.s3-select-file:checked').length;
            var $bar = $('.s3-selection-bar');

            if (!count) {
                $bar.prop('hidden', true);
                return;
            }

            var label = count === 1
                ? s3BrowserConfig.i18n.files.oneSelected
                : s3BrowserConfig.i18n.files.manySelected.replace('%d', count);

            $bar.find('.s3-selection-count').text(label);
            $bar.prop('hidden', false);
        },

        /**
         * Issue a request against a named REST route.
         *
         * Callbacks receive {success, data} — retained because every call site
         * reads response.data, not because admin-ajax is still involved.
         */
        makeAjaxRequest: function (routeName, data, callbacks) {
            callbacks = callbacks || {};

            var route = this.restRoutes[routeName];
            if (!route) {
                callbacks.error && callbacks.error('Unknown route: ' + routeName);
                callbacks.complete && callbacks.complete();
                return;
            }

            S3Browser.restRequest(route, data, callbacks);
        },

        /**
         * Perform a REST request and normalise the response.
         */
        restRequest: function (route, data, callbacks) {
            callbacks = callbacks || {};

            var params = $.extend({}, data || {});

            // Apply parameter aliases before the path is built.
            if (route.rename) {
                for (var from in route.rename) {
                    if (Object.prototype.hasOwnProperty.call(route.rename, from) && params[from] !== undefined) {
                        params[route.rename[from]] = params[from];
                        delete params[from];
                    }
                }
            }

            // Substitute path placeholders, consuming those params.
            var path = route.path.replace(/\{(\w+)}/g, function (match, name) {
                var value = params[name];
                delete params[name];
                return encodeURIComponent(value === undefined || value === null ? '' : value);
            });

            var url = S3BrowserGlobalConfig.restUrl + path;
            var settings = {
                method: route.method,
                dataType: 'json',
                headers: {'X-WP-Nonce': S3BrowserGlobalConfig.restNonce},
                success: function (payload) {
                    // Callers expect the admin-ajax envelope.
                    callbacks.success && callbacks.success({success: true, data: payload || {}});
                },
                error: function (xhr) {
                    var message = (xhr && xhr.responseJSON && xhr.responseJSON.message)
                        ? xhr.responseJSON.message
                        : 'Network error occurred';
                    callbacks.error && callbacks.error(message, xhr);
                },
                complete: callbacks.complete
            };

            // GET and DELETE carry parameters in the query string: some hosts
            // and proxies drop DELETE request bodies.
            if (route.method === 'GET' || route.method === 'DELETE') {
                var query = $.param(params);
                if (query) {
                    url += (url.indexOf('?') === -1 ? '?' : '&') + query;
                }
            } else {
                settings.contentType = 'application/json; charset=utf-8';
                settings.data = JSON.stringify(params);
            }

            settings.url = url;

            $.ajax(settings);
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

            // Messages routinely originate from the storage provider's error
            // text, which echoes the object key back — so treat them as data,
            // not markup.
            var $notification = $('<div/>')
                .addClass('s3-notification s3-notification-' + (type || 'info'))
                .text(message === undefined || message === null ? '' : message);
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