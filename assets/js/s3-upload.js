/**
 * S3 Browser Upload Functionality
 * Handles direct browser uploads to S3 buckets using presigned URLs
 */
(function ($) {
    'use strict';

    if (typeof window.S3Browser === 'undefined') {
        window.S3Browser = {};
    }

    window.S3Browser.uploads = {
        activeUploads: {},
        activeUploadCount: 0,
        i18n: {},

        init: function () {
            this.loadTranslations();
            this.bindEvents();
        },

        loadTranslations: function () {
            this.i18n = s3BrowserConfig?.i18n?.upload || {};
        },

        bindEvents: function () {
            const self = this;
            const $dropzone = $('.s3-upload-zone');
            const $fileInput = $('.s3-file-input');

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

                    const files = e.originalEvent.dataTransfer.files;
                    if (files.length) {
                        const bucket = $(this).data('bucket');
                        const prefix = $(this).data('prefix') || '';
                        self.uploadFiles(files, bucket, prefix);
                    }
                }
            });

            // File input
            $fileInput.on('change', function () {
                if (this.files.length) {
                    const bucket = $dropzone.data('bucket');
                    const prefix = $dropzone.data('prefix') || '';
                    self.uploadFiles(this.files, bucket, prefix);
                    this.value = '';
                }
            });

            // Cancel uploads
            $('.s3-upload-list').on('click', '.s3-cancel-upload', function (e) {
                e.preventDefault();
                const uploadId = $(this).data('upload-id');
                self.cancelUpload(uploadId);
            });
        },

        showUploadError: function (message) {
            $('.s3-upload-notice').remove();
            const $notice = $(`<div class="s3-upload-notice">${message}</div>`);
            $('.s3-upload-list').before($notice);

            setTimeout(() => {
                $notice.fadeOut(500, function () {
                    $(this).remove();
                });
            }, 8000);
        },

        uploadFiles: function (files, bucket, prefix) {
            const self = this;
            const $uploadList = $('.s3-upload-list');
            const uploadPromises = [];

            $uploadList.show();
            $(document).trigger('s3UploadStarted');

            Array.from(files).forEach(file => {
                const normalizedPrefix = prefix ? (prefix.endsWith('/') ? prefix : prefix + '/') : '';
                const objectKey = normalizedPrefix + file.name;
                const uploadId = 'upload_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);

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
                            <span class="s3-transfer-data"></span>
                        </div>
                        <div class="s3-upload-status">
                            <button class="s3-cancel-upload" title="Cancel upload" data-upload-id="${uploadId}">
                                <span class="dashicons dashicons-no"></span>
                            </button>
                        </div>
                    </div>
                `);

                $uploadList.append($progress);
                self.activeUploadCount++;

                const uploadPromise = self.getPresignedUrl(bucket, objectKey)
                    .then(url => self.uploadToS3(file, url, $progress, uploadId))
                    .then(() => {
                        $progress.addClass('s3-upload-success');
                        $progress.find('.s3-upload-status').html('<span class="dashicons dashicons-yes"></span>');
                        self.handleUploadComplete(uploadId, true);
                    })
                    .catch(error => {
                        console.error('Upload error:', error);
                        self.handleUploadError(error, $progress, uploadId);
                    });

                uploadPromises.push(uploadPromise);
            });

            this.handleAllUploadsComplete(uploadPromises);
        },

        handleUploadComplete: function (uploadId, success) {
            delete this.activeUploads[uploadId];
            this.activeUploadCount--;

            if (this.activeUploadCount === 0) {
                $(document).trigger('s3AllUploadsComplete');
            }
            $(document).trigger('s3UploadComplete', [success]);
        },

        handleUploadError: function (error, $progress, uploadId) {
            if (error.message === this.i18n.uploadCancelled) {
                $progress.addClass('s3-upload-cancelled');
                $progress.find('.s3-upload-status').html('<span class="dashicons dashicons-no"></span>');
                setTimeout(() => {
                    $progress.fadeOut(800, function () {
                        $(this).remove();
                    });
                }, 3000);
            } else {
                $progress.addClass('s3-upload-error');
                $progress.find('.s3-upload-status').html('<span class="dashicons dashicons-warning"></span>');

                const errorMsg = error.message || this.i18n.uploadFailed;
                if (errorMsg.includes('CORS') || errorMsg.includes('403') || errorMsg.includes('401')) {
                    this.showUploadError(this.i18n.corsError);
                } else if (errorMsg.includes('network')) {
                    this.showUploadError(this.i18n.networkError);
                } else {
                    this.showUploadError(this.i18n.uploadFailed + ' ' + errorMsg);
                }
            }

            this.handleUploadComplete(uploadId, false);
        },

        handleAllUploadsComplete: function (uploadPromises) {
            const self = this;
            Promise.allSettled(uploadPromises).then(() => {
                setTimeout(() => {
                    const hasSuccessfulUploads = $('.s3-upload-success').length > 0;
                    if (hasSuccessfulUploads) {
                        if (typeof S3Browser.showNotification === 'function') {
                            S3Browser.showNotification(self.i18n.uploadComplete, 'success');
                        }
                        self.clearCache(() => window.location.reload());
                    }
                }, 3000);
            });
        },

        clearCache: function (callback) {
            const urlParams = new URLSearchParams(window.location.search);
            const bucket = urlParams.get('bucket') || $('.s3-upload-zone').data('bucket');
            const prefix = urlParams.get('prefix') || $('.s3-upload-zone').data('prefix') || '';

            if (!bucket) {
                if (callback) callback();
                return;
            }

            $.ajax({
                url: S3BrowserGlobalConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 's3_clear_cache_' + S3BrowserGlobalConfig.providerId,
                    type: 'objects',
                    bucket: bucket,
                    prefix: prefix,
                    nonce: S3BrowserGlobalConfig.nonce
                },
                success: () => {
                    console.log('Cache cleared successfully');
                    if (callback) callback();
                },
                error: () => {
                    console.error('Failed to clear cache');
                    if (callback) callback();
                }
            });
        },

        getPresignedUrl: function (bucket, objectKey) {
            const self = this;
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
                        if (response.success && response.data?.url) {
                            resolve(response.data.url);
                        } else {
                            reject(new Error(response.data?.message || self.i18n.failedPresignedUrl));
                        }
                    },
                    error: function (xhr, status, error) {
                        if (xhr.status === 403 || xhr.status === 401) {
                            reject(new Error('Authentication failed - check S3 credentials'));
                        } else {
                            reject(new Error(error || self.i18n.networkError));
                        }
                    }
                });
            });
        },

        uploadToS3: function (file, presignedUrl, $progress, uploadId) {
            const self = this;
            return new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                let lastLoaded = 0;
                let lastTime = Date.now();

                self.activeUploads[uploadId] = xhr;

                xhr.upload.addEventListener('progress', function (e) {
                    if (e.lengthComputable) {
                        const percentComplete = Math.round((e.loaded / e.total) * 100);
                        $progress.find('.s3-progress').css('width', percentComplete + '%');
                        $progress.find('.s3-progress-text').text(percentComplete + '%');

                        const currentTime = Date.now();
                        const timeDiff = (currentTime - lastTime) / 1000;

                        if (timeDiff > 0.5) {
                            const loadedDiff = e.loaded - lastLoaded;
                            const uploadSpeed = loadedDiff / timeDiff;

                            const transferred = self.formatFileSize(e.loaded) + ' / ' + self.formatFileSize(e.total);
                            const speed = self.formatFileSize(uploadSpeed) + '/s';
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
                        reject(new Error(`${self.i18n.uploadFailedStatus} ${xhr.status}`));
                    }
                });

                xhr.addEventListener('error', function (e) {
                    if (e.target.status === 0) {
                        reject(new Error(self.i18n.corsError));
                    } else {
                        reject(new Error(self.i18n.networkError));
                    }
                });

                xhr.addEventListener('abort', function () {
                    reject(new Error(self.i18n.uploadCancelled));
                });

                xhr.open('PUT', presignedUrl, true);
                xhr.setRequestHeader('Content-Type', file.type || 'application/octet-stream');
                xhr.send(file);
            });
        },

        cancelUpload: function (uploadId) {
            const $uploadItem = $('#' + uploadId);
            const filename = $uploadItem.find('.s3-filename').text();
            const confirmMessage = this.i18n.cancelUploadConfirm.replace('{filename}', filename);

            if (confirm(confirmMessage) && this.activeUploads[uploadId]) {
                this.activeUploads[uploadId].abort();
                $uploadItem.addClass('s3-upload-cancelled');
                setTimeout(() => {
                    $uploadItem.fadeOut(800, function () {
                        $(this).remove();
                    });
                }, 3000);
                console.log('Upload cancelled:', uploadId);
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

    $(document).ready(function () {
        S3Browser.uploads.init();
    });

})(jQuery);