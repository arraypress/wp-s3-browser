(function ($) {
    'use strict';

    window.S3BrowserEDDIntegration = {
        init: function () {
            // Check if global config exists
            if (typeof S3BrowserGlobalConfig === 'undefined') {
                console.error('S3BrowserGlobalConfig is not defined!');
                return;
            }

            this.bindEvents();
        },

        bindEvents: function () {
            // Track EDD file upload button clicks
            $(document).on('click', '.edd_upload_file_button', this.trackFileButton);

            // Monitor other media buttons to clear EDD context when needed
            $(document).on('click', '.wp-media-buttons .button', function () {
                // Clear EDD context when using general media buttons
                if (window.edd_media_frame_context === 'edd_file') {
                    delete window.edd_media_frame_context;
                    delete window.edd_fileurl;
                    delete window.edd_filename;
                }
            });
        },

        trackFileButton: function (e) {
            var $button = $(this);

            // Find the related input fields
            window.edd_fileurl = $button.parent().prev().find('input');
            window.edd_filename = $button.parent().parent().parent().prev().find('input');

            // Set context flag to identify we're in EDD file upload context
            window.edd_media_frame_context = 'edd_file';
        }
    };

    // Initialize when ready
    $(document).ready(function () {
        S3BrowserEDDIntegration.init();
    });

})(jQuery);