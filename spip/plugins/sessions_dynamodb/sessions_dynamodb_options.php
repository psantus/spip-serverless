<?php
/**
 * Sessions DynamoDB — options loaded early in SPIP bootstrap.
 *
 * Overrides ecrire_fichier() and lire_fichier() for session files,
 * redirecting them to DynamoDB instead of the filesystem.
 */
if (!defined('_ECRIRE_INC_VERSION')) return;

$table = getenv('SPIP_SESSION_TABLE');
if (!$table) return;

// DynamoDB client singleton
function _dynamodb_client() {
    static $client;
    if (!$client) {
        require_once '/var/task/vendor/autoload.php';
        $client = new Aws\DynamoDb\DynamoDbClient([
            'region'  => getenv('AWS_REGION') ?: 'us-east-1',
            'version' => 'latest',
        ]);
    }
    return $client;
}

/**
 * Convert a session filepath to a DynamoDB key.
 * Session files look like: /tmp/spip/sessions/1_abc123def.php
 */
function _session_file_to_key($fichier) {
    return basename($fichier);
}

function _is_session_file($fichier) {
    return str_contains($fichier, 'sessions/');
}

// Override ecrire_fichier for session files
// SPIP calls ecrire_fichier($fichier, $contenu) from ecrire_fichier_session()
$GLOBALS['_SESSIONS_DDB_ORIGINAL_ECRIRE_FICHIER'] = null;

/**
 * Intercept ecrire_fichier calls for session files → DynamoDB
 */
function ecrire_fichier($fichier, $contenu, $ignorer_echec = false, $truncate = true) {
    if (_is_session_file($fichier)) {
        try {
            _dynamodb_client()->putItem([
                'TableName' => getenv('SPIP_SESSION_TABLE'),
                'Item' => [
                    'id'      => ['S' => _session_file_to_key($fichier)],
                    'data'    => ['S' => $contenu],
                    'expires' => ['N' => (string)(time() + 3600 * 24)],
                ],
            ]);
            return true;
        } catch (Exception $e) {
            spip_log('DynamoDB session write error: ' . $e->getMessage(), 'session');
            return false;
        }
    }
    // Fall through to original for non-session files
    return _ecrire_fichier_original($fichier, $contenu, $ignorer_echec, $truncate);
}

/**
 * Intercept lire_fichier calls for session files → DynamoDB
 */
function lire_fichier($fichier, &$contenu, $options = []) {
    if (_is_session_file($fichier)) {
        try {
            $result = _dynamodb_client()->getItem([
                'TableName' => getenv('SPIP_SESSION_TABLE'),
                'Key' => ['id' => ['S' => _session_file_to_key($fichier)]],
            ]);
            if (isset($result['Item']['data']['S'])) {
                $contenu = $result['Item']['data']['S'];
                return true;
            }
        } catch (Exception $e) {
            spip_log('DynamoDB session read error: ' . $e->getMessage(), 'session');
        }
        return false;
    }
    return _lire_fichier_original($fichier, $contenu, $options);
}
