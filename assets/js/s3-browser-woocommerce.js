(function ($) {
    'use strict';

    window.S3BrowserWooCommerceIntegration = {
        frameInstance: null,
        mediaFrame: null,

        init: function () {
            if (typeof s3BrowserWooCommerce === 'undefined') {
                console.error('s3BrowserWooCommerce is not defined!');
                return;
            }
            this.bindEvents();
            this.extendMediaFrame();
            this.addCustomStyles();
        },

        bindEvents: function () {
            // Track file upload buttons
            $(document).on('click', '.upload_file_button', this.trackFileButton);
        },

        trackFileButton: function (e) {
            var $button = $(this);
            var $row = $button.closest('tr');
            var $input = $row.find('input[name="_wc_file_urls[]"]');

            if ($input.length) {
                window.wc_target_input = $input[0];
            }

            window.wc_gallery_frame = false;
        },

        // Add custom CSS to ensure toolbar is hidden
        addCustomStyles: function () {
            var css = `
                <style id="s3-browser-toolbar-removal">
                    /* Hide toolbar for S3 Browser tabs */
                    .media-frame.mode-s3_${s3BrowserWooCommerce.providerId} .media-frame-toolbar {
                        display: none !important;
                    }
                    
                    /* Adjust content area to fill the space */
                    .media-frame.mode-s3_${s3BrowserWooCommerce.providerId} .media-frame-content {
                        bottom: 0 !important;
                    }
                    
                    /* General fallback for S3 browser frames */
                    .s3-browser-frame-wrapper {
                        height: 100% !important;
                    }
                    
                    /* Hide any stray toolbars that might appear */
                    .media-frame-toolbar:has(.s3-browser-frame),
                    .media-frame:has(.s3-browser-frame) .media-frame-toolbar {
                        display: none !important;
                    }
                </style>
            `;

            if (!$('#s3-browser-toolbar-removal').length) {
                $('head').append(css);
            }
        },

        extendMediaFrame: function () {
            if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
                return;
            }

            var self = this;
            var providerId = s3BrowserWooCommerce.providerId;
            var providerName = s3BrowserWooCommerce.providerName || 'S3 Files';

            // Extend the media frame
            var originalWpMedia = wp.media.view.MediaFrame.Select;

            wp.media.view.MediaFrame.Select = originalWpMedia.extend({
                initialize: function () {
                    originalWpMedia.prototype.initialize.apply(this, arguments);

                    // Store reference to this frame
                    self.mediaFrame = this;

                    // Add our S3 tab state with enhanced toolbar removal
                    this.states.add([
                        new wp.media.controller.State({
                            id: 's3_' + providerId,
                            title: providerName,
                            priority: 60,
                            toolbar: false, // Primary method: disable toolbar in state
                            content: 's3-content',
                            menu: 'default'
                        })
                    ]);

                    // Bind events for additional toolbar removal
                    this.on('content:render:s3-content', this.s3ContentRender, this);
                    this.on('activate:s3_' + providerId, this.hideToolbarOnActivate, this);
                    this.on('deactivate:s3_' + providerId, this.cleanupOnDeactivate, this);
                },

                // Method called when S3 tab becomes active
                hideToolbarOnActivate: function () {
                    // Add class to frame for CSS targeting
                    this.$el.addClass('s3-browser-active');

                    // Direct DOM manipulation as backup
                    this.$el.find('.media-frame-toolbar').remove();
                    this.$el.find('.media-frame-content').css('bottom', '0');
                },

                // Cleanup when leaving S3 tab
                cleanupOnDeactivate: function () {
                    this.$el.removeClass('s3-browser-active');
                },

                s3ContentRender: function () {
                    // Prevent multiple renders
                    if (self.frameInstance) {
                        this.content.set(self.frameInstance);
                        // Double-check toolbar removal after content render
                        setTimeout(() => {
                            this.hideToolbarOnActivate();
                        }, 100);
                        return;
                    }

                    var postId = wp.media.view.settings.post && wp.media.view.settings.post.id ? wp.media.view.settings.post.id : 0;

                    // Build iframe URL parameters
                    var params = {
                        chromeless: 1,
                        tab: 's3_' + providerId
                    };

                    // Handle default bucket
                    if (s3BrowserWooCommerce.defaultBucket) {
                        params.bucket = s3BrowserWooCommerce.defaultBucket;
                        params.view = 'objects';

                        if (s3BrowserWooCommerce.defaultPrefix) {
                            params.prefix = s3BrowserWooCommerce.defaultPrefix;
                        }
                    } else {
                        params.view = 'buckets';
                    }

                    if (postId) {
                        params.post_id = postId;
                    }

                    // Build complete iframe URL
                    var iframeUrl = s3BrowserWooCommerce.adminUrl + '?' + $.param(params);

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

                    // Ensure the toolbar is hidden after content is rendered
                    setTimeout(() => {
                        this.hideToolbarOnActivate();
                    }, 100);
                }
            });
        }
    };

    // Initialize when ready
    $(document).ready(function () {
        S3BrowserWooCommerceIntegration.init();
    });

})(jQuery);