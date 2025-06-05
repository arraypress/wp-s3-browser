/**
 * S3 Browser CORS - CORS configuration management
 * Handles CORS information display and upload configuration setup
 */
(function ($) {
    'use strict';

    // Extend the main S3Browser object with CORS methods
    $.extend(window.S3Browser, {

        /**
         * Initialize CORS functionality
         */
        initCORS: function () {
            this.bindCORSEvents();
            this.loadCORSStatus();
        },

        /**
         * Bind CORS-related event handlers
         */
        bindCORSEvents: function () {
            var self = this;

            // CORS info buttons
            $(document).off('click.s3cors').on('click.s3cors', '.s3-cors-info, .s3-cors-info-link', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var $button = $(this);
                self.showCORSInfo($button.data('bucket'));
            });

            // CORS setup buttons
            $(document).off('click.s3corssetup').on('click.s3corssetup', '.s3-cors-setup, .s3-cors-setup-link', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var $button = $(this);
                self.showCORSSetupModal($button.data('bucket'));
            });
        },

        /**
         * Load CORS status for all visible buckets
         */
        loadCORSStatus: function () {
            var self = this;
            $('.s3-cors-status').each(function () {
                var $status = $(this);
                var bucket = $status.data('bucket');
                if (bucket) {
                    self.loadSingleCORSStatus($status, bucket);
                }
            });
        },

        /**
         * Load CORS status for a single bucket
         */
        loadSingleCORSStatus: function ($statusElement, bucket) {
            var self = this;

            // Show loading state
            $statusElement.find('.s3-cors-loading').show();
            $statusElement.find('.s3-cors-result').hide();

            this.makeAjaxRequest('s3_get_cors_info_', {
                bucket: bucket
            }, {
                success: function (response) {
                    var analysis = response.data.analysis;
                    var upload = response.data.upload_capability;

                    var statusHtml = self.formatCORSStatus(analysis, upload);

                    $statusElement.find('.s3-cors-loading').hide();
                    $statusElement.find('.s3-cors-result').html(statusHtml).show();
                },
                error: function (message) {
                    $statusElement.find('.s3-cors-loading').hide();
                    $statusElement.find('.s3-cors-result')
                        .html('<span class="s3-cors-error">Error: ' + message + '</span>')
                        .show();
                }
            });
        },

        /**
         * Format CORS status for display
         */
        formatCORSStatus: function (analysis, upload) {
            if (!analysis.has_cors) {
                return '<span class="s3-cors-none" title="No CORS configuration">No CORS</span>';
            }

            var statusClass = upload.allows_upload ? 's3-cors-good' : 's3-cors-limited';
            var statusText = upload.allows_upload ? 'Upload OK' : 'Limited';
            var titleText = upload.allows_upload
                ? 'CORS allows uploads from this domain'
                : 'CORS configured but uploads not allowed from this domain';

            return '<span class="' + statusClass + '" title="' + titleText + '">' + statusText + '</span>';
        },

        /**
         * Show detailed CORS information modal
         */
        showCORSInfo: function (bucket) {
            var self = this;

            this.showProgressOverlay('Loading CORS information...');

            this.makeAjaxRequest('s3_get_cors_info_', {
                bucket: bucket
            }, {
                success: function (response) {
                    self.hideProgressOverlay();
                    self.displayCORSInfoModal(bucket, response.data);
                },
                error: function (message) {
                    self.hideProgressOverlay();
                    self.showNotification('Failed to load CORS information: ' + message, 'error');
                }
            });
        },

        /**
         * Display CORS information in a modal
         */
        displayCORSInfoModal: function (bucket, corsData) {
            var self = this;
            var analysis = corsData.analysis;
            var upload = corsData.upload_capability;

            var content = this.buildCORSInfoContent(bucket, analysis, upload);

            var buttons = [
                {
                    text: 'Close',
                    action: 'close',
                    classes: 'button-secondary',
                    callback: function () {
                        self.hideModal('s3CORSInfoModal');
                    }
                }
            ];

            // Add setup button if uploads not working
            if (!upload.allows_upload) {
                buttons.unshift({
                    text: 'Setup CORS for Uploads',
                    action: 'setup',
                    classes: 'button-primary',
                    callback: function () {
                        self.hideModal('s3CORSInfoModal');
                        setTimeout(function () {
                            self.showCORSSetupModal(bucket);
                        }, 200);
                    }
                });
            }

            this.showModal('s3CORSInfoModal', 'CORS Information: ' + bucket, content, buttons);
        },

        /**
         * Build CORS information content
         */
        buildCORSInfoContent: function (bucket, analysis, upload) {
            var content = '<div class="s3-cors-info-content">';

            // Upload capability section
            content += '<div class="s3-cors-section">';
            content += '<h4>Upload Capability</h4>';
            content += '<table class="s3-cors-table">';
            content += '<tr><td><strong>Current Domain:</strong></td><td>' + this.escapeHtml(upload.current_origin) + '</td></tr>';
            content += '<tr><td><strong>Upload Allowed:</strong></td><td>';

            if (upload.allows_upload) {
                content += '<span class="s3-cors-status-good">✓ Yes</span>';
                if (upload.allowed_methods && upload.allowed_methods.length > 0) {
                    content += ' (' + upload.allowed_methods.join(', ') + ')';
                }
            } else {
                content += '<span class="s3-cors-status-bad">✗ No</span>';
            }

            content += '</td></tr>';
            content += '<tr><td colspan="2"><small>' + this.escapeHtml(upload.details) + '</small></td></tr>';
            content += '</table>';
            content += '</div>';

            // CORS configuration section
            content += '<div class="s3-cors-section">';
            content += '<h4>CORS Configuration</h4>';
            content += '<table class="s3-cors-table">';
            content += '<tr><td><strong>Has CORS:</strong></td><td>' + (analysis.has_cors ? 'Yes' : 'No') + '</td></tr>';

            if (analysis.has_cors) {
                content += '<tr><td><strong>Rules Count:</strong></td><td>' + analysis.rules_count + '</td></tr>';
                content += '<tr><td><strong>Capabilities:</strong></td><td>';

                var capabilities = [];
                if (analysis.supports_public_read) capabilities.push('Read');
                if (analysis.supports_upload) capabilities.push('Upload');
                if (analysis.supports_delete) capabilities.push('Delete');

                content += capabilities.length > 0 ? capabilities.join(', ') : 'None';
                content += '</td></tr>';

                if (analysis.allows_all_origins) {
                    content += '<tr><td><strong>Security:</strong></td><td><span class="s3-cors-warning">Allows all origins (*)</span></td></tr>';
                }
            }

            content += '</table>';
            content += '</div>';

            // Recommendations section
            if (analysis.recommendations && analysis.recommendations.length > 0) {
                content += '<div class="s3-cors-section">';
                content += '<h4>Recommendations</h4>';
                content += '<ul class="s3-cors-recommendations">';
                var self = this;
                analysis.recommendations.forEach(function (rec) {
                    content += '<li>' + self.escapeHtml(rec) + '</li>';
                });
                content += '</ul>';
                content += '</div>';
            }

            // Security warnings
            if (analysis.security_warnings && analysis.security_warnings.length > 0) {
                content += '<div class="s3-cors-section s3-cors-warnings">';
                content += '<h4>Security Warnings</h4>';
                content += '<ul>';
                var self = this;
                analysis.security_warnings.forEach(function (warning) {
                    content += '<li>' + self.escapeHtml(warning) + '</li>';
                });
                content += '</ul>';
                content += '</div>';
            }

            content += '</div>';

            return content;
        },

        /**
         * Show CORS setup modal for uploads
         */
        showCORSSetupModal: function (bucket) {
            var self = this;
            var currentOrigin = window.location.protocol + '//' + window.location.host;

            var content = [
                '<div class="s3-cors-setup-content">',
                '<p>This will configure CORS to allow file uploads from your current domain to the bucket.</p>',
                '<div class="s3-cors-setup-details">',
                '<h4>Configuration Details:</h4>',
                '<ul>',
                '<li><strong>Allowed Origin:</strong> ' + this.escapeHtml(currentOrigin) + '</li>',
                '<li><strong>Allowed Methods:</strong> PUT (for uploads)</li>',
                '<li><strong>Headers:</strong> Content-Type, Content-Length</li>',
                '<li><strong>Cache Time:</strong> 1 hour</li>',
                '</ul>',
                '</div>',
                '<div class="s3-cors-setup-warning">',
                '<p><strong>Note:</strong> This will replace any existing CORS configuration on this bucket. The configuration is minimal and focused only on upload functionality.</p>',
                '</div>',
                '</div>'
            ].join('');

            var $modal = this.showModal('s3CORSSetupModal', 'Setup CORS for Uploads: ' + bucket, content, [
                {
                    text: 'Cancel',
                    action: 'cancel',
                    callback: function () {
                        self.hideModal('s3CORSSetupModal');
                    }
                },
                {
                    text: 'Setup CORS',
                    action: 'setup',
                    classes: 'button-primary',
                    callback: function () {
                        self.executeCORSSetup(bucket, currentOrigin);
                    }
                }
            ]);
        },

        /**
         * Execute CORS setup
         */
        executeCORSSetup: function (bucket, origin) {
            var self = this;

            this.setModalLoading('s3CORSSetupModal', true, 'Configuring CORS...');

            this.makeAjaxRequest('s3_setup_cors_upload_', {
                bucket: bucket,
                origin: origin
            }, {
                success: function (response) {
                    self.setModalLoading('s3CORSSetupModal', false);

                    var successMessage = response.data.verification_passed
                        ? 'CORS configured successfully! Uploads should now work.'
                        : 'CORS configured, but verification failed. Please check manually.';

                    self.showNotification(successMessage, 'success');
                    self.hideModal('s3CORSSetupModal');

                    // Reload CORS status
                    setTimeout(function () {
                        self.loadCORSStatus();
                    }, 1000);
                },
                error: function (message) {
                    self.showModalError('s3CORSSetupModal', message);
                }
            });
        },

        /**
         * Escape HTML for safe display
         */
        escapeHtml: function (text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

    });

})(jQuery);