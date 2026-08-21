(function ($) {
    'use strict';

    window.S3BrowserWCIntegration = {
        frameInstance: null,
        mediaFrame: null,
        originalButtonState: null,

        init: function () {
            // Check if global config exists
            if (typeof S3BrowserGlobalConfig === 'undefined') {
                console.error('S3BrowserGlobalConfig is not defined!');
                return;
            }

            this.bindEvents();
            this.listenForInserts();
            this.extendMediaFrame();
            this.startButtonMonitoring();
        },

        bindEvents: function () {
            var self = this;

            // Track file upload buttons
            $(document).on('click', '.upload_file_button', this.trackFileButton);

            // Monitor tab clicks to capture original button state
            $(document).on('click', '.media-menu-item', function () {
                var $clickedTab = $(this);
                var config = S3BrowserGlobalConfig;
                var isS3Tab = $clickedTab.attr('id') === 'menu-item-s3_' + config.providerId;

                if (!isS3Tab) {
                    self.captureButtonState();
                }
            });

            // Monitor classic editor media button clicks to reset WooCommerce context
            $(document).on('click', '.wp-media-buttons .button', function () {
                // Clear WooCommerce file context when using general media buttons
                if (window.wc_media_frame_context === 'product_file') {
                    delete window.wc_media_frame_context;
                }
            });
        },

        trackFileButton: function () {
            var $button = $(this);
            var $row = $button.closest('tr');
            var $input = $row.find('input[name="_wc_file_urls[]"]');

            if ($input.length) {
                window.wc_target_input = $input[0];
                window.wc_target_row = $row;
                window.wc_media_frame_context = 'product_file';
            }

            window.wc_gallery_frame = false;
        },

        /**
         * Carry out an insert the browser asked for.
         *
         * By message rather than the browser writing here directly: it renders
         * in a frame whose parent cannot be read -- touching window.parent
         * throws a SecurityError -- so this page, which holds the fields, is
         * the only one that can fill them.
         */
        listenForInserts: function () {
            var self = this;

            window.addEventListener('message', function (event) {
                var data = event.data;

                if (!data || data.type !== 's3-browser:insert') {
                    return;
                }

                // Origin will not identify the sender: a frame with an opaque
                // origin posts as "null". The token comes from this page's own
                // configuration, so a frame without it is not ours.
                if (!data.token || data.token !== S3BrowserGlobalConfig.insertToken) {
                    return;
                }

                self.insertFiles(data.files || []);
            });
        },

        /**
         * Write the files into the downloadable files table.
         */
        insertFiles: function (files) {
            if (!files.length) {
                return;
            }

            var existing = [];

            $('.downloadable_files input[name="_wc_file_urls[]"]').each(function () {
                var value = $.trim($(this).val() || '');

                if (value) {
                    existing.push(value.replace(/^s3:\/\//, '').replace(/^\/+|\/+$/g, ''));
                }
            });

            files = files.filter(function (file) {
                return existing.indexOf(file.bucket + '/' + file.key) === -1;
            });

            if (!files.length) {
                this.closeFrame();

                return;
            }

            // Only the row whose button opened the browser is written over --
            // that is what clicking it asks for. Without one, take an empty row
            // or add one, rather than replacing a file nobody asked to change.
            var $row = window.wc_target_row && window.wc_target_row.length
                ? window.wc_target_row
                : null;

            if (!$row) {
                $('.downloadable_files tbody tr').each(function () {
                    var $candidate = $(this);

                    if (!$row && !$.trim($candidate.find('input[name="_wc_file_urls[]"]').val() || '')) {
                        $row = $candidate;
                    }
                });
            }

            files.forEach(function (file, index) {
                if (index > 0 || !$row || !$row.length) {
                    $('.downloadable_files .insert').filter(':visible').first().trigger('click');
                    $row = $('.downloadable_files tbody tr').last();
                }

                $row.find('input[name="_wc_file_urls[]"]').val(file.bucket + '/' + file.key);
                $row.find('input[name="_wc_file_names[]"]').val(file.fileName);
            });

            this.closeFrame();
        },

        /**
         * Close whichever frame the browser was opened in.
         */
        closeFrame: function () {
            try {
                if (window.wp && window.wp.media && window.wp.media.frame) {
                    window.wp.media.frame.close();
                }
            } catch (e) {
                // A frame that will not close is not a reason to lose the
                // files that were just written.
            }
        },

        captureButtonState: function () {
            var $button = $('.media-button-select');
            if ($button.length && $button.text().trim()) {
                // Store the good button state
                this.originalButtonState = {
                    text: $button.text(),
                    classes: $button.attr('class'),
                    disabled: $button.prop('disabled')
                };
            }
        },

        restoreButtonState: function () {
            var $button = $('.media-button-select');
            var config = S3BrowserGlobalConfig;

            if ($button.length && this.originalButtonState) {
                // Check if we're NOT in S3 tab
                var $activeTab = $('.media-menu-item.active');
                var isS3Active = $activeTab.length &&
                    $activeTab.attr('id') === 'menu-item-s3_' + config.providerId;

                if (!isS3Active) {
                    // Restore the original button state
                    if (!$button.text().trim()) {
                        $button.text(this.originalButtonState.text);
                    }

                    // Restore CSS classes if missing button-primary
                    if (!$button.hasClass('button-primary') && this.originalButtonState.classes.indexOf('button-primary') !== -1) {
                        $button.attr('class', this.originalButtonState.classes);
                    }
                }
            }
        },

        startButtonMonitoring: function () {
            var self = this;

            // Monitor for button changes
            setInterval(function () {
                self.restoreButtonState();
            }, 100);
        },

        extendMediaFrame: function () {
            if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
                return;
            }

            var self = this;
            var config = S3BrowserGlobalConfig;
            var providerId = config.providerId;
            var providerName = config.providerName || 'S3 Files';

            // Extend the media frame
            var originalWpMedia = wp.media.view.MediaFrame.Select;

            wp.media.view.MediaFrame.Select = originalWpMedia.extend({
                initialize: function () {
                    originalWpMedia.prototype.initialize.apply(this, arguments);

                    // Store reference to this frame
                    self.mediaFrame = this;

                    // Add our S3 tab state
                    this.states.add([
                        new wp.media.controller.State({
                            id: 's3_' + providerId,
                            title: providerName,
                            priority: 60,
                            content: 's3-content',
                            menu: 'default'
                        })
                    ]);

                    // Bind events for content rendering
                    this.on('content:render:s3-content', this.s3ContentRender, this);

                    // Capture button state when frame opens
                    this.on('open', function () {
                        setTimeout(function () {
                            self.captureButtonState();
                        }, 100);
                    });
                },

                s3ContentRender: function () {
                    // Prevent multiple renders
                    if (self.frameInstance) {
                        this.content.set(self.frameInstance);
                        return;
                    }

                    var postId = wp.media.view.settings.post && wp.media.view.settings.post.id ? wp.media.view.settings.post.id : 0;

                    // Build iframe URL parameters
                    var params = {
                        chromeless: 1,
                        tab: 's3_' + providerId
                    };

                    // Check for a favorite or default bucket
                    var bucketToUse = config.favoriteBucket || config.defaultBucket;

                    // Handle bucket selection
                    if (bucketToUse) {
                        params.bucket = bucketToUse;
                        params.view = 'objects';
                    } else {
                        params.view = 'buckets';
                    }

                    if (postId) {
                        params.post_id = postId;
                    }

                    // Build complete iframe URL
                    var iframeUrl = config.baseUrl + '?' + $.param(params);

                    // Create the iframe
                    var $iframe = $('<iframe>', {
                        src: iframeUrl,
                        class: 's3-browser-frame',
                        frameborder: 0,
                        style: 'width: 100%; height: 100%; border: none; min-height: 500px;'
                    });

                    // Create a view that contains the iframe
                    var view = new wp.media.View({
                        controller: this,
                        className: 's3-browser-frame-wrapper',
                        tagName: 'div'
                    });

                    // Override the render method to add our iframe
                    view.render = function () {
                        this.$el.html($iframe);
                        this.$el.css({
                            height: '100%',
                            minHeight: '500px',
                            width: '100%',
                            overflow: 'hidden'
                        });
                        return this;
                    };

                    // Set the content
                    this.content.set(view);
                    self.frameInstance = view;
                }
            });
        }
    };

    // Initialize when ready
    $(document).ready(function () {
        S3BrowserWCIntegration.init();
    });

})(jQuery);