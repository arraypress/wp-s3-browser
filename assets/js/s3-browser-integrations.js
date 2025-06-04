/**
 * S3 Browser Integrations - WordPress/WooCommerce/EDD integrations
 * Handles file selection and integration with various WordPress contexts
 */
(function ($) {
    'use strict';

    // Extend the main S3Browser object with integration methods
    $.extend(window.S3Browser, {

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

            switch (context) {
                case 'edd':
                    parent.jQuery(parent.edd_filename).val(fileData.fileName);
                    parent.jQuery(parent.edd_fileurl).val(fileData.url);
                    parent.tb_remove();
                    break;

                case 'woocommerce_file':
                    parent.jQuery(parent.wc_target_input).val(fileData.url);
                    var $filenameInput = parent.jQuery(parent.wc_target_input)
                        .closest('tr').find('input[name="_wc_file_names[]"]');
                    if ($filenameInput.length) {
                        $filenameInput.val(fileData.fileName);
                    }
                    parent.wp.media.frame.close();
                    break;

                case 'wp_editor':
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
                    break;

                default:
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
         * Toggle favorite bucket status
         */
        toggleFavoriteBucket: function ($button) {
            var self = this;

            // Add processing class for visual feedback
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
            // Reset all star buttons to empty/inactive
            $('.s3-favorite-bucket, .s3-favorite-star').each(function () {
                var $otherButton = $(this);
                $otherButton.removeClass('dashicons-star-filled s3-favorite-active')
                    .addClass('dashicons-star-empty')
                    .data('action', 'add')
                    .attr('title', s3BrowserConfig.i18n.navigation.setDefault);
            });

            // Update clicked button if it was added as favorite
            if (response.data.status === 'added') {
                $button.removeClass('dashicons-star-empty')
                    .addClass('dashicons-star-filled s3-favorite-active')
                    .data('action', 'remove')
                    .attr('title', s3BrowserConfig.i18n.navigation.removeDefault);
            }
        }

    });

})(jQuery);