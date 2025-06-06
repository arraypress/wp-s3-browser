/**
 * S3 Browser - Buckets Table JavaScript
 * Handles Browse and Details actions for the simplified buckets table
 */
(function ($) {
    'use strict';

    // Extend the main S3Browser object with bucket methods
    $.extend(window.S3Browser, {

        /**
         * Bind bucket-related event handlers
         */
        bindBucketEvents: function () {
            var self = this;

            // Browse bucket action (both button and row action)
            $(document).off('click.s3browse').on('click.s3browse', '.browse-bucket-button, .bucket-name', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var $button = $(this);
                var bucket = $button.data('bucket');
                if (bucket) {
                    self.browseBucket(bucket);
                }
            });

            // Bucket details action
            $(document).off('click.s3details').on('click.s3details', '.s3-bucket-details', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var $button = $(this);
                var bucket = $button.data('bucket');
                var provider = $button.data('provider');
                if (bucket) {
                    self.showBucketDetails(bucket, provider);
                }
            });

            // Existing favorite star functionality (keep as-is)
            $(document).off('click.s3favorite').on('click.s3favorite', '.s3-favorite-bucket', function (e) {
                e.preventDefault();
                e.stopPropagation();
                self.toggleFavoriteBucket($(this));
            });
        },

        /**
         * Browse bucket - navigate to bucket contents
         */
        browseBucket: function (bucket) {
            this.navigateTo({
                bucket: bucket,
                prefix: ''
            });
        },

        /**
         * Show comprehensive bucket details modal
         */
        showBucketDetails: function (bucket, provider) {
            var self = this;

            this.showProgressOverlay('Loading bucket details...');

            // Load bucket details including CORS info
            this.makeAjaxRequest('s3_get_bucket_details_', {
                bucket: bucket,
                provider: provider || S3BrowserGlobalConfig.providerId
            }, {
                success: function (response) {
                    self.hideProgressOverlay();
                    self.displayBucketDetailsModal(bucket, response.data);
                },
                error: function (message) {
                    self.hideProgressOverlay();
                    self.showNotification('Failed to load bucket details: ' + message, 'error');
                }
            });
        },

        /**
         * Display bucket details modal
         */
        displayBucketDetailsModal: function (bucket, data) {
            var self = this;
            var content = this.buildBucketDetailsContent(bucket, data);

            var buttons = [
                {
                    text: 'Close',
                    action: 'close',
                    classes: 'button-secondary',
                    callback: function () {
                        self.hideModal('s3BucketDetailsModal');
                    }
                }
            ];

            // Add CORS setup button if CORS is not properly configured
            if (data.cors && !data.cors.upload_ready) {
                buttons.unshift({
                    text: 'Setup CORS',
                    action: 'setup_cors',
                    classes: 'button-primary',
                    callback: function () {
                        self.hideModal('s3BucketDetailsModal');
                        // Fallback to direct AJAX call if showCORSSetupModal doesn't exist
                        setTimeout(function () {
                            if (window.S3Browser && typeof window.S3Browser.showCORSSetupModal === 'function') {
                                window.S3Browser.showCORSSetupModal(bucket);
                            } else {
                                self.setupCORSDirectly(bucket);
                            }
                        }, 200);
                    }
                });
            }

            // Add CORS revoke button if CORS exists
            if (data.cors && data.cors.analysis && data.cors.analysis.has_cors) {
                buttons.splice(-1, 0, {
                    text: 'Revoke CORS Rules',
                    action: 'revoke_cors',
                    classes: 'button-secondary button-destructive',
                    callback: function () {
                        self.hideModal('s3BucketDetailsModal');
                        setTimeout(function () {
                            self.confirmRevokeCORS(bucket);
                        }, 200);
                    }
                });
            }

            // Add browse button
            buttons.unshift({
                text: 'Browse Bucket',
                action: 'browse',
                classes: 'button-primary',
                callback: function () {
                    self.hideModal('s3BucketDetailsModal');
                    self.browseBucket(bucket);
                }
            });

            this.showModal('s3BucketDetailsModal', 'Bucket Details: ' + bucket, content, buttons);
        },

        /**
         * Build bucket details content
         */
        buildBucketDetailsContent: function (bucket, data) {
            var self = this; // Capture 'this' reference at the beginning
            var content = '<div class="s3-bucket-details-content">';

            // Basic bucket information
            content += '<div class="s3-details-section">';
            content += '<h4>Bucket Information</h4>';
            content += '<table class="s3-details-table">';
            content += '<tr><td><strong>Bucket Name:</strong></td><td><code>' + self.escapeHtml(bucket) + '</code></td></tr>';

            if (data.basic) {
                if (data.basic.region) {
                    content += '<tr><td><strong>Region:</strong></td><td>' + self.escapeHtml(data.basic.region) + '</td></tr>';
                }
                if (data.basic.created) {
                    content += '<tr><td><strong>Created:</strong></td><td>' + self.escapeHtml(data.basic.created) + '</td></tr>';
                }
            }

            content += '<tr><td><strong>Provider:</strong></td><td>' + (S3BrowserGlobalConfig.providerName || 'S3 Compatible') + '</td></tr>';
            content += '</table>';
            content += '</div>';

            // Upload capability section
            if (data.cors) {
                content += '<div class="s3-details-section">';
                content += '<h4>Upload Capability</h4>';
                content += '<table class="s3-details-table">';
                content += '<tr><td><strong>Upload Ready:</strong></td><td>';

                if (data.cors.upload_ready) {
                    content += '<span style="color: #00a32a; font-weight: 600;">✓ Yes</span>';
                } else {
                    content += '<span style="color: #d63638; font-weight: 600;">✗ No</span>';
                }

                content += '</td></tr>';
                content += '<tr><td><strong>Current Domain:</strong></td><td>' + self.escapeHtml(data.cors.current_origin || window.location.origin) + '</td></tr>';

                if (data.cors.details) {
                    content += '<tr><td colspan="2"><small>' + self.escapeHtml(data.cors.details) + '</small></td></tr>';
                }

                content += '</table>';
                content += '</div>';
            }

            // CORS configuration summary
            if (data.cors && data.cors.analysis) {
                var analysis = data.cors.analysis;
                content += '<div class="s3-details-section">';
                content += '<h4>CORS Configuration</h4>';
                content += '<table class="s3-details-table">';
                content += '<tr><td><strong>Has CORS:</strong></td><td>' + (analysis.has_cors ? 'Yes' : 'No') + '</td></tr>';

                if (analysis.has_cors) {
                    content += '<tr><td><strong>Rules Count:</strong></td><td>' + (analysis.rules_count || 0) + '</td></tr>';

                    if (analysis.security_warnings && analysis.security_warnings.length > 0) {
                        content += '<tr><td><strong>Security Warnings:</strong></td><td>';
                        content += '<span style="color: #dba617;">' + analysis.security_warnings.length + ' warning(s)</span>';
                        content += '</td></tr>';
                    }
                }

                content += '</table>';
                content += '</div>';
            }

            // Permissions summary (if available)
            if (data.permissions) {
                content += '<div class="s3-details-section">';
                content += '<h4>Permissions</h4>';
                content += '<table class="s3-details-table">';
                content += '<tr><td><strong>Read Access:</strong></td><td>' + (data.permissions.read ? '✓ Yes' : '✗ No') + '</td></tr>';
                content += '<tr><td><strong>Write Access:</strong></td><td>' + (data.permissions.write ? '✓ Yes' : '✗ No') + '</td></tr>';
                content += '<tr><td><strong>Delete Access:</strong></td><td>' + (data.permissions.delete ? '✓ Yes' : '✗ No') + '</td></tr>';
                content += '</table>';
                content += '</div>';
            }

            // Recommendations
            if (data.cors && data.cors.analysis && data.cors.analysis.recommendations) {
                content += '<div class="s3-details-section">';
                content += '<h4>Recommendations</h4>';
                content += '<ul style="margin: 8px 0; padding-left: 20px;">';
                data.cors.analysis.recommendations.forEach(function (rec) {
                    content += '<li style="margin-bottom: 4px; font-size: 13px;">' + self.escapeHtml(rec) + '</li>';
                });
                content += '</ul>';
                content += '</div>';
            }

            content += '</div>';

            return content;
        },

        /**
         * Direct CORS setup fallback method
         */
        setupCORSDirectly: function(bucket) {
            var self = this;

            // Show confirmation dialog
            var confirmMessage = [
                'Set up CORS (Cross-Origin Resource Sharing) for bucket "' + bucket + '"?',
                '',
                'This will:',
                '• Enable file uploads from web browsers',
                '• Allow cross-origin access from this domain: ' + window.location.origin,
                '• Configure secure upload permissions',
                '',
                'This is required for the upload functionality to work properly.'
            ].join('\n');

            if (!confirm(confirmMessage)) {
                return;
            }

            // Show progress
            this.showProgressOverlay('Setting up CORS configuration...');

            // Make the CORS setup request
            this.makeAjaxRequest('s3_setup_cors_', {
                bucket: bucket,
                origin: window.location.origin
            }, {
                success: function (response) {
                    self.hideProgressOverlay();
                    self.showNotification(
                        response.data.message || 'CORS successfully configured for bucket "' + bucket + '"',
                        'success'
                    );

                    // Reload the page after a short delay to reflect changes
                    setTimeout(function () {
                        window.location.reload();
                    }, 2000);
                },
                error: function (message) {
                    self.hideProgressOverlay();
                    self.showNotification('Failed to setup CORS: ' + message, 'error');

                    // Show manual setup instructions
                    setTimeout(function () {
                        self.showManualCORSInstructions(bucket);
                    }, 1000);
                }
            });
        },

        /**
         * Show manual CORS setup instructions (S3-provider agnostic)
         */
        showManualCORSInstructions: function(bucket) {
            var providerName = S3BrowserGlobalConfig.providerName || 'S3 Compatible Provider';

            var content = [
                '<div class="s3-cors-setup-content">',
                '<p><strong>Automatic CORS setup failed.</strong> You can set up CORS manually through your ' + providerName + ' console or API.</p>',

                '<div class="s3-cors-setup-details">',
                '<h4>Required CORS Configuration:</h4>',
                '<p>Add this minimal CORS rule to bucket <code>' + bucket + '</code>:</p>',
                '<textarea readonly style="width: 100%; height: 140px; font-family: monospace; font-size: 12px;">',
                '{',
                '  "ID": "UploadFromBrowser",',
                '  "AllowedOrigins": ["' + window.location.origin + '"],',
                '  "AllowedMethods": ["PUT"],',
                '  "AllowedHeaders": ["Content-Type", "Content-Length"],',
                '  "MaxAgeSeconds": 3600',
                '}',
                '</textarea>',
                '</div>',

                '<div class="s3-cors-setup-details">',
                '<h4>What This Rule Does:</h4>',
                '<ul>',
                '<li><strong>PUT method only:</strong> Enables secure file uploads via presigned URLs</li>',
                '<li><strong>Minimal headers:</strong> Only Content-Type and Content-Length for security</li>',
                '<li><strong>Single origin:</strong> Restricts access to your domain only</li>',
                '<li><strong>1-hour cache:</strong> Reduces preflight requests</li>',
                '</ul>',
                '</div>',

                '<div class="s3-cors-setup-warning">',
                '<p><strong>Note:</strong> This configuration is optimized for browser uploads only. All other operations (delete, list, etc.) are handled server-side and don\'t require additional CORS permissions.</p>',
                '</div>',
                '</div>'
            ].join('');

            this.showModal('s3CORSManualSetupModal', 'Manual CORS Setup Instructions', content, [
                {
                    text: 'Close',
                    action: 'close',
                    classes: 'button-secondary',
                    callback: function () {
                        window.S3Browser.hideModal('s3CORSManualSetupModal');
                    }
                },
                {
                    text: 'Refresh Page',
                    action: 'refresh',
                    classes: 'button-primary',
                    callback: function () {
                        window.location.reload();
                    }
                }
            ]);
        },

        /**
         * Confirm CORS revocation with detailed warning
         */
        confirmRevokeCORS: function (bucket) {
            var confirmMessage = [
                'Are you sure you want to revoke all CORS rules for bucket "' + bucket + '"?',
                '',
                'This will:',
                '• Disable file uploads from web browsers',
                '• Prevent cross-origin access to bucket resources',
                '• Require manual CORS reconfiguration to restore upload capability',
                '',
                'This action cannot be undone automatically.'
            ].join('\n');

            if (confirm(confirmMessage)) {
                this.revokeCORSRules(bucket);
            }
        },

        /**
         * Revoke CORS rules via AJAX
         */
        revokeCORSRules: function (bucket) {
            var self = this;

            this.showProgressOverlay('Revoking CORS rules...');

            this.makeAjaxRequest('s3_delete_cors_configuration_', {
                bucket: bucket
            }, {
                success: function (response) {
                    self.hideProgressOverlay();
                    self.showNotification('CORS rules successfully revoked for bucket "' + bucket + '"', 'success');

                    setTimeout(function () {
                        window.location.reload();
                    }, 2000);
                },
                error: function (message) {
                    self.hideProgressOverlay();
                    self.showNotification('Failed to revoke CORS rules: ' + message, 'error');
                }
            });
        },

        /**
         * Escape HTML for safe display
         */
        escapeHtml: function (text) {
            if (!text) return '';
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

    });

})(jQuery);