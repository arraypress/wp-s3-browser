/**
 * S3 Browser Upload Functionality
 * Handles direct browser uploads to S3 buckets using presigned URLs
 */
(function ($) {
    'use strict';

    // Extend the main S3Browser object with upload methods
    $.extend(window.S3Browser, {

        // Upload-specific state
        activeUploads: {},
        activeUploadCount: 0,

        /**
         * Bind upload event handlers
         */
        bindUploadEvents: function () {
            var self = this;
            var $dropzone = $('.s3-upload-zone');
            var $fileInput = $('.s3-file-input');

            // Drag and drop
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

                    var files = e.originalEvent.dataTransfer.files;
                    if (files.length) {
                        var bucket = $(this).data('bucket');
                        var prefix = $(this).data('prefix') || '';
                        self.uploadFiles(files, bucket, prefix);
                    }
                }
            });

            // File input
            $fileInput.on('change', function () {
                if (this.files.length) {
                    var bucket = $dropzone.data('bucket');
                    var prefix = $dropzone.data('prefix') || '';
                    self.uploadFiles(this.files, bucket, prefix);
                    this.value = '';
                }
            });

            // Cancel uploads
            $('.s3-upload-list').on('click', '.s3-cancel-upload', function (e) {
                e.preventDefault();
                var uploadId = $(this).data('upload-id');
                self.cancelUpload(uploadId);
            });
        },

        /**
         * Show upload error message
         */
        showUploadError: function (message) {
            $('.s3-upload-notice').remove();
            var $notice = $('<div class="s3-upload-notice">' + message + '</div>');
            $('.s3-upload-list').before($notice);

            setTimeout(function () {
                $notice.fadeOut(500, function () {
                    $(this).remove();
                });
            }, 8000);
        },

        /**
         * Upload multiple files
         */
        uploadFiles: function (files, bucket, prefix) {
            var self = this;
            var $uploadList = $('.s3-upload-list');
            var uploadPromises = [];

            $uploadList.show();
            $(document).trigger('s3UploadStarted');

            Array.from(files).forEach(function (file) {
                var normalizedPrefix = prefix ? (prefix.endsWith('/') ? prefix : prefix + '/') : '';
                var objectKey = normalizedPrefix + file.name;
                var uploadId = 'upload_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);

                var $progress = $([
                    '<div class="s3-upload-item" id="' + uploadId + '">',
                    '  <div class="s3-upload-item-info">',
                    '    <span class="s3-filename">' + file.name + '</span>',
                    '    <span class="s3-filesize">' + self.formatFileSize(file.size) + '</span>',
                    '  </div>',
                    '  <div class="s3-progress-container">',
                    '    <div class="s3-progress-bar">',
                    '      <div class="s3-progress" style="width: 0"></div>',
                    '    </div>',
                    '    <span class="s3-progress-text">0%</span>',
                    '    <span class="s3-transfer-data"></span>',
                    '  </div>',
                    '  <div class="s3-upload-status">',
                    '    <button class="s3-cancel-upload" title="Cancel upload" data-upload-id="' + uploadId + '">',
                    '      <span class="dashicons dashicons-no"></span>',
                    '    </button>',
                    '  </div>',
                    '</div>'
                ].join(''));

                $uploadList.append($progress);
                self.activeUploadCount++;

                var uploadPromise = self.getPresignedUrl(bucket, objectKey)
                    .then(function (url) {
                        return self.uploadToS3(file, url, $progress, uploadId);
                    })
                    .then(function () {
                        $progress.addClass('s3-upload-success');
                        $progress.find('.s3-upload-status').html('<span class="dashicons dashicons-yes"></span>');
                        self.handleUploadComplete(uploadId, true);
                    })
                    .catch(function (error) {
                        console.error('Upload error:', error);
                        self.handleUploadError(error, $progress, uploadId);
                    });

                uploadPromises.push(uploadPromise);
            });

            this.handleAllUploadsComplete(uploadPromises);
        },

        /**
         * Handle upload completion
         */
        handleUploadComplete: function (uploadId, success) {
            delete this.activeUploads[uploadId];
            this.activeUploadCount--;

            if (this.activeUploadCount === 0) {
                $(document).trigger('s3AllUploadsComplete');
            }
            $(document).trigger('s3UploadComplete', [success]);
        },

        /**
         * Handle upload errors
         */
        handleUploadError: function (error, $progress, uploadId) {
            var upload = s3BrowserConfig.i18n.upload;

            if (error.message === upload.uploadCancelled) {
                $progress.addClass('s3-upload-cancelled');
                $progress.find('.s3-upload-status').html('<span class="dashicons dashicons-no"></span>');
                setTimeout(function () {
                    $progress.fadeOut(800, function () {
                        $(this).remove();
                    });
                }, 3000);
            } else {
                $progress.addClass('s3-upload-error');
                $progress.find('.s3-upload-status').html('<span class="dashicons dashicons-warning"></span>');

                var errorMsg = error.message || upload.uploadFailed;
                if (errorMsg.includes('CORS') || errorMsg.includes('403') || errorMsg.includes('401')) {
                    this.showUploadError(upload.corsError);
                } else if (errorMsg.includes('network')) {
                    this.showUploadError(upload.networkError);
                } else {
                    this.showUploadError(upload.uploadFailed + ' ' + errorMsg);
                }
            }

            this.handleUploadComplete(uploadId, false);
        },

        /**
         * Handle all uploads completion
         */
        handleAllUploadsComplete: function (uploadPromises) {
            var self = this;
            Promise.allSettled(uploadPromises).then(function () {
                setTimeout(function () {
                    var hasSuccessfulUploads = $('.s3-upload-success').length > 0;
                    if (hasSuccessfulUploads) {
                        self.showNotification(s3BrowserConfig.i18n.upload.uploadComplete, 'success');
                        window.location.reload();
                    }
                }, 3000);
            });
        },

        /**
         * Get presigned URL for upload
         */
        getPresignedUrl: function (bucket, objectKey) {
            var self = this;
            return new Promise(function (resolve, reject) {
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
                            reject(new Error(response.data && response.data.message || s3BrowserConfig.i18n.upload.failedPresignedUrl));
                        }
                    },
                    error: function (xhr, status, error) {
                        if (xhr.status === 403 || xhr.status === 401) {
                            reject(new Error('Authentication failed - check S3 credentials'));
                        } else {
                            reject(new Error(error || s3BrowserConfig.i18n.upload.networkError));
                        }
                    }
                });
            });
        },

        /**
         * Upload file to S3 using presigned URL
         */
        uploadToS3: function (file, presignedUrl, $progress, uploadId) {
            var self = this;
            return new Promise(function (resolve, reject) {
                var xhr = new XMLHttpRequest();
                var lastLoaded = 0;
                var lastTime = Date.now();

                self.activeUploads[uploadId] = xhr;

                xhr.upload.addEventListener('progress', function (e) {
                    if (e.lengthComputable) {
                        var percentComplete = Math.round((e.loaded / e.total) * 100);
                        $progress.find('.s3-progress').css('width', percentComplete + '%');
                        $progress.find('.s3-progress-text').text(percentComplete + '%');

                        var currentTime = Date.now();
                        var timeDiff = (currentTime - lastTime) / 1000;

                        if (timeDiff > 0.5) {
                            var loadedDiff = e.loaded - lastLoaded;
                            var uploadSpeed = loadedDiff / timeDiff;

                            var transferred = self.formatFileSize(e.loaded) + ' / ' + self.formatFileSize(e.total);
                            var speed = self.formatFileSize(uploadSpeed) + '/s';
                            $progress.find('.s3-transfer-data').text(transferred + ' • ' + speed);

                            lastLoaded = e.loaded;
                            lastTime = currentTime;
                        }
                    }
                });

                xhr.addEventListener('load', function () {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        resolve(xhr.response);
                    } else {
                        reject(new Error(s3BrowserConfig.i18n.upload.uploadFailedStatus + ' ' + xhr.status));
                    }
                });

                xhr.addEventListener('error', function (e) {
                    if (e.target.status === 0) {
                        reject(new Error(s3BrowserConfig.i18n.upload.corsError));
                    } else {
                        reject(new Error(s3BrowserConfig.i18n.upload.networkError));
                    }
                });

                xhr.addEventListener('abort', function () {
                    reject(new Error(s3BrowserConfig.i18n.upload.uploadCancelled));
                });

                xhr.open('PUT', presignedUrl, true);
                xhr.setRequestHeader('Content-Type', file.type || 'application/octet-stream');
                xhr.send(file);
            });
        },

        /**
         * Cancel an upload
         */
        cancelUpload: function (uploadId) {
            var $uploadItem = $('#' + uploadId);
            var filename = $uploadItem.find('.s3-filename').text();
            var confirmMessage = s3BrowserConfig.i18n.upload.cancelUploadConfirm.replace('{filename}', filename);

            if (confirm(confirmMessage) && this.activeUploads[uploadId]) {
                this.activeUploads[uploadId].abort();
                $uploadItem.addClass('s3-upload-cancelled');
                setTimeout(function () {
                    $uploadItem.fadeOut(800, function () {
                        $(this).remove();
                    });
                }, 3000);
                console.log('Upload cancelled:', uploadId);
            }
        },

        /**
         * Format file size for display
         */
        formatFileSize: function (bytes) {
            if (bytes === 0) return '0 Bytes';
            var k = 1024;
            var sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            var i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

    });

})(jQuery);