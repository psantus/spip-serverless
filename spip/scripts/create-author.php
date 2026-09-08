<?php
/**
 * One-shot: create (or update) a SPIP author with a peppered password hash, using the
 * env's own secret_des_auth (from SPIP_CLES). Same DB-wiring approach as bootstrap-db.php.
 *
 * Run INSIDE the container:
 *   docker run --rm \
 *     -e SPIP_DSQL_CLUSTER=<cluster>.dsql.<region>.on.aws -e SPIP_TABLE_PREFIX=spip \
 *     -e SPIP_CLES='{"secret_du_site":"...","secret_des_auth":"..."}' \
 *     -e AWS_ACCESS_KEY_ID=... -e AWS_SECRET_ACCESS_KEY=... -e AWS_SESSION_TOKEN=... -e AWS_REGION=<region> \
 *     --entrypoint php <image> -d auto_prepend_file= /var/task/scripts/create-author.php \
 *       --login=admin --email=admin@example.com --nom=Admin --pass='<pass>' --statut=0minirezo
 *
 * Idempotent: if the login exists, updates its pass/statut (so it also serves to reset).
 */

$opts = getopt('', ['login::', 'email::', 'nom::', 'pass::', 'statut::', 'webmestre::']);
$login  = $opts['login']  ?? '';
$email  = $opts['email']  ?? '';
$nom    = $opts['nom']    ?? ($login ?: '');
$pass   = $opts['pass']   ?? '';
$statut = $opts['statut'] ?? '0minirezo';
$webmestre = $opts['webmestre'] ?? 'non';
if ($login === '' || $pass === '') { fwrite(STDERR, "--login and --pass required\n"); exit(2); }

$root = getenv('LAMBDA_TASK_ROOT') ?: '/var/task';
require_once $root . '/vendor/autoload.php';
if (!defined('_ECRIRE_INSTALL')) define('_ECRIRE_INSTALL', '1');
require_once $root . '/ecrire/inc_version.php';
if (!defined('_ECRIRE_INC_VERSION')) { fwrite(STDERR, "SPIP core not loaded\n"); exit(3); }

// cles.php from SPIP_CLES so SpipCles::getSecretAuth() works (peppered hash).
$cles_json = getenv('SPIP_CLES');
if ($cles_json && defined('_DIR_ETC') && !str_starts_with($cles_json, 'bref-ssm:')) {
    @mkdir(_DIR_ETC, 0777, true);
    @file_put_contents(_DIR_ETC . 'cles.php', "<?php die ('Acces interdit'); ?>\n" . $cles_json);
}

include_spip('base/abstract_sql');
include_spip('base/connect_sql');

// Wire default connection by hand (bypass spip_connect_main charset lookup).
$cluster = getenv('SPIP_DSQL_CLUSTER');
$region  = getenv('SPIP_DSQL_REGION') ?: (getenv('AWS_REGION') ?: 'us-east-1');
if (preg_match('/\.dsql\.([a-z0-9-]+)\.on\.aws$/', (string) $cluster, $m)) { $region = $m[1]; }
$creds = new \Aws\Credentials\Credentials(getenv('AWS_ACCESS_KEY_ID'), getenv('AWS_SECRET_ACCESS_KEY'), getenv('AWS_SESSION_TOKEN') ?: null);
$token = (new \Aws\DSQL\AuthTokenGenerator($creds))->generateDbConnectAdminAuthToken($cluster, $region);
$prefix = getenv('SPIP_TABLE_PREFIX') ?: 'spip';
$sqlv = $GLOBALS['spip_sql_version'] ?? 1;
$desc = spip_connect_db($cluster, '', 'admin', $token, 'postgres', 'dsql', $prefix, '', 'utf8');
if (!$desc) { fwrite(STDERR, "spip_connect_db failed\n"); exit(3); }
$GLOBALS['connexions'][0] = $desc;
$GLOBALS['connexions'][0][$sqlv] = $GLOBALS['spip_dsql_functions_' . $sqlv];
$GLOBALS['connexions'][0]['prefixe'] = $prefix;
$GLOBALS['connexions'][0]['db'] = 'postgres';
$GLOBALS['connexions'][0]['spip_connect_version'] = 0.8;

// Peppered hash with this env's secret_des_auth.
$hash = null;
if (class_exists('\\Spip\\Chiffrer\\Password') && class_exists('\\Spip\\Chiffrer\\SpipCles')) {
    $secret = \Spip\Chiffrer\SpipCles::instance()->getSecretAuth();
    if ($secret) { $hash = \Spip\Chiffrer\Password::hacher($pass, $secret); }
}
if ($hash === null) { fwrite(STDERR, "no auth secret — cannot hash\n"); exit(4); }

$existing = sql_getfetsel('id_auteur', 'spip_auteurs', 'login=' . sql_quote($login));
if ($existing) {
    sql_updateq('spip_auteurs', ['pass' => $hash, 'statut' => $statut, 'webmestre' => $webmestre, 'email' => $email, 'nom' => $nom],
        'id_auteur=' . intval($existing));
    echo "updated author id_auteur=" . intval($existing) . " login=$login statut=$statut\n";
} else {
    $id = sql_insertq('spip_auteurs', [
        'nom' => $nom, 'login' => $login, 'email' => $email, 'pass' => $hash,
        'statut' => $statut, 'webmestre' => $webmestre, 'lang' => 'fr',
    ]);
    echo "created author id_auteur=" . intval($id) . " login=$login statut=$statut\n";
}
