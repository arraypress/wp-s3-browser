/**
 * S3 Browser Integrations - WordPress/WooCommerce/EDD integrations
 * Handles file selection and integration with various WordPress contexts
 */
(function ($) {
    'use strict';

    // Extend the main S3Browser object with integration methods
    $.extend(window.S3Browser, {

        /**
         * Find the window holding the fields to write into.
         *
         * Reading any property of a cross-origin frame throws, and window.parent
         * is not reliably reachable: the block editor renders its canvas in an
         * iframe with an opaque origin, so a browser opened from inside it has
         * a parent that cannot be touched at all -- not a parent missing the
         * property, a parent that throws on the attempt.
         *
         * So this walks outward and returns the first window it can actually
         * read, or null when there is none.
         */
        getHostWindow: function () {
            // Ancestors only. The browser renders in an iframe and the fields
            // to write into belong to the page that opened it, so falling back
            // to this window would always "succeed" and always find nothing --
            // which is exactly what it did, leaving every insert reporting an
            // unknown context and doing nothing.
            var candidates = [];

            try {
                if (window.parent && window.parent !== window) {
                    candidates.push(window.parent);
                }
            } catch (e) {
                // Reading .parent can itself throw in a sandboxed frame.
            }

            try {
                if (window.top && window.top !== window && candidates.indexOf(window.top) === -1) {
                    candidates.push(window.top);
                }
            } catch (e) {
                // Same.
            }

            for (var i = 0; i < candidates.length; i++) {
                try {
                    // Touching a property is the only way to find out: a
                    // cross-origin window looks fine until it is read.
                    if (candidates[i].document && candidates[i].jQuery) {
                        return candidates[i];
                    }
                } catch (e) {
                    // Not this one.
                }
            }

            return null;
        },

        /**
         * Handle file selection and integration with WordPress
         */
        handleFileSelection: function ($button) {
            var parent = this.getHostWindow();
            var file = {
                fileName: $button.data('filename'),
                bucket: $button.data('bucket'),
                key: $button.data('key')
            };

            // No reachable frame: ask the other side to do it.
            if (!parent) {
                if (this.requestHostInsert([ file ])) {
                    this.clearSelection();
                } else {
                    window.console.warn('S3 Browser: no insert target. ' + this.describeHost());
                    window.prompt(s3BrowserConfig.i18n.files.insertUnavailable, file.bucket + '/' + file.key);
                }

                return;
            }

            var fileData = {
                fileName: $button.data('filename'),
                bucket: $button.data('bucket'),
                key: $button.data('key'),
                url: $button.data('bucket') + '/' + $button.data('key')
            };

            var context = this.detectCallingContext(parent);

            // Before the frame closes: this document outlives it wherever the
            // host reuses one media frame rather than building a new one, and
            // a stale tick would still be there on the next open.
            if (context !== 'unknown') {
                this.clearSelection();
            }

            switch (context) {
                case 'edd':
                    parent.jQuery(parent.edd_filename).val(fileData.fileName);
                    parent.jQuery(parent.edd_fileurl).val(fileData.url);
                    parent.tb_remove();
                    break;

                case 'woocommerce_file':
                    parent.jQuery(parent.wc_target_input).val(fileData.url);
                    var $filenameInput = parent.jQuery(parent.wc_target_input)
                        .closest('tr').find('input[name="_wc_file_names[]"]');
                    if ($filenameInput.length) {
                        $filenameInput.val(fileData.fileName);
                    }
                    parent.wp.media.frame.close();
                    break;

                case 'wp_editor':
                    try {
                        if (parent.wp.media.editor.activeEditor) {
                            parent.wp.media.editor.insert(fileData.url);
                        } else if (parent.wpActiveEditor) {
                            parent.wp.media.editor.insert(fileData.url, parent.wpActiveEditor);
                        } else {
                            throw new Error('No active editor found');
                        }
                        if (parent.wp.media.frame) {
                            parent.wp.media.frame.close();
                        }
                    } catch (e) {
                        console.error('Editor insertion error:', e);
                        alert('File URL: ' + fileData.url);
                    }
                    break;

                default:
                    // Nothing here knows where to put it. Give the admin the
                    // key so the click is not wasted, and say what was found
                    // so the reason is diagnosable rather than guessable.
                    window.console.warn('S3 Browser: no insert target. ' + this.describeHost());
                    window.prompt(s3BrowserConfig.i18n.files.insertUnavailable, fileData.url);
            }
        },

        /**
         * Insert several files at once.
         *
         * EDD and WooCommerce both hold their files in a repeatable row, and
         * the browser was opened from one specific row -- that row's inputs
         * are what window.edd_fileurl and friends point at. There is no way to
         * hand back more than one file without creating the rows to hold them,
         * so this follows what each plugin's own media handler does: fill the
         * row that opened the browser, then add a row per remaining file.
         *
         * Adding a row means clicking the plugin's own "add" button rather than
         * calling into its internals, which is the difference between using a
         * documented control and depending on a private function.
         */
        handleMultipleFileSelection: function (files) {
            if (!files.length) {
                return;
            }

            var parent = this.getHostWindow();
            var context = parent ? this.detectCallingContext(parent) : 'unknown';

            // Hand the whole set to the host page's own integration script.
            //
            // It owns the file table and is the only side that can place rows
            // safely: which row's button opened the browser, which rows are
            // genuinely empty, and how that platform adds another one. This
            // document used to walk the parent DOM itself, and the two copies
            // drifted -- it looked for WooCommerce's add-file control by a
            // class WooCommerce does not use, so the table never grew and only
            // the first file landed; and it wrote that first file into the
            // last row whether or not the row already held one.
            if ('edd' === context || 'woocommerce_file' === context || !parent) {
                if (this.requestHostInsert(files)) {
                    // Only once the request is away. A failed insert keeps the
                    // selection so it can be retried.
                    this.clearSelection();

                    return;
                }
            }

            window.console.warn('S3 Browser: no insert target. ' + this.describeHost());
            window.prompt(s3BrowserConfig.i18n.files.insertUnavailable, files[0].bucket + '/' + files[0].key);
        },

        closeFrame: function (parent, context) {
            try {
                if (context === 'edd' && parent.tb_remove) {
                    parent.tb_remove();
                } else if (parent.wp && parent.wp.media && parent.wp.media.frame) {
                    parent.wp.media.frame.close();
                }
            } catch (e) {
                // A frame that will not close is not a reason to lose the
                // files that were just written.
            }
        },

        /**
         * Ask the page that opened the browser to insert these files.
         *
         * postMessage is the only thing that crosses an origin boundary, and
         * there is one to cross: the browser renders in a frame whose parent
         * cannot be read at all. Reading window.parent throws a SecurityError
         * rather than returning something unhelpful, so no amount of guarding
         * the DOM access makes it work -- the page on the other side has to do
         * the writing, because it is the only one that can.
         *
         * The message is addressed to the admin's own origin, so it goes to
         * the page that opened this and nowhere else, and carries a token the
         * receiving page can check against its own copy. Origin alone will not
         * identify the sender: a frame with an opaque origin posts as "null".
         *
         * @return bool Whether a request could be sent.
         */
        requestHostInsert: function (files) {
            var config = window.S3BrowserGlobalConfig || {};

            if (!config.adminOrigin || !config.insertToken) {
                return false;
            }

            try {
                window.parent.postMessage({
                    type: 's3-browser:insert',
                    token: config.insertToken,
                    files: files
                }, config.adminOrigin);
            } catch (e) {
                return false;
            }

            return true;
        },

        /**
         * Describe why no host could be written to.
         *
         * "unknown context" on its own says nothing about which of several
         * things went wrong -- an unreachable frame, a frame that is reachable
         * but was not opened from a file field, a plugin whose own script did
         * not run. Reporting which makes the difference between one look at
         * the console and a round of guessing.
         */
        describeHost: function () {
            var notes = [];

            try {
                notes.push('parent reachable: ' + (window.parent !== window ? !!(window.parent.document) : 'not framed'));
            } catch (e) {
                notes.push('parent reachable: no (' + e.name + ')');
            }

            var host = this.getHostWindow();

            if (!host) {
                notes.push('no readable ancestor window');

                return notes.join(' | ');
            }

            ['edd_fileurl', 'edd_filename', 'formfield', 'wc_target_input', 'wc_media_frame_context'].forEach(function (name) {
                try {
                    notes.push(name + ': ' + (typeof host[name] === 'undefined' ? 'unset' : 'set'));
                } catch (e) {
                    notes.push(name + ': unreadable');
                }
            });

            try {
                notes.push('wp.media: ' + (host.wp && host.wp.media ? 'present' : 'absent'));
            } catch (e) {
                notes.push('wp.media: unreadable');
            }

            return notes.join(' | ');
        },

        /**
         * Detect which context called the browser
         */
        detectCallingContext: function (parent) {
            try {
                if (parent.edd_fileurl && parent.edd_filename) {
                    return 'edd';
                }

                if (parent.wc_target_input && parent.wc_media_frame_context === 'product_file') {
                    return 'woocommerce_file';
                }

                if (parent.wp && parent.wp.media && parent.wp.media.editor) {
                    return 'wp_editor';
                }
            } catch (e) {
                // A frame that refuses to be read is one this cannot write to
                // either, and saying so beats an uncaught SecurityError.
                return 'unknown';
            }

            return 'unknown';
        },

    });

})(jQuery);