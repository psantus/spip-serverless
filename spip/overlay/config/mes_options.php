<?php
/**
 * SPIP mes_options.php — Aurora DSQL auto-configuration
 *
 * When SPIP_DSQL_CLUSTER env var is set:
 * - Generates IAM auth tokens for every connection (no stored passwords)
 * - Pre-fills install wizard constants
 * - On first run, auto-creates tables if DB is empty
 *
 * Env vars:
 *   SPIP_DSQL_CLUSTER  - DSQL cluster endpoint (required)
 *   AWS_REGION          - (optional) fallback if not in endpoint
 *   AWS credentials     - via env vars, shared config, or IAM role
 */
$dsql_cluster = getenv('SPIP_DSQL_CLUSTER');
if ($dsql_cluster) {
    $dsql_region = getenv('SPIP_DSQL_REGION') ?: getenv('AWS_REGION') ?: null;
    if (!$dsql_region && preg_match('/\.dsql\.([a-z0-9-]+)\.on\.aws$/', $dsql_cluster, $m)) {
        $dsql_region = $m[1];
    }

    require_once dirname(__DIR__) . '/vendor/autoload.php';

    $provider = Aws\Credentials\CredentialProvider::defaultProvider();
    $generator = new Aws\DSQL\AuthTokenGenerator($provider);
    $dsql_token = $generator->generateDbConnectAdminAuthToken($dsql_cluster, $dsql_region);

    // Pre-fill install wizard
    if (!defined('_INSTALL_SERVER_DB')) define('_INSTALL_SERVER_DB', 'dsql');
    if (!defined('_INSTALL_HOST_DB'))   define('_INSTALL_HOST_DB', $dsql_cluster);
    if (!defined('_INSTALL_USER_DB'))   define('_INSTALL_USER_DB', 'admin');
    if (!defined('_INSTALL_PASS_DB'))   define('_INSTALL_PASS_DB', $dsql_token);
    if (!defined('_INSTALL_NAME_DB'))   define('_INSTALL_NAME_DB', 'postgres');

    unset($dsql_region, $provider, $generator, $dsql_token);
}
unset($dsql_cluster);
