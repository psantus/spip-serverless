<?php
if (!defined("_ECRIRE_INC_VERSION")) return;

// ── S3 Upload: handle _s3key form fields ──────────────────────────────────
if (getenv('S3_BUCKET')) {
    // Convert _s3key POST fields to $_FILES entries
    foreach ($_POST ?? [] as $k => $v) {
        if (str_ends_with($k, '_s3key') && $v) {
            $field = str_replace('_s3key', '', $k);
            // Ensure SPIP knows this is an upload
            $_POST['joindre_upload'] = '1';
            $_REQUEST['joindre_upload'] = '1';
            $ext = strtolower(pathinfo($v, PATHINFO_EXTENSION));
            $_FILES[$field] = [
                'name' => basename($v),
                'type' => match($ext) {
                    'jpg','jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    'gif' => 'image/gif',
                    'webp' => 'image/webp',
                    'pdf' => 'application/pdf',
                    default => 'application/octet-stream',
                },
                'tmp_name' => 's3://' . getenv('S3_BUCKET') . '/' . $v,
                'error' => 0,
                'size' => 1,
            ];
        }
    }
}
