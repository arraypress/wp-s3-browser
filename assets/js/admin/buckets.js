(function ($) {
    'use strict';

    const S3BucketSelect = {

        init: function () {
            this.bindEvents();
        },

        bindEvents: function () {
            $(document).on('click', '.s3-refresh-buckets', this.refreshBuckets.bind(this));
        },

        refreshBuckets: function (e) {
            e.preventDefault();

            const $button = $(e.currentTarget);
            const selectId = $button.data('select-id');
            const $select = $('#' + selectId);
            const $status = $button.siblings('.s3-bucket-status');
            const currentValue = $select.val();

            if (!S3BrowserGlobalConfig) {
                console.error('S3 Browser configuration not found');
                return;
            }

            $button.prop('disabled', true);
            $status.html('<span class="spinner is-active"></span> Fetching buckets...');

            $.ajax({
                url: S3BrowserGlobalConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 's3_refresh_buckets_' + S3BrowserGlobalConfig.providerId,
                    nonce: S3BrowserGlobalConfig.nonce
                },
                success: function (response) {
                    if (response.success && response.data.buckets) {
                        // Clear and repopulate
                        $select.empty();

                        // Add empty option if it has the class
                        if ($select.hasClass('s3-bucket-select-with-empty')) {
                            $select.append('<option value="">— Select a bucket —</option>');
                        }

                        $.each(response.data.buckets, function (i, bucket) {
                            const $option = $('<option>').val(bucket).text(bucket);
                            if (bucket === currentValue) {
                                $option.prop('selected', true);
                            }
                            $select.append($option);
                        });

                        $status.html('<span style="color: green;">✓ Buckets refreshed</span>');
                    } else {
                        $status.html('<span style="color: red;">✗ Failed to fetch buckets</span>');
                    }
                },
                error: function () {
                    $status.html('<span style="color: red;">✗ Failed to fetch buckets</span>');
                },
                complete: function () {
                    $button.prop('disabled', false);
                    setTimeout(function () {
                        $status.fadeOut();
                    }, 3000);
                }
            });
        }
    };

    $(document).ready(function () {
        S3BucketSelect.init();
    });

    window.S3BucketSelect = S3BucketSelect;

})(jQuery);