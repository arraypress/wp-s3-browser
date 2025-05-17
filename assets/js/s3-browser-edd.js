(function ($) {
    'use strict';

    window.S3BrowserEDDIntegration = {
        init: function () {
            console.log('S3BrowserEDD integration starting...');
            this.bindEvents();
        },

        bindEvents: function () {
            // Track EDD file upload button clicks - exactly like the original
            $(document).on('click', '.edd_upload_file_button', this.trackFileButton);
        },

        trackFileButton: function (e) {
            var $button = $(this);

            // Find the related input fields - same as original working code
            window.edd_fileurl = $button.parent().prev().find('input');
            window.edd_filename = $button.parent().parent().parent().prev().find('input');
        }
    };

    // Initialize when ready
    $(document).ready(function () {
        S3BrowserEDDIntegration.init();
    });

})(jQuery);