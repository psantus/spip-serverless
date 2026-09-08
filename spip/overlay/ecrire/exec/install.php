<?php

/**
 * Override: Fix install wizard auth detection for dynamic connect.php.
 *
 * SPIP's analyse_fichier_connection() regex-parses connect.php for literal
 * string arguments. Our connect.php uses runtime variables (IAM tokens),
 * so the regex fails. We replace the regex check with a functional test:
 * if _FILE_CONNECT exists and the DB connection works, we're installed.
 */

use Spip\Afficher\Minipage\Admin as MinipageAdmin;

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

include_spip('inc/install');
include_spip('inc/autoriser');

define('_ECRIRE_INSTALL', '1');
define('_FILE_TMP', '_install');

function exec_install_dist() {
	$etape = _request('etape');

	// Original check fails with dynamic connect.php, so test actual connectivity
	$deja = (_FILE_CONNECT and spip_connect());

	if ($deja and in_array($etape, ['chmod', 'sup1', 'sup2'])) {
		$auth = charger_fonction('auth', 'inc');
		if (!$auth()) {
			verifier_visiteur();
			$deja = (!autoriser('configurer'));
		}
	}
	if ($deja) {
		$minipage = new MinipageAdmin();
		echo $minipage->page();
	} else {
		include_spip('base/create');
		$fonc = charger_fonction("etape_$etape", 'install');
		$fonc();
	}
}
