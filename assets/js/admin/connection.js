/**
 * S3 Connection Test - JavaScript
 *
 * Generic connection test functionality for S3 admin components
 * Uses S3BrowserGlobalConfig for all configuration and translations
 *
 * @package   ArrayPress\S3
 * @version   1.0.0
 */

(function ($) {
    'use strict';

    /**
     * S3 Connection Test Handler
     */
    const S3ConnectionTest = {

        /**
         * Initialize the connection test functionality
         */
        init: function () {
            this.bindEvents();
            this.storeOriginalButtonTexts();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function () {
            $(document).on('click', '.s3-test-connection', this.testConnection.bind(this));
        },

        /**
         * Store original button texts for restoration
         */
        storeOriginalButtonTexts: function () {
            $('.s3-test-connection').each(function () {
                const $button = $(this);
                if (!$button.data('original-text')) {
                    $button.data('original-text', $button.text());
                }
            });
        },

        /**
         * Handle connection test button click
         */
        testConnection: function (e) {
            e.preventDefault();

            const $button = $(e.currentTarget);
            const buttonId = $button.attr('id');
            const resultId = this.getResultId($button);
            const $result = $('#' + resultId);

            if (!resultId || $result.length === 0) {
                console.error('S3 Connection Test: Could not find result element for button', buttonId);
                return;
            }

            // Check if we have the required configuration
            if (typeof S3BrowserGlobalConfig === 'undefined') {
                this.showError($result, 'Configuration error', 'S3 browser configuration not found');
                return;
            }

            // Update UI to loading state
            this.setLoadingState($button, $result);

            // Build the action name dynamically
            const action = 's3_connection_test_' + S3BrowserGlobalConfig.providerId;

            // Make AJAX request
            $.ajax({
                url: S3BrowserGlobalConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: action,
                    nonce: S3BrowserGlobalConfig.nonce
                },
                success: this.handleSuccess.bind(this, $result),
                error: this.handleError.bind(this, $result),
                complete: this.handleComplete.bind(this, $button)
            });
        },

        /**
         * Get the result element ID for a given button
         */
        getResultId: function ($button) {
            const buttonId = $button.attr('id');
            if (!buttonId) {
                return null;
            }

            // Try common patterns for result IDs
            const patterns = [
                buttonId.replace('-test-connection', '-test-result'),
                buttonId.replace('test-connection', 'test-result'),
                buttonId + '-result'
            ];

            for (const pattern of patterns) {
                if ($('#' + pattern).length > 0) {
                    return pattern;
                }
            }

            return null;
        },

        /**
         * Set loading state for button and result
         */
        setLoadingState: function ($button, $result) {
            const strings = this.getTranslationStrings();
            const testingText = strings.testing || 'Testing...';

            $button.prop('disabled', true).text(testingText);
            $result.removeClass('success error').addClass('loading').text('');
        },

        /**
         * Handle successful AJAX response
         */
        handleSuccess: function ($result, response) {
            if (response.success) {
                const strings = this.getTranslationStrings();
                $result.removeClass('loading error').addClass('success');

                let html = '✓ ' + (response.data.message || strings.connectionSuccess || 'Connection successful!');

                // Add bucket count summary
                if (response.data.summary) {
                    html += '<br><strong>' + response.data.summary + '</strong>';
                }

                // Add bucket list if available and not too many
                if (response.data.buckets && response.data.buckets.length > 0) {
                    if (response.data.buckets.length <= 5) {
                        html += '<br><span class="s3-bucket-list">Buckets: ' + response.data.buckets.join(', ') + '</span>';
                    } else {
                        html += '<br><span class="s3-bucket-list">First 5 buckets: ' + response.data.buckets.slice(0, 5).join(', ') + '...</span>';
                    }
                }

                $result.html(html);
            } else {
                this.showError($result, response.data.message, response.data.details);
            }
        },

        /**
         * Handle AJAX error
         */
        handleError: function ($result, xhr, status, error) {
            const strings = this.getTranslationStrings();
            let message = strings.connectionFailed || 'Connection test failed';
            let details = error;

            // Handle specific error cases
            if (xhr.status === 0) {
                message = strings.networkError || 'Network error occurred';
                details = 'Please check your internet connection';
            } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                message = xhr.responseJSON.data.message;
                details = xhr.responseJSON.data.details || '';
            }

            this.showError($result, message, details);
        },

        /**
         * Handle request completion (always runs)
         */
        handleComplete: function ($button) {
            const strings = this.getTranslationStrings();
            const originalText = $button.data('original-text') || strings.testConnection || 'Test Connection';
            $button.prop('disabled', false).text(originalText);
        },

        /**
         * Show error message
         */
        showError: function ($result, message, details) {
            $result.removeClass('loading success').addClass('error');

            let html = '✗ ' + message;
            if (details) {
                html += '<br><span class="s3-test-details">' + details + '</span>';
            }

            $result.html(html);
        },

        /**
         * Get translation strings from global config
         */
        getTranslationStrings: function () {
            if (typeof S3BrowserGlobalConfig === 'undefined' || !S3BrowserGlobalConfig.i18n) {
                return {};
            }

            // Merge relevant translation groups
            return $.extend({},
                S3BrowserGlobalConfig.i18n.loading || {},
                S3BrowserGlobalConfig.i18n.validation || {},
                S3BrowserGlobalConfig.i18n.ui || {}
            );
        }
    };

    /**
     * Initialize when document is ready
     */
    $(document).ready(function () {
        S3ConnectionTest.init();
    });

    // Expose for external use if needed
    window.S3ConnectionTest = S3ConnectionTest;

})(jQuery);