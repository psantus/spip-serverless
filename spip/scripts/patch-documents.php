<?php
$file = $argv[1] ?? '/var/task/ecrire/inc/documents.php';
$code = file_get_contents($file);

// Patch creer_repertoire_documents
$code = str_replace(
    'function creer_repertoire_documents($ext) {' . "\n" . '	$rep = sous_repertoire(_DIR_IMG, $ext);',
    'function creer_repertoire_documents($ext) {' . "\n"
    . '	if (defined("_S3_BUCKET")) { $d = preg_replace(",^\\.\\./,", "", _DIR_IMG) . ($ext ? $ext . "/" : ""); @file_put_contents("s3://" . _S3_BUCKET . "/" . $d . ".ok", ""); return _DIR_IMG . ($ext ? $ext . "/" : ""); }' . "\n"
    . '	$rep = sous_repertoire(_DIR_IMG, $ext);',
    $code
);

// Patch deplacer_fichier_upload
$code = str_replace(
    "\tif (\$move) {\n\t\t\$ok = @rename(\$source, \$dest);",
    "\tif (defined('_S3_BUCKET') && (str_starts_with(\$source, 's3://') || preg_match(',^(\\.\\./)?IMG/,', \$dest))) {\n"
    . "\t\t\$s3key = preg_replace(',^\\.\\./,', '', \$dest);\n"
    . "\t\t\$s3dest = 's3://' . _S3_BUCKET . '/' . \$s3key;\n"
    . "\t\tif (\$source === \$s3dest) return \$dest;\n"
    . "\t\t\$ok = @copy(\$source, \$s3dest);\n"
    . "\t\tif (\$ok && \$move && !str_starts_with(\$source, 's3://')) @unlink(\$source);\n"
    . "\t\treturn \$ok ? \$dest : false;\n"
    . "\t}\n\n"
    . "\tif (\$move) {\n\t\t\$ok = @rename(\$source, \$dest);",
    $code
);

// Patch get_spip_doc — $fichier at this point already has _DIR_IMG prefix (e.g. ../IMG/logo/file.png or IMG/logo/file.png)
// Just strip ../ and prepend s3://bucket/
$code = str_replace(
    "\t\$fichier = pipeline('get_spip_doc', ['args' => ['fichier' => \$fichier_demande], 'data' => \$fichier]);\n\n\t// fichier normal\n\treturn \$fichier;",
    "\t\$fichier = pipeline('get_spip_doc', ['args' => ['fichier' => \$fichier_demande], 'data' => \$fichier]);\n\n"
    . "\tif (defined('_S3_BUCKET') && !tester_url_absolue(\$fichier)) {\n"
    . "\t\t\$clean = preg_replace(',^\\.\\./,', '', \$fichier);\n"
    . "\t\treturn 's3://' . _S3_BUCKET . '/' . \$clean;\n"
    . "\t}\n"
    . "\treturn \$fichier;",
    $code
);

// Patch set_spip_doc to strip s3:// when storing in DB
$code = str_replace(
    "function set_spip_doc(?string \$fichier): string {\n\tif (\$fichier and strpos(\$fichier, (string) _DIR_IMG) === 0) {",
    "function set_spip_doc(?string \$fichier): string {\n"
    . "\tif (defined('_S3_BUCKET') && \$fichier && str_starts_with(\$fichier, 's3://' . _S3_BUCKET . '/')) {\n"
    . "\t\t\$fichier = substr(\$fichier, strlen('s3://' . _S3_BUCKET . '/'));\n"
    . "\t}\n"
    . "\tif (\$fichier and strpos(\$fichier, (string) _DIR_IMG) === 0) {",
    $code
);

file_put_contents($file, $code);
echo "Patched $file\n";

// Patch renseigner_document.php — make file_exists/filesize work with S3
$file3 = str_replace('ecrire/inc/documents.php', 'plugins-dist/medias/inc/renseigner_document.php', $file);
if (file_exists($file3)) {
    $code3 = file_get_contents($file3);
    // Before the file_exists check, resolve to S3 path
    $code3 = str_replace(
        "// Quelques infos sur le fichier\n\tif (\n\t\t!\$fichier\n\t\tor !@file_exists(\$fichier)",
        "// Quelques infos sur le fichier\n\tif (defined('_S3_BUCKET') && \$fichier && !str_starts_with(\$fichier, 's3://')) {\n"
        . "\t\t\$fichier = 's3://' . _S3_BUCKET . '/' . preg_replace(',^\\.\\./,', '', \$fichier);\n"
        . "\t}\n"
        . "\tif (\n\t\t!\$fichier\n\t\tor !@file_exists(\$fichier)",
        $code3
    );
    file_put_contents($file3, $code3);
    echo "Patched $file3\n";
}

echo "Patched $file\n";

// Patch filtres_images_lib_mini.php — resolve IMG/ paths to s3:// for GD
$file4 = str_replace('ecrire/inc/documents.php', 'ecrire/inc/filtres_images_lib_mini.php', $file);
if (file_exists($file4)) {
    $code4 = file_get_contents($file4);
    $code4 = str_replace(
        '$img = @$func($filename);',
        'if (defined(\'_S3_BUCKET\') && !str_starts_with($filename, \'s3://\') && !file_exists($filename)) { $filename = \'s3://\' . _S3_BUCKET . \'/\' . preg_replace(\',^\\.\\./,\', \'\', $filename); }' . "\n\t" . '$img = @$func($filename);',
        $code4
    );
    file_put_contents($file4, $code4);
    echo "Patched $file4\n";
}

// Patch flock.php — use recursive mkdir for Lambda
$file5 = '/var/task/ecrire/inc/flock.php';
if (file_exists($file5)) {
    $code5 = file_get_contents($file5);
    $code5 = str_replace(
        '@mkdir($path, _SPIP_CHMOD);',
        '@mkdir($path, _SPIP_CHMOD, true);',
        $code5
    );
    file_put_contents($file5, $code5);
    echo "Patched $file5\n";
}
