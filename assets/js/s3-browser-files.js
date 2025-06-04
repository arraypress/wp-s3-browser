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
            var confirmMessage = s3BrowserConfig.i18n.confirmDelete
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
                    self.showNotification(response.data.message || s3BrowserConfig.i18n.deleteSuccess, 'success');
                    $button.closest('tr').fadeOut(300, function () {
                        $(this).remove();
                        self.totalLoadedItems--;
                        self.updateTotalCount(false);
                        self.refreshSearch();
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

            this.showModal('s3DetailsModal', s3BrowserConfig.i18n.fileDetails, detailsHtml, [
                {
                    text: s3BrowserConfig.i18n.cancel,
                    action: 'close',
                    classes: 'button-secondary',
                    callback: function () {
                        self.hideModal('s3DetailsModal');
                    }
                },
                {
                    text: s3BrowserConfig.i18n.copyLink,
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
            if (!fileData.md5) {
                return {
                    display: 'No checksum available',
                    type: 'None',
                    class: 's3-checksum-none'
                };
            }

            if (fileData.isMultipart) {
                var partText = fileData.partCount ? fileData.partCount + ' parts' : 'multiple parts';
                return {
                    display: fileData.md5,
                    type: 'MD5 (Composite)',
                    note: 'Hash of hashes from ' + partText + ' - not directly verifiable against file content',
                    class: 's3-checksum-multipart'
                };
            } else {
                return {
                    display: fileData.md5,
                    type: 'MD5',
                    note: 'Direct MD5 of file content - can be verified after download',
                    class: 's3-checksum-single'
                };
            }
        },

        /**
         * Build details HTML content
         */
        buildDetailsHtml: function (fileData, checksumInfo) {
            return [
                '<div class="s3-details-content">',
                '  <div class="s3-details-section">',
                '    <h4>Basic Information</h4>',
                '    <table class="s3-details-table">',
                '      <tr><td><strong>Filename:</strong></td><td>' + $('<div>').text(fileData.filename).html() + '</td></tr>',
                '      <tr><td><strong>Object Key:</strong></td><td><code>' + $('<div>').text(fileData.key).html() + '</code></td></tr>',
                '      <tr><td><strong>Size:</strong></td><td>' + fileData.sizeFormatted + ' (' + fileData.sizeBytes.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',') + ' bytes)</td></tr>',
                '      <tr><td><strong>Last Modified:</strong></td><td>' + fileData.modifiedFormatted + '</td></tr>',
                '      <tr><td><strong>MIME Type:</strong></td><td>' + $('<div>').text(fileData.mimeType).html() + '</td></tr>',
                '      <tr><td><strong>Category:</strong></td><td>' + $('<div>').text(fileData.category).html() + '</td></tr>',
                '    </table>',
                '  </div>',
                '  <div class="s3-details-section">',
                '    <h4>Storage Information</h4>',
                '    <table class="s3-details-table">',
                '      <tr><td><strong>Storage Class:</strong></td><td>' + $('<div>').text(fileData.storageClass).html() + '</td></tr>',
                '      <tr><td><strong>ETag:</strong></td><td><code>' + $('<div>').text(fileData.etag).html() + '</code></td></tr>',
                fileData.isMultipart ?
                    '      <tr><td><strong>Upload Type:</strong></td><td>Multipart' + (fileData.partCount ? ' (' + fileData.partCount + ' parts)' : '') + '</td></tr>' :
                    '      <tr><td><strong>Upload Type:</strong></td><td>Single-part</td></tr>',
                '    </table>',
                '  </div>',
                '  <div class="s3-details-section">',
                '    <h4>Checksum Information</h4>',
                '    <table class="s3-details-table">',
                '      <tr><td><strong>Type:</strong></td><td><span class="' + checksumInfo.class + '">' + checksumInfo.type + '</span></td></tr>',
                '      <tr><td><strong>Value:</strong></td><td><code class="' + checksumInfo.class + '">' + checksumInfo.display + '</code></td></tr>',
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
                '<label for="s3ExpiresInput">' + s3BrowserConfig.i18n.linkDuration + '</label>',
                '<input type="number" id="s3ExpiresInput" min="1" max="10080" value="60">',
                '<p class="description">' + s3BrowserConfig.i18n.linkDurationHelp + '</p>',
                '</div>',
                '<div class="s3-modal-field">',
                '<label for="s3GeneratedUrl">' + s3BrowserConfig.i18n.generatedLink + '</label>',
                '<textarea id="s3GeneratedUrl" rows="4" readonly placeholder="' + s3BrowserConfig.i18n.generateLinkFirst + '"></textarea>',
                '</div>'
            ].join('');

            var $modal = this.showModal('s3CopyLinkModal', s3BrowserConfig.i18n.copyLink, content, [
                {
                    text: s3BrowserConfig.i18n.cancel,
                    action: 'cancel',
                    callback: function () {
                        self.hideModal('s3CopyLinkModal');
                    }
                },
                {
                    text: s3BrowserConfig.i18n.generateLink,
                    action: 'generate',
                    classes: 'button-primary',
                    callback: function () {
                        self.generatePresignedUrl(context);
                    }
                },
                {
                    text: s3BrowserConfig.i18n.copyToClipboard,
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
                this.showModalError('s3CopyLinkModal', s3BrowserConfig.i18n.invalidDuration);
                return;
            }

            this.setModalLoading('s3CopyLinkModal', true, s3BrowserConfig.i18n.generatingLink);

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
                    self.showNotification(s3BrowserConfig.i18n.linkCopied, 'success');
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
                    successful ? s3BrowserConfig.i18n.linkCopied : s3BrowserConfig.i18n.copyFailed,
                    successful ? 'success' : 'error'
                );
            } catch (err) {
                this.showNotification(s3BrowserConfig.i18n.copyFailed, 'error');
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
                '<label for="s3RenameInput">' + s3BrowserConfig.i18n.filenameLabel + '</label>',
                '<input type="text" id="s3RenameInput" maxlength="255" value="' + $('<div>').text(nameWithoutExt).html() + '">',
                '<p class="description">' + s3BrowserConfig.i18n.filenameHelp + '</p>',
                '</div>'
            ].join('');

            var $modal = this.showModal('s3RenameModal', s3BrowserConfig.i18n.renameFile, content, [
                {
                    text: s3BrowserConfig.i18n.cancel,
                    action: 'cancel',
                    callback: function () {
                        self.hideModal('s3RenameModal');
                    }
                },
                {
                    text: s3BrowserConfig.i18n.renameFile,
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
            var i18n = s3BrowserConfig.i18n;

            if (nameWithoutExt.length === 0) {
                return {valid: false, message: i18n.filenameRequired};
            }

            if (fullName.length > 255) {
                return {valid: false, message: i18n.filenameTooLong};
            }

            // Check for invalid characters
            if (/[<>:"|?*\/\\]/.test(nameWithoutExt)) {
                return {valid: false, message: i18n.filenameInvalid};
            }

            // Check if starts with problematic characters
            if (/^[.\-_]/.test(nameWithoutExt)) {
                return {valid: false, message: i18n.filenameInvalid};
            }

            // Check for relative path indicators
            if (nameWithoutExt.includes('..')) {
                return {valid: false, message: i18n.filenameInvalid};
            }

            // Check if the new name is the same as current
            if (fullName === originalFilename) {
                return {valid: false, message: i18n.filenameSame};
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

            this.setModalLoading('s3RenameModal', true, s3BrowserConfig.i18n.renamingFile);

            this.makeAjaxRequest('s3_rename_object_', {
                bucket: context.bucket,
                current_key: context.key,
                new_filename: newFilename
            }, {
                success: function (response) {
                    self.showNotification(response.data.message || s3BrowserConfig.i18n.renameSuccess, 'success');
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