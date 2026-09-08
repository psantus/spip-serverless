<?php
/**
 * Lambda front controller for SPIP.
 *
 * Routes a request to the correct SPIP entry point based on its URI:
 *   /ecrire*     → SPIP back-office (admin)
 *   /spip.php*   → public entry point (also serves the login/auth pages)
 *   everything else → public site (index.php)
 *
 * If you ship a custom API plugin, add a branch BEFORE the /ecrire one that
 * requires your plugin's front controller, e.g.:
 *
 *   if (str_starts_with($path, '/api/')) {
 *       chdir('/var/task/ecrire'); require '/var/task/ecrire/inc_version.php';
 *       chdir('/var/task'); require '/var/task/plugins-dist/myapi/api.php'; exit;
 *   }
 */
$uri  = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH);

// Optionally disable the public SPIP site (skeletons). The admin (/ecrire) and the
// login pages stay available; every other public path serves an "off" page.
$public_disabled = getenv('SPIP_PUBLIC_DISABLED') === '1';

function spip_public_off() {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo '<!doctype html><html><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex, nofollow">'
       . '<title>Not found</title>'
       . '<style>body{font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;'
       . 'display:flex;min-height:100vh;margin:0;align-items:center;justify-content:center;text-align:center}'
       . 'main{max-width:32rem;padding:2rem}h1{font-size:1.5rem;margin:0 0 .5rem}p{opacity:.7;line-height:1.5}'
       . '</style></head><body><main>'
       . '<h1>Not found</h1><p>This page does not exist.</p>'
       . '</main></body></html>';
    exit;
}

if (str_starts_with($path, '/ecrire/') || $path === '/ecrire') {
    chdir('/var/task/ecrire');
    require '/var/task/ecrire/index.php';
} elseif ($path === '/spip.php' || str_starts_with($path, '/spip.php')) {
    // Even with the public site off, spip.php must still serve the auth pages —
    // /ecrire redirects to spip.php?page=login for the back-office login.
    $page = $_GET['page'] ?? $_REQUEST['page'] ?? '';
    $auth_pages = ['login', 'logout', 'spip_pass', 'identifiants_oublies', 'mot_de_passe_oublie'];
    if ($public_disabled && !in_array($page, $auth_pages, true)) { spip_public_off(); }
    chdir('/var/task');
    require '/var/task/spip.php';
} else {
    if ($public_disabled) { spip_public_off(); }
    chdir('/var/task');
    require '/var/task/index.php';
}
