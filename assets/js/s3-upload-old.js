/**
 * S3 Browser Upload Functionality
 * This script handles direct browser uploads to S3 buckets using presigned URLs
 */
(function ($) {
    'use strict';

    // Extend the S3Browser object with upload functionality
    if (typeof window.S3Browser === 'undefined') {
        window.S3Browser = {};
    }

    window.S3Browser.uploads = {
        init: function () {
            this.bindEvents();
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

                // Create progress element
                const $progress = $(`
                    <div class="s3-upload-item">
                        <div class="s3-upload-item-info">
                            <span class="s3-filename">${file.name}</span>
                            <span class="s3-filesize">${self.formatFileSize(file.size)}</span>
                        </div>
                        <div class="s3-progress-container">
                            <div class="s3-progress-bar">
                                <div class="s3-progress" style="width: 0%"></div>
                            </div>
                            <span class="s3-progress-text">0%</span>
                        </div>
                        <div class="s3-upload-status"></div>
                    </div>
                `);

                $uploadList.append($progress);

                // Start upload process
                const uploadPromise = self.getPresignedUrl(bucket, objectKey)
                    .then(url => self.uploadToS3(file, url, $progress))
                    .then(() => {
                        $progress.addClass('s3-upload-success');
                        $progress.find('.s3-upload-status').html('<span class="dashicons dashicons-yes"></span>');
                    })
                    .catch(error => {
                        console.error('Upload error:', error);
                        $progress.addClass('s3-upload-error');
                        $progress.find('.s3-upload-status').html(`<span class="dashicons dashicons-no"></span>`);

                        // Add error message with tooltip for full text
                        const errorMsg = error.message || 'Upload failed';
                        $progress.find('.s3-upload-status').append(
                            `<span class="s3-error-message" title="${errorMsg}">${errorMsg}</span>`
                        );
                    });

                uploadPromises.push(uploadPromise);
            });

            // When all uploads complete, refresh the file listing
            Promise.allSettled(uploadPromises).then(() => {
                setTimeout(() => {
                    // Show notification
                    if (typeof S3Browser.showNotification === 'function') {
                        S3Browser.showNotification('Uploads completed. Refreshing file listing...', 'success');
                    }

                    // Refresh the page to show new files
                    window.location.reload();
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

        uploadToS3: function (file, presignedUrl, $progress) {
            return new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();

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
                    reject(new Error('Upload aborted'));
                });

                // Start upload - PUT for pre-signed URLs
                xhr.open('PUT', presignedUrl, true);
                xhr.setRequestHeader('Content-Type', file.type || 'application/octet-stream');
                xhr.send(file);
            });
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