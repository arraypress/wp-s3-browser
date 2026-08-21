/**
 * S3 Browser — Easy Digital Downloads integration
 *
 * Runs on the download edit screen, not inside the browser. Two jobs: remember
 * which file row opened the browser, and carry out an insert the browser asks
 * for.
 *
 * The asking is done by postMessage rather than by the browser writing into
 * this page directly. It renders in a frame whose parent cannot be read --
 * touching window.parent throws a SecurityError, not returns undefined -- so
 * the page holding the fields is the only one that can fill them.
 */
(function ($) {
    'use strict';

    window.S3BrowserEDDIntegration = {

        init: function () {
            if (typeof S3BrowserGlobalConfig === 'undefined') {
                return;
            }

            this.bindEvents();
            this.listenForInserts();
        },

        bindEvents: function () {
            // Remember the row whose button was clicked; it is where a single
            // file goes, and where extra rows are added after.
            $(document).on('click', '.edd_upload_file_button', this.trackFileButton);

            $(document).on('click', '.wp-media-buttons .button', function () {
                if (window.edd_media_frame_context === 'edd_file') {
                    delete window.edd_media_frame_context;
                    delete window.edd_fileurl;
                    delete window.edd_filename;
                }
            });
        },

        trackFileButton: function () {
            var $button = $(this);

            window.edd_fileurl = $button.parent().prev().find('input');
            window.edd_filename = $button.parent().parent().parent().prev().find('input');
            window.edd_row = $button.closest('.edd_repeatable_row');
            window.edd_media_frame_context = 'edd_file';
        },

        /**
         * Carry out an insert the browser asked for.
         */
        listenForInserts: function () {
            var self = this;

            window.addEventListener('message', function (event) {
                var data = event.data;

                if (!data || data.type !== 's3-browser:insert') {
                    return;
                }

                // Origin will not identify the sender: the browser can run in a
                // frame with an opaque origin, which posts as "null". The token
                // comes from this page's own configuration, so a frame that did
                // not receive it is not ours.
                if (!data.token || data.token !== S3BrowserGlobalConfig.insertToken) {
                    return;
                }

                self.insertFiles(data.files || []);
            });
        },

        /**
         * Write the files into the file table, adding rows as needed.
         */
        insertFiles: function (files) {
            if (!files.length) {
                return;
            }

            var $row = window.edd_row && window.edd_row.length
                ? window.edd_row
                : $('.edd_repeatable_row').last();

            files.forEach(function (file, index) {
                if (index > 0) {
                    // EDD clones the last row on click, so the new one lands
                    // after whatever is currently last.
                    $('.edd_add_repeatable').filter(':visible').first().trigger('click');
                    $row = $('.edd_repeatable_row').last();
                }

                $row.find('.edd_repeatable_upload_field').val(file.bucket + '/' + file.key);
                $row.find('.edd_repeatable_name_field').val(file.fileName);
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
                } else if (window.tb_remove) {
                    window.tb_remove();
                }
            } catch (e) {
                // A frame that will not close is not a reason to lose the
                // files that were just written.
            }
        }
    };

    $(document).ready(function () {
        S3BrowserEDDIntegration.init();
    });

})(jQuery);
