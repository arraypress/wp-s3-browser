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

    });

})(jQuery);