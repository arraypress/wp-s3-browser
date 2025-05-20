/**
 * S3 Browser Upload Functionality
 * This script handles direct browser uploads to S3 buckets using presigned URLs
 * with added cancel functionality
 */
(function ($) {
    'use strict';

    // Extend the S3Browser object with upload functionality
    if (typeof window.S3Browser === 'undefined') {
        window.S3Browser = {};
    }

    window.S3Browser.uploads = {
        // Store active XHR requests for cancellation
        activeUploads: {},

        init: function () {
            this.bindEvents();
            this.addStyles();
        },

        addStyles: function () {
            // Add CSS styles for cancelled uploads
            const style = $('<style>').text(`
                .s3-upload-cancelled .s3-filename,
                .s3-upload-cancelled .s3-filesize,
                .s3-upload-cancelled .s3-progress-text,
                .s3-upload-cancelled .s3-error-message {
                    color: #e74c3c !important;
                }
            `);
            $('head').append(style);
        },

        bindEvents: function () {
            const self = this;
            const $dropzone = $('.s3-upload-zone');
            const $fileInput = $('.s3-file-input');

            // Handle drag and drop events
            $dropzone.on({
                dragover: function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    $(this).addClass('s3-dragover');
                },
                dragleave: function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    $(this).removeClass('s3-dragover');
                },
                drop: function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    $(this).removeClass('s3-dragover');

                    const files = e.originalEvent.dataTransfer.files;
                    if (files.length) {
                        const bucket = $(this).data('bucket');
                        const prefix = $(this).data('prefix') || '';
                        self.uploadFiles(files, bucket, prefix);
                    }
                }
            });

            // Handle file input selection
            $fileInput.on('change', function () {
                if (this.files.length) {
                    const bucket = $dropzone.data('bucket');
                    const prefix = $dropzone.data('prefix') || '';
                    self.uploadFiles(this.files, bucket, prefix);
                    // Reset input to allow selecting the same file again
                    this.value = '';
                }
            });

            // Handle cancel button clicks (delegated event)
            $('.s3-upload-list').on('click', '.s3-cancel-upload', function (e) {
                e.preventDefault();
                const uploadId = $(this).data('upload-id');
                self.cancelUpload(uploadId);
            });
        },

        uploadFiles: function (files, bucket, prefix) {
            const self = this;
            const $uploadList = $('.s3-upload-list');
            const uploadPromises = [];

            // Show the upload list container
            $uploadList.show();

            Array.from(files).forEach(file => {
                // Create a unique key that preserves the file name
                // Ensure prefix ends with a slash if not empty
                const normalizedPrefix = prefix ? (prefix.endsWith('/') ? prefix : prefix + '/') : '';
                const objectKey = normalizedPrefix + file.name;

                // Create unique upload ID
                const uploadId = 'upload_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);

                // Create progress element with cancel button
                const $progress = $(`
                    <div class="s3-upload-item" id="${uploadId}">
                        <div class="s3-upload-item-info">
                            <span class="s3-filename">${file.name}</span>
                            <span class="s3-filesize">${self.formatFileSize(file.size)}</span>
                        </div>
                        <div class="s3-progress-container">
                            <div class="s3-progress-bar">
                                <div class="s3-progress" style="width: 0"></div>
                            </div>
                            <span class="s3-progress-text">0%</span>
                        </div>
                        <div class="s3-upload-status">
                            <button class="s3-cancel-upload" title="Cancel upload" data-upload-id="${uploadId}">
                                <span class="dashicons dashicons-no"></span>
                            </button>
                        </div>
                    </div>
                `);

                $uploadList.append($progress);

                // Start upload process
                const uploadPromise = self.getPresignedUrl(bucket, objectKey)
                    .then(url => self.uploadToS3(file, url, $progress, uploadId))
                    .then(() => {
                        $progress.addClass('s3-upload-success');
                        $progress.find('.s3-upload-status').html('<span class="dashicons dashicons-yes"></span>');
                        // Remove from active uploads
                        delete self.activeUploads[uploadId];
                    })
                    .catch(error => {
                        console.error('Upload error:', error);
                        $progress.addClass('s3-upload-error');

                        // Check if this was a cancellation
                        if (error.message === 'Upload cancelled') {
                            $progress.addClass('s3-upload-cancelled');
                            $progress.find('.s3-upload-status').html(
                                `<span class="dashicons dashicons-no"></span><span class="s3-error-message">Cancelled</span>`
                            );

                            // Fade out and remove after 3 seconds
                            setTimeout(function () {
                                $progress.fadeOut(800, function () {
                                    $(this).remove();
                                });
                            }, 3000);
                        } else {
                            // Other errors
                            const errorMsg = error.message || 'Upload failed';
                            $progress.find('.s3-upload-status').html(
                                `<span class="dashicons dashicons-no"></span><span class="s3-error-message" title="${errorMsg}">${errorMsg}</span>`
                            );
                        }

                        // Remove from active uploads
                        delete self.activeUploads[uploadId];
                    });

                uploadPromises.push(uploadPromise);
            });

            // When all uploads complete, refresh the file listing
            Promise.allSettled(uploadPromises).then(() => {
                setTimeout(() => {
                    // Only reload if there are successful uploads
                    const hasSuccessfulUploads = $('.s3-upload-success').length > 0;

                    if (hasSuccessfulUploads) {
                        // Show notification
                        if (typeof S3Browser.showNotification === 'function') {
                            S3Browser.showNotification('Uploads completed. Refreshing file listing...', 'success');
                        }

                        // Refresh the page to show new files
                        window.location.reload();
                    }
                }, 3000);
            });
        },

        getPresignedUrl: function (bucket, objectKey) {
            return new Promise((resolve, reject) => {
                $.ajax({
                    url: S3BrowserGlobalConfig.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 's3_get_upload_url_' + S3BrowserGlobalConfig.providerId,
                        bucket: bucket,
                        object_key: objectKey,
                        nonce: S3BrowserGlobalConfig.nonce
                    },
                    success: function (response) {
                        if (response.success && response.data && response.data.url) {
                            resolve(response.data.url);
                        } else {
                            reject(new Error(response.data?.message || 'Failed to get upload URL'));
                        }
                    },
                    error: function (xhr, status, error) {
                        reject(new Error(error || 'Network error'));
                    }
                });
            });
        },

        uploadToS3: function (file, presignedUrl, $progress, uploadId) {
            const self = this;

            return new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();

                // Store the XHR object for potential cancellation
                self.activeUploads[uploadId] = xhr;

                // Track upload progress
                xhr.upload.addEventListener('progress', function (e) {
                    if (e.lengthComputable) {
                        const percentComplete = Math.round((e.loaded / e.total) * 100);
                        $progress.find('.s3-progress').css('width', percentComplete + '%');
                        $progress.find('.s3-progress-text').text(percentComplete + '%');
                    }
                });

                // Handle completion
                xhr.addEventListener('load', function () {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        resolve(xhr.response);
                    } else {
                        reject(new Error(`Upload failed with status ${xhr.status}`));
                    }
                });

                // Better error handling
                xhr.addEventListener('error', function (e) {
                    // Check for CORS errors
                    if (e.target.status === 0) {
                        reject(new Error('CORS configuration error - bucket needs proper CORS settings'));
                    } else {
                        reject(new Error('Upload failed due to network error'));
                    }
                });

                xhr.addEventListener('abort', function () {
                    reject(new Error('Upload cancelled'));
                });

                // Start upload - PUT for pre-signed URLs
                xhr.open('PUT', presignedUrl, true);
                xhr.setRequestHeader('Content-Type', file.type || 'application/octet-stream');
                xhr.send(file);
            });
        },

        cancelUpload: function (uploadId) {
            const self = this;

            // Get the filename from the UI element
            const $uploadItem = $('#' + uploadId);
            const filename = $uploadItem.find('.s3-filename').text();

            // Show confirmation dialog
            if (confirm('Are you sure you want to cancel "' + filename + '"?')) {
                // Check if this upload is active
                if (self.activeUploads[uploadId]) {
                    // Abort the XHR request
                    self.activeUploads[uploadId].abort();

                    // Add visual feedback immediately (red text)
                    $uploadItem.addClass('s3-upload-cancelled');

                    // Fade out and remove after 3 seconds
                    setTimeout(function () {
                        $uploadItem.fadeOut(800, function () {
                            $(this).remove();
                        });
                    }, 3000);

                    console.log('Upload cancelled:', uploadId);
                }
            }
        },

        formatFileSize: function (bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
    };

    // Initialize uploads when the document is ready
    $(document).ready(function () {
        S3Browser.uploads.init();
    });

})(jQuery);