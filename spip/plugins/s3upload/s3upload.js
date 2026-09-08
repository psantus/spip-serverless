/**
 * S3 Direct Upload — intercepts all file input submissions in SPIP admin.
 * Files go directly from browser to S3 via presigned URLs.
 * No file data passes through Lambda.
 */
(function() {
    'use strict';

    function init() {
        // Intercept all forms containing file inputs
        document.querySelectorAll('form').forEach(function(form) {
            if (form.dataset.s3hooked) return;
            var fileInputs = form.querySelectorAll('input[type="file"]');
            if (!fileInputs.length) return;

            form.dataset.s3hooked = '1';
            form.addEventListener('submit', function(e) {
                var files = [];
                fileInputs.forEach(function(input) {
                    if (input.files && input.files.length) {
                        files.push({ input: input, file: input.files[0] });
                    }
                });
                if (!files.length) return; // no files, let normal submit proceed

                e.preventDefault();
                e.stopPropagation();
                uploadAllFiles(files, form);
            });
        });
    }

    function uploadAllFiles(files, form) {
        var pending = files.length;
        var results = [];
        var statusEl = document.createElement('div');
        statusEl.className = 's3upload-status';
        statusEl.style.cssText = 'padding:10px;margin:10px 0;background:#f0f0f0;border-radius:4px';
        statusEl.textContent = 'Uploading ' + pending + ' file(s) to S3...';
        form.prepend(statusEl);

        files.forEach(function(item, idx) {
            uploadOneFile(item.file, function(err, result) {
                if (err) {
                    statusEl.textContent = 'Upload error: ' + err;
                    statusEl.style.background = '#fdd';
                    return;
                }
                results.push(result);
                pending--;
                if (pending === 0) {
                    // All files uploaded to S3. Now submit the form with S3 keys instead of files.
                    statusEl.textContent = 'Registering documents...';
                    submitFormWithS3Keys(form, files, results, statusEl);
                }
            }, function(pct) {
                statusEl.textContent = 'Uploading file ' + (idx+1) + '/' + files.length + ': ' + pct + '%';
            });
        });
    }

    function uploadOneFile(file, callback, onProgress) {
        // Step 1: Get presigned URL
        fetch('/ecrire/?exec=s3upload_presign'
            + '&filename=' + encodeURIComponent(file.name)
            + '&type=' + encodeURIComponent(file.type || 'application/octet-stream'),
            { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.error) throw new Error(data.error);

                // Step 2: PUT directly to S3
                var xhr = new XMLHttpRequest();
                xhr.open('PUT', data.url, true);
                xhr.setRequestHeader('Content-Type', file.type || 'application/octet-stream');

                xhr.upload.onprogress = function(e) {
                    if (e.lengthComputable) onProgress(Math.round(e.loaded / e.total * 100));
                };

                xhr.onload = function() {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        callback(null, { key: data.key, filename: data.filename, name: file.name });
                    } else {
                        callback('S3 upload failed: HTTP ' + xhr.status);
                    }
                };
                xhr.onerror = function() { callback('Network error'); };
                xhr.send(file);
            })
            .catch(function(err) { callback(err.message); });
    }

    function submitFormWithS3Keys(form, files, results, statusEl) {
        // Replace file inputs with hidden inputs containing S3 keys,
        // then resubmit the form normally
        files.forEach(function(item, idx) {
            var input = item.input;
            var result = results[idx];

            // Create a hidden input with the S3 key
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = input.name.replace(/\[?\]?$/, '') + '_s3key';
            hidden.value = result.key;
            form.appendChild(hidden);

            // Disable the file input so it doesn't send file data
            input.disabled = true;
        });

        // Remove our hook to prevent infinite loop
        form.dataset.s3hooked = 'submitted';

        statusEl.textContent = 'Processing...';

        // Submit via fetch to avoid page navigation (keeps modal/popin context)
        var formData = new FormData(form);
        fetch(form.action || window.location.href, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        }).then(function() {
            statusEl.textContent = 'Done!';
            // Reload parent page to show updated content
            if (window.parent !== window) {
                window.parent.location.reload();
            } else {
                location.reload();
            }
        }).catch(function() {
            // Fallback: regular submit
            form.submit();
        });
    }

    // Init on load and after AJAX updates
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    if (typeof jQuery !== 'undefined') {
        jQuery(document).on('ajaxComplete', function() { setTimeout(init, 100); });

        // Helper functions for S3 upload
        function getPresignedUrl(file) {
            return fetch('/ecrire/?exec=s3upload_presign'
                + '&filename=' + encodeURIComponent(file.name)
                + '&type=' + encodeURIComponent(file.type || 'application/octet-stream'),
                { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.error) throw new Error(data.error);
                    return data;
                });
        }
        function uploadToS3(url, file) {
            return new Promise(function(resolve, reject) {
                var xhr = new XMLHttpRequest();
                xhr.open('PUT', url, true);
                xhr.setRequestHeader('Content-Type', file.type || 'application/octet-stream');
                xhr.onload = function() {
                    if (xhr.status >= 200 && xhr.status < 300) resolve();
                    else reject('S3 upload failed: HTTP ' + xhr.status);
                };
                xhr.onerror = function() { reject('Network error'); };
                xhr.send(file);
            });
        }

        // Intercept all jQuery AJAX file uploads via ajaxPrefilter
        // (works even when jquery.form.js caches $.ajax reference)
        jQuery.ajaxPrefilter(function(options, originalOptions, jqXHR) {
            if (options.data instanceof FormData) {
                var fileEntry = null;
                options.data.forEach(function(value, key) {
                    if (value instanceof File && !fileEntry) {
                        fileEntry = { key: key, file: value };
                    }
                });
                if (fileEntry) {
                    // Abort this request, upload to S3, then retry
                    jqXHR.abort();
                    var formData = options.data;
                    getPresignedUrl(fileEntry.file).then(function(result) {
                        return uploadToS3(result.url, fileEntry.file).then(function() { return result; });
                    }).then(function(result) {
                        formData.delete(fileEntry.key);
                        formData.append(fileEntry.key + '_s3key', result.key);
                        if (!formData.has('joindre_upload')) formData.append('joindre_upload', '1');
                        options.data = formData;
                        jQuery.ajax(options);
                    }).then(function() {
                        setTimeout(function() { location.reload(); }, 1500);
                    });
                }
            }
        });
    }
})();
