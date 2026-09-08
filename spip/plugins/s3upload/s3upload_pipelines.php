<?php
if (!defined('_ECRIRE_INC_VERSION')) return;

function s3upload_header_prive($flux) {
    if (getenv('S3_BUCKET')) {
        // The Dockerfile ships this plugin under plugins-dist/ (not plugins/), so the
        // asset is served from /plugins-dist/s3upload/. Pointing at /plugins/ gave a 403.
        $flux .= "\n" . '<script src="/plugins-dist/s3upload/s3upload.js" defer></script>' . "\n";
    }
    return $flux;
}
