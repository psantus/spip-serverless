<?php
/**
 * Action: register an S3-uploaded file as a SPIP document.
 *
 * Called after browser uploads directly to S3.
 * POST spip.php?action=s3upload_register
 * Body: { "key": "IMG/jpg/photo.jpg", "objet": "article", "id_objet": 1, "mode": "document" }
 * Returns JSON: { "id_document": 42 }
 */
if (!defined('_ECRIRE_INC_VERSION')) return;

function action_s3upload_register_dist() {
    include_spip('inc/autoriser');
    if (!autoriser('joindredocument')) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $key = _request('key');       // e.g. IMG/jpg/photo.jpg
    $objet = _request('objet');   // e.g. article
    $id_objet = intval(_request('id_objet'));
    $mode = _request('mode') ?: 'document';

    if (!$key) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing key']);
        exit;
    }

    $bucket = getenv('S3_BUCKET');
    $region = getenv('S3_REGION') ?: getenv('AWS_REGION') ?: 'us-east-1';

    // Get file info from S3
    require_once '/var/task/vendor/autoload.php';
    $s3 = new Aws\S3\S3Client(['region' => $region, 'version' => 'latest']);

    try {
        $head = $s3->headObject(['Bucket' => $bucket, 'Key' => $key]);
    } catch (Exception $e) {
        http_response_code(404);
        echo json_encode(['error' => 'File not found in S3']);
        exit;
    }

    $taille = $head['ContentLength'] ?? 0;
    $contentType = $head['ContentType'] ?? '';
    $filename = basename($key);
    $ext = pathinfo($filename, PATHINFO_EXTENSION);

    // Determine document type
    include_spip('inc/documents');
    include_spip('base/abstract_sql');

    // fichier is stored relative to IMG/ in the DB
    $fichier = set_spip_doc($key);

    // Get or create the document type
    $id_type = sql_getfetsel('id_type', 'spip_types_documents', 'extension=' . sql_quote($ext));
    if (!$id_type) {
        $id_type = 0;
    }

    // Detect dimensions for images
    $largeur = 0;
    $hauteur = 0;
    if (str_starts_with($contentType, 'image/')) {
        // Download to temp to get dimensions
        $tmp = '/tmp/s3upload_' . md5($key);
        $s3->getObject(['Bucket' => $bucket, 'Key' => $key, 'SaveAs' => $tmp]);
        if ($size = @getimagesize($tmp)) {
            $largeur = $size[0];
            $hauteur = $size[1];
        }
        @unlink($tmp);
    }

    // Insert document in DB
    $id_document = sql_insertq('spip_documents', [
        'fichier' => $fichier,
        'extension' => $ext,
        'taille' => $taille,
        'largeur' => $largeur,
        'hauteur' => $hauteur,
        'mode' => $mode,
        'distant' => 'non',
        'date' => date('Y-m-d H:i:s'),
        'statut' => 'publie',
        'titre' => pathinfo($filename, PATHINFO_FILENAME),
    ]);

    // Link to object if specified
    if ($id_document && $objet && $id_objet) {
        include_spip('action/editer_liens');
        objet_associer(['document' => $id_document], [$objet => $id_objet]);
    }

    header('Content-Type: application/json');
    echo json_encode([
        'id_document' => $id_document,
        'fichier' => $fichier,
    ]);
    exit;
}
