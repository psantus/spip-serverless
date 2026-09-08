<?php

/**
 * One-shot DB bootstrap for a fresh DSQL cluster — CLI script.
 *
 * SPIP normally skips installation because config/connect.php is baked into the
 * image (it believes it is already installed). On an empty database the schema is
 * therefore never created. This script creates the schema explicitly — no interactive
 * wizard — so it can be run identically on test / prep / prod by pointing it at the
 * target cluster.
 *
 * Run it INSIDE the container (which carries connect.php + mes_options.php + plugins):
 *
 *   docker run --rm \
 *     -e SPIP_DSQL_CLUSTER=<cluster>.dsql.<region>.on.aws \
 *     -e SPIP_TABLE_PREFIX=spip \
 *     -e SPIP_CLES='{"secret_du_site":"...","secret_des_auth":"..."}' \
 *     -e AWS_ACCESS_KEY_ID=... -e AWS_SECRET_ACCESS_KEY=... -e AWS_SESSION_TOKEN=... \
 *     -e AWS_REGION=<region> \
 *     --entrypoint php <image> /var/task/scripts/bootstrap-db.php \
 *       --admin-login=admin --admin-email=you@ex.org --admin-pass=<pass>
 *
 * Idempotent (safe to re-run; SPIP tolerates "already exists"):
 *   1. creer_base()               — core SPIP tables
 *   2. install metas              — version_installee, nouvelle_install
 *   3. actualise_plugins_actifs() — every active plugin's *_upgrade()
 *                                   (your plugin migrations, e.g. custom fields, forms…)
 *   4. create the admin author    — statut 0minirezo, webmestre, peppered password
 *
 * NOTE: the auth secret (secret_des_auth) must be available (env SPIP_CLES or the
 * cles.php prepared by prepend.php) for the admin password hash to match auth.
 */

// ── Locate the SPIP root (Lambda image = /var/task, apache image = /var/www/html) ──
$root = null;
foreach (['/var/task', '/var/www/html'] as $candidate) {
    if (is_file($candidate . '/ecrire/inc_version.php')) {
        $root = $candidate;
        break;
    }
}
if ($root === null) {
    fwrite(STDERR, "SPIP root not found (looked in /var/task, /var/www/html)\n");
    exit(2);
}

// ── Parse CLI args ─────────────────────────────────────────────────────────────
$opts = getopt('', ['admin-login::', 'admin-email::', 'admin-pass::']);
$admin_login = $opts['admin-login'] ?? 'admin';
$admin_email = $opts['admin-email'] ?? '';
$admin_pass  = $opts['admin-pass'] ?? '';

// ── Bootstrap SPIP core (like spip.php: autoload → kernel → inc_version) ────────
// The Composer autoload defines the SpipLeague Kernel (app()/param()); inc_version.php
// early-returns without it. We must load the autoload FIRST, then resolve the core dir.
require_once $root . '/vendor/autoload.php';

// Install mode: tells SPIP the DB may be empty, so sql_* / spip_connect_sql do NOT
// abort with the "technical problem" admin page when spip_meta is missing yet.
if (!defined('_ECRIRE_INSTALL')) {
    define('_ECRIRE_INSTALL', '1');
}

$ecrire = \SpipLeague\Component\Kernel\param('spip.dirs.core');  // e.g. .../ecrire/
chdir($root);
require_once $ecrire . 'inc_version.php';

if (!defined('_ECRIRE_INC_VERSION')) {
    fwrite(STDERR, "SPIP failed to bootstrap\n");
    exit(2);
}

// We run with auto_prepend_file disabled (prepend.php breaks inc_version in CLI), so
// the cles.php that prepend normally writes from $SPIP_CLES is absent. Write it here
// into _DIR_ETC so SpipCles::getSecretAuth() works (needed to hash the admin password).
$cles_json = getenv('SPIP_CLES');
if ($cles_json && defined('_DIR_ETC') && !str_starts_with($cles_json, 'bref-ssm:')) {
    @mkdir(_DIR_ETC, 0777, true);
    @file_put_contents(_DIR_ETC . 'cles.php', "<?php die ('Acces interdit'); ?>\n" . $cles_json);
}

include_spip('base/create');
include_spip('base/abstract_sql');
include_spip('base/connect_sql');
include_spip('inc/plugin');

function _out(string $k, string $v): void { echo str_pad($k, 22) . $v . "\n"; }

// Establish the default DB connection WITHOUT going through spip_connect()/spip_connect_main.
// On an empty DB the latter reads the charset from spip_meta (absent) and fails, which makes
// every sql_* call render the 503 "technical problem" page in a loop. We therefore build
// $GLOBALS['connexions'][''] by hand — exactly like install/etape_3.php does for a named
// server — so sql_* uses this connection directly. '' is the default server name used by
// creer_base(), actualise_plugins_actifs() and sql_insertq().
$cluster = getenv('SPIP_DSQL_CLUSTER');
$region  = getenv('SPIP_DSQL_REGION') ?: (getenv('AWS_REGION') ?: 'us-east-1');
if (preg_match('/\.dsql\.([a-z0-9-]+)\.on\.aws$/', (string) $cluster, $m)) {
    $region = $m[1];
}
$creds = new \Aws\Credentials\Credentials(
    getenv('AWS_ACCESS_KEY_ID'),
    getenv('AWS_SECRET_ACCESS_KEY'),
    getenv('AWS_SESSION_TOKEN') ?: null
);
$token = (new \Aws\DSQL\AuthTokenGenerator($creds))
    ->generateDbConnectAdminAuthToken($cluster, $region);
$prefix = getenv('SPIP_TABLE_PREFIX') ?: 'spip';
$GLOBALS['spip_connect_version'] = 0.8;
$sqlv = $GLOBALS['spip_sql_version'] ?? 1;

