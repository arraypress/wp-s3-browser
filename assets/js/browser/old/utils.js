/**
 * S3 Browser Utilities - Core utility functions and helpers
 * Provides shared functionality used across all browser components
 */
(function ($) {
    'use strict';

    // Create utilities namespace
    window.S3BrowserUtils = {

        /**
         * Generic AJAX request handler with consistent error handling
         */
        makeAjaxRequest: function (actionSuffix, data, callbacks) {
            var requestData = $.extend({
                action: actionSuffix + S3BrowserGlobalConfig.providerId,
                nonce: S3BrowserGlobalConfig.nonce
            }, data);

            $.ajax({
                url: S3BrowserGlobalConfig.ajaxUrl,
                type: 'POST',
                data: requestData,
                dataType: 'json',
                success: function (response) {
                    if (response.success) {
                        callbacks.success && callbacks.success(response);
                    } else {
                        callbacks.error && callbacks.error(response.data.message || 'Unknown error occurred');
                    }
                },
                error: function (xhr, status, error) {
                    var message = 'Network error occurred';
                    if (xhr.status === 403 || xhr.status === 401) {
                        message = 'Authentication failed - please refresh the page';
                    } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        message = xhr.responseJSON.data.message;
                    }
                    callbacks.error && callbacks.error(message);
                },
                complete: callbacks.complete
            });
        },

        /**
         * Navigation helper
         */
        navigateTo: function (params) {
            params.chromeless = 1;
            params.post_id = s3BrowserConfig.postId || 0;
            params.tab = 's3_' + S3BrowserGlobalConfig.providerId;

            var queryString = $.param(params);
            window.location.href = window.location.href.split('?')[0] + '?' + queryString;
        },

        /**
         * Show notification with automatic fade-out
         */
        showNotification: function (message, type, duration) {
            duration = duration || 5000;

            $('.s3-notification').remove();

            var $notification = $('<div class="s3-notification s3-notification-' + type + '">' + message + '</div>');
            $('.s3-browser-container').prepend($notification);

            if ($notification.length) {
                $('html, body').animate({
                    scrollTop: $notification.offset().top - 50
                }, 200);
            }

            setTimeout(function () {
                $notification.fadeOut(300, function () {
                    $(this).remove();
                });
            }, duration);
        },

        /**
         * Format file size for display
         */
        formatFileSize: function (bytes) {
            if (bytes === 0) return '0 Bytes';
            var k = 1024;
            var sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            var i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        },

        /**
         * Escape HTML for safe display
         */
        escapeHtml: function (text) {
            if (!text) return '';
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        /**
         * Debounce function for search and other rapid inputs
         */
        debounce: function (func, wait, immediate) {
            var timeout;
            return function () {
                var context = this, args = arguments;
                var later = function () {
                    timeout = null;
                    if (!immediate) func.apply(context, args);
                };
                var callNow = immediate && !timeout;
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
                if (callNow) func.apply(context, args);
            };
        },

        /**
         * Check if current context is iframe/popup
         */
        isInIframe: function () {
            try {
                return window.self !== window.top;
            } catch (e) {
                return true;
            }
        },

        /**
         * Get current WordPress admin page context
         */
        getAdminContext: function () {
            var pathname = window.location.pathname;
            if (pathname.includes('media-upload.php')) return 'media-upload';
            if (pathname.includes('post.php') || pathname.includes('post-new.php')) return 'post-edit';
            if (pathname.includes('edit.php')) return 'post-list';
            return 'unknown';
        },

        /**
         * Validate filename for various operations
         */
        validateFilename: function (filename, context) {
            context = context || 'general';
            var errors = [];

            if (!filename || filename.trim().length === 0) {
                errors.push(s3BrowserConfig.i18n.files.filenameRequired);
            }

            if (filename.length > 255) {
                errors.push(s3BrowserConfig.i18n.files.filenameTooLong);
            }

            // Check for invalid characters
            if (/[<>:"|?*\/\\]/.test(filename)) {
                errors.push(s3BrowserConfig.i18n.files.filenameInvalid);
            }

            // Context-specific validations
            if (context === 'upload' && filename.startsWith('.')) {
                errors.push('Filenames cannot start with a period');
            }

            return {
                valid: errors.length === 0,
                errors: errors,
                message: errors.length > 0 ? errors[0] : ''
            };
        },

        /**
         * Update total items count display
         */
        updateTotalCount: function (count, hasMore) {
            var $countSpan = $('#s3-total-count');
            if (!$countSpan.length) return;

            var itemText = count === 1
                ? s3BrowserConfig.i18n.display.singleItem
                : s3BrowserConfig.i18n.display.multipleItems;
            var text = count + ' ' + itemText;
            if (hasMore) text += s3BrowserConfig.i18n.display.moreAvailable;

            $countSpan.text(text);
        },

        /**
         * Handle clipboard operations with fallback
         */
        copyToClipboard: function (text, successCallback, errorCallback) {
            // Try modern clipboard API first
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    successCallback && successCallback();
                }).catch(function () {
                    this.fallbackCopyToClipboard(text, successCallback, errorCallback);
                }.bind(this));
            } else {
                this.fallbackCopyToClipboard(text, successCallback, errorCallback);
            }
        },

        /**
         * Fallback clipboard copy method
         */
        fallbackCopyToClipboard: function (text, successCallback, errorCallback) {
            var textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.top = '-999px';
            textArea.style.left = '-999px';
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();

            try {
                var successful = document.execCommand('copy');
                if (successful) {
                    successCallback && successCallback();
                } else {
                    errorCallback && errorCallback();
                }
            } catch (err) {
                errorCallback && errorCallback();
            }

            document.body.removeChild(textArea);
        },

        /**
         * Extract context information for integrations
         */
        detectIntegrationContext: function () {
            var parent = window.parent;

            if (parent.edd_fileurl && parent.edd_filename) {
                return { type: 'edd', parent: parent };
            } else if (parent.wc_target_input && parent.wc_media_frame_context === 'product_file') {
                return { type: 'woocommerce', parent: parent };
            } else if (parent.wp && parent.wp.media && parent.wp.media.editor) {
                return { type: 'wp_editor', parent: parent };
            }

            return { type: 'unknown', parent: parent };
        },

        /**
         * Throttle function for scroll events and similar
         */
        throttle: function (func, limit) {
            var inThrottle;
            return function () {
                var args = arguments;
                var context = this;
                if (!inThrottle) {
                    func.apply(context, args);
                    inThrottle = true;
                    setTimeout(function () { inThrottle = false; }, limit);
                }
            };
        },

        /**
         * Check if string is a valid URL
         */
        isValidUrl: function (string) {
            try {
                new URL(string);
                return true;
            } catch (_) {
                return false;
            }
        },

        /**
         * Generate random string for unique identifiers
         */
        generateId: function (length) {
            length = length || 8;
            return Math.random().toString(36).substr(2, length);
        }
    };

    // Extend main S3Browser object with utility methods for backward compatibility
    if (window.S3Browser) {
        $.extend(window.S3Browser, {
            makeAjaxRequest: S3BrowserUtils.makeAjaxRequest,
            navigateTo: S3BrowserUtils.navigateTo,
            showNotification: S3BrowserUtils.showNotification,
            formatFileSize: S3BrowserUtils.formatFileSize,
            escapeHtml: S3BrowserUtils.escapeHtml,
            updateTotalCount: S3BrowserUtils.updateTotalCount,
            copyToClipboard: S3BrowserUtils.copyToClipboard,
            fallbackCopyToClipboard: S3BrowserUtils.fallbackCopyToClipboard
        });
    }

})(jQuery);