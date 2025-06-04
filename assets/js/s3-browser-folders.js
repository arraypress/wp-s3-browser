/**
 * S3 Browser Folders - Folder operations (create, delete, navigate)
 * Handles folder creation, deletion, and navigation with simplified modals
 */
(function ($) {
    'use strict';

    // Extend the main S3Browser object with folder methods
    $.extend(window.S3Browser, {

        /**
         * Handle folder open button click
         */
        handleFolderOpen: function ($button) {
            var prefix = $button.data('prefix');
            var bucket = $button.data('bucket');

            // Add loading state to the button
            var originalHtml = $button.html();
            $button.html('<span class="dashicons dashicons-update s3-spin"></span> ' + s3BrowserConfig.i18n.opening);
            $button.prop('disabled', true);

            // Navigate to the folder
            try {
                this.navigateTo({
                    bucket: bucket,
                    prefix: prefix
                });
            } catch (error) {
                // Reset button state if navigation fails
                $button.html(originalHtml);
                $button.prop('disabled', false);
                this.showNotification(s3BrowserConfig.i18n.folderOpenError, 'error');
            }
        },

        /**
         * Confirm folder deletion
         */
        deleteFolderConfirm: function ($button) {
            var folderName = $button.data('folder-name');
            var confirmMessage = s3BrowserConfig.i18n.confirmDeleteFolder
                .replace('{foldername}', folderName)
                .replace(/\\n/g, '\n');

            if (!confirm(confirmMessage)) return;

            this.deleteFolder($button);
        },

        /**
         * Delete a folder from S3 with progress indicator
         */
        deleteFolder: function ($button) {
            var self = this;
            var folderName = $button.data('folder-name');
            var bucket = $button.data('bucket');
            var folderPath = $button.data('prefix');

            // Show progress overlay with translatable message
            var progressMessage = s3BrowserConfig.i18n.deletingFolderProgress.replace('{name}', folderName);
            this.showProgressOverlay(progressMessage);

            this.makeAjaxRequest('s3_delete_folder_', {
                bucket: bucket,
                folder_path: folderPath,
                recursive: true
            }, {
                success: function (response) {
                    self.updateProgressOverlay(s3BrowserConfig.i18n.folderDeletedSuccess);

                    setTimeout(function () {
                        self.hideProgressOverlay();
                        self.showNotification(
                            response.data.message || s3BrowserConfig.i18n.deleteFolderSuccess,
                            'success'
                        );

                        setTimeout(function () {
                            window.location.reload();
                        }, 1500);
                    }, 500);
                },
                error: function (message) {
                    self.hideProgressOverlay();
                    self.showNotification(message, 'error');
                }
            });
        },

        /**
         * Open folder creation modal
         */
        openCreateFolderModal: function (bucket, prefix) {
            var self = this;

            var content = [
                '<div class="s3-modal-field">',
                '<label for="s3FolderNameInput">' + s3BrowserConfig.i18n.folderName + '</label>',
                '<input type="text" id="s3FolderNameInput" maxlength="63" placeholder="' + s3BrowserConfig.i18n.folderNamePlaceholder + '">',
                '<p class="description">' + s3BrowserConfig.i18n.folderNameHelp + '</p>',
                '</div>'
            ].join('');

            var $modal = this.showModal('s3FolderModal', s3BrowserConfig.i18n.newFolder, content, [
                {
                    text: s3BrowserConfig.i18n.cancel,
                    action: 'cancel',
                    callback: function () {
                        self.hideModal('s3FolderModal');
                    }
                },
                {
                    text: s3BrowserConfig.i18n.createFolder,
                    action: 'submit',
                    classes: 'button-primary',
                    callback: function () {
                        self.submitFolderForm(bucket, prefix || '');
                    }
                }
            ]);

            // Initially disable submit button
            $modal.find('button[data-action="submit"]').prop('disabled', true);

            // Bind validation
            $modal.on('keyup', '#s3FolderNameInput', function (e) {
                var folderName = e.target.value.trim();
                var validation = self.validateFolderName(folderName);
                var $submit = $modal.find('button[data-action="submit"]');
                var $error = $modal.find('.s3-modal-error');

                $error.hide();

                if (!validation.valid && folderName.length > 0) {
                    $error.text(validation.message).show();
                }

                $submit.prop('disabled', !validation.valid);
            }).on('keydown', '#s3FolderNameInput', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    self.submitFolderForm(bucket, prefix || '');
                }
            });

            // Focus the input
            setTimeout(function () {
                $('#s3FolderNameInput').focus();
            }, 250);
        },

        /**
         * Validate folder name
         */
        validateFolderName: function (folderName) {
            var i18n = s3BrowserConfig.i18n;

            if (folderName.length === 0) {
                return {valid: false, message: i18n.folderNameRequired};
            }
            if (folderName.length > 63) {
                return {valid: false, message: i18n.folderNameTooLong};
            }
            // Allow spaces in folder names - updated regex to include space
            if (!/^[a-zA-Z0-9 ._-]+$/.test(folderName)) {
                return {valid: false, message: i18n.folderNameInvalidChars};
            }
            if (['.', '-'].includes(folderName[0]) || ['.', '-'].includes(folderName[folderName.length - 1])) {
                return {valid: false, message: i18n.folderNameStartEnd};
            }
            if (folderName.includes('..')) {
                return {valid: false, message: i18n.folderNameConsecutiveDots};
            }

            return {valid: true, message: ''};
        },

        /**
         * Submit folder creation form
         */
        submitFolderForm: function (bucket, prefix) {
            var folderName = $('#s3FolderNameInput').val().trim();
            var validation = this.validateFolderName(folderName);

            if (!validation.valid) {
                this.showModalError('s3FolderModal', validation.message);
                return;
            }

            this.createFolder(bucket, prefix, folderName);
        },

        /**
         * Create folder via AJAX
         */
        createFolder: function (bucket, prefix, folderName) {
            var self = this;

            this.setModalLoading('s3FolderModal', true, s3BrowserConfig.i18n.creatingFolder);

            this.makeAjaxRequest('s3_create_folder_', {
                bucket: bucket,
                prefix: prefix,
                folder_name: folderName
            }, {
                success: function (response) {
                    var successMessage = response.data.message ||
                        s3BrowserConfig.i18n.createFolderSuccess.replace('{name}', folderName);

                    self.showNotification(successMessage, 'success');
                    self.hideModal('s3FolderModal');

                    setTimeout(function () {
                        window.location.reload();
                    }, 1500);
                },
                error: function (message) {
                    self.showModalError('s3FolderModal', message);
                }
            });
        }

    });

})(jQuery);