$desc = spip_connect_db($cluster, '', 'admin', $token, 'postgres', 'dsql', $prefix, '', 'utf8');
if (!$desc) {
    fwrite(STDERR, "spip_connect_db failed (check cluster/creds/region)\n");
    exit(3);
}
// Wire the default connection by hand, like etape_3 (bypasses spip_connect_main).
// spip_connect() indexes the default server as integer 0 ($index = $serveur ?: 0),
// so we must populate $GLOBALS['connexions'][0] (NOT ['']).
$GLOBALS['connexions'][0] = $desc;
$GLOBALS['connexions'][0][$sqlv] = $GLOBALS['spip_dsql_functions_' . $sqlv];
$GLOBALS['connexions'][0]['prefixe'] = $prefix;
$GLOBALS['connexions'][0]['db'] = 'postgres';
$GLOBALS['connexions'][0]['spip_connect_version'] = 0.8;
_out('db_connection', 'ok (manual wire, prefix=' . $prefix . ')');



// ── 1. Core SPIP tables ─────────────────────────────────────────────────────────
creer_base();
_out('creer_base', 'ok');

// ── 2. Install metas (only if fresh) ─────────────────────────────────────────────
$has_version = sql_getfetsel('valeur', 'spip_meta', "nom='version_installee'");
if (!$has_version) {
    @sql_insertq('spip_meta', ['nom' => 'version_installee', 'valeur' => $GLOBALS['spip_version_base'], 'impt' => 'non']);
    @sql_insertq('spip_meta', ['nom' => 'nouvelle_install', 'valeur' => '1', 'impt' => 'non']);
    _out('metas', 'created');
} else {
    _out('metas', 'already present (' . $has_version . ')');
}

// ── 2b. Session aleas (required for DynamoDB session ids) ────────────────────────
// Without alea_ephemere, fichier_session() yields an empty name → the sessions_dynamodb
// plugin writes an empty 'id' key → DynamoDB PutItem ValidationException → "Accès
// interdit" after login. renouvelle_alea() generates them.
include_spip('inc/acces');
$has_alea = sql_getfetsel('valeur', 'spip_meta', "nom='alea_ephemere'");
if (!$has_alea && function_exists('renouvelle_alea')) {
    // seed the in-memory meta so renouvelle_alea can rotate current→ancien cleanly
    $GLOBALS['meta']['alea_ephemere'] = $GLOBALS['meta']['alea_ephemere'] ?? '';
    renouvelle_alea();
    _out('aleas', 'created');
} else {
    _out('aleas', $has_alea ? 'already present' : 'renouvelle_alea unavailable');
}

// ── 3. Plugin install/upgrade (plugin tables + your plugin migrations) ────────────
// Two steps, mirroring what the first authenticated visit to the private area does:
//  a) actualise_plugins_actifs(): refresh the active-plugins list (meta 'plugin')
//  b) plugin_installes_meta(): actually run each plugin's install/upgrade and fill the
//     'plugin_installes' config. This is the reliable trigger for ALL migrations
//     (your plugin migrations, svp…), not just actualise_plugins_actifs.
//
// SVP guard: plugin_installes_meta() → svp_actualiser_paquets_locaux() does
// in_array($x, lire_config('plugin_installes')); if that config is absent it is null
// and SVP fatals (in_array: null). Seed it as an empty array first to break the cycle.
if (function_exists('actualise_plugins_actifs')) {
    actualise_plugins_actifs();
}
include_spip('inc/config');
if (function_exists('lire_config') && lire_config('plugin_installes', null) === null) {
    ecrire_config('plugin_installes', []);
    _out('plugin_installes', 'seeded empty (SVP guard)');
}
if (function_exists('plugin_installes_meta')) {
    // plugin_installes_meta() runs plugin install boxes that call template helpers
    // like typo()/propre(); load them (not auto-loaded in this CLI context).
    include_spip('inc/texte');
    include_spip('inc/filtres');
    // plugin_installes_meta() may echo install boxes; capture and drop that output.
    ob_start();
    plugin_installes_meta();
    ob_end_clean();
    _out('plugins_upgrade', 'ok (plugin_installes_meta)');
} else {
    _out('plugins_upgrade', 'plugin_installes_meta unavailable');
}

// ── 4. Admin author (peppered hash via SPIP's own Password helper) ───────────────
$existing = sql_getfetsel('id_auteur', 'spip_auteurs', 'login=' . sql_quote($admin_login));
if ($existing) {
    _out('admin', 'already exists (id_auteur=' . intval($existing) . ')');
} elseif ($admin_pass === '') {
    _out('admin', 'skipped (no --admin-pass)');
} else {
    $hash = null;
    if (class_exists('\\Spip\\Chiffrer\\Password') && class_exists('\\Spip\\Chiffrer\\SpipCles')) {
        try {
            $secret = \Spip\Chiffrer\SpipCles::instance()->getSecretAuth();
            if ($secret) {
                $hash = \Spip\Chiffrer\Password::hacher($admin_pass, $secret);
            }
        } catch (\Throwable $e) {
            // fall through
        }
    }
    if ($hash === null) {
        _out('admin', 'ERROR: no auth secret — cannot hash password');
        exit(4);
    }
    $id = sql_insertq('spip_auteurs', [
        'nom'       => $admin_login,
        'login'     => $admin_login,
        'email'     => $admin_email,
        'pass'      => $hash,
        'statut'    => '0minirezo',
        'webmestre' => 'oui',
        'lang'      => 'fr',
    ]);
    _out('admin', $id ? ('created (id_auteur=' . intval($id) . ')') : 'ERROR: insert failed');
}

_out('status', 'done');
