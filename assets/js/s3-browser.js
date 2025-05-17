(function ($) {
    'use strict';

    window.S3Browser = {
        originalTableData: null,
        searchTimeout: null,
        totalLoadedItems: 0,
        isLoading: false,

        init: function () {
            this.bindEvents();
            this.setupJSSearch();
            this.setupAjaxLoading();
            this.countInitialItems();
        },

        bindEvents: function () {
            // Bucket clicks
            $(document).on('click', '.bucket-name, .browse-bucket-button', function (e) {
                e.preventDefault();
                var bucket = $(this).data('bucket');
                var newUrl = window.location.href.split('?')[0] + '?' + $.param({
                    chromeless: 1,
                    post_id: s3BrowserConfig.postId,
                    tab: 's3_' + s3BrowserConfig.providerId,
                    bucket: bucket
                });
                window.location.href = newUrl;
            });

            // File selection
            $(document).on('click', '.s3-select-file', function (e) {
                e.preventDefault();
                var parent = window.parent;
                var fileData = {
                    fileName: $(this).data('filename'),
                    bucket: $(this).data('bucket'),
                    key: $(this).data('key')
                };
                fileData.url = fileData.bucket + '/' + fileData.key;

                // EDD integration
                if (parent.edd_fileurl && parent.edd_filename) {
                    parent.jQuery(parent.edd_filename).val(fileData.fileName);
                    parent.jQuery(parent.edd_fileurl).val(fileData.url);
                    parent.tb_remove();
                }
                // WooCommerce integration
                else if (parent.wc_target_input) {
                    parent.jQuery(parent.wc_target_input).val(fileData.url);
                    var $filenameInput = parent.jQuery(parent.wc_target_input).closest('tr').find('input[name="_wc_file_names[]"]');
                    if ($filenameInput.length) {
                        $filenameInput.val(fileData.fileName);
                    }
                    parent.wp.media.frame.close();
                }
                // WordPress media
                else if (parent.wp && parent.wp.media && parent.wp.media.editor) {
                    parent.wp.media.editor.insert(fileData.url);
                    if (parent.wp.media.frame) parent.wp.media.frame.close();
                }
                // Fallback
                else {
                    alert('File URL: ' + fileData.url);
                }
            });

            // Downloads
            $(document).on('click', '.s3-download-file', function (e) {
                e.preventDefault();
                window.open($(this).data('url'), '_blank');
            });

            // Folder navigation - handle both click and Open button
            $(document).on('click', '.s3-folder-link', function (e) {
                e.preventDefault();
                var prefix = $(this).data('prefix');
                var bucket = $(this).data('bucket') ||
                    $('#s3-load-more').data('bucket') ||
                    s3BrowserConfig.bucket;

                var newUrl = window.location.href.split('?')[0] + '?' + $.param({
                    chromeless: 1,
                    post_id: s3BrowserConfig.postId,
                    tab: 's3_' + s3BrowserConfig.providerId,
                    bucket: bucket,
                    prefix: prefix
                });
                window.location.href = newUrl;
            });
        },

        setupJSSearch: function() {
            var self = this;
            var $table = $('.wp-list-table tbody');

            if (!$table.length) return;

            // Store original table data
            this.originalTableData = $table.find('tr:not(.s3-no-results)').clone();

            // Search input events
            $('#s3-js-search').on('input', function() {
                var $this = $(this);
                var $clearBtn = $('#s3-js-search-clear');

                if ($this.val()) {
                    $clearBtn.show();
                } else {
                    $clearBtn.hide();
                }

                clearTimeout(self.searchTimeout);
                self.searchTimeout = setTimeout(function() {
                    self.filterTable($this.val());
                }, 200);
            });

            $('#s3-js-search-clear').on('click', function() {
                $('#s3-js-search').val('').trigger('input');
            });
        },

        setupAjaxLoading: function() {
            var self = this;

            // Load more button
            $(document).on('click', '#s3-load-more', function(e) {
                e.preventDefault();

                if (self.isLoading) return;

                var $button = $(this);
                var token = $button.data('token');
                var bucket = $button.data('bucket');
                var prefix = $button.data('prefix');

                self.loadMoreItems(token, bucket, prefix, $button);
            });

            // Optional auto-load
            if (s3BrowserConfig.autoLoad) {
                $(window).on('scroll.s3browser', function() {
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

        loadMoreItems: function(token, bucket, prefix, $button) {
            var self = this;

            if (self.isLoading || !token) return;

            self.isLoading = true;

            // Update button state
            $button.prop('disabled', true);
            $button.find('.s3-button-text').text('Loading...');
            $button.find('.spinner').show();

            // AJAX request
            $.ajax({
                url: s3BrowserConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: s3BrowserConfig.ajaxAction,
                    bucket: bucket,
                    prefix: prefix || '',
                    continuation_token: token,
                    nonce: s3BrowserConfig.nonce
                },
                dataType: 'json',
                success: function(response) {
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
                            $button.data('token', response.data.continuation_token);
                            $button.find('.s3-button-text').text('Load More Items');
                            $button.find('.spinner').hide();
                            $button.prop('disabled', false);
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
                    } else {
                        self.showError('Failed to load more items. Please try again.');
                        self.resetButton($button);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    self.showError('Network error. Please check your connection and try again.');
                    self.resetButton($button);
                },
                complete: function() {
                    self.isLoading = false;
                }
            });
        },

        resetButton: function($button) {
            $button.find('.s3-button-text').text('Load More Items');
            $button.find('.spinner').hide();
            $button.prop('disabled', false);
        },

        filterTable: function(searchTerm) {
            var $tbody = $('.wp-list-table tbody');
            var $stats = $('.s3-search-stats');
            var $bottomNav = $('.tablenav.bottom');

            $tbody.find('.s3-no-results').remove();

            if (!searchTerm) {
                $tbody.empty().append(this.originalTableData.clone());
                $stats.text('');
                // Show bottom navigation when search is cleared
                $bottomNav.show();
                return;
            }

            // Hide entire bottom navigation during search
            $bottomNav.hide();

            searchTerm = searchTerm.toLowerCase();
            var visibleRows = 0;
            var totalRows = 0;

            var $filteredRows = this.originalTableData.clone();
            $tbody.empty();

            $filteredRows.each(function() {
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
            }
        },

        countInitialItems: function() {
            this.totalLoadedItems = $('.wp-list-table tbody tr:not(.s3-no-results)').length;
            var hasMore = $('#s3-load-more').length && $('#s3-load-more').is(':visible');
            this.updateTotalCount(hasMore);
        },

        updateTotalCount: function(hasMore) {
            var $countSpan = $('#s3-total-count');
            if ($countSpan.length) {
                var text = this.totalLoadedItems;
                text += (this.totalLoadedItems === 1) ? ' item' : ' items';

                if (hasMore) {
                    text += ' (more available)';
                }

                $countSpan.text(text);
            }
        },

        showError: function(message) {
            var $notice = $('.s3-ajax-error');
            if (!$notice.length) {
                $notice = $('<div class="notice notice-error s3-ajax-error"><p></p></div>');
                $('.s3-load-more-wrapper').before($notice);
            }
            $notice.find('p').text(message);
            $notice.show();

            setTimeout(function() {
                $notice.fadeOut();
            }, 5000);
        },

        refreshSearch: function() {
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
        }
    };

    // Initialize
    $(document).ready(function () {
        S3Browser.init();
    });

    $(window).on('load', function() {
        if (window.S3Browser) {
            S3Browser.refreshSearch();
        }
    });

})(jQuery);