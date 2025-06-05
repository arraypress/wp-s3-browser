/**
 * S3 Browser Utilities - Core utility functions and helpers
 * Provides shared functionality used across all browser components
 */
(function ($) {
    'use strict';

    // Prevent double initialization
    if (window.S3Utils) return;

    // Create main utilities object
    window.S3Utils = {

        // ===========================================
        // AJAX & API COMMUNICATION
        // ===========================================

        /**
         * Generic AJAX request handler with enhanced error handling
         */
        ajax: function (actionSuffix, data, callbacks) {
            const requestData = {
                action: actionSuffix + S3BrowserGlobalConfig.providerId,
                nonce: S3BrowserGlobalConfig.nonce,
                ...data
            };

            $.ajax({
                url: S3BrowserGlobalConfig.ajaxUrl,
                type: 'POST',
                data: requestData,
                dataType: 'json',
                success: function (response) {
                    if (response.success) {
                        callbacks.success?.(response);
                    } else {
                        const message = response.data?.message || 'Unknown error occurred';
                        callbacks.error?.(message);
                    }
                },
                error: function (xhr, status, error) {
                    let message = 'Network error occurred';

                    if (xhr.status === 403 || xhr.status === 401) {
                        message = 'Authentication failed - please refresh the page';
                    } else if (xhr.status === 413) {
                        message = 'Request too large - file may be too big';
                    } else if (xhr.status >= 500) {
                        message = 'Server error - please try again later';
                    } else if (xhr.responseJSON?.data?.message) {
                        message = xhr.responseJSON.data.message;
                    }

                    callbacks.error?.(message);
                },
                complete: callbacks.complete
            });
        },

        // ===========================================
        // DOM MANIPULATION & UI
        // ===========================================

        /**
         * Show notification with automatic fade-out and better positioning
         */
        notify: function (message, type = 'info', duration = 5000) {
            $('.s3-notification').remove();

            const $notification = $(`<div class="s3-notification s3-notification-${type}">${message}</div>`);
            $('.s3-browser-container').prepend($notification);

            // Smooth scroll to notification
            if ($notification.length) {
                $('html, body').animate({
                    scrollTop: Math.max(0, $notification.offset().top - 50)
                }, 200);
            }

            // Auto-remove
            setTimeout(() => {
                $notification.fadeOut(300, function () {
                    $(this).remove();
                });
            }, duration);
        },

        /**
         * Enhanced progress overlay with cancellation support
         */
        showProgress: function (message, cancelCallback = null) {
            $('.s3-progress-overlay').remove();

            const cancelButton = cancelCallback
                ? `<button class="s3-progress-cancel button">${s3BrowserConfig.i18n.ui.cancel}</button>`
                : '';

            const overlay = $(`
                <div class="s3-progress-overlay">
                    <div class="s3-progress-modal">
                        <div class="s3-progress-content">
                            <div class="spinner is-active"></div>
                            <div class="s3-progress-message">${message}</div>
                            ${cancelButton}
                        </div>
                    </div>
                </div>
            `);

            if (cancelCallback) {
                overlay.find('.s3-progress-cancel').on('click', () => {
                    this.hideProgress();
                    cancelCallback();
                });
            }

            $('body').append(overlay);
            overlay.fadeIn(200);
            return overlay;
        },

        /**
         * Update progress message
         */
        updateProgress: function (message) {
            $('.s3-progress-message').text(message);
        },

        /**
         * Hide progress overlay
         */
        hideProgress: function () {
            $('.s3-progress-overlay').fadeOut(200, function () {
                $(this).remove();
            });
        },

        /**
         * Update total items count with better formatting
         */
        updateCount: function (count, hasMore = false) {
            const $countSpan = $('#s3-total-count');
            if (!$countSpan.length) return;

            const itemText = count === 1
                ? s3BrowserConfig.i18n.display.singleItem
                : s3BrowserConfig.i18n.display.multipleItems;

            let text = `${count.toLocaleString()} ${itemText}`;
            if (hasMore) text += s3BrowserConfig.i18n.display.moreAvailable;

            $countSpan.text(text);
        },

        // ===========================================
        // NAVIGATION & ROUTING
        // ===========================================

        /**
         * Navigate to new location with proper parameter handling
         */
        navigate: function (params) {
            const baseParams = {
                chromeless: 1,
                post_id: s3BrowserConfig.postId || 0,
                tab: 's3_' + S3BrowserGlobalConfig.providerId,
                ...params
            };

            const queryString = $.param(baseParams);
            const baseUrl = window.location.href.split('?')[0];
            window.location.href = `${baseUrl}?${queryString}`;
        },

        /**
         * Get current URL parameters as object
         */
        getUrlParams: function () {
            const params = new URLSearchParams(window.location.search);
            const result = {};
            for (const [key, value] of params) {
                result[key] = value;
            }
            return result;
        },

        // ===========================================
        // VALIDATION & FORMATTING
        // ===========================================

        /**
         * Enhanced filename validation with context-specific rules
         */
        validateFilename: function (filename, context = 'general') {
            const errors = [];

            if (!filename?.trim()) {
                errors.push(s3BrowserConfig.i18n.files.filenameRequired);
                return {valid: false, errors, message: errors[0]};
            }

            filename = filename.trim();

            // Length validation
            if (filename.length > 255) {
                errors.push(s3BrowserConfig.i18n.files.filenameTooLong);
            }

            // Invalid characters (more comprehensive)
            if (/[<>:"|?*\/\\]/.test(filename)) {
                errors.push(s3BrowserConfig.i18n.files.filenameInvalid);
            }

            // Leading/trailing issues
            if (/^[.\-_\s]|[.\-_\s]$/.test(filename)) {
                errors.push('Filename cannot start or end with dots, dashes, underscores, or spaces');
            }

            // Relative path indicators
            if (filename.includes('..') || filename.includes('./')) {
                errors.push('Filename cannot contain relative path indicators');
            }

            // Context-specific rules
            if (context === 'upload') {
                if (filename.startsWith('.')) {
                    errors.push('Upload filenames cannot start with a period');
                }
                // Check for common problematic names
                const problematic = ['CON', 'PRN', 'AUX', 'NUL', 'COM1', 'COM2', 'COM3', 'LPT1', 'LPT2'];
                if (problematic.includes(filename.toUpperCase().split('.')[0])) {
                    errors.push('Filename uses a reserved system name');
                }
            }

            return {
                valid: errors.length === 0,
                errors,
                message: errors[0] || ''
            };
        },

        /**
         * Validate folder name with S3-specific rules
         */
        validateFolderName: function (folderName) {
            const errors = [];

            if (!folderName?.trim()) {
                errors.push(s3BrowserConfig.i18n.folders.folderNameRequired);
                return {valid: false, errors, message: errors[0]};
            }

            folderName = folderName.trim();

            if (folderName.length > 63) {
                errors.push(s3BrowserConfig.i18n.folders.folderNameTooLong);
            }

            // S3-safe characters (allows spaces)
            if (!/^[a-zA-Z0-9 ._-]+$/.test(folderName)) {
                errors.push(s3BrowserConfig.i18n.folders.folderNameInvalidChars);
            }

            // Cannot start/end with dots or dashes
            if (/^[.-]|[.-]$/.test(folderName)) {
                errors.push(s3BrowserConfig.i18n.folders.folderNameStartEnd);
            }

            // No consecutive dots
            if (folderName.includes('..')) {
                errors.push(s3BrowserConfig.i18n.folders.folderNameConsecutiveDots);
            }

            return {
                valid: errors.length === 0,
                errors,
                message: errors[0] || ''
            };
        },

        /**
         * Format file size with proper units
         */
        formatSize: function (bytes) {
            if (bytes === 0) return '0 Bytes';

            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));

            return `${parseFloat((bytes / Math.pow(k, i)).toFixed(2))} ${sizes[i]}`;
        },

        /**
         * Format duration in minutes to human readable
         */
        formatDuration: function (minutes) {
            if (minutes < 60) return `${minutes} minute${minutes !== 1 ? 's' : ''}`;
            if (minutes < 1440) {
                const hours = Math.floor(minutes / 60);
                return `${hours} hour${hours !== 1 ? 's' : ''}`;
            }
            const days = Math.floor(minutes / 1440);
            return `${days} day${days !== 1 ? 's' : ''}`;
        },

        /**
         * Escape HTML for safe display
         */
        escapeHtml: function (text) {
            if (!text) return '';
            return $('<div>').text(text).html();
        },

        /**
         * Parse and format dates consistently
         */
        formatDate: function (dateString) {
            try {
                const date = new Date(dateString);
                return date.toLocaleString();
            } catch (e) {
                return dateString;
            }
        },

        // ===========================================
        // CLIPBOARD & USER INTERACTIONS
        // ===========================================

        /**
         * Copy to clipboard with modern API and fallback
         */
        copyToClipboard: function (text) {
            return new Promise((resolve, reject) => {
                // Try modern API first
                if (navigator.clipboard?.writeText) {
                    navigator.clipboard.writeText(text)
                        .then(resolve)
                        .catch(() => this._fallbackCopy(text, resolve, reject));
                } else {
                    this._fallbackCopy(text, resolve, reject);
                }
            });
        },

        /**
         * Fallback clipboard method
         */
        _fallbackCopy: function (text, resolve, reject) {
            const textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.cssText = 'position:fixed;top:-999px;left:-999px;opacity:0;';

            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();

            try {
                const successful = document.execCommand('copy');
                successful ? resolve() : reject(new Error('Copy command failed'));
            } catch (err) {
                reject(err);
            } finally {
                document.body.removeChild(textArea);
            }
        },

        // ===========================================
        // CONTEXT & INTEGRATION DETECTION
        // ===========================================

        /**
         * Detect integration context with enhanced detection
         */
        detectContext: function () {
            const parent = window.parent;

            try {
                // EDD detection
                if (parent.edd_fileurl && parent.edd_filename) {
                    return {type: 'edd', parent};
                }

                // WooCommerce detection
                if (parent.wc_target_input && parent.wc_media_frame_context === 'product_file') {
                    return {type: 'woocommerce', parent};
                }

                // WordPress editor detection
                if (parent.wp?.media?.editor) {
                    return {type: 'wp_editor', parent};
                }

                // Gutenberg detection
                if (parent.wp?.blocks) {
                    return {type: 'gutenberg', parent};
                }

                // Generic iframe detection
                if (this.isInIframe()) {
                    return {type: 'iframe', parent};
                }

                return {type: 'standalone', parent};
            } catch (e) {
                return {type: 'restricted', parent};
            }
        },

        /**
         * Check if running in iframe
         */
        isInIframe: function () {
            try {
                return window.self !== window.top;
            } catch (e) {
                return true;
            }
        },

        /**
         * Get admin page context
         */
        getAdminContext: function () {
            const {pathname} = window.location;

            if (pathname.includes('media-upload.php')) return 'media-upload';
            if (pathname.includes('post.php') || pathname.includes('post-new.php')) return 'post-edit';
            if (pathname.includes('edit.php')) return 'post-list';
            if (pathname.includes('upload.php')) return 'media-library';

            return 'unknown';
        },

        // ===========================================
        // PERFORMANCE & UTILITIES
        // ===========================================

        /**
         * Debounce function with immediate option
         */
        debounce: function (func, wait, immediate = false) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    timeout = null;
                    if (!immediate) func.apply(this, args);
                };
                const callNow = immediate && !timeout;
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
                if (callNow) func.apply(this, args);
            };
        },

        /**
         * Throttle function for performance
         */
        throttle: function (func, limit) {
            let inThrottle;
            return function (...args) {
                if (!inThrottle) {
                    func.apply(this, args);
                    inThrottle = true;
                    setTimeout(() => {
                        inThrottle = false;
                    }, limit);
                }
            };
        },

        /**
         * Generate unique ID
         */
        generateId: function (prefix = 's3', length = 8) {
            const random = Math.random().toString(36).substr(2, length);
            return `${prefix}_${Date.now()}_${random}`;
        },

        /**
         * Check if URL is valid
         */
        isValidUrl: function (string) {
            try {
                new URL(string);
                return true;
            } catch {
                return false;
            }
        },

        /**
         * Deep merge objects
         */
        merge: function (target, ...sources) {
            if (!sources.length) return target;
            const source = sources.shift();

            if (this.isObject(target) && this.isObject(source)) {
                for (const key in source) {
                    if (this.isObject(source[key])) {
                        if (!target[key]) Object.assign(target, {[key]: {}});
                        this.merge(target[key], source[key]);
                    } else {
                        Object.assign(target, {[key]: source[key]});
                    }
                }
            }

            return this.merge(target, ...sources);
        },

        /**
         * Check if value is object
         */
        isObject: function (item) {
            return item && typeof item === 'object' && !Array.isArray(item);
        },

        /**
         * Safe JSON parse with fallback
         */
        safeJsonParse: function (str, fallback = null) {
            try {
                return JSON.parse(str);
            } catch {
                return fallback;
            }
        },

        // ===========================================
        // EVENT HELPERS
        // ===========================================

        /**
         * Enhanced event delegation with namespace support
         */
        on: function (selector, events, handler, namespace = 's3browser') {
            const eventList = events.split(' ').map(event => `${event}.${namespace}`).join(' ');
            $(document).off(eventList, selector).on(eventList, selector, handler);
        },

        /**
         * Remove namespaced events
         */
        off: function (events = '', namespace = 's3browser') {
            const eventList = events ?
                events.split(' ').map(event => `${event}.${namespace}`).join(' ') :
                `.${namespace}`;
            $(document).off(eventList);
        }
    };

    // Global shorthand reference
    window.S3 = window.S3Utils;

})(jQuery);