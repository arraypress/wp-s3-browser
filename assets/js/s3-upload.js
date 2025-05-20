/**
 * S3 Browser Upload Functionality using Dropzone.js
 * Handles chunked uploads, cancellation, and progress tracking
 */
(function($) {
    'use strict';

    // Add upload functionality to existing S3Browser object
    if (typeof window.S3Browser === 'undefined') {
        window.S3Browser = {};
    }

    window.S3Browser.uploads = {
        dropzoneInstance: null,

        init: function() {
            this.initDropzone();
            this.setupEventHandlers();
        },

        initDropzone: function() {
            const self = this;
            // Create upload UI
            const $uploadHtml = $(`
                <div class="s3-upload-container">
                    <div class="s3-upload-header">
                        <h3 class="s3-upload-title">Upload Files</h3>
                    </div>
                    <div id="s3-dropzone" class="s3-upload-zone">
                        <div class="s3-upload-message">
                            <span class="dashicons dashicons-upload"></span>
                            <p>Drop files here to upload</p>
                            <p class="s3-upload-or">or</p>
                            <button type="button" class="button s3-browse-button">Choose Files</button>
                        </div>
                    </div>
                    <div class="s3-upload-list"></div>
                </div>
            `);

            // Insert upload container after the breadcrumbs
            $('.s3-browser-breadcrumbs').after($uploadHtml);

            // Get current bucket and prefix
            const bucket = this.getCurrentBucket();
            const prefix = this.getCurrentPrefix();

            // Only initialize if we have a bucket
            if (!bucket) return;

            // Create template for the dropzone previews
            const previewTemplate = `
                <div class="s3-upload-item">
                    <div class="s3-upload-item-info">
                        <span class="s3-filename" data-dz-name></span>
                        <span class="s3-filesize" data-dz-size></span>
                    </div>
                    <div class="s3-progress-container">
                        <div class="s3-progress-bar">
                            <div class="s3-progress" data-dz-uploadprogress></div>
                        </div>
                        <span class="s3-progress-text" data-dz-percent>0%</span>
                    </div>
                    <div class="s3-upload-status">
                        <button class="s3-cancel-upload" data-dz-remove>
                            <span class="dashicons dashicons-no-alt"></span>
                        </button>
                    </div>
                </div>
            `;

            // Initialize Dropzone
            this.dropzoneInstance = new Dropzone("#s3-dropzone", {
                url: this.getPresignedUrlEndpoint(),
                paramName: "file",
                maxFilesize: 500, // 500MB, adjust as needed
                parallelUploads: 2,
                chunking: true,
                forceChunking: true,
                chunkSize: 5000000, // 5MB chunks
                retryChunks: true,
                retryChunksLimit: 3,
                previewsContainer: ".s3-upload-list",
                previewTemplate: previewTemplate,
                clickable: ".s3-browse-button",
                autoProcessQueue: true,
                createImageThumbnails: false,

                // Custom function to handle getting the presigned URL
                accept: function(file, done) {
                    // Ensure prefix ends with a slash if not empty
                    const normalizedPrefix = prefix ? (prefix.endsWith('/') ? prefix : prefix + '/') : '';
                    const objectKey = normalizedPrefix + file.name;

                    // Store the key for use in the sending event
                    file.objectKey = objectKey;
                    file.s3Bucket = bucket;

                    done();
                },

                // We need to override sending to get a presigned URL for each chunk
                init: function() {
                    // Override the default send method
                    this.on("sending", function(file, xhr, formData) {
                        // Remove default formData since we'll be using direct PUT
                        formData.delete("file");

                        // Note: actual URL will be set in the processing event
                        xhr.open(xhr.method, xhr.url, true);
                    });

                    this.on("processing", function(file) {
                        self.getPresignedUrl(file.s3Bucket, file.objectKey)
                            .then(url => {
                                file.uploadUrl = url;
                                file.xhr.open("PUT", url);
                                // Set needed headers
                                file.xhr.setRequestHeader('Content-Type', file.type || 'application/octet-stream');
                            })
                            .catch(error => {
                                self.dropzoneInstance.emit("error", file, "Failed to get upload URL: " + error.message);
                            });
                    });

                    this.on("uploadprogress", function(file, progress) {
                        const $progressText = $(file.previewElement).find('[data-dz-percent]');
                        $progressText.text(Math.round(progress) + '%');
                    });

                    this.on("success", function(file) {
                        $(file.previewElement).addClass('s3-upload-success');
                        $(file.previewElement).find('.s3-upload-status')
                            .html('<span class="dashicons dashicons-yes"></span>');
                    });

                    this.on("error", function(file, message) {
                        $(file.previewElement).addClass('s3-upload-error');
                        $(file.previewElement).find('.s3-upload-status')
                            .html(`<span class="dashicons dashicons-no"></span> <span class="s3-error-message" title="${message}">${message}</span>`);
                    });

                    this.on("queuecomplete", function() {
                        // After all uploads complete, refresh the file listing
                        setTimeout(() => {
                            if (typeof S3Browser.showNotification === 'function') {
                                S3Browser.showNotification('Uploads completed. Refreshing file listing...', 'success');
                            }
                            // Refresh the page to show new files
                            window.location.reload();
                        }, 1000);
                    });

                    this.on("removedfile", function(file) {
                        if (file.xhr && file.status === 'uploading') {
                            // Cancel the upload
                            file.xhr.abort();
                        }
                    });
                }
            });
        },

        setupEventHandlers: function() {
            const self = this;

            // Add cancel button functionality
            $(document).on('click', '.s3-cancel-upload', function(e) {
                e.preventDefault();
                e.stopPropagation();

                const $item = $(this).closest('.s3-upload-item');
                const fileIndex = $item.index();

                // Get the file and abort
                if (self.dropzoneInstance && self.dropzoneInstance.files[fileIndex]) {
                    self.dropzoneInstance.removeFile(self.dropzoneInstance.files[fileIndex]);
                }
            });
        },

        // Helper to get current bucket name
        getCurrentBucket: function() {
            return $('#s3-load-more').data('bucket') || S3BrowserGlobalConfig.defaultBucket || null;
        },

        // Helper to get current prefix
        getCurrentPrefix: function() {
            return $('#s3-load-more').data('prefix') || '';
        },

        // Helper to get the endpoint for AJAX requests
        getPresignedUrlEndpoint: function() {
            return S3BrowserGlobalConfig.ajaxUrl;
        },

        // Get a presigned URL for uploading
        getPresignedUrl: function(bucket, objectKey) {
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
                    success: function(response) {
                        if (response.success && response.data && response.data.url) {
                            resolve(response.data.url);
                        } else {
                            reject(new Error(response.data?.message || 'Failed to get upload URL'));
                        }
                    },
                    error: function(xhr, status, error) {
                        reject(new Error(error || 'Network error'));
                    }
                });
            });
        },

        // Handle CORS errors
        handleCorsError: function(file, errorMessage) {
            const message = 'CORS configuration error - The bucket needs proper CORS settings to allow uploads from this domain.';
            this.dropzoneInstance.emit("error", file, message);

            if (typeof S3Browser.showNotification === 'function') {
                S3Browser.showNotification(message, 'error');
            }
        }
    };

    // Initialize uploads when the document is ready
    $(document).ready(function() {
        if (window.S3Browser) {
            // Add upload initialization to S3Browser.init
            const originalInit = S3Browser.init;
            S3Browser.init = function() {
                originalInit.call(this);
                this.uploads.init();
            };

            // If S3Browser is already initialized, just init uploads
            if (window.S3BrowserInitialized) {
                S3Browser.uploads.init();
            }
        }
    });

})(jQuery);