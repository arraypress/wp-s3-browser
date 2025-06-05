/**
 * S3 Browser Upload - Enhanced direct browser uploads to S3
 * Handles drag & drop, progress tracking, and error recovery
 */
(function ($) {
    'use strict';

    // Upload namespace
    window.S3Upload = {

        // Upload state management
        state: {
            activeUploads: new Map(),
            totalUploads: 0,
            completedUploads: 0,
            failedUploads: 0,
            isUploading: false,
            maxConcurrentUploads: 3,
            currentUploads: 0
        },

        // Upload queue
        uploadQueue: [],

        // ===========================================
        // INITIALIZATION
        // ===========================================

        /**
         * Initialize upload functionality
         */
        init: function () {
            this.setupDropZone();
            this.setupFileInput();
            this.setupEventHandlers();
            this.setupDragAndDrop();
        },

        /**
         * Setup drag and drop functionality
         */
        setupDropZone: function () {
            const $dropzone = $('.s3-upload-zone');
            if (!$dropzone.length) return;

            // Enhanced drag and drop with visual feedback
            $dropzone.on({
                dragover: (e) => this.handleDragOver(e),
                dragleave: (e) => this.handleDragLeave(e),
                drop: (e) => this.handleDrop(e)
            });

            // Prevent default drag behavior on the document
            $(document).on('dragover drop', (e) => e.preventDefault());
        },

        /**
         * Setup file input handling
         */
        setupFileInput: function () {
            $('.s3-file-input').on('change', (e) => {
                if (e.target.files.length) {
                    const $dropzone = $('.s3-upload-zone');
                    this.processFiles(
                        e.target.files,
                        $dropzone.data('bucket'),
                        $dropzone.data('prefix') || ''
                    );
                    e.target.value = ''; // Reset input
                }
            });
        },

        /**
         * Setup upload event handlers
         */
        setupEventHandlers: function () {
            // Cancel upload buttons
            $(document).on('click', '.s3-cancel-upload', (e) => {
                e.preventDefault();
                const uploadId = $(e.target).closest('[data-upload-id]').data('upload-id');
                this.cancelUpload(uploadId);
            });

            // Retry failed uploads
            $(document).on('click', '.s3-retry-upload', (e) => {
                e.preventDefault();
                const uploadId = $(e.target).closest('[data-upload-id]').data('upload-id');
                this.retryUpload(uploadId);
            });

            // Clear completed uploads
            $(document).on('click', '.s3-clear-completed', (e) => {
                e.preventDefault();
                this.clearCompletedUploads();
            });
        },

        // ===========================================
        // DRAG & DROP HANDLING
        // ===========================================

        /**
         * Handle drag over with enhanced visual feedback
         */
        handleDragOver: function (e) {
            e.preventDefault();
            e.stopPropagation();

            const $dropzone = $(e.currentTarget);
            $dropzone.addClass('s3-dragover');

            // Update drop zone message
            const $message = $dropzone.find('.s3-upload-message');
            if (!$message.hasClass('drag-active')) {
                $message.addClass('drag-active')
                    .find('p').text('Drop files here to upload');
            }
        },

        /**
         * Handle drag leave
         */
        handleDragLeave: function (e) {
            e.preventDefault();
            e.stopPropagation();

            const $dropzone = $(e.currentTarget);

            // Only remove active state if actually leaving the dropzone
            setTimeout(() => {
                if (!$dropzone.is(':hover')) {
                    $dropzone.removeClass('s3-dragover');
                    const $message = $dropzone.find('.s3-upload-message');
                    $message.removeClass('drag-active')
                        .find('p').text(s3BrowserConfig.i18n.ui.dropFilesHere);
                }
            }, 50);
        },

        /**
         * Handle file drop with validation
         */
        handleDrop: function (e) {
            e.preventDefault();
            e.stopPropagation();

            const $dropzone = $(e.currentTarget);
            $dropzone.removeClass('s3-dragover');

            // Reset message
            const $message = $dropzone.find('.s3-upload-message');
            $message.removeClass('drag-active')
                .find('p').text(s3BrowserConfig.i18n.ui.dropFilesHere);

            const files = e.originalEvent.dataTransfer.files;
            if (files.length) {
                this.processFiles(
                    files,
                    $dropzone.data('bucket'),
                    $dropzone.data('prefix') || ''
                );
            }
        },

        // ===========================================
        // FILE PROCESSING
        // ===========================================

        /**
         * Process dropped/selected files with validation
         */
        processFiles: function (files, bucket, prefix) {
            if (!bucket) {
                S3.notify('No bucket specified for upload', 'error');
                return;
            }

            // Validate files before upload
            const validFiles = [];
            const errors = [];

            Array.from(files).forEach(file => {
                const validation = this.validateFile(file);
                if (validation.valid) {
                    validFiles.push(file);
                } else {
                    errors.push(`${file.name}: ${validation.message}`);
                }
            });

            // Show validation errors
            if (errors.length > 0) {
                this.showUploadErrors(errors);
            }

            // Upload valid files
            if (validFiles.length > 0) {
                this.startUploads(validFiles, bucket, prefix);
            }
        },

        /**
         * Validate individual file
         */
        validateFile: function (file) {
            // File size validation (default 100MB max)
            const maxSize = S3BrowserGlobalConfig.maxFileSize || (100 * 1024 * 1024);
            if (file.size > maxSize) {
                return {
                    valid: false,
                    message: `File too large (max ${S3.formatSize(maxSize)})`
                };
            }

            // File name validation
            const nameValidation = S3.validateFilename(file.name, 'upload');
            if (!nameValidation.valid) {
                return nameValidation;
            }

            // File type validation if configured
            const allowedTypes = S3BrowserGlobalConfig.allowedFileTypes;
            if (allowedTypes && allowedTypes.length > 0) {
                const fileExt = file.name.split('.').pop().toLowerCase();
                if (!allowedTypes.includes(fileExt)) {
                    return {
                        valid: false,
                        message: `File type .${fileExt} not allowed`
                    };
                }
            }

            return { valid: true };
        },

        /**
         * Start upload process
         */
        startUploads: function (files, bucket, prefix) {
            // Show upload container
            $('.s3-upload-list').show();
            $(document).trigger('s3UploadStarted');

            // Initialize state
            this.state.totalUploads = files.length;
            this.state.completedUploads = 0;
            this.state.failedUploads = 0;
            this.state.isUploading = true;

            // Create upload items for all files
            files.forEach(file => {
                const uploadItem = this.createUploadItem(file, bucket, prefix);
                this.uploadQueue.push(uploadItem);
            });

            // Start processing queue
            this.processUploadQueue();
        },

        /**
         * Create upload item with UI element
         */
        createUploadItem: function (file, bucket, prefix) {
            const uploadId = S3.generateId('upload');
            const normalizedPrefix = prefix ? (prefix.endsWith('/') ? prefix : prefix + '/') : '';
            const objectKey = normalizedPrefix + file.name;

            const uploadItem = {
                id: uploadId,
                file: file,
                bucket: bucket,
                objectKey: objectKey,
                status: 'pending',
                progress: 0,
                xhr: null,
                retryCount: 0,
                maxRetries: 3
            };

            // Create UI element
            const $element = this.createUploadElement(uploadItem);
            $('.s3-upload-list').append($element);

            return uploadItem;
        },

        /**
         * Create upload UI element
         */
        createUploadElement: function (uploadItem) {
            const { id, file } = uploadItem;

            return $(`
                <div class="s3-upload-item" id="${id}" data-upload-id="${id}">
                    <div class="s3-upload-item-info">
                        <span class="s3-filename">${S3.escapeHtml(file.name)}</span>
                        <span class="s3-filesize">${S3.formatSize(file.size)}</span>
                    </div>
                    <div class="s3-progress-container">
                        <div class="s3-progress-bar">
                            <div class="s3-progress" style="width: 0%"></div>
                        </div>
                        <span class="s3-progress-text">0%</span>
                        <span class="s3-transfer-data"></span>
                    </div>
                    <div class="s3-upload-status">
                        <button class="s3-cancel-upload" title="Cancel upload">
                            <span class="dashicons dashicons-no"></span>
                        </button>
                    </div>
                </div>
            `);
        },

        // ===========================================
        // UPLOAD QUEUE PROCESSING
        // ===========================================

        /**
         * Process upload queue with concurrency control
         */
        processUploadQueue: function () {
            // Start uploads up to the concurrent limit
            while (this.state.currentUploads < this.state.maxConcurrentUploads && this.uploadQueue.length > 0) {
                const uploadItem = this.uploadQueue.shift();
                this.startSingleUpload(uploadItem);
            }
        },

        /**
         * Start individual upload
         */
        startSingleUpload: function (uploadItem) {
            this.state.currentUploads++;
            this.state.activeUploads.set(uploadItem.id, uploadItem);

            uploadItem.status = 'getting_url';
            this.updateUploadStatus(uploadItem.id, 'Getting upload URL...');

            // Get presigned URL
            this.getPresignedUrl(uploadItem)
                .then(url => this.uploadToS3(uploadItem, url))
                .then(() => this.handleUploadSuccess(uploadItem))
                .catch(error => this.handleUploadError(uploadItem, error));
        },

        /**
         * Get presigned upload URL
         */
        getPresignedUrl: function (uploadItem) {
            return new Promise((resolve, reject) => {
                S3.ajax('s3_get_upload_url_', {
                    bucket: uploadItem.bucket,
                    object_key: uploadItem.objectKey
                }, {
                    success: (response) => {
                        if (response.data?.url) {
                            resolve(response.data.url);
                        } else {
                            reject(new Error('Invalid presigned URL response'));
                        }
                    },
                    error: (message) => reject(new Error(message))
                });
            });
        },

        /**
         * Upload file to S3 using presigned URL
         */
        uploadToS3: function (uploadItem, presignedUrl) {
            return new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                uploadItem.xhr = xhr;
                uploadItem.status = 'uploading';

                // Progress tracking
                let lastLoaded = 0;
                let lastTime = Date.now();

                xhr.upload.addEventListener('progress', (e) => {
                    if (e.lengthComputable) {
                        this.updateUploadProgress(uploadItem, e, lastLoaded, lastTime);
                        lastLoaded = e.loaded;
                        lastTime = Date.now();
                    }
                });

                // Success handler
                xhr.addEventListener('load', () => {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        resolve();
                    } else {
                        reject(new Error(`Upload failed with status ${xhr.status}`));
                    }
                });

                // Error handler
                xhr.addEventListener('error', () => {
                    if (xhr.status === 0) {
                        reject(new Error('Network error or CORS issue'));
                    } else {
                        reject(new Error('Upload failed'));
                    }
                });

                // Abort handler
                xhr.addEventListener('abort', () => {
                    reject(new Error('Upload cancelled'));
                });

                // Start upload
                xhr.open('PUT', presignedUrl, true);
                xhr.setRequestHeader('Content-Type', uploadItem.file.type || 'application/octet-stream');
                xhr.send(uploadItem.file);
            });
        },

        // ===========================================
        // PROGRESS & STATUS UPDATES
        // ===========================================

        /**
         * Update upload progress with transfer rate
         */
        updateUploadProgress: function (uploadItem, progressEvent, lastLoaded, lastTime) {
            const { loaded, total } = progressEvent;
            const percentComplete = Math.round((loaded / total) * 100);

            uploadItem.progress = percentComplete;

            const $element = $(`#${uploadItem.id}`);
            $element.find('.s3-progress').css('width', percentComplete + '%');
            $element.find('.s3-progress-text').text(percentComplete + '%');

            // Calculate transfer rate
            const currentTime = Date.now();
            const timeDiff = (currentTime - lastTime) / 1000;

            if (timeDiff > 0.5) { // Update every 500ms
                const loadedDiff = loaded - lastLoaded;
                const uploadSpeed = loadedDiff / timeDiff;

                const transferred = S3.formatSize(loaded) + ' / ' + S3.formatSize(total);
                const speed = S3.formatSize(uploadSpeed) + '/s';

                // Calculate ETA
                const remaining = total - loaded;
                const eta = uploadSpeed > 0 ? Math.round(remaining / uploadSpeed) : 0;
                const etaText = eta > 0 ? ` • ${eta}s remaining` : '';

                $element.find('.s3-transfer-data').text(`${transferred} • ${speed}${etaText}`);
            }
        },

        /**
         * Update upload status message
         */
        updateUploadStatus: function (uploadId, message) {
            $(`#${uploadId} .s3-transfer-data`).text(message);
        },

        /**
         * Handle successful upload
         */
        handleUploadSuccess: function (uploadItem) {
            uploadItem.status = 'completed';

            const $element = $(`#${uploadItem.id}`);
            $element.addClass('s3-upload-success');
            $element.find('.s3-upload-status').html('<span class="dashicons dashicons-yes" title="Upload completed"></span>');
            $element.find('.s3-transfer-data').text('Upload completed');

            this.finalizeUpload(uploadItem, true);
        },

        /**
         * Handle upload error with retry logic
         */
        handleUploadError: function (uploadItem, error) {
            uploadItem.status = 'failed';

            const $element = $(`#${uploadItem.id}`);

            if (error.message === 'Upload cancelled') {
                $element.addClass('s3-upload-cancelled');
                $element.find('.s3-upload-status').html('<span class="dashicons dashicons-no" title="Upload cancelled"></span>');
                $element.find('.s3-transfer-data').text('Cancelled');

                // Auto-remove cancelled uploads
                setTimeout(() => $element.fadeOut(800, () => $element.remove()), 3000);
            } else {
                $element.addClass('s3-upload-error');

                // Show retry button if retries available
                if (uploadItem.retryCount < uploadItem.maxRetries) {
                    $element.find('.s3-upload-status').html(`
                        <button class="s3-retry-upload" title="Retry upload">
                            <span class="dashicons dashicons-update"></span>
                        </button>
                    `);
                } else {
                    $element.find('.s3-upload-status').html('<span class="dashicons dashicons-warning" title="Upload failed"></span>');
                }

                // Show error message
                let errorMsg = error.message || 'Upload failed';
                if (errorMsg.includes('CORS') || errorMsg.includes('403') || errorMsg.includes('401')) {
                    this.showUploadError('CORS configuration error - Your bucket needs proper CORS settings');
                } else if (errorMsg.includes('network')) {
                    this.showUploadError('Network error - Please check your connection');
                } else {
                    $element.find('.s3-transfer-data').text(errorMsg);
                }
            }

            this.finalizeUpload(uploadItem, false);
        },

        /**
         * Finalize upload and continue queue processing
         */
        finalizeUpload: function (uploadItem, success) {
            this.state.currentUploads--;
            this.state.activeUploads.delete(uploadItem.id);

            if (success) {
                this.state.completedUploads++;
            } else {
                this.state.failedUploads++;
            }

            // Continue processing queue
            this.processUploadQueue();

            // Check if all uploads are complete
            if (this.state.currentUploads === 0 && this.uploadQueue.length === 0) {
                this.handleAllUploadsComplete();
            }

            // Trigger individual upload complete event
            $(document).trigger('s3UploadComplete', [success, uploadItem]);
        },

        // ===========================================
        // UPLOAD CONTROL & MANAGEMENT
        // ===========================================

        /**
         * Cancel individual upload
         */
        cancelUpload: function (uploadId) {
            const uploadItem = this.state.activeUploads.get(uploadId);
            if (!uploadItem) return;

            const confirmMessage = s3BrowserConfig.i18n.upload.cancelUploadConfirm
                .replace('{filename}', uploadItem.file.name);

            S3M.confirm(
                'Cancel Upload',
                confirmMessage,
                () => {
                    if (uploadItem.xhr) {
                        uploadItem.xhr.abort();
                    }
                    this.handleUploadError(uploadItem, new Error('Upload cancelled'));
                }
            );
        },

        /**
         * Retry failed upload
         */
        retryUpload: function (uploadId) {
            const $element = $(`#${uploadId}`);
            const uploadData = $element.data('upload-data');

            if (!uploadData) return;

            uploadData.retryCount++;
            uploadData.status = 'pending';

            // Reset UI
            $element.removeClass('s3-upload-error')
                .find('.s3-progress').css('width', '0%');
            $element.find('.s3-progress-text').text('0%');
            $element.find('.s3-transfer-data').text('Retrying...');

            // Add back to queue
            this.uploadQueue.unshift(uploadData);
            this.processUploadQueue();
        },

        /**
         * Clear completed uploads
         */
        clearCompletedUploads: function () {
            $('.s3-upload-item.s3-upload-success').fadeOut(400, function() {
                $(this).remove();
            });
        },

        /**
         * Handle all uploads completion
         */
        handleAllUploadsComplete: function () {
            this.state.isUploading = false;

            const { completedUploads, failedUploads, totalUploads } = this.state;

            if (completedUploads > 0) {
                const message = failedUploads > 0
                    ? `${completedUploads} of ${totalUploads} files uploaded successfully`
                    : s3BrowserConfig.i18n.upload.uploadComplete;

                S3.notify(message, 'success');

                // Refresh page after successful uploads
                setTimeout(() => window.location.reload(), 3000);
            }

            // Show clear button if there are completed uploads
            if (completedUploads > 0) {
                this.showClearCompletedButton();
            }

            $(document).trigger('s3AllUploadsComplete');
        },

        /**
         * Show clear completed uploads button
         */
        showClearCompletedButton: function () {
            if ($('.s3-clear-completed').length) return;

            const $button = $(`
                <button class="s3-clear-completed button button-secondary" style="margin: 10px 0;">
                    <span class="dashicons dashicons-trash"></span> Clear Completed
                </button>
            `);

            $('.s3-upload-list').after($button);
        },

        /**
         * Show upload errors
         */
        showUploadError: function (message) {
            $('.s3-upload-notice').remove();
            const $notice = $(`<div class="s3-upload-notice">${message}</div>`);
            $('.s3-upload-list').before($notice);

            setTimeout(() => {
                $notice.fadeOut(500, () => $notice.remove());
            }, 8000);
        }
    };

    // Initialize on document ready
    $(document).ready(() => {
        S3Upload.init();
    });

    // Global shorthand
    window.S3U = window.S3Upload;

})(jQuery);