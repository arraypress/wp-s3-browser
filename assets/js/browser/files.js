/**
 * S3 Browser Files - Lightweight file operations
 * Handles file operations with improved UX and error handling
 */
(function ($) {
    'use strict';

    // Files namespace
    window.S3Files = {

        // ===========================================
        // FILE DELETION
        // ===========================================

        /**
         * Delete file with confirmation and smooth UI feedback
         */
        deleteFile: function ($button) {
            const filename = $button.data('filename');
            const confirmMessage = s3BrowserConfig.i18n.files.confirmDelete
                .replace('{filename}', filename)
                .replace(/\\n/g, '\n');

            S3M.confirm(
                'Delete File',
                confirmMessage,
                () => this.performDelete($button)
            );
        },

        /**
         * Perform the actual file deletion
         */
        performDelete: function ($button) {
            const $icon = $button.find('.dashicons');
            const $row = $button.closest('tr');

            // Visual feedback
            $icon.addClass('spin');
            $button.prop('disabled', true);
            $row.addClass('deleting');

            S3.ajax('s3_delete_object_', {
                bucket: $button.data('bucket'),
                key: $button.data('key')
            }, {
                success: (response) => {
                    S3.notify(response.data.message || s3BrowserConfig.i18n.files.deleteSuccess, 'success');

                    // Smooth row removal
                    $row.fadeOut(400, function() {
                        $(this).remove();
                        S3Browser.refreshSearch?.();
                    });
                },
                error: (message) => {
                    S3.notify(message, 'error');
                    $icon.removeClass('spin');
                    $button.prop('disabled', false);
                    $row.removeClass('deleting');
                }
            });
        },

        // ===========================================
        // FILE DETAILS
        // ===========================================

        /**
         * Open file details modal with enhanced information
         */
        openDetailsModal: function ($trigger) {
            const fileData = this.extractFileData($trigger);
            if (!fileData) {
                S3.notify('Could not find file data', 'error');
                return;
            }

            this.showDetailsModal(fileData);
        },

        /**
         * Extract file data from DOM elements
         */
        extractFileData: function ($trigger) {
            const key = $trigger.data('key');
            let $row = $trigger.closest('tr');

            // Fallback to find row by key
            if (!$row.length) {
                $row = $('.wp-list-table tr').find(`[data-key="${key}"]`).closest('tr');
            }

            if (!$row.length) return null;

            const $fileElement = $row.find('[data-key]');

            return {
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
        },

        /**
         * Show enhanced file details modal
         */
        showDetailsModal: function (fileData) {
            const checksumInfo = this.formatChecksumInfo(fileData);
            const content = this.buildDetailsContent(fileData, checksumInfo);

            const buttons = [
                {
                    text: s3BrowserConfig.i18n.ui.cancel,
                    action: 'close',
                    classes: 'button-secondary',
                    callback: () => S3M.hide('s3DetailsModal')
                },
                {
                    text: s3BrowserConfig.i18n.copyLink.copyLink,
                    action: 'copy_link',
                    classes: 'button-primary',
                    callback: () => {
                        S3M.hide('s3DetailsModal');
                        setTimeout(() => this.openCopyLinkModal({ data: () => fileData }), 200);
                    }
                }
            ];

            S3M.show('s3DetailsModal', s3BrowserConfig.i18n.fileDetails.title, content, buttons);
        },

        /**
         * Build comprehensive details content
         */
        buildDetailsContent: function (fileData, checksumInfo) {
            const details = s3BrowserConfig.i18n.fileDetails;

            return `
                <div class="s3-details-content">
                    <div class="s3-details-section">
                        <h4>${details.basicInfo}</h4>
                        <table class="s3-details-table">
                            <tr><td><strong>${details.filename}</strong></td><td>${S3.escapeHtml(fileData.filename)}</td></tr>
                            <tr><td><strong>${details.objectKey}</strong></td><td><code>${S3.escapeHtml(fileData.key)}</code></td></tr>
                            <tr><td><strong>${details.size}</strong></td><td>${fileData.sizeFormatted} (${fileData.sizeBytes.toLocaleString()} ${details.bytes})</td></tr>
                            <tr><td><strong>${details.lastModified}</strong></td><td>${fileData.modifiedFormatted}</td></tr>
                            <tr><td><strong>${details.mimeType}</strong></td><td>${S3.escapeHtml(fileData.mimeType)}</td></tr>
                            <tr><td><strong>${details.category}</strong></td><td>${S3.escapeHtml(fileData.category)}</td></tr>
                        </table>
                    </div>
                    
                    <div class="s3-details-section">
                        <h4>${details.storageInfo}</h4>
                        <table class="s3-details-table">
                            <tr><td><strong>${details.storageClass}</strong></td><td>${S3.escapeHtml(fileData.storageClass)}</td></tr>
                            <tr><td><strong>${details.etag}</strong></td><td><code>${S3.escapeHtml(fileData.etag)}</code></td></tr>
                            <tr>
                                <td><strong>${details.uploadType}</strong></td>
                                <td>${fileData.isMultipart ?
                `${details.multipart}${fileData.partCount ? ` (${fileData.partCount} ${details.parts})` : ''}` :
                details.singlePart
            }</td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class="s3-details-section">
                        <h4>${details.checksumInfo}</h4>
                        <table class="s3-details-table">
                            <tr><td><strong>${details.checksumType}</strong></td><td><span class="${checksumInfo.class}">${checksumInfo.type}</span></td></tr>
                            <tr><td><strong>${details.checksumValue}</strong></td><td><code class="${checksumInfo.class}">${checksumInfo.display}</code></td></tr>
                            ${checksumInfo.note ? `<tr><td colspan="2"><small class="description">${checksumInfo.note}</small></td></tr>` : ''}
                        </table>
                    </div>
                </div>
            `;
        },

        /**
         * Format checksum information for display
         */
        formatChecksumInfo: function (fileData) {
            const checksum = s3BrowserConfig.i18n.checksum;

            if (!fileData.md5) {
                return {
                    display: checksum.noChecksumAvailable,
                    type: checksum.none,
                    class: 's3-checksum-none'
                };
            }

            if (fileData.isMultipart) {
                const partText = fileData.partCount ?
                    `${fileData.partCount} ${s3BrowserConfig.i18n.fileDetails.parts}` :
                    checksum.multipleParts;

                return {
                    display: fileData.md5,
                    type: checksum.md5Composite,
                    note: checksum.compositeNote.replace('{parts}', partText),
                    class: 's3-checksum-multipart'
                };
            }

            return {
                display: fileData.md5,
                type: checksum.md5,
                note: checksum.directNote,
                class: 's3-checksum-single'
            };
        },

        // ===========================================
        // COPY LINK FUNCTIONALITY
        // ===========================================

        /**
         * Open copy link modal with improved UX
         */
        openCopyLinkModal: function ($button) {
            const fileData = typeof $button.data === 'function' ? {
                filename: $button.data('filename'),
                bucket: $button.data('bucket'),
                key: $button.data('key')
            } : $button.data();

            const content = this.buildCopyLinkContent();
            const buttons = this.buildCopyLinkButtons(fileData);

            const $modal = S3M.show('s3CopyLinkModal', s3BrowserConfig.i18n.copyLink.copyLink, content, buttons);

            // Setup real-time validation
            this.setupCopyLinkValidation($modal);

            // Focus and select duration input
            setTimeout(() => $('#s3ExpiresInput').focus().select(), 250);
        },

        /**
         * Build copy link modal content
         */
        buildCopyLinkContent: function () {
            return `
                <div class="s3-modal-field">
                    <label for="s3ExpiresInput">${s3BrowserConfig.i18n.copyLink.linkDuration}</label>
                    <input type="number" id="s3ExpiresInput" min="1" max="10080" value="60" step="1">
                    <p class="description">${s3BrowserConfig.i18n.copyLink.linkDurationHelp}</p>
                </div>
                <div class="s3-modal-field">
                    <label for="s3GeneratedUrl">${s3BrowserConfig.i18n.copyLink.generatedLink}</label>
                    <textarea id="s3GeneratedUrl" rows="4" readonly placeholder="${s3BrowserConfig.i18n.copyLink.generateLinkFirst}"></textarea>
                    <p class="description"></p>
                </div>
            `;
        },

        /**
         * Build copy link modal buttons
         */
        buildCopyLinkButtons: function (fileData) {
            return [
                {
                    text: s3BrowserConfig.i18n.ui.cancel,
                    action: 'cancel',
                    classes: 'button-secondary',
                    callback: () => S3M.hide('s3CopyLinkModal')
                },
                {
                    text: s3BrowserConfig.i18n.copyLink.generateLink,
                    action: 'generate',
                    classes: 'button-primary',
                    callback: () => this.generatePresignedUrl(fileData)
                },
                {
                    text: s3BrowserConfig.i18n.copyLink.copyToClipboard,
                    action: 'copy',
                    classes: 'button-secondary',
                    disabled: true,
                    callback: () => this.copyLinkToClipboard()
                }
            ];
        },

        /**
         * Setup copy link validation and interactions
         */
        setupCopyLinkValidation: function ($modal) {
            $modal.on('input', '#s3ExpiresInput', (e) => {
                const value = parseInt(e.target.value, 10);
                const $generateBtn = $modal.find('button[data-action="generate"]');

                // Validate range
                if (value < 1 || value > 10080 || isNaN(value)) {
                    $generateBtn.prop('disabled', true);
                } else {
                    $generateBtn.prop('disabled', false);
                }
            });

            // Enter key handling
            $modal.on('keydown', '#s3ExpiresInput', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const $generateBtn = $modal.find('button[data-action="generate"]');
                    if (!$generateBtn.prop('disabled')) {
                        $generateBtn.trigger('click');
                    }
                }
            });
        },

        /**
         * Generate presigned URL with enhanced feedback
         */
        generatePresignedUrl: function (fileData) {
            const expiresMinutes = parseInt($('#s3ExpiresInput').val(), 10) || 60;

            if (expiresMinutes < 1 || expiresMinutes > 10080) {
                S3M.showError('s3CopyLinkModal', s3BrowserConfig.i18n.copyLink.invalidDuration);
                return;
            }

            S3M.setLoading('s3CopyLinkModal', true, s3BrowserConfig.i18n.copyLink.generatingLink);

            S3.ajax('s3_get_presigned_url_', {
                bucket: fileData.bucket,
                object_key: fileData.key,
                expires_minutes: expiresMinutes
            }, {
                success: (response) => this.handleUrlGenerated(response, expiresMinutes),
                error: (message) => S3M.showError('s3CopyLinkModal', message)
            });
        },

        /**
         * Handle successful URL generation
         */
        handleUrlGenerated: function (response, expiresMinutes) {
            const data = response.data;
            const $modal = $('#s3CopyLinkModal');

            // Update URL field
            $('#s3GeneratedUrl').val(data.url);

            // Enable copy button
            $modal.find('button[data-action="copy"]').prop('disabled', false);

            // Update description with expiration info
            const expiresAt = new Date(data.expires_at * 1000);
            const durationText = S3.formatDuration(expiresMinutes);
            const expirationText = s3BrowserConfig.i18n.linkExpiresAt
                .replace('{time}', expiresAt.toLocaleString());

            $('.s3-modal-field:last .description').html(
                `<strong>${s3BrowserConfig.i18n.linkGenerated}</strong><br>
                Duration: ${durationText}<br>
                ${expirationText}`
            );

            S3M.setLoading('s3CopyLinkModal', false);
            S3.notify(data.message || s3BrowserConfig.i18n.linkGeneratedSuccess, 'success');
        },

        /**
         * Copy generated link to clipboard with feedback
         */
        copyLinkToClipboard: function () {
            const url = $('#s3GeneratedUrl').val();
            if (!url) return;

            S3.copyToClipboard(url)
                .then(() => {
                    S3.notify(s3BrowserConfig.i18n.copyLink.linkCopied, 'success');
                    S3M.hide('s3CopyLinkModal');
                })
                .catch(() => {
                    S3.notify(s3BrowserConfig.i18n.copyLink.copyFailed, 'error');
                });
        },

        // ===========================================
        // FILE RENAMING
        // ===========================================

        /**
         * Open rename modal with enhanced validation
         */
        openRenameModal: function ($button) {
            const fileData = {
                filename: $button.data('filename'),
                bucket: $button.data('bucket'),
                key: $button.data('key')
            };

            const content = this.buildRenameContent(fileData);
            const buttons = this.buildRenameButtons(fileData);

            const $modal = S3M.show('s3RenameModal', s3BrowserConfig.i18n.files.renameFile, content, buttons);

            // Setup validation and interactions
            this.setupRenameValidation($modal, fileData);

            // Focus and select filename
            setTimeout(() => {
                const $input = $('#s3RenameInput');
                $input.focus().select();
            }, 250);
        },

        /**
         * Build rename modal content
         */
        buildRenameContent: function (fileData) {
            // Extract filename without extension for editing
            const lastDot = fileData.filename.lastIndexOf('.');
            const nameWithoutExt = lastDot > 0 ? fileData.filename.substring(0, lastDot) : fileData.filename;
            const extension = lastDot > 0 ? fileData.filename.substring(lastDot) : '';

            return `
                <div class="s3-modal-field">
                    <label for="s3RenameInput">${s3BrowserConfig.i18n.files.filenameLabel}</label>
                    <input type="text" id="s3RenameInput" maxlength="255" value="${S3.escapeHtml(nameWithoutExt)}">
                    <p class="description">${s3BrowserConfig.i18n.files.filenameHelp}${extension ? ` Extension "${extension}" will be preserved.` : ''}</p>
                </div>
            `;
        },

        /**
         * Build rename modal buttons
         */
        buildRenameButtons: function (fileData) {
            return [
                {
                    text: s3BrowserConfig.i18n.ui.cancel,
                    action: 'cancel',
                    classes: 'button-secondary',
                    callback: () => S3M.hide('s3RenameModal')
                },
                {
                    text: s3BrowserConfig.i18n.files.renameFile,
                    action: 'submit',
                    classes: 'button-primary',
                    disabled: true,
                    callback: () => this.submitRename(fileData)
                }
            ];
        },

        /**
         * Setup rename validation with real-time feedback
         */
        setupRenameValidation: function ($modal, fileData) {
            $modal.on('input', '#s3RenameInput', (e) => {
                const newName = e.target.value.trim();
                const $submitBtn = $modal.find('button[data-action="submit"]');

                S3M.clearMessages('s3RenameModal');

                if (!newName) {
                    $submitBtn.prop('disabled', true);
                    return;
                }

                // Build full filename with extension
                const lastDot = fileData.filename.lastIndexOf('.');
                const originalExt = lastDot > 0 ? fileData.filename.substring(lastDot) : '';
                const fullNewName = newName + originalExt;

                // Validate
                const validation = S3.validateFilename(newName, 'rename');

                if (!validation.valid) {
                    S3M.showError('s3RenameModal', validation.message);
                    $submitBtn.prop('disabled', true);
                } else if (fullNewName === fileData.filename) {
                    S3M.showError('s3RenameModal', s3BrowserConfig.i18n.files.filenameSame);
                    $submitBtn.prop('disabled', true);
                } else {
                    $submitBtn.prop('disabled', false);
                }
            });

            // Enter key handling
            $modal.on('keydown', '#s3RenameInput', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const $submitBtn = $modal.find('button[data-action="submit"]');
                    if (!$submitBtn.prop('disabled')) {
                        $submitBtn.trigger('click');
                    }
                }
            });
        },

        /**
         * Submit rename operation
         */
        submitRename: function (fileData) {
            const newNameWithoutExt = $('#s3RenameInput').val().trim();

            // Rebuild full filename
            const lastDot = fileData.filename.lastIndexOf('.');
            const originalExt = lastDot > 0 ? fileData.filename.substring(lastDot) : '';
            const fullNewName = newNameWithoutExt + originalExt;

            // Final validation
            const validation = S3.validateFilename(newNameWithoutExt, 'rename');
            if (!validation.valid) {
                S3M.showError('s3RenameModal', validation.message);
                return;
            }

            if (fullNewName === fileData.filename) {
                S3M.showError('s3RenameModal', s3BrowserConfig.i18n.files.filenameSame);
                return;
            }

            this.performRename(fileData, fullNewName);
        },

        /**
         * Perform the actual rename operation
         */
        performRename: function (fileData, newFilename) {
            S3M.setLoading('s3RenameModal', true, s3BrowserConfig.i18n.files.renamingFile);

            S3.ajax('s3_rename_object_', {
                bucket: fileData.bucket,
                current_key: fileData.key,
                new_filename: newFilename
            }, {
                success: (response) => {
                    S3.notify(response.data.message || s3BrowserConfig.i18n.files.renameSuccess, 'success');
                    S3M.hide('s3RenameModal');

                    // Refresh page after delay
                    setTimeout(() => window.location.reload(), 1500);
                },
                error: (message) => S3M.showError('s3RenameModal', message)
            });
        }
    };

    // Global shorthand
    window.S3F = window.S3Files;

})(jQuery);