/**
 * S3 Browser Buckets - Enhanced bucket operations with comprehensive CORS management
 * Handles bucket browsing, details, CORS configuration, and management
 */
(function ($) {
    'use strict';

    // Buckets namespace
    window.S3Buckets = {

        // Bucket state management
        state: {
            currentBucket: null,
            bucketDetails: new Map(),
            corsCache: new Map(),
            isAnalyzing: false
        },

        // ===========================================
        // INITIALIZATION & EVENT BINDING
        // ===========================================

        /**
         * Initialize buckets functionality
         */
        init: function () {
            this.bindBucketEvents();
        },

        /**
         * Bind bucket-related event handlers
         */
        bindBucketEvents: function () {
            // Browse bucket actions (both button and row title)
            S3.on('.browse-bucket-button, .bucket-name', 'click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const bucket = $(e.target).data('bucket');
                if (bucket) {
                    this.browseBucket(bucket);
                }
            });

            // Bucket details action
            S3.on('.s3-bucket-details', 'click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const $button = $(e.target);
                const bucket = $button.data('bucket');
                const provider = $button.data('provider');
                if (bucket) {
                    this.showBucketDetails(bucket, provider);
                }
            });

            // Favorite toggle (delegate to integrations)
            S3.on('.s3-favorite-bucket, .s3-favorite-star', 'click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.toggleFavoriteBucket($(e.target));
            });
        },

        // ===========================================
        // BUCKET NAVIGATION & BROWSING
        // ===========================================

        /**
         * Browse bucket with enhanced navigation
         */
        browseBucket: function (bucket) {
            if (!bucket) {
                S3.notify('Invalid bucket name', 'error');
                return;
            }

            this.state.currentBucket = bucket;
            S3.navigate({ bucket, prefix: '' });
        },

        /**
         * Enhanced bucket details with comprehensive information
         */
        showBucketDetails: function (bucket, provider) {
            if (!bucket) {
                S3.notify('Bucket name is required', 'error');
                return;
            }

            // Show loading state
            const loadingModal = S3M.progress(
                'Loading Bucket Details',
                'Gathering comprehensive bucket information...'
            );

            this.loadBucketDetails(bucket, provider)
                .then(data => {
                    loadingModal.close();
                    this.displayBucketDetailsModal(bucket, data);
                })
                .catch(error => {
                    loadingModal.close();
                    S3.notify(`Failed to load bucket details: ${error.message}`, 'error');
                });
        },

        /**
         * Load comprehensive bucket details
         */
        loadBucketDetails: function (bucket, provider) {
            return new Promise((resolve, reject) => {
                // Check cache first
                const cacheKey = `${bucket}_${provider || S3BrowserGlobalConfig.providerId}`;
                if (this.state.bucketDetails.has(cacheKey)) {
                    resolve(this.state.bucketDetails.get(cacheKey));
                    return;
                }

                S3.ajax('s3_get_bucket_details_', {
                    bucket: bucket,
                    provider: provider || S3BrowserGlobalConfig.providerId
                }, {
                    success: (response) => {
                        // Cache the result
                        this.state.bucketDetails.set(cacheKey, response.data);
                        resolve(response.data);
                    },
                    error: (message) => reject(new Error(message))
                });
            });
        },

        /**
         * Display comprehensive bucket details modal
         */
        displayBucketDetailsModal: function (bucket, data) {
            const content = this.buildBucketDetailsContent(bucket, data);
            const buttons = this.buildBucketDetailsButtons(bucket, data);

            S3M.show('s3BucketDetailsModal', `Bucket Details: ${bucket}`, content, buttons, {
                closeOnOverlay: true,
                destroyOnClose: true
            });
        },

        /**
         * Build comprehensive bucket details content
         */
        buildBucketDetailsContent: function (bucket, data) {
            const sections = [];

            // Basic Information Section
            sections.push(this.buildBasicInfoSection(bucket, data.basic || {}));

            // Upload Capability Section
            if (data.cors) {
                sections.push(this.buildUploadCapabilitySection(data.cors));
            }

            // CORS Configuration Section
            if (data.cors?.analysis) {
                sections.push(this.buildCORSConfigSection(data.cors.analysis));
            }

            // Permissions Section
            if (data.permissions) {
                sections.push(this.buildPermissionsSection(data.permissions));
            }

            // Recommendations Section
            if (data.cors?.analysis?.recommendations) {
                sections.push(this.buildRecommendationsSection(data.cors.analysis.recommendations));
            }

            return `<div class="s3-bucket-details-content">${sections.join('')}</div>`;
        },

        /**
         * Build basic information section
         */
        buildBasicInfoSection: function (bucket, basicInfo) {
            return `
                <div class="s3-details-section">
                    <h4>Bucket Information</h4>
                    <table class="s3-details-table">
                        <tr><td><strong>Bucket Name:</strong></td><td><code>${S3.escapeHtml(bucket)}</code></td></tr>
                        ${basicInfo.region ? `<tr><td><strong>Region:</strong></td><td>${S3.escapeHtml(basicInfo.region)}</td></tr>` : ''}
                        ${basicInfo.created ? `<tr><td><strong>Created:</strong></td><td>${S3.escapeHtml(basicInfo.created)}</td></tr>` : ''}
                        <tr><td><strong>Provider:</strong></td><td>${S3BrowserGlobalConfig.providerName || 'S3 Compatible'}</td></tr>
                    </table>
                </div>
            `;
        },

        /**
         * Build upload capability section
         */
        buildUploadCapabilitySection: function (corsData) {
            const uploadReady = corsData.upload_ready || false;
            const currentOrigin = corsData.current_origin || window.location.origin;

            return `
                <div class="s3-details-section">
                    <h4>Upload Capability</h4>
                    <table class="s3-details-table">
                        <tr>
                            <td><strong>Upload Ready:</strong></td>
                            <td>${uploadReady ?
                '<span class="s3-cors-status-good">✓ Enabled</span>' :
                '<span class="s3-cors-status-bad">✗ Disabled</span>'
            }</td>
                        </tr>
                        <tr><td><strong>Current Domain:</strong></td><td><code>${S3.escapeHtml(currentOrigin)}</code></td></tr>
                        ${corsData.details ? `<tr><td colspan="2"><small>${S3.escapeHtml(corsData.details)}</small></td></tr>` : ''}
                    </table>
                </div>
            `;
        },

        /**
         * Build CORS configuration section
         */
        buildCORSConfigSection: function (analysis) {
            let warningsHtml = '';
            if (analysis.security_warnings && analysis.security_warnings.length > 0) {
                warningsHtml = `
                    <tr>
                        <td><strong>Security Warnings:</strong></td>
                        <td><span class="s3-cors-warning">${analysis.security_warnings.length} warning(s)</span></td>
                    </tr>
                `;
            }

            return `
                <div class="s3-details-section">
                    <h4>CORS Configuration</h4>
                    <table class="s3-details-table">
                        <tr><td><strong>Has CORS:</strong></td><td>${analysis.has_cors ? 'Yes' : 'No'}</td></tr>
                        ${analysis.has_cors ? `<tr><td><strong>Rules Count:</strong></td><td>${analysis.rules_count || 0}</td></tr>` : ''}
                        <tr><td><strong>Supports Upload:</strong></td><td>${analysis.supports_upload ? 'Yes' : 'No'}</td></tr>
                        <tr><td><strong>Allows All Origins:</strong></td><td>${analysis.allows_all_origins ? 'Yes' : 'No'}</td></tr>
                        ${warningsHtml}
                    </table>
                </div>
            `;
        },

        /**
         * Build permissions section
         */
        buildPermissionsSection: function (permissions) {
            return `
                <div class="s3-details-section">
                    <h4>Permissions</h4>
                    <table class="s3-details-table">
                        <tr><td><strong>Read Access:</strong></td><td>${permissions.read ? '✓ Yes' : '✗ No'}</td></tr>
                        <tr><td><strong>Write Access:</strong></td><td>${permissions.write ? '✓ Yes' : '✗ No'}</td></tr>
                        <tr><td><strong>Delete Access:</strong></td><td>${permissions.delete ? '✓ Yes' : '✗ No'}</td></tr>
                    </table>
                </div>
            `;
        },

        /**
         * Build recommendations section
         */
        buildRecommendationsSection: function (recommendations) {
            const recommendationsHtml = recommendations
                .map(rec => `<li>${S3.escapeHtml(rec)}</li>`)
                .join('');

            return `
                <div class="s3-details-section">
                    <h4>Recommendations</h4>
                    <ul class="s3-cors-recommendations">${recommendationsHtml}</ul>
                </div>
            `;
        },

        /**
         * Build bucket details modal buttons
         */
        buildBucketDetailsButtons: function (bucket, data) {
            const buttons = [
                {
                    text: 'Browse Bucket',
                    action: 'browse',
                    classes: 'button-primary',
                    callback: () => {
                        S3M.hide('s3BucketDetailsModal');
                        this.browseBucket(bucket);
                    }
                },
                {
                    text: 'Close',
                    action: 'close',
                    classes: 'button-secondary',
                    callback: () => S3M.hide('s3BucketDetailsModal')
                }
            ];

            // Add CORS management button
            if (data.cors) {
                buttons.splice(1, 0, {
                    text: 'Manage CORS',
                    action: 'manage_cors',
                    classes: 'button-secondary',
                    callback: () => {
                        S3M.hide('s3BucketDetailsModal');
                        setTimeout(() => this.showCORSManagementModal(bucket, data.cors), 200);
                    }
                });
            }

            return buttons;
        },

        // ===========================================
        // CORS MANAGEMENT
        // ===========================================

        /**
         * Show comprehensive CORS management modal
         */
        showCORSManagementModal: function (bucket, corsData = null) {
            if (!corsData) {
                // Load CORS data if not provided
                this.loadBucketDetails(bucket)
                    .then(data => this.showCORSManagementModal(bucket, data.cors))
                    .catch(error => S3.notify(`Failed to load CORS data: ${error.message}`, 'error'));
                return;
            }

            const content = this.buildCORSManagementContent(bucket, corsData);
            const buttons = this.buildCORSManagementButtons(bucket, corsData);

            S3M.show('s3CORSManagementModal', `CORS Management: ${bucket}`, content, buttons, {
                closeOnOverlay: false
            });
        },

        /**
         * Build CORS management content
         */
        buildCORSManagementContent: function (bucket, corsData) {
            const analysis = corsData.analysis || {};
            const uploadReady = corsData.upload_ready || false;

            return `
                <div class="s3-cors-management-content">
                    ${this.buildCORSStatusSection(corsData, uploadReady)}
                    ${analysis.has_cors ? this.buildCORSRulesSection(analysis) : ''}
                    ${this.buildCORSActionsSection(bucket, corsData)}
                    ${analysis.security_warnings ? this.buildCORSWarningsSection(analysis.security_warnings) : ''}
                </div>
            `;
        },

        /**
         * Build CORS status section
         */
        buildCORSStatusSection: function (corsData, uploadReady) {
            return `
                <div class="s3-cors-section">
                    <h4>Current Status</h4>
                    <table class="s3-cors-table">
                        <tr>
                            <td><strong>Upload Capability:</strong></td>
                            <td>${uploadReady ?
                '<span class="s3-cors-status-good">✓ Enabled</span>' :
                '<span class="s3-cors-status-bad">✗ Disabled</span>'
            }</td>
                        </tr>
                        <tr>
                            <td><strong>CORS Rules:</strong></td>
                            <td>${corsData.analysis?.has_cors ?
                `<span class="s3-cors-status-good">${corsData.analysis.rules_count || 0} rule(s) configured</span>` :
                '<span class="s3-cors-status-bad">No CORS configuration</span>'
            }</td>
                        </tr>
                        <tr><td><strong>Current Domain:</strong></td><td><code>${corsData.current_origin || window.location.origin}</code></td></tr>
                    </table>
                </div>
            `;
        },

        /**
         * Build CORS rules section
         */
        buildCORSRulesSection: function (analysis) {
            return `
                <div class="s3-cors-section">
                    <h4>Configuration Details</h4>
                    <table class="s3-cors-table">
                        <tr><td><strong>Public Read:</strong></td><td>${analysis.supports_public_read ? 'Yes' : 'No'}</td></tr>
                        <tr><td><strong>Upload Support:</strong></td><td>${analysis.supports_upload ? 'Yes' : 'No'}</td></tr>
                        <tr><td><strong>Delete Support:</strong></td><td>${analysis.supports_delete ? 'Yes' : 'No'}</td></tr>
                        <tr><td><strong>Allows All Origins:</strong></td><td>${analysis.allows_all_origins ? 'Yes' : 'No'}</td></tr>
                        <tr><td><strong>Max Cache Time:</strong></td><td>${analysis.max_cache_time || 0} seconds</td></tr>
                    </table>
                </div>
            `;
        },

        /**
         * Build CORS actions section
         */
        buildCORSActionsSection: function (bucket, corsData) {
            const hasRules = corsData.analysis?.has_cors || false;
            const uploadReady = corsData.upload_ready || false;

            return `
                <div class="s3-cors-section">
                    <h4>Available Actions</h4>
                    ${!uploadReady ? '<p><strong>Setup CORS:</strong> Configure minimal CORS rules to enable file uploads from this domain.</p>' : ''}
                    ${hasRules ? '<p><strong>Revoke CORS Rules:</strong> Remove all CORS configuration. This will disable cross-origin access to the bucket.</p>' : ''}
                    <p><strong>Advanced Configuration:</strong> View detailed CORS information and customize rules.</p>
                    ${hasRules ? '<p><strong>Analyze Configuration:</strong> Get detailed analysis of current CORS setup with security recommendations.</p>' : ''}
                </div>
            `;
        },

        /**
         * Build CORS warnings section
         */
        buildCORSWarningsSection: function (warnings) {
            const warningsHtml = warnings
                .map(warning => `<li>${S3.escapeHtml(warning)}</li>`)
                .join('');

            return `
                <div class="s3-cors-warnings">
                    <h4>Security Warnings</h4>
                    <ul>${warningsHtml}</ul>
                </div>
            `;
        },

        /**
         * Build CORS management buttons
         */
        buildCORSManagementButtons: function (bucket, corsData) {
            const hasRules = corsData.analysis?.has_cors || false;
            const uploadReady = corsData.upload_ready || false;

            const buttons = [
                {
                    text: 'Close',
                    action: 'close',
                    classes: 'button-secondary',
                    callback: () => S3M.hide('s3CORSManagementModal')
                }
            ];

            // Setup CORS button (if not upload ready)
            if (!uploadReady) {
                buttons.unshift({
                    text: 'Setup CORS for Uploads',
                    action: 'setup',
                    classes: 'button-primary',
                    callback: () => this.setupCORSForUploads(bucket)
                });
            }

            // Revoke CORS button (if has rules)
            if (hasRules) {
                buttons.splice(-1, 0, {
                    text: 'Revoke CORS Rules',
                    action: 'revoke',
                    classes: 'button-secondary button-destructive',
                    callback: () => this.confirmRevokeCORS(bucket)
                });
            }

            // Advanced configuration button
            buttons.splice(-1, 0, {
                text: 'Advanced Analysis',
                action: 'advanced',
                classes: 'button-secondary',
                callback: () => this.showAdvancedCORSAnalysis(bucket, corsData)
            });

            return buttons;
        },

        // ===========================================
        // CORS OPERATIONS
        // ===========================================

        /**
         * Setup CORS for uploads with comprehensive feedback
         */
        setupCORSForUploads: function (bucket) {
            const currentOrigin = window.location.origin;

            S3M.setLoading('s3CORSManagementModal', true, 'Configuring CORS rules for uploads...');

            S3.ajax('s3_setup_cors_upload_', {
                bucket: bucket,
                origin: currentOrigin
            }, {
                success: (response) => this.handleCORSSetupSuccess(response, bucket),
                error: (message) => S3M.showError('s3CORSManagementModal', message)
            });
        },

        /**
         * Handle successful CORS setup
         */
        handleCORSSetupSuccess: function (response, bucket) {
            const data = response.data;
            const success = data.verification_passed;

            S3M.setLoading('s3CORSManagementModal', false);

            if (success) {
                S3M.showSuccess('s3CORSManagementModal', 'CORS configured successfully! Upload functionality is now enabled.');
            } else {
                S3M.showError('s3CORSManagementModal', 'CORS configured but verification failed. Please check manually.');
            }

            // Clear cache and offer to refresh
            this.clearBucketCache(bucket);

            setTimeout(() => {
                S3M.hide('s3CORSManagementModal');
                S3M.confirm(
                    'CORS Configuration Complete',
                    'CORS has been configured. Would you like to refresh the page to see the changes?',
                    () => window.location.reload()
                );
            }, 2000);
        },

        /**
         * Confirm CORS revocation with detailed warning
         */
        confirmRevokeCORS: function (bucket) {
            const content = `
                <div class="s3-cors-revoke-confirm">
                    <p><strong>You are about to revoke all CORS rules for bucket:</strong></p>
                    <div class="bucket-info">
                        <span class="dashicons dashicons-database"></span>
                        <code>${S3.escapeHtml(bucket)}</code>
                    </div>
                    <div class="warning-box">
                        <p><strong>⚠️ This will:</strong></p>
                        <ul>
                            <li>Disable file uploads from web browsers</li>
                            <li>Prevent cross-origin access to bucket resources</li>
                            <li>Require manual CORS reconfiguration to restore upload capability</li>
                            <li>Affect any applications relying on cross-origin bucket access</li>
                        </ul>
                    </div>
                    <p>Type <strong>"REVOKE"</strong> to confirm this action:</p>
                    <input type="text" id="confirmRevoke" class="confirm-input" placeholder="Type REVOKE here" autocomplete="off">
                </div>
            `;

            const modal = S3M.show(
                'confirmRevokeCORS',
                `Revoke CORS Rules: ${bucket}`,
                content,
                [
                    {
                        text: 'Cancel',
                        action: 'cancel',
                        classes: 'button-secondary',
                        callback: () => S3M.hide('confirmRevokeCORS')
                    },
                    {
                        text: 'Revoke CORS Rules',
                        action: 'revoke',
                        classes: 'button-primary button-destructive',
                        disabled: true,
                        callback: () => this.performCORSRevocation(bucket)
                    }
                ],
                { closeOnOverlay: false }
            );

            // Setup confirmation validation
            modal.on('input', '#confirmRevoke', (e) => {
                const $revokeBtn = modal.find('button[data-action="revoke"]');
                const isMatch = e.target.value.trim().toUpperCase() === 'REVOKE';
                $revokeBtn.prop('disabled', !isMatch);
            });

            setTimeout(() => modal.find('#confirmRevoke').focus(), 300);
        },

        /**
         * Perform CORS revocation
         */
        performCORSRevocation: function (bucket) {
            S3M.hide('confirmRevokeCORS');
            S3M.setLoading('s3CORSManagementModal', true, 'Revoking CORS rules...');

            S3.ajax('s3_delete_cors_configuration_', {
                bucket: bucket
            }, {
                success: (response) => {
                    S3M.setLoading('s3CORSManagementModal', false);
                    S3M.showSuccess('s3CORSManagementModal', `CORS rules successfully revoked for bucket "${bucket}"`);

                    this.clearBucketCache(bucket);

                    setTimeout(() => {
                        S3M.hide('s3CORSManagementModal');
                        S3.notify('CORS rules revoked. Page will refresh to show changes.', 'success');
                        setTimeout(() => window.location.reload(), 2000);
                    }, 2000);
                },
                error: (message) => S3M.showError('s3CORSManagementModal', message)
            });
        },

        /**
         * Show advanced CORS analysis
         */
        showAdvancedCORSAnalysis: function (bucket, corsData) {
            const content = this.buildAdvancedCORSContent(bucket, corsData);

            S3M.show('s3CORSAdvancedModal', `Advanced CORS Analysis: ${bucket}`, content, [
                {
                    text: 'Back to Management',
                    action: 'back',
                    classes: 'button-secondary',
                    callback: () => {
                        S3M.hide('s3CORSAdvancedModal');
                        setTimeout(() => this.showCORSManagementModal(bucket, corsData), 200);
                    }
                },
                {
                    text: 'Refresh Analysis',
                    action: 'refresh',
                    classes: 'button-secondary',
                    callback: () => this.refreshCORSAnalysis(bucket)
                },
                {
                    text: 'Close',
                    action: 'close',
                    classes: 'button-secondary',
                    callback: () => S3M.hide('s3CORSAdvancedModal')
                }
            ]);
        },

        /**
         * Build advanced CORS analysis content
         */
        buildAdvancedCORSContent: function (bucket, corsData) {
            const analysis = corsData.analysis || {};

            return `
                <div class="s3-cors-advanced-content">
                    ${this.buildAnalysisSummary(analysis)}
                    ${analysis.origins_summary ? this.buildOriginsSection(analysis.origins_summary) : ''}
                    ${analysis.methods_summary ? this.buildMethodsSection(analysis.methods_summary) : ''}
                    ${analysis.recommendations ? this.buildAdvancedRecommendations(analysis.recommendations) : ''}
                    ${this.buildCORSBestPractices()}
                </div>
            `;
        },

        /**
         * Build analysis summary section
         */
        buildAnalysisSummary: function (analysis) {
            return `
                <div class="s3-cors-section">
                    <h4>Configuration Analysis</h4>
                    <table class="s3-cors-table">
                        <tr><td><strong>Rules Count:</strong></td><td>${analysis.rules_count || 0}</td></tr>
                        <tr><td><strong>Capabilities:</strong></td><td>${(analysis.capabilities || []).join(', ') || 'None'}</td></tr>
                        <tr><td><strong>Security Score:</strong></td><td>${this.calculateSecurityScore(analysis)}</td></tr>
                        <tr><td><strong>Cache Duration:</strong></td><td>${S3.formatDuration((analysis.max_cache_time || 0) / 60)}</td></tr>
                    </table>
                </div>
            `;
        },

        /**
         * Build origins section
         */
        buildOriginsSection: function (origins) {
            const originsHtml = origins.map(origin => `<code>${S3.escapeHtml(origin)}</code>`).join(', ');
            return `
                <div class="s3-cors-section">
                    <h4>Allowed Origins</h4>
                    <p>${originsHtml}</p>
                </div>
            `;
        },

        /**
         * Build methods section
         */
        buildMethodsSection: function (methods) {
            const methodsHtml = methods.map(method => `<code>${method}</code>`).join(', ');
            return `
                <div class="s3-cors-section">
                    <h4>Allowed Methods</h4>
                    <p>${methodsHtml}</p>
                </div>
            `;
        },

        /**
         * Build advanced recommendations
         */
        buildAdvancedRecommendations: function (recommendations) {
            const recsHtml = recommendations
                .map(rec => `<li>${S3.escapeHtml(rec)}</li>`)
                .join('');

            return `
                <div class="s3-cors-section">
                    <h4>Security Recommendations</h4>
                    <ul class="s3-cors-recommendations">${recsHtml}</ul>
                </div>
            `;
        },

        /**
         * Build CORS best practices section
         */
        buildCORSBestPractices: function () {
            return `
                <div class="s3-cors-section">
                    <h4>CORS Best Practices</h4>
                    <ul class="s3-cors-recommendations">
                        <li>Use specific origins instead of "*" for production environments</li>
                        <li>Limit allowed methods to only what's necessary</li>
                        <li>Set appropriate cache times for preflight requests</li>
                        <li>Regularly review and audit CORS configurations</li>
                        <li>Test CORS configuration with your application</li>
                    </ul>
                </div>
            `;
        },

        // ===========================================
        // UTILITY FUNCTIONS
        // ===========================================

        /**
         * Calculate security score based on CORS configuration
         */
        calculateSecurityScore: function (analysis) {
            let score = 100;

            if (analysis.allows_all_origins) score -= 30;
            if (analysis.supports_delete && analysis.allows_all_origins) score -= 20;
            if (analysis.security_warnings?.length > 0) score -= (analysis.security_warnings.length * 10);
            if (analysis.max_cache_time > 86400) score -= 10;

            score = Math.max(0, score);

            const rating = score >= 80 ? 'Excellent' : score >= 60 ? 'Good' : score >= 40 ? 'Fair' : 'Poor';
            const color = score >= 80 ? 'good' : score >= 60 ? 'warning' : 'bad';

            return `<span class="s3-cors-status-${color}">${score}/100 (${rating})</span>`;
        },

        /**
         * Refresh CORS analysis
         */
        refreshCORSAnalysis: function (bucket) {
            this.clearBucketCache(bucket);
            S3M.hide('s3CORSAdvancedModal');

            S3.notify('Refreshing CORS analysis...', 'info');
            setTimeout(() => this.showCORSManagementModal(bucket), 1000);
        },

        /**
         * Clear bucket cache
         */
        clearBucketCache: function (bucket) {
            // Clear local cache
            for (const [key] of this.state.bucketDetails) {
                if (key.startsWith(bucket + '_')) {
                    this.state.bucketDetails.delete(key);
                }
            }
            this.state.corsCache.delete(bucket);
        },

        /**
         * Toggle favorite bucket (inherited from integrations)
         */
        toggleFavoriteBucket: function ($button) {
            // Delegate to integrations
            if (window.S3Integrations?.toggleFavoriteBucket) {
                window.S3Integrations.toggleFavoriteBucket($button);
            }
        }
    };

    // Initialize on document ready
    $(document).ready(() => {
        S3Buckets.init();
    });

    // Global shorthand
    window.S3B = window.S3Buckets;

})(jQuery);