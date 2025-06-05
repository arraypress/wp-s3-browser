/**
 * S3 Browser Files - File operations (rename, delete, copy link, details)
 * Handles all file-specific operations with simplified modal system
 */
(function ($) {
    'use strict';

    // Extend the main S3Browser object with file operation methods
    $.extend(window.S3Browser, {

        /**
         * Delete a file from S3
         */
        deleteFile: function ($button) {
            var self = this;
            var filename = $button.data('filename');
            var confirmMessage = s3BrowserConfig.i18n.files.confirmDelete
                .replace('{filename}', filename)
                .replace(/\\n/g, '\n');

            if (!confirm(confirmMessage)) return;

            var $icon = $button.find('.dashicons');
            $icon.addClass('spin');
            $button.prop('disabled', true);

            this.makeAjaxRequest('s3_delete_object_', {
                bucket: $button.data('bucket'),
                key: $button.data('key')
            }, {
                success: function (response) {
                    self.showNotification(response.data.message || s3BrowserConfig.i18n.files.deleteSuccess, 'success');

                    // Fade out the deleted row, then reload the page
                    $button.closest('tr').fadeOut(300, function () {
                        setTimeout(function () {
                            window.location.reload();
                        }, 500);
                    });
                },
                error: function (message) {
                    self.showNotification(message, 'error');
                    $icon.removeClass('spin');
                    $button.prop('disabled', false);
                }
            });
        },

        /**
         * Open file details modal
         */
        openDetailsModal: function ($trigger) {
            var key = $trigger.data('key');
            var $row = $trigger.closest('tr');

            if (!$row.length) {
                $row = $('.wp-list-table tr').find('[data-key="' + key + '"]').closest('tr');
            }

            if (!$row.length) {
                this.showNotification('Could not find file data', 'error');
                return;
            }

            // Extract file data from row data attributes
            var $fileElement = $row.find('[data-key]');
            var fileData = {
                filename: $fileElement.data('filename'),
                key: $fileElement.data('key'),
                sizeBytes: $fileElement.data('size-bytes'),
                sizeFormatted: $fileElement.data('size-formatted'),
                modified: $fileElement.data('modified'),
                modifiedFormatted: $fileElement.data('modified-formatted'),
                etag: $fileElement.data('etag'),
                md5: $fileElement.data('md5'),
                isMultipart: $fileElement.data('is-multipart') === 'true',
                storageClass: $fileElement.data('storage-class'),
                mimeType: $fileElement.data('mime-type'),
                category: $fileElement.data('category'),
                partCount: $fileElement.data('part-count') || null
            };

            this.showDetailsModal(fileData);
        },

        /**
         * Show file details modal
         */
        showDetailsModal: function (fileData) {
            var self = this;
            var checksumInfo = this.formatChecksumInfo(fileData);
            var detailsHtml = this.buildDetailsHtml(fileData, checksumInfo);

            this.showModal('s3DetailsModal', s3BrowserConfig.i18n.fileDetails.title, detailsHtml, [
                {
                    text: s3BrowserConfig.i18n.ui.cancel,
                    action: 'close',
                    classes: 'button-secondary',
                    callback: function () {
                        self.hideModal('s3DetailsModal');
                    }
                },
                {
                    text: s3BrowserConfig.i18n.copyLink.copyLink,
                    action: 'copy_link',
                    classes: 'button-primary',
                    callback: function () {
                        self.hideModal('s3DetailsModal');
                        setTimeout(function () {
                            self.openCopyLinkModal({
                                data: function (key) {
                                    var values = {
                                        filename: fileData.filename,
                                        bucket: S3BrowserGlobalConfig.defaultBucket,
                                        key: fileData.key
                                    };
                                    return values[key];
                                }
                            });
                        }, 100);
                    }
                }
            ]);
        },

        /**
         * Format checksum information for display
         */
        formatChecksumInfo: function (fileData) {
            var checksum = s3BrowserConfig.i18n.checksum;

            if (!fileData.md5) {
                return {
                    display: checksum.noChecksumAvailable,
                    type: checksum.none,
                    class: 's3-checksum-none'
                };
            }

            if (fileData.isMultipart) {
                var partText = fileData.partCount ?
                    fileData.partCount + ' ' + s3BrowserConfig.i18n.fileDetails.parts :
                    checksum.multipleParts;

                return {
                    display: fileData.md5,
                    type: checksum.md5Composite,
                    note: checksum.compositeNote.replace('{parts}', partText),
                    class: 's3-checksum-multipart'
                };
            } else {
                return {
                    display: fileData.md5,
                    type: checksum.md5,
                    note: checksum.directNote,
                    class: 's3-checksum-single'
                };
            }
        },

        /**
         * Build details HTML content
         */
        buildDetailsHtml: function (fileData, checksumInfo) {
            var details = s3BrowserConfig.i18n.fileDetails;

            return [
                '<div class="s3-details-content">',
                '  <div class="s3-details-section">',
                '    <h4>' + details.basicInfo + '</h4>',
                '    <table class="s3-details-table">',
                '      <tr><td><strong>' + details.filename + '</strong></td><td>' + $('<div>').text(fileData.filename).html() + '</td></tr>',
                '      <tr><td><strong>' + details.objectKey + '</strong></td><td><code>' + $('<div>').text(fileData.key).html() + '</code></td></tr>',
                '      <tr><td><strong>' + details.size + '</strong></td><td>' + fileData.sizeFormatted + ' (' + fileData.sizeBytes.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',') + ' ' + details.bytes + ')</td></tr>',
                '      <tr><td><strong>' + details.lastModified + '</strong></td><td>' + fileData.modifiedFormatted + '</td></tr>',
                '      <tr><td><strong>' + details.mimeType + '</strong></td><td>' + $('<div>').text(fileData.mimeType).html() + '</td></tr>',
                '      <tr><td><strong>' + details.category + '</strong></td><td>' + $('<div>').text(fileData.category).html() + '</td></tr>',
                '    </table>',
                '  </div>',
                '  <div class="s3-details-section">',
                '    <h4>' + details.storageInfo + '</h4>',
                '    <table class="s3-details-table">',
                '      <tr><td><strong>' + details.storageClass + '</strong></td><td>' + $('<div>').text(fileData.storageClass).html() + '</td></tr>',
                '      <tr><td><strong>' + details.etag + '</strong></td><td><code>' + $('<div>').text(fileData.etag).html() + '</code></td></tr>',
                fileData.isMultipart ?
                    '      <tr><td><strong>' + details.uploadType + '</strong></td><td>' + details.multipart + (fileData.partCount ? ' (' + fileData.partCount + ' ' + details.parts + ')' : '') + '</td></tr>' :
                    '      <tr><td><strong>' + details.uploadType + '</strong></td><td>' + details.singlePart + '</td></tr>',
                '    </table>',
                '  </div>',
                '  <div class="s3-details-section">',
                '    <h4>' + details.checksumInfo + '</h4>',
                '    <table class="s3-details-table">',
                '      <tr><td><strong>' + details.checksumType + '</strong></td><td><span class="' + checksumInfo.class + '">' + checksumInfo.type + '</span></td></tr>',
                '      <tr><td><strong>' + details.checksumValue + '</strong></td><td><code class="' + checksumInfo.class + '">' + checksumInfo.display + '</code></td></tr>',
                checksumInfo.note ?
                    '      <tr><td colspan="2"><small class="description">' + checksumInfo.note + '</small></td></tr>' : '',
                '    </table>',
                '  </div>',
                '</div>'
            ].join('');
        },

        /**
         * Open copy link modal for a file
         */
        openCopyLinkModal: function ($button) {
            var self = this;
            var filename = $button.data('filename');
            var bucket = $button.data('bucket');
            var key = $button.data('key');

            // Store context for later use
            var context = {filename: filename, bucket: bucket, key: key};

            var content = [
                '<div class="s3-modal-field">',
                '<label for="s3ExpiresInput">' + s3BrowserConfig.i18n.copyLink.linkDuration + '</label>',
                '<input type="number" id="s3ExpiresInput" min="1" max="10080" value="60">',
                '<p class="description">' + s3BrowserConfig.i18n.copyLink.linkDurationHelp + '</p>',
                '</div>',
                '<div class="s3-modal-field">',
                '<label for="s3GeneratedUrl">' + s3BrowserConfig.i18n.copyLink.generatedLink + '</label>',
                '<textarea id="s3GeneratedUrl" rows="4" readonly placeholder="' + s3BrowserConfig.i18n.copyLink.generateLinkFirst + '"></textarea>',
                '</div>'
            ].join('');

            var $modal = this.showModal('s3CopyLinkModal', s3BrowserConfig.i18n.copyLink.copyLink, content, [
                {
                    text: s3BrowserConfig.i18n.ui.cancel,
                    action: 'cancel',
                    callback: function () {
                        self.hideModal('s3CopyLinkModal');
                    }
                },
                {
                    text: s3BrowserConfig.i18n.copyLink.generateLink,
                    action: 'generate',
                    classes: 'button-primary',
                    callback: function () {
                        self.generatePresignedUrl(context);
                    }
                },
                {
                    text: s3BrowserConfig.i18n.copyLink.copyToClipboard,
                    action: 'copy',
                    classes: 'button-secondary',
                    callback: function () {
                        self.copyLinkToClipboard();
                    }
                }
            ]);

            // Initially disable copy button
            $modal.find('button[data-action="copy"]').prop('disabled', true);

            // Focus and select the expiry input
            setTimeout(function () {
                $('#s3ExpiresInput').focus().select();
            }, 250);
        },

        /**
         * Generate presigned URL via AJAX
         */
        generatePresignedUrl: function (context) {
            var self = this;
            var expiresMinutes = parseInt($('#s3ExpiresInput').val(), 10) || 60;

            if (expiresMinutes < 1 || expiresMinutes > 10080) {
                this.showModalError('s3CopyLinkModal', s3BrowserConfig.i18n.copyLink.invalidDuration);
                return;
            }

            this.setModalLoading('s3CopyLinkModal', true, s3BrowserConfig.i18n.copyLink.generatingLink);

            this.makeAjaxRequest('s3_get_presigned_url_', {
                bucket: context.bucket,
                object_key: context.key,
                expires_minutes: expiresMinutes
            }, {
                success: function (response) {
                    var url = response.data.url;
                    var expiresAt = new Date(response.data.expires_at * 1000);

                    $('#s3GeneratedUrl').val(url);
                    $('#s3CopyLinkModal button[data-action="copy"]').prop('disabled', false);

                    self.setModalLoading('s3CopyLinkModal', false);

                    var expirationText = s3BrowserConfig.i18n.linkExpiresAt.replace('{time}', expiresAt.toLocaleString());
                    $('#s3CopyLinkModal .description').last().html(
                        '<strong>' + s3BrowserConfig.i18n.linkGenerated + '</strong><br>' + expirationText
                    );

                    self.showNotification(response.data.message || s3BrowserConfig.i18n.linkGeneratedSuccess, 'success');
                },
                error: function (message) {
                    self.showModalError('s3CopyLinkModal', message);
                }
            });
        },

        /**
         * Copy generated link to clipboard
         */
        copyLinkToClipboard: function () {
            var url = $('#s3GeneratedUrl').val();
            if (!url) return;

            var self = this;

            // Try modern clipboard API first
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function () {
                    self.showNotification(s3BrowserConfig.i18n.copyLink.linkCopied, 'success');
                }).catch(function () {
                    self.fallbackCopyToClipboard(url);
                });
            } else {
                self.fallbackCopyToClipboard(url);
            }
        },

        /**
         * Fallback clipboard copy method
         */
        fallbackCopyToClipboard: function (text) {
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
                this.showNotification(
                    successful ? s3BrowserConfig.i18n.copyLink.linkCopied : s3BrowserConfig.i18n.copyLink.copyFailed,
                    successful ? 'success' : 'error'
                );
            } catch (err) {
                this.showNotification(s3BrowserConfig.i18n.copyLink.copyFailed, 'error');
            }

            document.body.removeChild(textArea);
        },

        /**
         * Open rename modal for a file
         */
        openRenameModal: function ($button) {
            var self = this;
            var filename = $button.data('filename');
            var bucket = $button.data('bucket');
            var key = $button.data('key');

            // Store context
            var context = {filename: filename, bucket: bucket, key: key};

            // Get filename without extension for editing
            var lastDot = filename.lastIndexOf('.');
            var nameWithoutExt = lastDot > 0 ? filename.substring(0, lastDot) : filename;

            var content = [
                '<div class="s3-modal-field">',
                '<label for="s3RenameInput">' + s3BrowserConfig.i18n.files.filenameLabel + '</label>',
                '<input type="text" id="s3RenameInput" maxlength="255" value="' + $('<div>').text(nameWithoutExt).html() + '">',
                '<p class="description">' + s3BrowserConfig.i18n.files.filenameHelp + '</p>',
                '</div>'
            ].join('');

            var $modal = this.showModal('s3RenameModal', s3BrowserConfig.i18n.files.renameFile, content, [
                {
                    text: s3BrowserConfig.i18n.ui.cancel,
                    action: 'cancel',
                    callback: function () {
                        self.hideModal('s3RenameModal');
                    }
                },
                {
                    text: s3BrowserConfig.i18n.files.renameFile,
                    action: 'submit',
                    classes: 'button-primary',
                    callback: function () {
                        self.submitRenameForm(context);
                    }
                }
            ]);

            // Initially disable submit button
            $modal.find('button[data-action="submit"]').prop('disabled', true);

            // Bind real-time validation
            $modal.on('keyup', '#s3RenameInput', function (e) {
                var newName = e.target.value.trim();
                var $submit = $modal.find('button[data-action="submit"]');
                var $error = $modal.find('.s3-modal-error');

                $error.hide();

                if (!newName) {
                    $submit.prop('disabled', true);
                    return;
                }

                // Get original extension and rebuild filename
                var lastDot = context.filename.lastIndexOf('.');
                var originalExt = lastDot > 0 ? context.filename.substring(lastDot + 1) : '';
                var fullNewName = newName + (originalExt ? '.' + originalExt : '');

                var validation = self.validateFilename(newName, fullNewName, context.filename);

                if (!validation.valid) {
                    $error.text(validation.message).show();
                    $submit.prop('disabled', true);
                } else {
                    $submit.prop('disabled', false);
                }
            }).on('keydown', '#s3RenameInput', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    var $submit = $modal.find('button[data-action="submit"]');
                    if (!$submit.prop('disabled')) {
                        self.submitRenameForm(context);
                    }
                }
            });

            // Focus and select the input
            setTimeout(function () {
                $('#s3RenameInput').focus().select();
            }, 250);
        },

        /**
         * Validate filename for renaming
         */
        validateFilename: function (nameWithoutExt, fullName, originalFilename) {
            var files = s3BrowserConfig.i18n.files;

            if (nameWithoutExt.length === 0) {
                return {valid: false, message: files.filenameRequired};
            }

            if (fullName.length > 255) {
                return {valid: false, message: files.filenameTooLong};
            }

            // Check for invalid characters
            if (/[<>:"|?*\/\\]/.test(nameWithoutExt)) {
                return {valid: false, message: files.filenameInvalid};
            }

            // Check if starts with problematic characters
            if (/^[.\-_]/.test(nameWithoutExt)) {
                return {valid: false, message: files.filenameInvalid};
            }

            // Check for relative path indicators
            if (nameWithoutExt.includes('..')) {
                return {valid: false, message: files.filenameInvalid};
            }

            // Check if the new name is the same as current
            if (fullName === originalFilename) {
                return {valid: false, message: files.filenameSame};
            }

            return {valid: true, message: ''};
        },

        /**
         * Submit rename form
         */
        submitRenameForm: function (context) {
            var newNameWithoutExt = $('#s3RenameInput').val().trim();

            // Get original extension and rebuild filename
            var lastDot = context.filename.lastIndexOf('.');
            var originalExt = lastDot > 0 ? context.filename.substring(lastDot + 1) : '';
            var fullNewName = newNameWithoutExt + (originalExt ? '.' + originalExt : '');

            var validation = this.validateFilename(newNameWithoutExt, fullNewName, context.filename);
            if (!validation.valid) {
                this.showModalError('s3RenameModal', validation.message);
                return;
            }

            this.renameFile(context, fullNewName);
        },

        /**
         * Perform file rename via AJAX
         */
        renameFile: function (context, newFilename) {
            var self = this;

            this.setModalLoading('s3RenameModal', true, s3BrowserConfig.i18n.files.renamingFile);

            this.makeAjaxRequest('s3_rename_object_', {
                bucket: context.bucket,
                current_key: context.key,
                new_filename: newFilename
            }, {
                success: function (response) {
                    self.showNotification(response.data.message || s3BrowserConfig.i18n.files.renameSuccess, 'success');
                    self.hideModal('s3RenameModal');

                    setTimeout(function () {
                        window.location.reload();
                    }, 1500);
                },
                error: function (message) {
                    self.showModalError('s3RenameModal', message);
                }
            });
        }

    });

})(jQuery);