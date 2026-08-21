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
         * Insert several files at once.
         *
         * EDD and WooCommerce both hold their files in a repeatable row, and
         * the browser was opened from one specific row -- that row's inputs
         * are what window.edd_fileurl and friends point at. There is no way to
         * hand back more than one file without creating the rows to hold them,
         * so this follows what each plugin's own media handler does: fill the
         * row that opened the browser, then add a row per remaining file.
         *
         * Adding a row means clicking the plugin's own "add" button rather than
         * calling into its internals, which is the difference between using a
         * documented control and depending on a private function.
         */
        handleMultipleFileSelection: function (files) {
            if (!files.length) {
                return;
            }

            var parent = window.parent;
            var context = this.detectCallingContext(parent);

            // One file is the ordinary path and needs none of this.
            if (files.length === 1 || context === 'unknown') {
                this.insertOne(files[0], context, parent, true);
                return;
            }

            var self = this;
            var inserted = 0;

            files.forEach(function (file, index) {
                if (index > 0 && !self.addRepeatableRow(parent, context)) {
                    return;
                }

                if (self.insertOne(file, context, parent, false)) {
                    inserted++;
                }
            });

            if (inserted < files.length) {
                // Something refused to grow. Say so rather than closing and
                // leaving the admin to notice the missing rows themselves.
                window.alert(
                    s3BrowserConfig.i18n.files.insertPartial
                        .replace('%1$d', inserted)
                        .replace('%2$d', files.length)
                );
            }

            this.closeFrame(parent, context);
        },

        /**
         * Add an empty row to the host plugin's file table.
         *
         * Returns whether a row appeared, so a caller can stop rather than
         * overwrite the row it already filled.
         */
        addRepeatableRow: function (parent, context) {
            var $ = parent.jQuery;
            var selector = context === 'edd' ? '.edd_add_repeatable' : '.insert_row';
            var rowSelector = context === 'edd' ? '.edd_repeatable_row' : '.wc-metabox';

            var before = $(rowSelector).length;
            var $button = $(selector).filter(':visible').first();

            if (!$button.length) {
                return false;
            }

            $button.trigger('click');

            return $(rowSelector).length > before;
        },

        /**
         * Write one file into the last row of the host plugin's file table.
         */
        insertOne: function (file, context, parent, closeAfter) {
            var $ = parent.jQuery;
            var url = file.bucket + '/' + file.key;
            var written = false;

            if (context === 'edd') {
                var $row = $('.edd_repeatable_row').last();

                if ($row.length) {
                    $row.find('.edd_repeatable_upload_field').val(url);
                    $row.find('.edd_repeatable_name_field').val(file.fileName);
                    written = true;
                }
            } else if (context === 'woocommerce_file') {
                var $wcRow = $('.wc-metabox, .downloadable_files tbody tr').last();

                if ($wcRow.length) {
                    $wcRow.find('input.file_url, input[name="_wc_file_urls[]"]').val(url);
                    $wcRow.find('input[name="_wc_file_names[]"]').val(file.fileName);
                    written = true;
                }
            }

            if (closeAfter) {
                this.closeFrame(parent, context);
            }

            return written;
        },

        /**
         * Close whichever frame the browser was opened in.
         */
        closeFrame: function (parent, context) {
            try {
                if (context === 'edd' && parent.tb_remove) {
                    parent.tb_remove();
                } else if (parent.wp && parent.wp.media && parent.wp.media.frame) {
                    parent.wp.media.frame.close();
                }
            } catch (e) {
                // A frame that will not close is not a reason to lose the
                // files that were just written.
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