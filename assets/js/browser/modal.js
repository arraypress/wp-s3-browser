/**
 * S3 Browser Modals - Simplified modal system
 * Handles modal creation and management with streamlined API
 */
(function ($) {
    'use strict';

    // Extend the main S3Browser object with modal methods
    $.extend(window.S3Browser, {

        /**
         * Create and show a simple modal
         */
        showModal: function (id, title, content, buttons) {
            // Remove existing modal
            $('#' + id).remove();

            // Build buttons HTML
            var buttonsHtml = '';
            buttons.forEach(function (button) {
                var classes = 'button ' + (button.classes || '');
                buttonsHtml += '<button type="button" class="' + classes + '" data-action="' + button.action + '">' +
                    button.text + '</button>';
            });

            // Create modal HTML
            var modalHtml = [
                '<div id="' + id + '" class="s3-modal-overlay">',
                '<div class="s3-modal">',
                '<div class="s3-modal-header">',
                '<h2>' + title + '</h2>',
                '<button type="button" class="s3-modal-close">&times;</button>',
                '</div>',
                '<div class="s3-modal-body">',
                '<div class="s3-modal-error" style="display: none;"></div>',
                content,
                '<div class="s3-modal-loading" style="display: none;">',
                '<span class="spinner is-active"></span><span class="loading-text"></span>',
                '</div>',
                '</div>',
                '<div class="s3-modal-footer">',
                buttonsHtml,
                '</div>',
                '</div>',
                '</div>'
            ].join('');

            $('body').append(modalHtml);
            $('#' + id).fadeIn(200);

            // Bind events for this modal
            this.bindModalEvents(id, buttons);

            return $('#' + id);
        },

        /**
         * Hide modal
         */
        hideModal: function (id) {
            $('#' + id).fadeOut(200, function () {
                $(this).remove();
            });
        },

        /**
         * Set modal loading state
         */
        setModalLoading: function (id, isLoading, loadingText) {
            var $modal = $('#' + id);
            var $loading = $modal.find('.s3-modal-loading');
            var $buttons = $modal.find('.s3-modal-footer button');
            var $error = $modal.find('.s3-modal-error');

            if (isLoading) {
                $loading.find('.loading-text').text(loadingText || s3BrowserConfig.i18n.loading.loadingText);
                $loading.show();
                $buttons.prop('disabled', true);
                $error.hide();
            } else {
                $loading.hide();
                $buttons.prop('disabled', false);
            }
        },

        /**
         * Show modal error
         */
        showModalError: function (id, message) {
            var $modal = $('#' + id);
            $modal.find('.s3-modal-error').text(message).show();
            this.setModalLoading(id, false);
        },

        /**
         * Bind modal events
         */
        bindModalEvents: function (modalId, buttons) {
            var self = this;
            var $modal = $('#' + modalId);

            // Close events
            $modal.off('click.modal').on('click.modal', '.s3-modal-overlay, .s3-modal-close', function (e) {
                if (e.target === this) {
                    self.hideModal(modalId);
                }
            });

            // Escape key
            $(document).off('keydown.modal' + modalId).on('keydown.modal' + modalId, function (e) {
                if (e.key === 'Escape' && $modal.is(':visible')) {
                    self.hideModal(modalId);
                    $(document).off('keydown.modal' + modalId);
                }
            });

            // Button clicks
            $modal.off('click.modalbutton').on('click.modalbutton', 'button[data-action]', function () {
                var action = $(this).data('action');
                var button = buttons.find(function (btn) {
                    return btn.action === action;
                });
                if (button && button.callback) {
                    button.callback();
                }
            });
        },

        /**
         * Show simple progress overlay
         */
        showProgressOverlay: function (message) {
            $('.s3-progress-overlay').remove();

            var overlay = $([
                '<div class="s3-progress-overlay">',
                '  <div class="s3-progress-modal">',
                '    <div class="s3-progress-content">',
                '      <div class="spinner is-active"></div>',
                '      <div class="s3-progress-message">' + message + '</div>',
                '    </div>',
                '  </div>',
                '</div>'
            ].join(''));

            $('body').append(overlay);
            overlay.fadeIn(200);
            return overlay;
        },

        /**
         * Update progress overlay message
         */
        updateProgressOverlay: function (message) {
            $('.s3-progress-overlay .s3-progress-message').text(message);
        },

        /**
         * Hide progress overlay
         */
        hideProgressOverlay: function () {
            $('.s3-progress-overlay').fadeOut(200, function () {
                $(this).remove();
            });
        }

    });

})(jQuery);