<?php
if (!defined("_ECRIRE_INC_VERSION")) return;

$cluster = getenv('SPIP_DSQL_CLUSTER');
if (!$cluster) return;

$region = getenv('SPIP_DSQL_REGION') ?: getenv('AWS_REGION') ?: null;
if (!$region && preg_match('/\.dsql\.([a-z0-9-]+)\.on\.aws$/', $cluster, $m)) {
    $region = $m[1];
}

require_once '/var/task/vendor/autoload.php';

// Use environment credentials directly (fastest on Lambda)
$creds = new Aws\Credentials\Credentials(
    getenv('AWS_ACCESS_KEY_ID'),
    getenv('AWS_SECRET_ACCESS_KEY'),
    getenv('AWS_SESSION_TOKEN') ?: null
);
$generator = new Aws\DSQL\AuthTokenGenerator($creds);
$token = $generator->generateDbConnectAdminAuthToken($cluster, $region);

$GLOBALS['spip_connect_version'] = 0.8;
spip_connect_db($cluster, "", "admin", $token, "postgres", "dsql", "spip");
