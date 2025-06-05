/**
 * S3 Browser Modals - Enhanced modal system with better UX
 * Handles modal creation, management, and interactions
 */
(function ($) {
    'use strict';

    // Modal system namespace
    window.S3Modal = {

        // Active modals tracking
        activeModals: new Set(),

        // Modal stack for proper layering
        modalStack: [],

        // Default options
        defaults: {
            closeOnOverlay: true,
            closeOnEscape: true,
            showCloseButton: true,
            animate: true,
            destroyOnClose: true,
            backdrop: true
        },

        // ===========================================
        // MODAL CREATION & MANAGEMENT
        // ===========================================

        /**
         * Create and show a modal with enhanced options
         */
        show: function (id, title, content, buttons = [], options = {}) {
            // Merge options with defaults
            options = S3.merge({}, this.defaults, options);

            // Remove existing modal if it exists
            this.destroy(id);

            // Build modal structure
            const modal = this.buildModal(id, title, content, buttons, options);

            // Add to DOM and show
            $('body').append(modal);
            this.activeModals.add(id);
            this.modalStack.push(id);

            // Apply initial styling for animation
            const $modal = $(`#${id}`);
            if (options.animate) {
                $modal.hide().fadeIn(200);
                $modal.find('.s3-modal').css({
                    transform: 'scale(0.9) translateY(-20px)',
                    opacity: 0
                }).animate({
                    opacity: 1
                }, 200).css({
                    transform: 'scale(1) translateY(0)',
                    transition: 'transform 0.2s ease-out'
                });
            }

            // Setup event handlers
            this.bindModalEvents(id, buttons, options);

            // Focus management
            this.manageFocus($modal);

            return $modal;
        },

        /**
         * Hide modal with animation
         */
        hide: function (id, callback = null) {
            const $modal = $(`#${id}`);
            if (!$modal.length) return;

            // Remove from tracking
            this.activeModals.delete(id);
            this.modalStack = this.modalStack.filter(modalId => modalId !== id);

            // Animate out
            $modal.fadeOut(200, () => {
                if (callback) callback();
                this.destroy(id);
            });
        },

        /**
         * Destroy modal completely
         */
        destroy: function (id) {
            $(`#${id}`).remove();
            this.activeModals.delete(id);
            this.modalStack = this.modalStack.filter(modalId => modalId !== id);

            // Clean up event handlers
            $(document).off(`keydown.modal${id}`);
        },

        /**
         * Build modal HTML structure
         */
        buildModal: function (id, title, content, buttons, options) {
            const buttonsHtml = this.buildButtons(buttons);
            const closeButton = options.showCloseButton ?
                '<button type="button" class="s3-modal-close" aria-label="Close">&times;</button>' : '';

            return `
                <div id="${id}" class="s3-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="${id}-title">
                    <div class="s3-modal" role="document">
                        <div class="s3-modal-header">
                            <h2 id="${id}-title" class="s3-modal-title">${title}</h2>
                            ${closeButton}
                        </div>
                        <div class="s3-modal-body">
                            <div class="s3-modal-error" style="display: none;" role="alert"></div>
                            <div class="s3-modal-success" style="display: none;" role="status"></div>
                            ${content}
                            <div class="s3-modal-loading" style="display: none;" aria-live="polite">
                                <span class="spinner is-active"></span>
                                <span class="loading-text"></span>
                            </div>
                        </div>
                        ${buttonsHtml ? `<div class="s3-modal-footer">${buttonsHtml}</div>` : ''}
                    </div>
                </div>
            `;
        },

        /**
         * Build buttons HTML
         */
        buildButtons: function (buttons) {
            if (!buttons.length) return '';

            return buttons.map(button => {
                const classes = `button ${button.classes || ''}`;
                const disabled = button.disabled ? 'disabled' : '';
                const attrs = button.attrs || {};
                const attrString = Object.entries(attrs)
                    .map(([key, value]) => `${key}="${S3.escapeHtml(value)}"`)
                    .join(' ');

                return `<button type="button" class="${classes}" data-action="${button.action}" ${disabled} ${attrString}>
                    ${button.text}
                </button>`;
            }).join('');
        },

        // ===========================================
        // EVENT HANDLING
        // ===========================================

        /**
         * Bind modal events
         */
        bindModalEvents: function (id, buttons, options) {
            const $modal = $(`#${id}`);

            // Close on overlay click
            if (options.closeOnOverlay) {
                $modal.off('click.modaloverlay').on('click.modaloverlay', (e) => {
                    if (e.target === e.currentTarget) {
                        this.hide(id);
                    }
                });
            }

            // Close button
            if (options.showCloseButton) {
                $modal.find('.s3-modal-close').off('click.modalclose').on('click.modalclose', () => {
                    this.hide(id);
                });
            }

            // Escape key
            if (options.closeOnEscape) {
                $(document).off(`keydown.modal${id}`).on(`keydown.modal${id}`, (e) => {
                    if (e.key === 'Escape' && this.isTopModal(id)) {
                        this.hide(id);
                    }
                });
            }

            // Button clicks
            $modal.off('click.modalbuttons').on('click.modalbuttons', 'button[data-action]', (e) => {
                const action = $(e.target).data('action');
                const button = buttons.find(btn => btn.action === action);

                if (button?.callback && !$(e.target).prop('disabled')) {
                    // Prevent double-clicks
                    $(e.target).prop('disabled', true);

                    try {
                        const result = button.callback(e, this);

                        // Re-enable button if callback doesn't handle it
                        if (result !== false) {
                            setTimeout(() => $(e.target).prop('disabled', false), 100);
                        }
                    } catch (error) {
                        console.error('Modal button callback error:', error);
                        $(e.target).prop('disabled', false);
                    }
                }
            });

            // Form submission handling
            $modal.off('submit.modalform').on('submit.modalform', 'form', (e) => {
                e.preventDefault();

                // Find submit button and trigger it
                const $submitBtn = $modal.find('button[type="submit"], button.button-primary').first();
                if ($submitBtn.length) {
                    $submitBtn.trigger('click');
                }
            });
        },

        // ===========================================
        // MODAL STATE MANAGEMENT
        // ===========================================

        /**
         * Set modal loading state
         */
        setLoading: function (id, isLoading, message = null) {
            const $modal = $(`#${id}`);
            const $loading = $modal.find('.s3-modal-loading');
            const $buttons = $modal.find('.s3-modal-footer button');
            const $error = $modal.find('.s3-modal-error');
            const $success = $modal.find('.s3-modal-success');

            if (isLoading) {
                $loading.find('.loading-text').text(message || s3BrowserConfig.i18n.loading.loadingText);
                $loading.show();
                $buttons.prop('disabled', true);
                $error.hide();
                $success.hide();
            } else {
                $loading.hide();
                $buttons.prop('disabled', false);
            }
        },

        /**
         * Show modal error
         */
        showError: function (id, message) {
            const $modal = $(`#${id}`);
            $modal.find('.s3-modal-error').html(message).show();
            $modal.find('.s3-modal-success').hide();
            this.setLoading(id, false);
        },

        /**
         * Show modal success
         */
        showSuccess: function (id, message) {
            const $modal = $(`#${id}`);
            $modal.find('.s3-modal-success').html(message).show();
            $modal.find('.s3-modal-error').hide();
        },

        /**
         * Clear modal messages
         */
        clearMessages: function (id) {
            const $modal = $(`#${id}`);
            $modal.find('.s3-modal-error, .s3-modal-success').hide();
        },

        /**
         * Update modal content
         */
        updateContent: function (id, content) {
            const $modal = $(`#${id}`);
            const $body = $modal.find('.s3-modal-body');

            // Preserve message and loading elements
            const $error = $body.find('.s3-modal-error').detach();
            const $success = $body.find('.s3-modal-success').detach();
            const $loading = $body.find('.s3-modal-loading').detach();

            $body.html(content);
            $body.prepend($error, $success).append($loading);
        },

        /**
         * Update modal title
         */
        updateTitle: function (id, title) {
            $(`#${id} .s3-modal-title`).text(title);
        },

        // ===========================================
        // UTILITY FUNCTIONS
        // ===========================================

        /**
         * Check if modal is the top modal
         */
        isTopModal: function (id) {
            return this.modalStack[this.modalStack.length - 1] === id;
        },

        /**
         * Get current active modal ID
         */
        getCurrentModal: function () {
            return this.modalStack[this.modalStack.length - 1] || null;
        },

        /**
         * Close all modals
         */
        closeAll: function () {
            [...this.activeModals].forEach(id => this.hide(id));
        },

        /**
         * Focus management for accessibility
         */
        manageFocus: function ($modal) {
            // Store previous focus
            const previousFocus = document.activeElement;
            $modal.data('previous-focus', previousFocus);

            // Focus first focusable element
            setTimeout(() => {
                const $focusable = $modal.find('input, select, textarea, button').filter(':visible').first();
                if ($focusable.length) {
                    $focusable.focus();
                } else {
                    $modal.find('.s3-modal').attr('tabindex', '-1').focus();
                }
            }, 100);

            // Restore focus when modal closes
            $modal.on('remove', () => {
                const previousFocus = $modal.data('previous-focus');
                if (previousFocus && $(previousFocus).is(':visible')) {
                    previousFocus.focus();
                }
            });
        },

        // ===========================================
        // SPECIALIZED MODAL TYPES
        // ===========================================

        /**
         * Show confirmation modal
         */
        confirm: function (title, message, confirmCallback, cancelCallback = null) {
            const id = S3.generateId('confirm');

            const content = `<div class="s3-modal-confirm">
                <p>${message}</p>
            </div>`;

            const buttons = [
                {
                    text: s3BrowserConfig.i18n.ui.cancel,
                    action: 'cancel',
                    classes: 'button-secondary',
                    callback: () => {
                        this.hide(id);
                        if (cancelCallback) cancelCallback();
                    }
                },
                {
                    text: 'Confirm',
                    action: 'confirm',
                    classes: 'button-primary',
                    callback: () => {
                        this.hide(id);
                        if (confirmCallback) confirmCallback();
                    }
                }
            ];

            return this.show(id, title, content, buttons, {
                closeOnOverlay: false,
                closeOnEscape: true
            });
        },

        /**
         * Show alert modal
         */
        alert: function (title, message, callback = null) {
            const id = S3.generateId('alert');

            const content = `<div class="s3-modal-alert">
                <p>${message}</p>
            </div>`;

            const buttons = [
                {
                    text: 'OK',
                    action: 'ok',
                    classes: 'button-primary',
                    callback: () => {
                        this.hide(id);
                        if (callback) callback();
                    }
                }
            ];

            return this.show(id, title, content, buttons);
        },

        /**
         * Show progress modal with cancellation
         */
        progress: function (title, message, cancelCallback = null) {
            const id = S3.generateId('progress');

            const cancelButton = cancelCallback
                ? `<button class="s3-progress-cancel button">${s3BrowserConfig.i18n.ui.cancel}</button>`
                : '';

            const content = `<div class="s3-modal-progress">
                <div class="spinner is-active"></div>
                <div class="progress-message">${message}</div>
                ${cancelButton}
            </div>`;

            const $modal = this.show(id, title, content, [], {
                closeOnOverlay: false,
                closeOnEscape: !!cancelCallback,
                showCloseButton: false
            });

            if (cancelCallback) {
                $modal.find('.s3-progress-cancel').on('click', () => {
                    this.hide(id);
                    cancelCallback();
                });
            }

            return {
                update: (message) => $modal.find('.progress-message').text(message),
                close: () => this.hide(id)
            };
        }
    };

    // Extend main S3Browser object for backward compatibility
    if (window.S3Browser) {
        S3Browser.showModal = S3Modal.show.bind(S3Modal);
        S3Browser.hideModal = S3Modal.hide.bind(S3Modal);
        S3Browser.setModalLoading = S3Modal.setLoading.bind(S3Modal);
        S3Browser.showModalError = S3Modal.showError.bind(S3Modal);
        S3Browser.showProgressOverlay = (message, cancelCallback) => S3Modal.progress('Processing...', message, cancelCallback);
        S3Browser.updateProgressOverlay = (message) => {
            const currentModal = S3Modal.getCurrentModal();
            if (currentModal) {
                $(`#${currentModal} .progress-message`).text(message);
            }
        };
        S3Browser.hideProgressOverlay = () => {
            const currentModal = S3Modal.getCurrentModal();
            if (currentModal) {
                S3Modal.hide(currentModal);
            }
        };
    }

    // Global shorthand
    window.S3M = window.S3Modal;

})(jQuery);