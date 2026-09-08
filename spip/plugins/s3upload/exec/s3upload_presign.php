<?php
/**
 * Exec: generate a presigned S3 PUT URL for direct browser upload.
 * Called via AJAX: /ecrire/?exec=s3upload_presign&filename=photo.jpg&type=image/jpeg
 */
if (!defined('_ECRIRE_INC_VERSION')) return;

function exec_s3upload_presign_dist() {
    // User must be authenticated (exec/ requires login by default in SPIP)
    $filename = _request('filename');
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'application/pdf', 'audio/mpeg'];
    $requestedType = _request('type');
    $contentType = in_array($requestedType, $allowedTypes) ? $requestedType : 'application/octet-stream';

    if (!$filename) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Missing filename']);
        exit;
    }

    $bucket = getenv('S3_BUCKET');
    $region = getenv('S3_REGION') ?: getenv('AWS_REGION') ?: 'us-east-1';

    if (!$bucket) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'S3 not configured']);
        exit;
    }

    include_spip('inc/charsets');
    $filename = strtolower(translitteration(preg_replace(',\.\.+,', '.', basename($filename))));
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $name = pathinfo($filename, PATHINFO_FILENAME);
    $name = preg_replace('/[^.=\w-]+/', '_', $name);

    $key = 'IMG/' . $ext . '/' . $name . '-' . substr(md5(uniqid()), 0, 8) . '.' . $ext;

    require_once '/var/task/vendor/autoload.php';
    $s3 = new Aws\S3\S3Client(['region' => $region, 'version' => 'latest']);

    $cmd = $s3->getCommand('PutObject', [
        'Bucket' => $bucket,
        'Key' => $key,
        'ContentType' => $contentType,
    ]);

    $presigned = $s3->createPresignedRequest($cmd, '+15 minutes');

    header('Content-Type: application/json');
    echo json_encode([
        'url' => (string) $presigned->getUri(),
        'key' => $key,
        'filename' => basename($key),
    ]);
    exit;
}
