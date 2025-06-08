/**
 * S3 Browser - Buckets Table JavaScript (Cleaned Up)
 * Handles Browse and Details actions for the simplified buckets table
 * All strings moved to PHP translations, all styles moved to CSS
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
        /**
         * Debug version - Show comprehensive bucket details modal
         */
        showBucketDetails: function (bucket, provider) {
            var self = this;

            this.showProgressOverlay(s3BrowserConfig.i18n.buckets.loadingDetails);

            // Load bucket details including CORS info
            this.makeAjaxRequest('s3_get_bucket_details_', {
                bucket: bucket,
                provider: provider || S3BrowserGlobalConfig.providerId,
                current_origin: window.location.origin // Explicitly pass origin
            }, {
                success: function (response) {
                    self.hideProgressOverlay();

                    // DEBUG: Log the full response
                    console.log('=== BUCKET DETAILS DEBUG ===');
                    console.log('Bucket:', bucket);
                    console.log('Full Response:', response);
                    console.log('Response Data:', response.data);

                    if (response.data && response.data.cors) {
                        console.log('CORS Data:', response.data.cors);
                        console.log('CORS Analysis:', response.data.cors.analysis);
                        console.log('Upload Ready:', response.data.cors.upload_ready);
                        console.log('Current Origin:', response.data.cors.current_origin);
                        console.log('Window Origin:', window.location.origin);
                    }

                    if (response.data && response.data.debug) {
                        console.log('Debug Info:', response.data.debug);
                    }
                    console.log('=== END DEBUG ===');

                    self.displayBucketDetailsModal(bucket, response.data);
                },
                error: function (message) {
                    self.hideProgressOverlay();
                    console.error('Bucket details error:', message);
                    self.showNotification(s3BrowserConfig.i18n.buckets.loadDetailsError.replace('{message}', message), 'error');
                }
            });
        },

        /**
         * Display bucket details modal with fixed button logic
         */
        /**
         * Debug version - Display bucket details modal
         */
        displayBucketDetailsModal: function (bucket, data) {
            var self = this;

            // DEBUG: Log button decision logic
            console.log('=== BUTTON LOGIC DEBUG ===');
            console.log('Data:', data);

            var hasCORS = data.cors && data.cors.analysis && data.cors.analysis.has_cors;
            var uploadReady = data.cors && data.cors.upload_ready;

            console.log('Has CORS:', hasCORS);
            console.log('Upload Ready:', uploadReady);

            if (data.cors && data.cors.analysis) {
                console.log('CORS Analysis has_cors:', data.cors.analysis.has_cors);
            }

            console.log('=== END BUTTON DEBUG ===');

            var content = this.buildBucketDetailsContent(bucket, data);

            var buttons = [
                {
                    text: s3BrowserConfig.i18n.ui.close,
                    action: 'close',
                    classes: 'button-secondary',
                    callback: function () {
                        self.hideModal('s3BucketDetailsModal');
                    }
                }
            ];

            // Simplified button logic for debugging
            if (hasCORS) {
                console.log('Adding REVOKE button because hasCORS =', hasCORS);
                buttons.splice(-1, 0, {
                    text: s3BrowserConfig.i18n.buckets.revokeCorsRules,
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

            if (!uploadReady) {
                console.log('Adding SETUP button because uploadReady =', uploadReady);
                buttons.splice(-1, 0, {
                    text: s3BrowserConfig.i18n.cors.corsSetup,
                    action: 'setup_cors',
                    classes: 'button-primary',
                    callback: function () {
                        self.hideModal('s3BucketDetailsModal');
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

            // Always add browse button at the beginning
            buttons.unshift({
                text: s3BrowserConfig.i18n.buckets.browseBucket,
                action: 'browse',
                classes: 'button-primary',
                callback: function () {
                    self.hideModal('s3BucketDetailsModal');
                    self.browseBucket(bucket);
                }
            });

            console.log('Final buttons array:', buttons.map(function(b) { return b.text; }));

            this.showModal('s3BucketDetailsModal', s3BrowserConfig.i18n.buckets.detailsTitle.replace('{bucket}', bucket), content, buttons);
        },

        /**
         * Build bucket details content
         */
        buildBucketDetailsContent: function (bucket, data) {
            var self = this;
            var i18n = s3BrowserConfig.i18n.buckets;
            var content = '<div class="s3-bucket-details-content">';

            // Basic bucket information
            content += '<div class="s3-details-section">';
            content += '<h4>' + i18n.bucketInformation + '</h4>';
            content += '<table class="s3-details-table">';
            content += '<tr><td><strong>' + i18n.bucketName + '</strong></td><td><code>' + self.escapeHtml(bucket) + '</code></td></tr>';

            if (data.basic) {
                if (data.basic.region) {
                    content += '<tr><td><strong>' + i18n.region + '</strong></td><td>' + self.escapeHtml(data.basic.region) + '</td></tr>';
                }
                if (data.basic.created) {
                    content += '<tr><td><strong>' + i18n.created + '</strong></td><td>' + self.escapeHtml(data.basic.created) + '</td></tr>';
                }
            }

            content += '<tr><td><strong>' + i18n.provider + '</strong></td><td>' + (S3BrowserGlobalConfig.providerName || i18n.s3Compatible) + '</td></tr>';
            content += '</table>';
            content += '</div>';

            // Upload capability section
            if (data.cors) {
                content += '<div class="s3-details-section">';
                content += '<h4>' + i18n.uploadCapability + '</h4>';
                content += '<table class="s3-details-table">';
                content += '<tr><td><strong>' + i18n.uploadReady + '</strong></td><td>';

                if (data.cors.upload_ready) {
                    content += '<span class="s3-status-success">✓ ' + i18n.yes + '</span>';
                } else {
                    content += '<span class="s3-status-error">✗ ' + i18n.no + '</span>';
                }

                content += '</td></tr>';
                content += '<tr><td><strong>' + i18n.currentDomain + '</strong></td><td>' + self.escapeHtml(data.cors.current_origin || window.location.origin) + '</td></tr>';

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
                content += '<h4>' + i18n.corsConfiguration + '</h4>';
                content += '<table class="s3-details-table">';
                content += '<tr><td><strong>' + i18n.hasCors + '</strong></td><td>' + (analysis.has_cors ? i18n.yes : i18n.no) + '</td></tr>';

                if (analysis.has_cors) {
                    content += '<tr><td><strong>' + i18n.rulesCount + '</strong></td><td>' + (analysis.rules_count || 0) + '</td></tr>';

                    if (analysis.security_warnings && analysis.security_warnings.length > 0) {
                        content += '<tr><td><strong>' + i18n.securityWarnings + '</strong></td><td>';
                        content += '<span class="s3-status-warning">' + i18n.warningCount.replace('{count}', analysis.security_warnings.length) + '</span>';
                        content += '</td></tr>';
                    }
                }

                content += '</table>';
                content += '</div>';
            }

            // Permissions summary (if available)
            if (data.permissions) {
                content += '<div class="s3-details-section">';
                content += '<h4>' + i18n.permissions + '</h4>';
                content += '<table class="s3-details-table">';
                content += '<tr><td><strong>' + i18n.readAccess + '</strong></td><td>' + (data.permissions.read ? '✓ ' + i18n.yes : '✗ ' + i18n.no) + '</td></tr>';
                content += '<tr><td><strong>' + i18n.writeAccess + '</strong></td><td>' + (data.permissions.write ? '✓ ' + i18n.yes : '✗ ' + i18n.no) + '</td></tr>';
                content += '<tr><td><strong>' + i18n.deleteAccess + '</strong></td><td>' + (data.permissions.delete ? '✓ ' + i18n.yes : '✗ ' + i18n.no) + '</td></tr>';
                content += '</table>';
                content += '</div>';
            }

            // Recommendations
            if (data.cors && data.cors.analysis && data.cors.analysis.recommendations) {
                content += '<div class="s3-details-section">';
                content += '<h4>' + i18n.recommendations + '</h4>';
                content += '<ul class="s3-recommendations-list">';
                data.cors.analysis.recommendations.forEach(function (rec) {
                    content += '<li>' + self.escapeHtml(rec) + '</li>';
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
        setupCORSDirectly: function (bucket) {
            var self = this;
            var i18n = s3BrowserConfig.i18n.buckets;

            var confirmMessage = i18n.corsSetupConfirm
                .replace('{bucket}', bucket)
                .replace('{origin}', window.location.origin);

            if (!confirm(confirmMessage)) {
                return;
            }

            // Show progress
            this.showProgressOverlay(i18n.settingUpCors);

            // Make the CORS setup request
            this.makeAjaxRequest('s3_setup_cors_', {
                bucket: bucket,
                origin: window.location.origin
            }, {
                success: function (response) {
                    self.hideProgressOverlay();
                    self.showNotification(
                        response.data.message || i18n.corsSetupSuccess.replace('{bucket}', bucket),
                        'success'
                    );

                    // Reload the page after a short delay to reflect changes
                    setTimeout(function () {
                        window.location.reload();
                    }, 2000);
                },
                error: function (message) {
                    self.hideProgressOverlay();
                    self.showNotification(i18n.corsSetupError.replace('{message}', message), 'error');

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
        showManualCORSInstructions: function (bucket) {
            var i18n = s3BrowserConfig.i18n.buckets;
            var providerName = S3BrowserGlobalConfig.providerName || i18n.s3CompatibleProvider;

            var content = [
                '<div class="s3-cors-setup-content">',
                '<p><strong>' + i18n.autoSetupFailed + '</strong> ' + i18n.manualSetupInstruction.replace('{provider}', providerName) + '</p>',

                '<div class="s3-cors-setup-details">',
                '<h4>' + i18n.requiredCorsConfig + '</h4>',
                '<p>' + i18n.addCorsRule.replace('{bucket}', bucket) + '</p>',
                '<textarea readonly class="s3-cors-config-textarea">',
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
                '<h4>' + i18n.whatRuleDoes + '</h4>',
                '<ul class="s3-cors-rule-list">',
                '<li><strong>' + i18n.putMethodOnly + '</strong> ' + i18n.putMethodDesc + '</li>',
                '<li><strong>' + i18n.minimalHeaders + '</strong> ' + i18n.minimalHeadersDesc + '</li>',
                '<li><strong>' + i18n.singleOrigin + '</strong> ' + i18n.singleOriginDesc + '</li>',
                '<li><strong>' + i18n.oneHourCache + '</strong> ' + i18n.oneHourCacheDesc + '</li>',
                '</ul>',
                '</div>',

                '<div class="s3-cors-setup-warning">',
                '<p><strong>' + i18n.note + '</strong> ' + i18n.configOptimized + '</p>',
                '</div>',
                '</div>'
            ].join('');

            this.showModal('s3CORSManualSetupModal', i18n.manualCorsSetup, content, [
                {
                    text: s3BrowserConfig.i18n.ui.close,
                    action: 'close',
                    classes: 'button-secondary',
                    callback: function () {
                        window.S3Browser.hideModal('s3CORSManualSetupModal');
                    }
                },
                {
                    text: i18n.refreshPage,
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
            var i18n = s3BrowserConfig.i18n.buckets;
            var confirmMessage = i18n.revokeConfirm.replace('{bucket}', bucket);

            if (confirm(confirmMessage)) {
                this.revokeCORSRules(bucket);
            }
        },

        /**
         * Revoke CORS rules via AJAX
         */
        revokeCORSRules: function (bucket) {
            var self = this;
            var i18n = s3BrowserConfig.i18n.buckets;

            this.showProgressOverlay(i18n.revokingCors);

            this.makeAjaxRequest('s3_delete_cors_configuration_', {
                bucket: bucket
            }, {
                success: function (response) {
                    self.hideProgressOverlay();
                    self.showNotification(i18n.revokeSuccess.replace('{bucket}', bucket), 'success');

                    setTimeout(function () {
                        window.location.reload();
                    }, 2000);
                },
                error: function (message) {
                    self.hideProgressOverlay();
                    self.showNotification(i18n.revokeError.replace('{message}', message), 'error');
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