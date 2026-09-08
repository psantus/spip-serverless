<?php
/**
 * Pre-compile PHP opcache to disk at BUILD time (mnapoli.fr/optimizing-laravel-aws-lambda).
 *
 * Runs in the Bref build image (same PHP version/extensions as prod), writing opcache
 * file-cache entries to opcache.file_cache. At runtime PHP 8.5 reads them read-only
 * (opcache.file_cache_read_only=1), skipping parse+compile of hot files on cold start.
 *
 * IMPORTANT: opcache.file_cache_read_only is PHP_INI_SYSTEM and CANNOT be overridden
 * with `php -d`. So this compilation MUST run with a php.ini where read_only=0 and
 * without the runtime opcache.ini loaded (use PHP_INI_SCAN_DIR="" + an explicit -c).
 *
 * Only hot files are compiled — compiling everything is counter-productive.
 */

$root = '/var/task';
$dirs = [
    // Core hot paths (public + admin request bootstrap)
    'ecrire/inc', 'ecrire/base', 'ecrire/public', 'ecrire/src', 'ecrire/balise',
    'ecrire/exec', 'ecrire/action', 'ecrire/maj',
    // Private admin space (exec pages, formulaires)
    'prive',
    // Frequently used plugins in the admin
    'plugins-dist/saisies', 'plugins-dist/mediabox', 'plugins-dist/mots', 'plugins-dist/gis',
    // Vendors sure to load every request
    'vendor/composer', 'vendor/bref', 'vendor/aws', 'vendor/symfony', 'vendor/psr',
    'vendor/guzzlehttp',
];

$compiled = 0; $skipped = 0;
foreach ($dirs as $dir) {
    $path = "$root/$dir";
    if (!is_dir($path)) { fwrite(STDERR, "[opcache] skip (missing): $dir\n"); continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') { continue; }
        $file = $f->getPathname();
        // Skip test/dev files: they may redeclare functions (fatal on compile) and are
        // never loaded in production.
        if (preg_match('#/(tests?|Tests?)/#', $file)) { continue; }
        if (@opcache_compile_file($file)) { $compiled++; }
        else { $skipped++; }
    }
}
echo "[opcache] compiled=$compiled skipped=$skipped\n";
if ($compiled === 0) { fwrite(STDERR, "[opcache] ERROR: nothing compiled\n"); exit(1); }
