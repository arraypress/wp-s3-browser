/**
 * S3 Browser Integrations - Enhanced WordPress/WooCommerce/EDD integrations
 * Handles file selection and integration with various WordPress contexts
 */
(function ($) {
    'use strict';

    // Integrations namespace
    window.S3Integrations = {

        // Integration state
        state: {
            context: null,
            lastSelection: null,
            pendingCallbacks: []
        },

        // ===========================================
        // INITIALIZATION & CONTEXT DETECTION
        // ===========================================

        /**
         * Initialize integrations with context detection
         */
        init: function () {
            this.state.context = S3.detectContext();
            this.setupIntegrationHandlers();
            this.notifyParentReady();
        },

        /**
         * Setup integration-specific handlers
         */
        setupIntegrationHandlers: function () {
            const { type } = this.state.context;

            switch (type) {
                case 'edd':
                    this.setupEDDIntegration();
                    break;
                case 'woocommerce':
                    this.setupWooCommerceIntegration();
                    break;
                case 'wp_editor':
                    this.setupEditorIntegration();
                    break;
                case 'gutenberg':
                    this.setupGutenbergIntegration();
                    break;
                default:
                    this.setupGenericIntegration();
            }
        },

        /**
         * Notify parent window that S3 browser is ready
         */
        notifyParentReady: function () {
            try {
                const { parent } = this.state.context;
                if (parent && parent.postMessage) {
                    parent.postMessage({
                        type: 's3_browser_ready',
                        context: this.state.context.type
                    }, '*');
                }
            } catch (e) {
                // Cross-origin restrictions, ignore
            }
        },

        // ===========================================
        // FILE SELECTION HANDLING
        // ===========================================

        /**
         * Enhanced file selection with context awareness
         */
        handleFileSelection: function ($button) {
            const fileData = this.extractFileData($button);

            if (!fileData) {
                S3.notify('Invalid file data', 'error');
                return;
            }

            // Store selection for potential re-use
            this.state.lastSelection = fileData;

            // Handle based on context
            try {
                switch (this.state.context.type) {
                    case 'edd':
                        this.handleEDDSelection(fileData);
                        break;
                    case 'woocommerce':
                        this.handleWooCommerceSelection(fileData);
                        break;
                    case 'wp_editor':
                        this.handleEditorSelection(fileData);
                        break;
                    case 'gutenberg':
                        this.handleGutenbergSelection(fileData);
                        break;
                    default:
                        this.handleGenericSelection(fileData);
                }
            } catch (error) {
                console.error('File selection error:', error);
                this.handleSelectionError(error, fileData);
            }
        },

        /**
         * Extract comprehensive file data
         */
        extractFileData: function ($button) {
            return {
                filename: $button.data('filename'),
                bucket: $button.data('bucket'),
                key: $button.data('key'),
                url: this.buildFileUrl($button.data('bucket'), $button.data('key')),
                size: $button.data('size-formatted'),
                sizeBytes: $button.data('size-bytes'),
                mimeType: $button.data('mime-type'),
                category: $button.data('category'),
                modified: $button.data('modified-formatted')
            };
        },

        /**
         * Build file URL from bucket and key
         */
        buildFileUrl: function (bucket, key) {
            // Use provider-specific URL building if available
            if (S3BrowserGlobalConfig.buildUrl) {
                return S3BrowserGlobalConfig.buildUrl(bucket, key);
            }

            // Fallback to simple concatenation
            return `${bucket}/${key}`;
        },

        // ===========================================
        // EDD INTEGRATION
        // ===========================================

        /**
         * Setup EDD-specific integration
         */
        setupEDDIntegration: function () {
            // EDD-specific setup if needed
            this.addIntegrationStyles('edd');
        },

        /**
         * Handle EDD file selection
         */
        handleEDDSelection: function (fileData) {
            const { parent } = this.state.context;

            if (!parent.edd_filename || !parent.edd_fileurl) {
                throw new Error('EDD integration fields not found');
            }

            // Set EDD fields
            parent.jQuery(parent.edd_filename).val(fileData.filename).trigger('change');
            parent.jQuery(parent.edd_fileurl).val(fileData.url).trigger('change');

            // Visual confirmation
            this.showSelectionFeedback('EDD', fileData);

            // Close modal
            this.closeParentModal('thickbox');
        },

        // ===========================================
        // WOOCOMMERCE INTEGRATION
        // ===========================================

        /**
         * Setup WooCommerce-specific integration
         */
        setupWooCommerceIntegration: function () {
            this.addIntegrationStyles('woocommerce');
        },

        /**
         * Handle WooCommerce file selection
         */
        handleWooCommerceSelection: function (fileData) {
            const { parent } = this.state.context;

            if (!parent.wc_target_input) {
                throw new Error('WooCommerce target input not found');
            }

            // Set file URL
            parent.jQuery(parent.wc_target_input).val(fileData.url).trigger('change');

            // Set filename if field exists
            const $filenameInput = parent.jQuery(parent.wc_target_input)
                .closest('tr, .form-field, .form-row')
                .find('input[name="_wc_file_names[]"], input[name*="file_name"]');

            if ($filenameInput.length) {
                $filenameInput.val(fileData.filename).trigger('change');
            }

            // Show confirmation
            this.showSelectionFeedback('WooCommerce', fileData);

            // Close media frame
            this.closeParentModal('wp_media');
        },

        // ===========================================
        // WORDPRESS EDITOR INTEGRATION
        // ===========================================

        /**
         * Setup WordPress editor integration
         */
        setupEditorIntegration: function () {
            this.addIntegrationStyles('wp_editor');
        },

        /**
         * Handle WordPress editor selection
         */
        handleEditorSelection: function (fileData) {
            const { parent } = this.state.context;

            try {
                // Multiple insertion methods for compatibility
                if (parent.wp?.media?.editor?.activeEditor) {
                    parent.wp.media.editor.insert(this.formatEditorContent(fileData));
                } else if (parent.wpActiveEditor) {
                    parent.wp.media.editor.insert(
                        this.formatEditorContent(fileData),
                        parent.wpActiveEditor
                    );
                } else if (parent.send_to_editor) {
                    parent.send_to_editor(this.formatEditorContent(fileData));
                } else {
                    throw new Error('No active editor method found');
                }

                this.showSelectionFeedback('WordPress Editor', fileData);
                this.closeParentModal('wp_media');

            } catch (error) {
                // Fallback to simple URL display
                this.showUrlFallback(fileData.url);
                throw error;
            }
        },

        /**
         * Format content for editor insertion
         */
        formatEditorContent: function (fileData) {
            const { category, url, filename, mimeType } = fileData;

            switch (category) {
                case 'image':
                    return `<img src="${url}" alt="${S3.escapeHtml(filename)}" />`;
                case 'video':
                    return `<video controls><source src="${url}" type="${mimeType}">Your browser does not support the video tag.</video>`;
                case 'audio':
                    return `<audio controls><source src="${url}" type="${mimeType}">Your browser does not support the audio tag.</audio>`;
                default:
                    return `<a href="${url}" target="_blank">${S3.escapeHtml(filename)}</a>`;
            }
        },

        // ===========================================
        // GUTENBERG INTEGRATION
        // ===========================================

        /**
         * Setup Gutenberg integration
         */
        setupGutenbergIntegration: function () {
            this.addIntegrationStyles('gutenberg');
        },

        /**
         * Handle Gutenberg block editor selection
         */
        handleGutenbergSelection: function (fileData) {
            const { parent } = this.state.context;

            try {
                // Try to use Gutenberg's media handling
                if (parent.wp?.data?.dispatch) {
                    const { dispatch } = parent.wp.data;

                    // Create media object for Gutenberg
                    const mediaObject = {
                        id: S3.generateId('s3media'),
                        url: fileData.url,
                        filename: fileData.filename,
                        filesize: fileData.sizeBytes,
                        mime: fileData.mimeType,
                        type: fileData.category,
                        title: fileData.filename,
                        alt: fileData.filename
                    };

                    // Dispatch to media store if available
                    if (dispatch('core/editor')) {
                        dispatch('core/editor').insertBlocks([
                            parent.wp.blocks.createBlock('core/file', {
                                href: fileData.url,
                                fileName: fileData.filename,
                                textLinkHref: fileData.url,
                                textLinkTarget: '_blank'
                            })
                        ]);
                    }
                }

                this.showSelectionFeedback('Gutenberg', fileData);
                this.closeParentModal('wp_media');

            } catch (error) {
                // Fallback to clipboard
                S3.copyToClipboard(fileData.url)
                    .then(() => S3.notify('File URL copied to clipboard', 'success'))
                    .catch(() => this.showUrlFallback(fileData.url));
            }
        },

        // ===========================================
        // GENERIC & FALLBACK HANDLING
        // ===========================================

        /**
         * Setup generic integration
         */
        setupGenericIntegration: function () {
            this.addIntegrationStyles('generic');
        },

        /**
         * Handle generic file selection
         */
        handleGenericSelection: function (fileData) {
            // Try clipboard first, then URL display
            S3.copyToClipboard(fileData.url)
                .then(() => {
                    S3.notify('File URL copied to clipboard', 'success');
                    this.showSelectionFeedback('Clipboard', fileData);
                })
                .catch(() => this.showUrlFallback(fileData.url));
        },

        /**
         * Show URL as fallback when other methods fail
         */
        showUrlFallback: function (url) {
            S3M.alert(
                'File Selected',
                `<p>File URL:</p><input type="text" value="${S3.escapeHtml(url)}" readonly style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 3px;" onclick="this.select()">`,
                () => this.closeParentModal()
            );
        },

        // ===========================================
        // FAVORITES MANAGEMENT
        // ===========================================

        /**
         * Toggle favorite bucket with enhanced feedback
         */
        toggleFavoriteBucket: function ($button) {
            if ($button.hasClass('s3-processing')) return;

            const bucket = $button.data('bucket');
            const action = $button.data('action');
            const postType = $button.data('post-type') || 'default';

            if (!bucket) {
                S3.notify('Invalid bucket data', 'error');
                return;
            }

            // Visual feedback
            $button.addClass('s3-processing');

            S3.ajax('s3_toggle_favorite_', {
                bucket: bucket,
                favorite_action: action,
                post_type: postType
            }, {
                success: (response) => this.handleFavoriteSuccess(response, $button),
                error: (message) => {
                    S3.notify(message, 'error');
                    $button.removeClass('s3-processing');
                }
            });
        },

        /**
         * Handle successful favorite toggle
         */
        handleFavoriteSuccess: function (response, $button) {
            const data = response.data;

            // Update all favorite buttons
            $('.s3-favorite-bucket, .s3-favorite-star').each(function() {
                const $btn = $(this);
                $btn.removeClass('dashicons-star-filled s3-favorite-active s3-processing')
                    .addClass('dashicons-star-empty')
                    .data('action', 'add')
                    .attr('title', s3BrowserConfig.i18n.navigation.setDefault);
            });

            // Update clicked button if it was set as favorite
            if (data.status === 'added') {
                $button.removeClass('dashicons-star-empty')
                    .addClass('dashicons-star-filled s3-favorite-active')
                    .data('action', 'remove')
                    .attr('title', s3BrowserConfig.i18n.navigation.removeDefault);
            }

            $button.removeClass('s3-processing');
            S3.notify(data.message, 'success');
        },

        // ===========================================
        // UTILITY FUNCTIONS
        // ===========================================

        /**
         * Show selection feedback to user
         */
        showSelectionFeedback: function (integration, fileData) {
            const message = `File selected for ${integration}: ${fileData.filename}`;
            S3.notify(message, 'success', 3000);
        },

        /**
         * Close parent modal based on type
         */
        closeParentModal: function (type = 'auto') {
            const { parent } = this.state.context;

            try {
                switch (type) {
                    case 'thickbox':
                        if (parent.tb_remove) parent.tb_remove();
                        break;
                    case 'wp_media':
                        if (parent.wp?.media?.frame) parent.wp.media.frame.close();
                        break;
                    case 'auto':
                    default:
                        // Try multiple methods
                        if (parent.wp?.media?.frame) {
                            parent.wp.media.frame.close();
                        } else if (parent.tb_remove) {
                            parent.tb_remove();
                        } else if (parent.close) {
                            parent.close();
                        }
                }
            } catch (e) {
                // Modal close failed, continue silently
            }
        },

        /**
         * Add integration-specific styles
         */
        addIntegrationStyles: function (integration) {
            const styles = {
                edd: 'edd-integration',
                woocommerce: 'wc-integration',
                wp_editor: 'editor-integration',
                gutenberg: 'gutenberg-integration',
                generic: 'generic-integration'
            };

            const className = styles[integration] || 'generic-integration';
            $('body').addClass(`s3-browser-${className}`);
        },

        /**
         * Handle selection errors gracefully
         */
        handleSelectionError: function (error, fileData) {
            console.error('Integration error:', error);

            // Show error and offer fallback
            S3M.confirm(
                'Integration Error',
                `Failed to integrate with ${this.state.context.type}. Would you like to copy the file URL instead?`,
                () => {
                    S3.copyToClipboard(fileData.url)
                        .then(() => S3.notify('File URL copied to clipboard', 'success'))
                        .catch(() => this.showUrlFallback(fileData.url));
                }
            );
        },

        /**
         * Get current integration context info
         */
        getContextInfo: function () {
            return {
                type: this.state.context.type,
                isIframe: S3.isInIframe(),
                adminContext: S3.getAdminContext(),
                lastSelection: this.state.lastSelection
            };
        }
    };

    // Initialize on document ready
    $(document).ready(() => {
        S3Integrations.init();
    });

    // Global shorthand
    window.S3I = window.S3Integrations;

})(jQuery);