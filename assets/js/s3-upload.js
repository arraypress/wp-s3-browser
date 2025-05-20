(function($) {
    'use strict';

    // Add upload functionality to S3Browser
    if (typeof window.S3Browser === 'undefined') {
        window.S3Browser = {};
    }

    window.S3Browser.uploads = {
        dropzoneInstance: null,

        init: function() {
            this.initializeDropzone();
        },

        initializeDropzone: function() {
            const self = this;
            const $uploadZone = $('.s3-upload-zone');

            if (!$uploadZone.length) return;

            const bucket = $uploadZone.data('bucket');
            const prefix = $uploadZone.data('prefix');

            if (!bucket) return;

            // Configure Dropzone
            Dropzone.autoDiscover = false;

            // Add dropzone class for CSS styling
            $uploadZone.addClass('dropzone');

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
                        <button class="button s3-cancel-button" data-dz-remove>
                            <span class="dashicons dashicons-no-alt"></span>
                        </button>
                    </div>
                    <div class="dz-error-message"><span data-dz-errormessage></span></div>
                </div>
            `;

            this.dropzoneInstance = new Dropzone(".s3-upload-zone", {
                url: S3BrowserGlobalConfig.ajaxUrl,
                paramName: "file",
                maxFilesize: 500,
                method: "PUT",
                timeout: 0,
                previewsContainer: ".s3-upload-list",
                previewTemplate: previewTemplate,
                clickable: ".s3-file-input, label[for='s3FileUpload']",
                autoProcessQueue: true,
                chunking: true,
                chunkSize: 5000000, // 5MB chunks
                retryChunks: true,
                retryChunksLimit: 3,
                createImageThumbnails: false,

                // Handle the presigned URL
                accept: function(file, done) {
                    const normalizedPrefix = prefix ? (prefix.endsWith('/') ? prefix : prefix + '/') : '';
                    const objectKey = normalizedPrefix + file.name;

                    file.s3Bucket = bucket;
                    file.s3ObjectKey = objectKey;

                    self.getPresignedUrl(bucket, objectKey)
                        .then(url => {
                            file.uploadUrl = url;
                            done();
                        })
                        .catch(error => {
                            done(error.message);
                        });
                },

                // Override the sending to use presigned URL
                sending: function(file, xhr, formData) {
                    // Cancel the original request
                    xhr.abort();

                    // Create a new request with the correct URL
                    xhr.open('PUT', file.uploadUrl);
                    xhr.setRequestHeader('Content-Type', file.type || 'application/octet-stream');
                },

                // Update progress display
                uploadprogress: function(file, progress) {
                    $(file.previewElement).find('.s3-progress-text').text(Math.round(progress) + '%');
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
                    if (self.dropzoneInstance.getSuccessfulUploads().length > 0) {
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