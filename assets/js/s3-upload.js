(function($) {
    'use strict';

    // Add upload functionality to S3Browser
    if (typeof window.S3Browser === 'undefined') {
        window.S3Browser = {};
    }

    window.S3Browser.uploads = {
        dropzoneInstance: null,
        initialized: false,

        init: function() {
            // Prevent multiple initializations
            if (this.initialized) return;

            this.initializeDropzone();
            this.initialized = true;
        },

        initializeDropzone: function() {
            const self = this;
            const $uploadZone = $('.s3-upload-zone');

            if (!$uploadZone.length) return;

            const bucket = $uploadZone.data('bucket');
            const prefix = $uploadZone.data('prefix');

            if (!bucket) return;

            // Set Dropzone to not auto-discover
            if (typeof Dropzone !== 'undefined') {
                Dropzone.autoDiscover = false;
            } else {
                console.error('Dropzone.js is not loaded');
                return;
            }

            // Create the preview template
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
                        <span class="s3-progress-text">0%</span>
                    </div>
                    <div class="s3-upload-status">
                        <button type="button" class="button s3-cancel-button" data-dz-remove>
                            <span class="dashicons dashicons-no-alt"></span>
                        </button>
                    </div>
                    <div class="dz-error-message"><span data-dz-errormessage></span></div>
                </div>
            `;

            // Initialize Dropzone
            this.dropzoneInstance = new Dropzone(".s3-upload-zone", {
                url: "dummy-url", // Will be overridden
                method: "PUT",
                paramName: "file",
                maxFilesize: 500,
                timeout: 0,
                previewsContainer: ".s3-upload-list",
                previewTemplate: previewTemplate,
                // Important: Use only #s3FileUpload, not the label
                clickable: "#s3FileUpload",
                autoProcessQueue: true,
                chunking: true,
                chunkSize: 5000000, // 5MB chunks
                retryChunks: true,
                retryChunksLimit: 3,
                createImageThumbnails: false,

                // This function is called when a file is added
                init: function() {
                    this.on("addedfile", function(file) {
                        // Get presigned URL when file is added
                        const normalizedPrefix = prefix ? (prefix.endsWith('/') ? prefix : prefix + '/') : '';
                        const objectKey = normalizedPrefix + file.name;

                        file.s3Bucket = bucket;
                        file.s3ObjectKey = objectKey;

                        self.getPresignedUrl(bucket, objectKey)
                            .then(url => {
                                file.uploadUrl = url;
                                // No need to call processQueue, it happens automatically
                            })
                            .catch(error => {
                                // Handle error getting presigned URL
                                this.emit("error", file, error.message);
                                this.emit("complete", file);
                            });
                    });
                },

                // Called just before sending
                transformFile: function(file, done) {
                    if (!file.uploadUrl) {
                        // No URL yet, wait a moment and retry
                        setTimeout(() => {
                            if (file.uploadUrl) {
                                done(file);
                            } else {
                                this.emit("error", file, "Failed to get upload URL");
                                this.emit("complete", file);
                            }
                        }, 500);
                    } else {
                        done(file);
                    }
                },

                // Override the default send method
                accept: function(file, done) {
                    done();
                },

                // Override the sending method to use our presigned URL
                sending: function(file, xhr, formData) {
                    if (file.uploadUrl) {
                        xhr.open("PUT", file.uploadUrl, true);
                        xhr.setRequestHeader('Content-Type', file.type || 'application/octet-stream');
                    }
                },

                // Update progress display
                uploadprogress: function(file, progress, bytesSent) {
                    const progressText = Math.round(progress) + '%';
                    $(file.previewElement).find('.s3-progress-text').text(progressText);
                },

                // Handle successful upload
                success: function(file) {
                    $(file.previewElement).addClass('s3-upload-success');
                    $(file.previewElement).find('.s3-cancel-button')
                        .replaceWith('<span class="dashicons dashicons-yes s3-success-icon"></span>');
                },

                // Handle errors
                error: function(file, errorMessage) {
                    $(file.previewElement).addClass('s3-upload-error');

                    // Show error message
                    if (typeof errorMessage === 'string') {
                        $(file.previewElement).find('[data-dz-errormessage]').text(errorMessage);
                    } else if (errorMessage && errorMessage.message) {
                        $(file.previewElement).find('[data-dz-errormessage]').text(errorMessage.message);
                    }

                    // Change the cancel button to an error icon
                    $(file.previewElement).find('.s3-cancel-button')
                        .replaceWith('<span class="dashicons dashicons-no s3-error-icon"></span>');
                },

                // After all files are processed
                queuecomplete: function() {
                    // Only reload if there were successful uploads
                    if (this.getUploadingFiles().length === 0 &&
                        this.getQueuedFiles().length === 0 &&
                        this.getSuccessfulUploads().length > 0) {

                        setTimeout(function() {
                            if (typeof S3Browser.showNotification === 'function') {
                                S3Browser.showNotification('Uploads completed. Refreshing file listing...', 'success');
                            }
                            window.location.reload();
                        }, 1500);
                    }
                }
            });
        },

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
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        if (window.S3Browser) {
            const originalInit = window.S3Browser.init;
            window.S3Browser.init = function() {
                originalInit.call(this);
                window.S3Browser.uploads.init();
            };

            if (window.S3BrowserInitialized) {
                window.S3Browser.uploads.init();
            }
        }
    });

})(jQuery);