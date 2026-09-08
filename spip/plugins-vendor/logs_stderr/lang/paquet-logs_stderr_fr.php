<?php

// Ceci est un fichier langue de SPIP -- This is a SPIP language file

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

return [
	'logs_stderr_description' => 'Redirige les appels à spip_log() vers stderr via error_log(), au lieu d’écrire les journaux dans tmp/log/. Ce plugin est utile dans les environnements conteneurisés, notamment avec Docker, Kubernetes et les chaînes CI/CD.',
	'logs_stderr_nom' => 'Logs vers STDERR',
	'logs_stderr_slogan' => 'Envoie les journaux SPIP vers stderr',
];
