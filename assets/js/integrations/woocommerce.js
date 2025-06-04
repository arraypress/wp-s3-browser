(function ($) {
    'use strict';

    window.S3BrowserWooCommerceIntegration = {
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
                window.wc_media_frame_context = 'product_file';
            }

            window.wc_gallery_frame = false;
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

                        // Handle prefix (only if we have a bucket)
                        if (config.defaultPrefix) {
                            params.prefix = config.defaultPrefix;
                        }
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
        S3BrowserWooCommerceIntegration.init();
    });

})(jQuery);