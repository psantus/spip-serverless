<?php

/* *************************************************************************\
 *  SPIP, Système de publication pour l'internet                           *
 *                                                                         *
 *  Copyright © avec tendresse depuis 2001                                 *
 *  Arnaud Martin, Antoine Pitrou, Philippe Rivière, Emmanuel Saint-James  *
 *                                                                         *
 *  Ce programme est un logiciel libre distribué sous licence GNU/GPL.     *
\***************************************************************************/

/**
 * Ce fichier contient les fonctions gerant
 * les instructions SQL pour PostgreSQL
 *
 * @package SPIP\Core\SQL\DSQL
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

if (!defined('_DEFAULT_DB')) {
	define('_DEFAULT_DB', 'spip');
}

// Se connecte et retourne le nom de la fonction a connexion persistante
// A la premiere connexion de l'installation (BD pas precisee)
// si on ne peut se connecter sans la preciser
// on reessaye avec le login comme nom de BD
// et si ca marche toujours pas, avec "spip" (constante ci-dessus)
// si ca ne marche toujours pas, echec.

function req_dsql_dist(
	$addr,
	$port,
	$login,
	#[\SensitiveParameter]
	$pass,
	$db = '',
	$prefixe = ''
) {
	static $last_connect = [];
	if (!extension_loaded('pgsql')) {
		return false;
	}

	// si provient de selectdb
	if (empty($addr) && empty($port) && empty($login) && empty($pass)) {
		foreach (['addr', 'port', 'login', 'pass', 'prefixe'] as $a) {
			${$a} = $last_connect[$a];
		}
	}
	[$host, $p] = array_pad(explode(';', $addr), 2, null);
	if ($p > 0) {
		$port = " port=$p";
	} else {
		$port = '';
	}
	$erreurs = [];
	$ssl = ' sslmode=require';
	// DSQL only has 'postgres' database — default to it if none specified
	if (!$db) {
		$db = 'postgres';
	}
	@$link = pg_connect("host=$host$port dbname=$db user=$login password='$pass'$ssl", PGSQL_CONNECT_FORCE_NEW);
	if (!$link) {
		$erreurs[] = @pg_last_error() ?: "Connection failed to $host dbname=$db";
		foreach ($erreurs as $e) {
			spip_log('Echec pg_connect. Erreur : ' . $e, 'dsql.' . _LOG_HS);
		}

		return false;
	}

	if ($link) {
		$last_connect = [
			'addr' => $addr,
			'port' => $port,
			'login' => $login,
			'pass' => $pass,
			'db' => $db,
			'prefixe' => $prefixe,
		];
	}

	spip_log(
		"Connexion vers $host, base $db, prefixe $prefixe " . ($link ? 'operationnelle' : 'impossible'),
		'dsql.' . _LOG_DEBUG
	);

	return !$link ? false : [
		'db' => $db,
		'prefixe' => $prefixe ?: $db,
		'link' => $link,
	];
}

$GLOBALS['spip_dsql_functions_1'] = [
	'alter' => 'spip_dsql_alter',
	'count' => 'spip_dsql_count',
	'countsel' => 'spip_dsql_countsel',
	'create' => 'spip_dsql_create',
	'create_base' => 'spip_dsql_create_base',
	'create_view' => 'spip_dsql_create_view',
	'date_proche' => 'spip_dsql_date_proche',
	'delete' => 'spip_dsql_delete',
	'drop_table' => 'spip_dsql_drop_table',
	'drop_view' => 'spip_dsql_drop_view',
	'errno' => 'spip_dsql_errno',
	'error' => 'spip_dsql_error',
	'explain' => 'spip_dsql_explain',
	'fetch' => 'spip_dsql_fetch',
	'seek' => 'spip_dsql_seek',
	'free' => 'spip_dsql_free',
	'hex' => 'spip_dsql_hex',
	'in' => 'spip_dsql_in',
	'insert' => 'spip_dsql_insert',
	'insertq' => 'spip_dsql_insertq',
	'insertq_multi' => 'spip_dsql_insertq_multi',
	'listdbs' => 'spip_dsql_listdbs',
	'multi' => 'spip_dsql_multi',
	'optimize' => 'spip_dsql_optimize',
	'query' => 'spip_dsql_query',
	'quote' => 'spip_dsql_quote',
	'replace' => 'spip_dsql_replace',
	'replace_multi' => 'spip_dsql_replace_multi',
	'select' => 'spip_dsql_select',
	'selectdb' => 'spip_dsql_selectdb',
	'set_connect_charset' => 'spip_dsql_set_connect_charset',
	'set_charset' => 'spip_dsql_set_charset',
	'get_charset' => 'spip_dsql_get_charset',
	'showbase' => 'spip_dsql_showbase',
	'showtable' => 'spip_dsql_showtable',
	'table_exists' => 'spip_dsql_table_exists',
	'update' => 'spip_dsql_update',
	'updateq' => 'spip_dsql_updateq',
];

// Par ou ca passe une fois les traductions faites
function spip_dsql_trace_query($query, $serveur = '') {
	$connexion = &$GLOBALS['connexions'][$serveur ? strtolower($serveur) : 0];
	$prefixe = $connexion['prefixe'];
	$link = $connexion['link'];
	$db = $connexion['db'];

	if (isset($_GET['var_profile'])) {
		include_spip('public/tracer');
		$t = trace_query_start();
		$e = '';
	} else {
		$t = 0;
	}

	$connexion['last'] = $query;
	if (isset($GLOBALS['_otel_tracer'])) {
		$_span = $GLOBALS['_otel_tracer']->spanBuilder('DSQL')->setAttribute('db.system', 'postgresql')->setAttribute('db.statement', substr($query, 0, 200))->startSpan();
	}
	$r = spip_dsql_query_simple($link, $query);
	if (isset($_span)) { $_span->end(); unset($_span); }

	// Log de l'erreur eventuelle
	if ($e = spip_dsql_errno($serveur)) {
		$e .= spip_dsql_error($query, $serveur);
	} // et du fautif
	return $t ? trace_query_end($query, $t, $r, $e, $serveur) : $r;
}

// Fonction de requete generale quand on est sur que c'est SQL standard.
// Elle change juste le noms des tables ($table_prefix) dans le FROM etc

function spip_dsql_query($query, $serveur = '', $requeter = true) {
	$connexion = &$GLOBALS['connexions'][$serveur ? strtolower($serveur) : 0];
	$prefixe = $connexion['prefixe'];
	$link = $connexion['link'];
	$db = $connexion['db'];

	if (preg_match('/\s(SET|VALUES|WHERE|DATABASE)\s/i', $query, $regs)) {
		$suite = strstr($query, (string) $regs[0]);
		$query = substr($query, 0, -strlen($suite));
	} else {
		$suite = '';
	}
	$query = preg_replace('/([,\s])spip_/', '\1' . $prefixe . '_', $query) . $suite;

	// renvoyer la requete inerte si demandee
	if (!$requeter) {
		return $query;
	}

	return spip_dsql_trace_query($query, $serveur);
}

function spip_dsql_query_simple($link, $query) {
	// Fix double-quoted integers: PostgreSQL treats "0" as column name, not value
	$query = preg_replace('/([><=!]+\s*)"(\d+)"/', '$1$2', $query);
	#spip_log(var_export($query,true), 'dsql.'._LOG_DEBUG);
	return @pg_query($link, $query);
}

/*
 * Retrouver les champs 'timestamp'
 * pour les ajouter aux 'insert' ou 'replace'
 * afin de simuler le fonctionnement de mysql
 *
 * stocke le resultat pour ne pas faire
 * de requetes showtable intempestives
 */
function spip_dsql_ajouter_champs_timestamp($table, $couples, $desc = '', $serveur = '') {
	static $tables = [];

	if (!isset($tables[$table])) {
		if (!$desc) {
			$trouver_table = charger_fonction('trouver_table', 'base');
			$desc = $trouver_table($table, $serveur);
			// si pas de description, on ne fait rien, ou on die() ?
			if (!$desc) {
				return $couples;
			}
		}

		// recherche des champs avec simplement 'TIMESTAMP'
		// cependant, il faudra peut etre etendre
		// avec la gestion de DEFAULT et ON UPDATE
		// mais ceux-ci ne sont pas utilises dans le core
		$tables[$table] = [];
		foreach ($desc['field'] as $k => $v) {
			$v = strtolower(ltrim($v));
			// ne pas ajouter de timestamp now() si un default est specifie
			if (strpos($v, 'timestamp') === 0 and !str_contains($v, 'default')) {
				$tables[$table][] = $k;
			}
		}
	}

	// ajout des champs type 'timestamp' absents
	foreach ($tables[$table] as $maj) {
		if (!array_key_exists($maj, $couples)) {
			$couples[$maj] = 'NOW()';
		}
	}

	return $couples;
}


// Alter en PG ne traite pas les index
function spip_dsql_alter($query, $serveur = '', $requeter = true) {
	// il faudrait une regexp pour eviter de spliter ADD PRIMARY KEY (colA, colB)
	// tout en cassant en deux alter distincts "ADD PRIMARY KEY (colA, colB), ADD INDEX (chose)"...
	// ou revoir l'api de sql_alter en creant un
	// sql_alter_table($table,array($actions));
	if (!preg_match('/\s*((\s*IGNORE)?\s*TABLE\s*([^\s]*))\s*(.*)?/is', $query, $regs)) {
		spip_log("$query mal comprise", 'dsql.' . _LOG_ERREUR);

		return false;
	}
	$debut = $regs[1];
	$table = $regs[3];
	$suite = $regs[4];
	$todo = explode(',', $suite);
	// on remet les morceaux dechires ensembles... que c'est laid !
	$todo2 = [];
	$i = 0;
	$ouverte = false;
	while ($do = array_shift($todo)) {
		$todo2[$i] = isset($todo2[$i]) ? $todo2[$i] . ',' . $do : $do;
		$o = (str_contains($do, '('));
		$f = (str_contains($do, ')'));
		if ($o and !$f) {
			$ouverte = true;
		} elseif ($f) {
			$ouverte = false;
		}
		if (!$ouverte) {
			$i++;
		}
	}
	$todo = $todo2;
	$query = $debut . ' ' . array_shift($todo);

	if (!preg_match('/^\s*(IGNORE\s*)?TABLE\s+(\w+)\s+(ADD|DROP|CHANGE|MODIFY|RENAME)\s*(.*)$/is', $query, $r)) {
		spip_log("$query incompris", 'dsql.' . _LOG_ERREUR);
	} else {
		if ($r[1]) {
			spip_log("j'ignore IGNORE dans $query", 'dsql.' . _LOG_AVERTISSEMENT);
		}
		$f = 'spip_dsql_alter_' . strtolower($r[3]);
		if (function_exists($f)) {
			$f($r[2], $r[4], $serveur, $requeter);
		} else {
			spip_log("$query non prevu", 'dsql.' . _LOG_ERREUR);
		}
	}
	// Alter a plusieurs args. Faudrait optimiser.
	if ($todo) {
		spip_dsql_alter("TABLE $table " . join(',', $todo));
	}
}

function spip_dsql_alter_change($table, $arg, $serveur = '', $requeter = true) {
	if (!preg_match('/^`?(\w+)`?\s+`?(\w+)`?\s+(.*?)\s*(DEFAULT .*?)?(NOT\s+NULL)?\s*(DEFAULT .*?)?$/i', $arg, $r)) {
		spip_log("alter change: $arg  incompris", 'dsql.' . _LOG_ERREUR);
	} else {
		[, $old, $new, $type, $default, $null, $def2] = $r;
		$actions = ["ALTER $old TYPE " . mysql2dsql_type($type)];
		if ($null) {
			$actions[] = "ALTER $old SET NOT NULL";
		} else {
			$actions[] = "ALTER $old DROP NOT NULL";
		}

		if ($d = ($default ?: $def2)) {
			$actions[] = "ALTER $old SET $d";
		} else {
			$actions[] = "ALTER $old DROP DEFAULT";
		}

		spip_dsql_query("ALTER TABLE $table " . join(', ', $actions));

		if ($old != $new) {
			spip_dsql_query("ALTER TABLE $table RENAME $old TO $new", $serveur);
		}
	}
}

function spip_dsql_alter_add($table, $arg, $serveur = '', $requeter = true) {
	$nom_index = null;
	if (!preg_match('/^(COLUMN|INDEX|KEY|PRIMARY\s+KEY|)\s*(.*)$/', $arg, $r)) {
		spip_log("alter add $arg  incompris", 'dsql.' . _LOG_ERREUR);

		return null;
	}
	if (!$r[1] or $r[1] == 'COLUMN') {
		preg_match('/`?(\w+)`?(.*)/', $r[2], $m);
		if (preg_match('/^(.*)(BEFORE|AFTER|FIRST)(.*)$/is', $m[2], $n)) {
			$m[2] = $n[1];
		}

		return spip_dsql_query("ALTER TABLE $table ADD " . $m[1] . ' ' . mysql2dsql_type($m[2]), $serveur, $requeter);
	} elseif ($r[1][0] == 'P') {
		// la primary peut etre sur plusieurs champs
		$r[2] = trim(str_replace('`', '', $r[2]));
		$m = ($r[2][0] == '(') ? substr($r[2], 1, -1) : $r[2];

		return spip_dsql_query(
			"ALTER TABLE $table ADD CONSTRAINT $table" . '_pkey PRIMARY KEY (' . $m . ')',
			$serveur,
			$requeter
		);
	} else {
		preg_match('/([^\s,]*)\s*(.*)?/', $r[2], $m);
		// peut etre "(colonne)" ou "nom_index (colonnes)"
		// bug potentiel si qqn met "(colonne, colonne)"
		//
		// nom_index (colonnes)
		if ($m[2]) {
			$colonnes = substr($m[2], 1, -1);
			$nom_index = $m[1];
		} else {
			// (colonne)
			if ($m[1][0] == '(') {
				$colonnes = substr($m[1], 1, -1);
				if (str_contains(',', $colonnes)) {
					spip_log('PG : Erreur, impossible de creer un index sur plusieurs colonnes'
						. " sans qu'il ait de nom ($table, ($colonnes))", 'dsql.' . _LOG_ERREUR);
				} else {
					$nom_index = $colonnes;
				}
			} // nom_index
			else {
				$nom_index = $colonnes = $m[1];
			}
		}

		return spip_dsql_create_index($nom_index, $table, $colonnes, $serveur, $requeter);
	}
}

function spip_dsql_alter_drop($table, $arg, $serveur = '', $requeter = true) {
	if (!preg_match('/^(COLUMN|INDEX|KEY|PRIMARY\s+KEY|)\s*`?(\w*)`?/', $arg, $r)) {
		spip_log("alter drop: $arg  incompris", 'dsql.' . _LOG_ERREUR);
	} else {
		if (!$r[1] or $r[1] == 'COLUMN') {
			return spip_dsql_query("ALTER TABLE $table DROP " . $r[2], $serveur);
		} elseif ($r[1][0] == 'P') {
			return spip_dsql_query("ALTER TABLE $table DROP CONSTRAINT $table" . '_pkey', $serveur);
		} else {
			return spip_dsql_query('DROP INDEX ' . $table . '_' . $r[2], $serveur);
		}
	}
}

function spip_dsql_alter_modify($table, $arg, $serveur = '', $requeter = true) {
	if (!preg_match('/^`?(\w+)`?\s+(.*)$/', $arg, $r)) {
		spip_log("alter modify: $arg  incompris", 'dsql.' . _LOG_ERREUR);
	} else {
		return spip_dsql_alter_change($table, $r[1] . ' ' . $arg, $serveur = '', $requeter = true);
	}
}

// attention (en pg) :
// - alter table A rename to X = changer le nom de la table
// - alter table A rename X to Y = changer le nom de la colonne X en Y
// pour l'instant, traiter simplement RENAME TO X
function spip_dsql_alter_rename($table, $arg, $serveur = '', $requeter = true) {
	$rename = '';
	// si TO, mais pas au debut
	if (!stripos($arg, 'TO ')) {
		$rename = $arg;
	} elseif (preg_match('/^(TO)\s*`?(\w*)`?/', $arg, $r)) {
		$rename = $r[2];
	} else {
		spip_log("alter rename: $arg  incompris", 'dsql.' . _LOG_ERREUR);
	}

	return $rename ? spip_dsql_query("ALTER TABLE $table RENAME TO $rename") : false;
}


/**
 * Fonction de creation d'un INDEX
 *
 * @param string $nom : nom de l'index
 * @param string $table : table sql de l'index
 * @param string /array $champs : liste de champs sur lesquels s'applique l'index
 * @param string $serveur : nom de la connexion sql utilisee
 * @param bool $requeter : true pour executer la requete ou false pour retourner le texte de la requete
 *
 * @return bool ou requete
 */
function spip_dsql_create_index($nom, $table, $champs, $serveur = '', $requeter = true) {
	if (!($nom or $table or $champs)) {
		spip_log(
			"Champ manquant pour creer un index pg ($nom, $table, (" . @join(',', $champs) . '))',
			'dsql.' . _LOG_ERREUR
		);

		return false;
	}

	$nom = str_replace('`', '', $nom);
	$champs = str_replace('`', '', $champs);

	// PG ne differentie pas noms des index en fonction des tables
	// il faut donc creer des noms uniques d'index pour une base pg
	$nom = $table . '_' . $nom;
	// enlever d'eventuelles parentheses deja presentes sur champs
	if (!is_array($champs)) {
		if ($champs[0] == '(') {
			$champs = substr($champs, 1, -1);
		}
		$champs = [$champs];
	}
	$query = "CREATE INDEX $nom ON $table (" . join(',', $champs) . ')';
	if (!$requeter) {
		return $query;
	}
	$res = spip_dsql_query($query, $serveur, $requeter);

	return $res;
}


function spip_dsql_explain($query, $serveur = '', $requeter = true) {
	if (strpos(ltrim($query), 'SELECT') !== 0) {
		return [];
	}
	$connexion = &$GLOBALS['connexions'][$serveur ? strtolower($serveur) : 0];
	$prefixe = $connexion['prefixe'];
	$link = $connexion['link'];
	if (preg_match('/\s(SET|VALUES|WHERE)\s/i', $query, $regs)) {
		$suite = strstr($query, (string) $regs[0]);
		$query = substr($query, 0, -strlen($suite));
	} else {
		$suite = '';
	}
	$query = 'EXPLAIN ' . preg_replace('/([,\s])spip_/', '\1' . $prefixe . '_', $query) . $suite;

	if (!$requeter) {
		return $query;
	}
	$r = spip_dsql_query_simple($link, $query);

	return spip_dsql_fetch($r, null, $serveur);
}


/**
 * Sélectionne une base de données
 *
 * @param string $db
 *     Nom de la base à utiliser
 * @param string $serveur
 *     Nom du connecteur
 * @param bool $requeter
 *     Inutilisé
 *
 * @return bool|string
 *     - Nom de la base en cas de succès.
 *     - False en cas d'erreur.
 **/
function spip_dsql_selectdb($db, $serveur = '', $requeter = true) {
	// se connecter a la base indiquee
	// avec les identifiants connus
	$index = $serveur ? strtolower($serveur) : 0;

	if ($link = spip_connect_db('', '', '', '', $db, 'pg', '', '')) {
		if (($db == $link['db']) && $GLOBALS['connexions'][$index] = $link) {
			return $db;
		}
	}

	return false;
}

// Qu'une seule base pour le moment

function spip_dsql_listdbs($serveur) {
	$connexion = &$GLOBALS['connexions'][$serveur ? strtolower($serveur) : 0];
	$link = $connexion['link'];
	$dbs = [];
	$res = spip_dsql_query_simple($link, 'select * From pg_database');
	while ($row = pg_fetch_array($res, null, PGSQL_NUM)) {
		$dbs[] = reset($row);
	}

	return $dbs;
}

function spip_dsql_select(
	$select,
	$from,
	$where = '',
	$groupby = [],
	$orderby = '',
	$limit = '',
	$having = '',
	$serveur = '',
	$requeter = true
) {

	$connexion = &$GLOBALS['connexions'][$serveur ? strtolower($serveur) : 0];
	$prefixe = $connexion['prefixe'];
	$link = $connexion['link'];
	$db = $connexion['db'];

	$limit = preg_match('/^\s*(([0-9]+),)?\s*([0-9]+)\s*$/', $limit, $limatch);
	if ($limit) {
		$offset = $limatch[2];
		$count = $limatch[3];
	}

	$select = spip_dsql_frommysql($select);

	// si pas de tri explicitement demande, le GROUP BY ne
	// contient que la clef primaire.
	// lui ajouter alors le champ de tri par defaut
	if (!empty($orderby[0]) && preg_match('/FIELD\(([a-z]+\.[a-z]+),/i', $orderby[0], $groupbyplus)) {
		$groupby[] = $groupbyplus[1];
	}

	$orderby = spip_dsql_orderby($orderby, $select);

	if ($having) {
		if (is_array($having)) {
			$having = join("\n\tAND ", array_map('calculer_dsql_where', $having));
		}
	}
	$from = spip_dsql_from($from, $prefixe);
	// DSQL/PostgreSQL: SELECT DISTINCT requires ORDER BY expressions in select list
	if ($orderby && preg_match('/\bDISTINCT\b/i', $select)) {
		foreach (explode(',', $orderby) as $o) {
			$expr = trim(preg_replace('/\s+(ASC|DESC)\s*$/i', '', trim($o)));
			if (!$expr) continue;
			if (stripos($select, $expr) !== false) continue;
			// For CASE expressions, add with alias
			if (preg_match('/\bCASE\b/i', $expr)) {
				$select .= ', ' . $expr . ' AS _sort_expr';
			} else {
				$select .= ', ' . $expr;
			}
		}
	}
	$query = 'SELECT ' . $select
		. (!$from ? '' : "\nFROM $from")
		. (!$where ? '' : ("\nWHERE " . (!is_array($where) ? calculer_dsql_where($where) : (join(
			"\n\tAND ",
			array_map('calculer_dsql_where', $where)
		)))))
		. spip_dsql_groupby($groupby, $from, $select)
		. (!$having ? '' : "\nHAVING $having")
		. ($orderby ? ("\nORDER BY $orderby") : '')
		. (!$limit ? '' : (" LIMIT $count" . (!$offset ? '' : " OFFSET $offset")));

	// renvoyer la requete inerte si demandee
	if ($requeter === false) {
		return $query;
	}

	$r = spip_dsql_trace_query($query, $serveur);

	return $r ?: $query;
;
}

// Le traitement des prefixes de table dans un Select se limite au FROM
// car le reste de la requete utilise les alias (AS) systematiquement

function spip_dsql_from($from, $prefixe) {
	if (is_array($from)) {
		$from = spip_dsql_select_as($from);
	}

	return !$prefixe ? $from : preg_replace('/(\b)spip_/', '\1' . $prefixe . '_', $from);
}

function spip_dsql_orderby($order, $select) {
	$res = [];
	$arg = (is_array($order) ? $order : preg_split('/\s*,\s*/', $order));

	foreach ($arg as $v) {
		if (preg_match('/(case\s+.*?else\s+0\s+end)\s*AS\s+' . $v . '/', $select, $m)) {
			$res[] = $m[1];
		} else {
			$res[] = $v;
		}
	}

	return spip_dsql_frommysql(join(',', $res));
}

// Conversion a l'arrach' des jointures MySQL en jointures PG
// A refaire pour tirer parti des possibilites de PG et de MySQL5
// et pour enlever les repetitions (sans incidence de perf, mais ca fait sale)

function spip_dsql_groupby($groupby, $from, $select) {
	$join = strpos($from, ',');
	// ismplifier avant de decouper
	if (is_string($select)) { // fct SQL sur colonne et constante apostrophee ==> la colonne
	$select = preg_replace('/\w+\(\s*([^(),\']*),\s*\'[^\']*\'[^)]*\)/', '\\1', $select);
	}

	if ($join or $groupby) {
		$join = is_array($select) ? $select : explode(', ', $select);
	}
	if ($join) {
		// enlever les 0 as points, '', ...
		foreach ($join as $k => $v) {
			$v = str_replace('DISTINCT ', '', $v);
			// fct SQL sur colonne et constante apostrophee ==> la colonne
			$v = preg_replace('/\w+\(\s*([^(),\']*),\s*\'[^\']*\'[^)]*\)/', '\\1', $v);
			$v = preg_replace('/CAST\(\s*([^(),\' ]*\s+)as\s*\w+\)/', '\\1', $v);
			// resultat d'agregat ne sont pas a mettre dans le groupby
			$v = preg_replace('/(SUM|COUNT|MAX|MIN|UPPER)\([^)]+\)(\s*AS\s+\w+)\s*,?/i', '', $v);
			// idem sans AS (fetch numerique)
			$v = preg_replace('/(SUM|COUNT|MAX|MIN|UPPER)\([^)]+\)\s*,?/i', '', $v);
			// des AS simples : on garde le cote droit du AS
			$v = preg_replace('/^.*\sAS\s+(\w+)\s*$/i', '\\1', $v);
			// ne reste plus que les vrais colonnes, ou des constantes a virer
			if (preg_match(',^[\'"],', $v) or is_numeric($v)) {
				unset($join[$k]);
			} else {
				$join[$k] = trim($v);
			}
		}
		$join = array_diff($join, ['']);
		$join = implode(',', $join);
	}
	if (is_array($groupby)) {
		$groupby = join(',', $groupby);
	}
	if ($join) {
		$groupby = $groupby ? "$groupby, $join" : $join;
	}
	if (!$groupby) {
		return '';
	}

	$groupby = spip_dsql_frommysql($groupby);
	// Ne pas mettre dans le Group-By des valeurs numeriques
	// issue de prepare_recherche
	$groupby = preg_replace('/^\s*\d+\s+AS\s+\w+\s*,?\s*/i', '', $groupby);
	$groupby = preg_replace('/,\s*\d+\s+AS\s+\w+\s*/i', '', $groupby);
	$groupby = preg_replace('/\s+AS\s+\w+\s*/i', '', $groupby);

	return "\nGROUP BY $groupby";
}

// Conversion des operateurs MySQL en PG
// IMPORTANT: "0+X" est vu comme conversion numerique du debut de X
// Les expressions de date ne sont pas gerees au-dela de 3 ()
// Le 'as' du 'CAST' est en minuscule pour echapper au dernier preg_replace
// de spip_dsql_groupby.
// A ameliorer.

function spip_dsql_frommysql($arg) {
	if (is_array($arg)) {
		$arg = join(', ', $arg);
	}

	$res = spip_dsql_fromfield($arg);

	// Fix IN('') on integer columns — PostgreSQL rejects empty string for bigint
	$res = preg_replace('/\bIN\s*\(\s*\'\'(?:\s*,\s*\'\')*\s*\)/i', 'IN (NULL)', $res);

	$res = preg_replace('/\brand[(][)]/i', 'random()', $res);

	// TIMESTAMPDIFF(HOUR, a, b) → EXTRACT(EPOCH FROM (b::timestamp - a::timestamp)) / 3600
	$res = preg_replace_callback(
		'/TIMESTAMPDIFF\s*[(]\s*(HOUR|DAY|MINUTE|SECOND)\s*,\s*([^,]+)\s*,\s*([^)]+)\s*[)]/i',
		function ($m) {
			$divisors = ['SECOND' => 1, 'MINUTE' => 60, 'HOUR' => 3600, 'DAY' => 86400];
			$div = $divisors[strtoupper($m[1])] ?? 1;
			return "EXTRACT(EPOCH FROM (" . trim($m[3]) . "::timestamp - " . trim($m[2]) . "::timestamp)) / $div";
		},
		$res
	);

	$res = preg_replace(
		'/\b0\.0[+]([a-zA-Z0-9_.]+)\s*/',
		'CAST(substring(\1, \'^ *[0-9.]+\') as float)',
		$res
	);
	$res = preg_replace(
		'/\b0[+]([a-zA-Z0-9_.]+)\s*/',
		'CAST(substring(\1, \'^ *[0-9]+\') as int)',
		$res
	);
	$res = preg_replace(
		'/\bconv[(]([^,]*)[^)]*[)]/i',
		'CAST(substring(\1, \'^ *[0-9]+\') as int)',
		$res
	);

	$res = preg_replace(
		'/UNIX_TIMESTAMP\s*[(]\s*[)]/',
		' EXTRACT(epoch FROM NOW())',
		$res
	);

	// la fonction md5(integer) n'est pas connu en pg
	// il faut donc forcer les types en text (cas de md5(id_article))
	$res = preg_replace(
		'/md5\s*[(]([^)]*)[)]/i',
		'MD5(CAST(\1 AS text))',
		$res
	);

	$res = preg_replace(
		'/UNIX_TIMESTAMP\s*[(]([^)]*)[)]/',
		' EXTRACT(epoch FROM \1)',
		$res
	);

	$res = preg_replace(
		'/\bDAYOFMONTH\s*[(]([^()]*([(][^()]*[)][^()]*)*[^)]*)[)]/',
		' EXTRACT(day FROM \1)',
		$res
	);

	$res = preg_replace(
		'/\bMONTH\s*[(]([^()]*([(][^)]*[)][^()]*)*[^)]*)[)]/',
		' EXTRACT(month FROM \1)',
		$res
	);

	$res = preg_replace(
		'/\bYEAR\s*[(]([^()]*([(][^)]*[)][^()]*)*[^)]*)[)]/',
		' EXTRACT(year FROM \1)',
		$res
	);

	$res = preg_replace(
		'/TO_DAYS\s*[(]([^()]*([(][^)]*[)][()]*)*)[)]/',
		' EXTRACT(day FROM \1 - \'0001-01-01\')',
		$res
	);

	$res = preg_replace('/(EXTRACT[(][^ ]* FROM *)"([^"]*)"/', '\1\'\2\'', $res);

	$res = preg_replace('/DATE_FORMAT\s*[(]([^,]*),\s*\'%Y%m%d\'[)]/', 'to_char(\1, \'YYYYMMDD\')', $res);

	$res = preg_replace('/DATE_FORMAT\s*[(]([^,]*),\s*\'%Y%m\'[)]/', 'to_char(\1, \'YYYYMM\')', $res);

	$res = preg_replace('/DATE_SUB\s*[(]([^,]*),/', '(\1 -', $res);
	$res = preg_replace('/DATE_ADD\s*[(]([^,]*),/', '(\1 +', $res);
	$res = preg_replace('/INTERVAL\s+(\d+\s+\w+)/', 'INTERVAL \'\1\'', $res);
	$res = preg_replace('/([+<>-]=?)\s*(\'\d+-\d+-\d+\s+\d+:\d+(:\d+)\')/', '\1 timestamp \2', $res);
	$res = preg_replace('/(\'\d+-\d+-\d+\s+\d+:\d+:\d+\')\s*([+<>-]=?)/', 'timestamp \1 \2', $res);

	$res = preg_replace('/([+<>-]=?)\s*(\'\d+-\d+-\d+\')/', '\1 timestamp \2', $res);
	$res = preg_replace('/(\'\d+-\d+-\d+\')\s*([+<>-]=?)/', 'timestamp \1 \2', $res);

	$res = preg_replace('/(timestamp .\d+)-00-/', '\1-01-', $res);
	$res = preg_replace('/(timestamp .\d+-\d+)-00/', '\1-01', $res);
# correct en theorie mais produit des debordements arithmetiques
#	$res = preg_replace("/(EXTRACT[(][^ ]* FROM *)(timestamp *'[^']*' *[+-] *timestamp *'[^']*') *[)]/", '\2', $res);
	$res = preg_replace("/(EXTRACT[(][^ ]* FROM *)('[^']*')/", '\1 timestamp \2', $res);
	$res = preg_replace('/\sLIKE\s+/', ' ILIKE ', $res);

	$res = str_replace('REGEXP', '~', $res);
	// DSQL/PostgreSQL: a regex operator (~) needs a text left operand. SPIP emits
	// "col ~ 'regex'" on numeric columns too (e.g. id_vignette ~ '^0' from the media
	// "sans vignette" filter) → PG errors "operator does not exist: bigint ~ unknown".
	// Cast the left column to text. Casting an already-text column is harmless.
	$res = preg_replace('/([a-zA-Z_][\w.]*)(?<!::text)\s*(~\*?)\s*(\')/', '$1::text $2 $3', $res);

	return $res;
}

function spip_dsql_fromfield($arg) {
	while (preg_match('/^(.*?)FIELD\s*\(([^,]*)((,[^,)]*)*)\)/', $arg, $m)) {
		preg_match_all('/,([^,]*)/', $m[3], $r, PREG_PATTERN_ORDER);
		$res = '';
		$n = 0;
		$index = $m[2];
		foreach ($r[1] as $v) {
			$n++;
			$res .= "\nwhen $index=$v then $n";
		}
		$arg = $m[1] . "case $res else 0 end "
			. substr($arg, strlen($m[0]));
	}

	return $arg;
}

function calculer_dsql_where($v) {
	if (!is_array($v)) {
		return spip_dsql_frommysql($v);
	}

	$op = str_replace('REGEXP', '~', array_shift($v));
	if (!($n = count($v))) {
		return $op;
	} else {
		$arg = calculer_dsql_where(array_shift($v));
		if ($n == 1) {
			return "$op($arg)";
		} else {
			$arg2 = calculer_dsql_where(array_shift($v));
			if ($n == 2) {
				// DSQL/PostgreSQL: the regex operators (~, ~*, !~, !~*) require a text
				// left operand. SPIP applies REGEXP to numeric columns too (e.g.
				// id_vignette ~ '^0' from the "sans vignette" media filter), which PG
				// rejects ("operator does not exist: bigint ~ unknown"). Cast to text.
				if (in_array(trim($op), ['~', '~*', '!~', '!~*'], true) && strpos($arg, '::') === false) {
					$arg = "$arg::text";
				}
				return "($arg $op $arg2)";
			} else {
				return "($arg $op ($arg2) : $v[0])";
			}
		}
	}
}


function calculer_dsql_expression($expression, $v, $join = 'AND') {
	if (empty($v)) {
		return '';
	}

	$exp = "\n$expression ";

	if (!is_array($v)) {
		$v = [$v];
	}

	if (strtoupper($join) === 'AND') {
		return $exp . join("\n\t$join ", array_map('calculer_dsql_where', $v));
	} else {
		return $exp . join($join, $v);
	}
}

function spip_dsql_select_as($args) {
	$argsas = '';
	foreach ($args as $k => $v) {
		if (substr($k, -1) == '@') {
			// c'est une jointure qui se refere au from precedent
			// pas de virgule
			$argsas .= '  ' . $v;
		} else {
			$as = '';
			//  spip_log("$k : $v", _LOG_DEBUG);
			if (!is_numeric($k)) {
				if (preg_match('/\.(.*)$/', $k, $r)) {
					$v = $k;
				} elseif ($v != $k) {
					$p = strpos($v, ' ');
					if ($p) {
						$v = substr($v, 0, $p) . " AS $k" . substr($v, $p);
					} else {
						$as = " AS $k";
					}
				}
			}
			// spip_log("subs $k : $v avec $as", _LOG_DEBUG);
			// if (strpos($v, 'JOIN') === false)  $argsas .= ', ';
			$argsas .= ', ' . $v . $as;
		}
	}

	return substr($argsas, 2);
}

function spip_dsql_fetch($res, $t = '', $serveur = '', $requeter = true) {

	if ($res) {
		$res = pg_fetch_array($res, null, PGSQL_ASSOC);
	}

	return $res;
}

function spip_dsql_seek($r, $row_number, $serveur = '', $requeter = true) {
	if ($r) {
		return pg_result_seek($r, $row_number);
	}
}


function spip_dsql_countsel(
	$from = [],
	$where = [],
	$groupby = [],
	$having = [],
	$serveur = '',
	$requeter = true
) {
	$c = !$groupby ? '*' : ('DISTINCT ' . (is_string($groupby) ? $groupby : join(',', $groupby)));
	$r = spip_dsql_select("COUNT($c)", $from, $where, '', '', '', $having, $serveur, $requeter);
	if (!$requeter) {
		return $r;
	}
	if (!is_resource($r) && !is_object($r)) {
		return 0;
	}
	[$c] = pg_fetch_array($r, null, PGSQL_NUM);

	return $c;
}

function spip_dsql_count($res, $serveur = '', $requeter = true) {
	return !$res ? 0 : pg_numrows($res);
}

function spip_dsql_free($res, $serveur = '', $requeter = true) {
	// rien a faire en postgres
}

function spip_dsql_delete($table, $where = '', $serveur = '', $requeter = true) {

	$connexion = &$GLOBALS['connexions'][$serveur ? strtolower($serveur) : 0];
	$table = prefixer_table_spip($table, $connexion['prefixe']);

	$query = calculer_dsql_expression('DELETE FROM', $table, ',')
		. calculer_dsql_expression('WHERE', $where, 'AND');

	// renvoyer la requete inerte si demandee
	if (!$requeter) {
		return $query;
	}

	$res = spip_dsql_trace_query($query, $serveur);
	if ($res) {
		return pg_affected_rows($res);
	} else {
		return false;
	}
}

function spip_dsql_insert($table, $champs, $valeurs, $desc = [], $serveur = '', $requeter = true) {
	$connexion = &$GLOBALS['connexions'][$serveur ? strtolower($serveur) : 0];
	$prefixe = $connexion['prefixe'];
	$link = $connexion['link'];

	if (!$desc) {
		$desc = description_table($table, $serveur);
	}
	$seq = spip_dsql_sequence($table, true);
	// si pas de cle primaire dans l'insertion, renvoyer curval
	if (!preg_match(",\b$seq\b,", $champs)) {
		$seq = spip_dsql_sequence($table);
		$seq = prefixer_table_spip($seq, $prefixe);
		$seq = "currval('$seq')";
	}

	$table = prefixer_table_spip($table, $prefixe);
	$ret = !$seq ? '' : (" RETURNING $seq");
	$ins = (strlen($champs) < 3)
		? ' DEFAULT VALUES'
		: "$champs VALUES $valeurs";
	$q = "INSERT INTO $table $ins $ret";
	if (!$requeter) {
		return $q;
	}
	$connexion['last'] = $q;
	$r = spip_dsql_query_simple($link, $q);
#	spip_log($q,'dsql.'._LOG_DEBUG);
	if ($r) {
		if (!$ret) {
			return 0;
		}
		if ($r2 = pg_fetch_array($r, null, PGSQL_NUM)) {
			return $r2[0];
		}
	}

	return false;
}

function spip_dsql_insertq($table, $couples = [], $desc = [], $serveur = '', $requeter = true) {

	if (!$desc) {
		$desc = description_table($table, $serveur);
	}
	if (!$desc) {
		die("$table insertion sans description");
	}
	$fields = $desc['field'];

	foreach ($couples as $champ => $val) {
		$couples[$champ] = spip_dsql_cite($val, $fields[$champ] ?? "");
	}

	// recherche de champs 'timestamp' pour mise a jour auto de ceux-ci
	$couples = spip_dsql_ajouter_champs_timestamp($table, $couples, $desc, $serveur);

	return spip_dsql_insert(
		$table,
		'(' . join(',', array_keys($couples)) . ')',
		'(' . join(',', $couples) . ')',
		$desc,
		$serveur,
		$requeter
	);
}


function spip_dsql_insertq_multi($table, $tab_couples = [], $desc = [], $serveur = '', $requeter = true) {

	if (!$desc) {
		$desc = description_table($table, $serveur);
	}
	if (!$desc) {
		die("$table insertion sans description");
	}
	$fields = $desc['field'] ?? [];

	// Re-index to ensure numeric keys (SVP passes associative arrays keyed by prefixe)
	$tab_couples = array_values($tab_couples);

	// recherche de champs 'timestamp' pour mise a jour auto de ceux-ci
	// une premiere fois pour ajouter maj dans les cles
	$c = $tab_couples[0] ?? [];
	$les_cles = spip_dsql_ajouter_champs_timestamp($table, $c, $desc, $serveur);

	$cles = '(' . join(',', array_keys($les_cles)) . ')';
	$valeurs = [];
	foreach ($tab_couples as $couples) {
		foreach ($couples as $champ => $val) {
			$couples[$champ] = spip_dsql_cite($val, $fields[$champ] ?? "");
		}
		// recherche de champs 'timestamp' pour mise a jour auto de ceux-ci
		$couples = spip_dsql_ajouter_champs_timestamp($table, $couples, $desc, $serveur);

		$valeurs[] = '(' . join(',', $couples) . ')';
	}
	$valeurs = implode(', ', $valeurs);

	return spip_dsql_insert($table, $cles, $valeurs, $desc, $serveur, $requeter);
}


function spip_dsql_update($table, $couples, $where = '', $desc = '', $serveur = '', $requeter = true) {

	if (!$couples) {
		return;
	}
	$connexion = $GLOBALS['connexions'][$serveur ? strtolower($serveur) : 0];
	$table = prefixer_table_spip($table, $connexion['prefixe']);

	// recherche de champs 'timestamp' pour mise a jour auto de ceux-ci
	$couples = spip_dsql_ajouter_champs_timestamp($table, $couples, $desc, $serveur);

	$set = [];
	foreach ($couples as $champ => $val) {
		$set[] = $champ . '=' . $val;
	}

	$query = calculer_dsql_expression('UPDATE', $table, ',')
		. calculer_dsql_expression('SET', $set, ',')
		. calculer_dsql_expression('WHERE', $where, 'AND');

	// renvoyer la requete inerte si demandee
	if (!$requeter) {
		return $query;
	}

	return spip_dsql_trace_query($query, $serveur);
}

// idem, mais les valeurs sont des constantes a mettre entre apostrophes
// sauf les expressions de date lorsqu'il s'agit de fonctions SQL (NOW etc)
function spip_dsql_updateq($table, $couples, $where = '', $desc = [], $serveur = '', $requeter = true) {
	if (!$couples) {
		return;
	}
	if (!$desc) {
		$desc = description_table($table, $serveur);
	}
	$fields = $desc['field'];
	foreach ($couples as $k => $val) {
		$couples[$k] = spip_dsql_cite($val, $fields[$k]);
	}

	return spip_dsql_update($table, $couples, $where, $desc, $serveur, $requeter);
}


function spip_dsql_replace($table, $values, $desc, $serveur = '', $requeter = true) {
	if (!$values) {
		spip_log("replace vide $table", 'dsql.' . _LOG_AVERTISSEMENT);

		return 0;
	}
	$connexion = &$GLOBALS['connexions'][$serveur ? strtolower($serveur) : 0];
	$prefixe = $connexion['prefixe'];
	$link = $connexion['link'];

	if (!$desc) {
		$desc = description_table($table, $serveur);
	}
	if (!$desc) {
		die("$table insertion sans description");
	}
	$prim = $desc['key']['PRIMARY KEY'];
	$ids = preg_split('/,\s*/', $prim);
	$noprims = $prims = [];
	foreach ($values as $k => $v) {
		$values[$k] = $v = spip_dsql_cite($v, $desc['field'][$k]);

		if (!in_array($k, $ids)) {
			$noprims[$k] = "$k=$v";
		} else {
			$prims[$k] = "$k=$v";
		}
	}

	// recherche de champs 'timestamp' pour mise a jour auto de ceux-ci
	$values = spip_dsql_ajouter_champs_timestamp($table, $values, $desc, $serveur);

	$where = join(' AND ', $prims);
	if (!$where) {
		return spip_dsql_insert(
			$table,
			'(' . join(',', array_keys($values)) . ')',
			'(' . join(',', $values) . ')',
			$desc,
			$serveur
		);
	}
	$couples = join(',', $noprims);

	$seq = spip_dsql_sequence($table);
	$table = prefixer_table_spip($table, $prefixe);
	$seq = prefixer_table_spip($seq, $prefixe);

	$connexion['last'] = $q = "UPDATE $table SET $couples WHERE $where";
	if ($couples) {
		$couples = spip_dsql_query_simple($link, $q);
#	  spip_log($q,'dsql.'._LOG_DEBUG);
		if (!$couples) {
			return false;
		}
		$couples = pg_affected_rows($couples);
	}
	if (!$couples) {
		$ret = !$seq ? '' :
			(" RETURNING nextval('$seq') < $prim");
		$connexion['last'] = $q = "INSERT INTO $table (" . join(',', array_keys($values)) . ') VALUES (' . join(
			',',
			$values
		) . ")$ret";
		$couples = spip_dsql_query_simple($link, $q);
		if (!$couples) {
			return false;
		} elseif ($ret) {
			$r = pg_fetch_array($couples, null, PGSQL_NUM);
			if ($r[0]) {
				$connexion['last'] = $q = "SELECT setval('$seq', $prim) from $table";
				// Le code de SPIP met parfois la sequence a 0 (dans l'import)
				// MySQL n'en dit rien, on fait pareil pour PG
				$r = @pg_query($link, $q);
			}
		}
	}

	return $couples;
}


function spip_dsql_replace_multi($table, $tab_couples, $desc = [], $serveur = '', $requeter = true) {
	$retour = null;
	// boucler pour traiter chaque requete independemment
	foreach ($tab_couples as $couples) {
		$retour = spip_dsql_replace($table, $couples, $desc, $serveur, $requeter);
	}

	// renvoie le dernier id
	return $retour;
}


// Donne la sequence eventuelle associee a une table
// Pas extensible pour le moment,

function spip_dsql_sequence($table, $raw = false) {

	include_spip('base/serial');
	if (!isset($GLOBALS['tables_principales'][$table])) {
		return false;
	}
	$desc = $GLOBALS['tables_principales'][$table];
	$prim = @$desc['key']['PRIMARY KEY'];
	if (
		!preg_match('/^\w+$/', $prim)
		or !str_contains($desc['field'][$prim], 'int')
	) {
		return '';
	} else {
		return $raw ? $prim : $table . '_' . $prim . '_seq';
	}
}

// Explicite les conversions de Mysql d'une valeur $v de type $t
// Dans le cas d'un champ date, pas d'apostrophe, c'est une syntaxe ad hoc

function spip_dsql_cite($v, $t) {
	if (is_null($v)) {
		return 'NULL';
	} // null php se traduit en NULL SQL

	if (sql_test_date($t)) {
		if ($v and (strpos('0123456789', (string) $v[0]) === false)) {
			return spip_dsql_frommysql($v);
		} else {
			if (strncmp($v, '0000', 4) == 0) {
				$v = '0001' . substr($v, 4);
			}
			if (strpos($v, '-00-00') === 4) {
				$v = substr($v, 0, 4) . '-01-01' . substr($v, 10);
			}

			return "timestamp '$v'";
		}
	} elseif (!sql_test_int($t)) {
		return ("'" . pg_escape_string($v) . "'");
	} elseif (is_numeric($v)) {
		return $v;
	} elseif ($v === '') {
		return '0';
	} elseif ($v[0] == '0' and $v[1] !== 'x' and ctype_xdigit(substr($v, 1))) {
		return substr($v, 1);
	} else {
		spip_log("Warning: '$v'  n'est pas de type $t", 'dsql.' . _LOG_AVERTISSEMENT);

		return intval($v);
	}
}

function spip_dsql_hex($v) {
	return "CAST(x'" . $v . "' as bigint)";
}

function spip_dsql_quote($v, $type = '') {
	if (!is_array($v)) {
		return spip_dsql_cite($v, $type);
	}
	// si c'est un tableau, le parcourir en propageant le type
	foreach ($v as $k => $r) {
		$v[$k] = spip_dsql_quote($r, $type);
	}

	return join(',', $v);
}

function spip_dsql_date_proche($champ, $interval, $unite) {
	return '('
	. $champ
	. (($interval <= 0) ? '>' : '<')
	. (($interval <= 0) ? 'DATE_SUB' : 'DATE_ADD')
	. '('
	. sql_quote(date('Y-m-d H:i:s'))
	. ', INTERVAL '
	. (($interval > 0) ? $interval : (0 - $interval))
	. ' '
	. $unite
	. '))';
}

function spip_dsql_in($val, $valeurs, $not = '', $serveur = '') {
//
// IN (...) souvent limite a 255  elements, d'ou cette fonction assistante
//
	// s'il n'y a pas de valeur, eviter de produire un IN vide: PG rale.
	if (!$valeurs) {
		return $not ? '0=0' : '0=1';
	}
	if (strpos($valeurs, "CAST(x'") !== false) {
		return "($val=" . join("OR $val=", explode(',', $valeurs)) . ')';
	}
	$n = $i = 0;
	$in_sql = '';
	while ($n = strpos($valeurs, ',', $n + 1)) {
		if ((++$i) >= 255) {
			$in_sql .= "($val $not IN (" .
				substr($valeurs, 0, $n) .
				"))\n" .
				($not ? "AND\t" : "OR\t");
			$valeurs = substr($valeurs, $n + 1);
			$i = $n = 0;
		}
	}
	$in_sql .= "($val $not IN ($valeurs))";

	return "($in_sql)";
}

function spip_dsql_error($query = '', $serveur = '', $requeter = true) {
	$link = $GLOBALS['connexions'][$serveur ? strtolower($serveur) : 0]['link'];
	$s = $link ? pg_last_error($link) : pg_last_error();
	if ($s) {
		$s = str_replace('ERROR', 'errcode: 1000 ', $s);
		spip_log("$s - $query", 'dsql.' . _LOG_ERREUR);
	}

	return $s;
}

function spip_dsql_errno($serveur = '') {
	// il faudrait avoir la derniere ressource retournee et utiliser
	// http://fr2.php.net/manual/fr/function.pg-result-error.php
	return 0;
}

function spip_dsql_drop_table($table, $exist = '', $serveur = '', $requeter = true) {
	if ($exist) {
		$exist = ' IF EXISTS';
	}
	if (spip_dsql_query("DROP TABLE$exist $table", $serveur, $requeter)) {
		return true;
	} else {
		return false;
	}
}

// supprime une vue
function spip_dsql_drop_view($view, $exist = '', $serveur = '', $requeter = true) {
	if ($exist) {
		$exist = ' IF EXISTS';
	}

	return spip_dsql_query("DROP VIEW$exist $view", $serveur, $requeter);
}

/**
 * Retourne une ressource de la liste des tables de la base de données
 *
 * @param string $match
 *     Filtre sur tables à récupérer
 * @param string $serveur
 *     Connecteur de la base
 * @param bool $requeter
 *     true pour éxecuter la requête
 *     false pour retourner le texte de la requête.
 * @return ressource
 *     Ressource à utiliser avec sql_fetch()
 **/
function spip_dsql_showbase($match, $serveur = '', $requeter = true) {
	$connexion = &$GLOBALS['connexions'][$serveur ? strtolower($serveur) : 0];
	$link = $connexion['link'];
	$connexion['last'] = $q = 'SELECT tablename FROM pg_tables WHERE tablename ILIKE ' . _q($match);

	return spip_dsql_query_simple($link, $q);
}

function spip_dsql_table_exists(string $table, $serveur = '', $requeter = true) {
	$connexion = &$GLOBALS['connexions'][$serveur ? strtolower($serveur) : 0];
	$link = $connexion['link'];
	$q = "SELECT 1 FROM pg_tables WHERE schemaname='public' AND tablename=" . _q($table);
	$r = spip_dsql_query_simple($link, $q);
	if (!$requeter) {
		return $r;
	}
	return (bool) ($r && pg_num_rows($r) > 0);
}

function spip_dsql_showtable($nom_table, $serveur = '', $requeter = true) {
	$connexion = &$GLOBALS['connexions'][$serveur ? strtolower($serveur) : 0];
	$link = $connexion['link'];
	$connexion['last'] = $q = 'SELECT column_name, column_default, data_type FROM information_schema.columns WHERE table_name ILIKE ' . _q($nom_table);

	$res = spip_dsql_query_simple($link, $q);
	if (!$res) {
		return false;
	}

	// etrangement, $res peut ne rien contenir, mais arriver ici...
	// il faut en tenir compte dans le return
	$fields = [];
	while ($field = pg_fetch_array($res, null, PGSQL_NUM)) {
		$fields[$field[0]] = $field[2] . (!$field[1] ? '' : (' DEFAULT ' . $field[1]));
	}
	$connexion['last'] = $q = 'SELECT indexdef FROM pg_indexes WHERE tablename ILIKE ' . _q($nom_table);
	$res = spip_dsql_query_simple($link, $q);
	$keys = [];
	// Get primary key from constraints
	$qpk = "SELECT kcu.column_name FROM information_schema.table_constraints tc JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema WHERE tc.table_name ILIKE " . _q($nom_table) . " AND tc.constraint_type = 'PRIMARY KEY' ORDER BY kcu.ordinal_position";
	$respk = spip_dsql_query_simple($link, $qpk);
	$pk_cols = [];
	if ($respk) {
		while ($row = pg_fetch_array($respk, null, PGSQL_NUM)) {
			$pk_cols[] = $row[0];
		}
	}
	if ($pk_cols) {
		$keys['PRIMARY KEY'] = implode(', ', $pk_cols);
	}
	while ($index = pg_fetch_array($res, null, PGSQL_NUM)) {
		if (preg_match('/CREATE\s+(UNIQUE\s+)?INDEX\s([^\s]+).*\((.*)\)$/', $index[0], $r)) {
			$nom = str_replace($nom_table . '_', '', $r[2]);
			if ($r[1]) {
				$keys['UNIQUE ' . $nom] = $r[3];
			} else {
				$keys['KEY ' . $nom] = $r[3];
			}
		}
	}

	return count($fields) ? ['field' => $fields, 'key' => $keys] : false;
}

// Fonction de creation d'une table SQL nommee $nom
// a partir de 2 tableaux PHP :
// champs: champ => type
// cles: type-de-cle => champ(s)
// si $autoinc, c'est une auto-increment (i.e. serial) sur la Primary Key
// Le nom des index est prefixe par celui de la table pour eviter les conflits
function spip_dsql_create($nom, $champs, $cles, $autoinc = false, $temporary = false, $serveur = '', $requeter = true) {

	$connexion = $GLOBALS['connexions'][$serveur ? strtolower($serveur) : 0];
	$link = $connexion['link'];
	$nom = prefixer_table_spip($nom, $connexion['prefixe']);

	$query = $prim = $prim_name = $v = $s = $p = '';
	$keys = [];

	// certains plugins declarent les tables  (permet leur inclusion dans le dump)
	// sans les renseigner (laisse le compilo recuperer la description)
	if (!is_array($champs) || !is_array($cles)) {
		return;
	}

	foreach ($cles as $k => $v) {
		if (strpos($k, 'KEY ') === 0) {
			$n = str_replace('`', '', $k);
			$v = str_replace('`', '"', $v);
			$i = $nom . preg_replace('/KEY +/', '_', $n);
			if ($k != $n) {
				$i = "\"$i\"";
			}
			$keys[] = "CREATE INDEX ASYNC $i ON $nom ($v);";
		} elseif (strpos($k, 'UNIQUE ') === 0) {
			$k = preg_replace('/^UNIQUE +/', '', $k);
			$prim .= "$s\n\t\tCONSTRAINT " . str_replace('`', '"', $k) . " UNIQUE ($v)";
		} else {
			$prim .= "$s\n\t\t" . str_replace('`', '"', $k) . " ($v)";
		}
		if ($k == 'PRIMARY KEY') {
			$prim_name = $v;
		}
		$s = ',';
	}
	$s = '';

	$character_set = '';
	// DSQL does not support CHARACTER SET or COLLATE clauses

	foreach ($champs as $k => $v) {
		$k = str_replace('`', '"', $k);
		if (preg_match(',([a-z]*\s*(\(\s*[0-9]*\s*\))?(\s*binary)?),i', $v, $defs)) {
			if (preg_match(',(char|text),i', $defs[1]) and !preg_match(',binary,i', $defs[1])) {
				$v = $defs[1] . $character_set . ' ' . substr($v, strlen($defs[1]));
			}
		}

		$query .= "$s\n\t\t$k "
			. (($autoinc && ($prim_name == $k) && preg_match(',\b(big|small|medium|tiny)?int\b,i', $v))
				? ' bigint GENERATED BY DEFAULT AS IDENTITY (CACHE 1)'
				: mysql2dsql_type($v)
			);
		$s = ',';
	}
	$temporary = $temporary ? 'TEMPORARY' : '';

	// En l'absence de "if not exists" en PG, on neutralise les erreurs

	$q = "CREATE $temporary TABLE $nom ($query" . ($prim ? ",$prim" : '') . ')' .
		($character_set ? " DEFAULT $character_set" : '')
		. "\n";

	if (!$requeter) {
		return $q;
	}
	$connexion['last'] = $q;
	$r = @pg_query($link, $q);

	if (!$r) {
		spip_log("Impossible de creer cette table: $q", 'dsql.' . _LOG_ERREUR);
	} else {
		foreach ($keys as $index) {
			pg_query($link, $index);
		}
	}

	return $r;
}


function spip_dsql_create_base($nom, $serveur = '', $requeter = true) {
	return spip_dsql_query("CREATE DATABASE $nom", $serveur, $requeter);
}

// Fonction de creation d'une vue SQL nommee $nom
function spip_dsql_create_view($nom, $query_select, $serveur = '', $requeter = true) {
	if (!$query_select) {
		return false;
	}
	// vue deja presente
	if (sql_showtable($nom, false, $serveur)) {
		if ($requeter) {
			spip_log("Echec creation d'une vue sql ($nom) car celle-ci existe deja (serveur:$serveur)", 'dsql.' . _LOG_ERREUR);
		}

		return false;
	}

	$query = "CREATE VIEW $nom AS " . $query_select;

	return spip_dsql_query($query, $serveur, $requeter);
}

function spip_dsql_set_charset($charset, $serveur = '', $requeter = true) {
	$connexion = &$GLOBALS['connexions'][$serveur ? strtolower($serveur) : 0];
	return @pg_query($connexion['link'], "SET client_encoding TO " . pg_escape_literal($connexion['link'], $charset));
}

function spip_dsql_get_charset($charset = [], $serveur = '', $requeter = true) {
	// DSQL/PostgreSQL uses UTF-8 by default; return a compatible structure
	if (!$charset) {
		return ['charset' => 'utf8', 'collation' => 'en_US.UTF-8'];
	}
	// Accept any utf8 variant
	if (stripos($charset['charset'], 'utf') !== false) {
		return ['charset' => 'utf8', 'collation' => 'en_US.UTF-8'];
	}
	return null;
}

function spip_dsql_set_connect_charset($charset, $serveur = '', $requeter = true) {
	spip_log('changement de charset sql a ecrire en PG', 'dsql.' . _LOG_ERREUR);
}


/**
 * Optimise une table SQL
 *
 * @param $table nom de la table a optimiser
 * @param $serveur nom de la connexion
 * @param $requeter effectuer la requete ? sinon retourner son code
 * @return bool|string true / false / requete
 **/
function spip_dsql_optimize($table, $serveur = '', $requeter = true) {
	return spip_dsql_query('VACUUM ' . $table, $serveur, $requeter);
}

// Selectionner la sous-chaine dans $objet
// correspondant a $lang. Cf balise Multi de Spip

function spip_dsql_multi($objet, $lang) {
	$r = 'regexp_replace('
		. $objet
		. ",'<multi>.*[[]"
		. $lang
		. "[]]([^[]*).*</multi>', E'\\\\1') AS multi";

	return $r;
}

// Palanquee d'idiosyncrasies MySQL dans les creations de table
// A completer par les autres, mais essayer de reduire en amont.

function mysql2dsql_type($v) {
	$remplace = [
		'/auto_increment/i' => '', // non reconnu
		'/bigint/i' => 'bigint',
		'/mediumint/i' => 'mediumint',
		'/smallint/i' => 'smallint',
		'/tinyint/i' => 'int',
		'/int\s*[(]\s*\d+\s*[)]/i' => 'int',
		'/longtext/i' => 'text',
		'/mediumtext/i' => 'text',
		'/tinytext/i' => 'text',
		'/longblob/i' => 'text',
		'/0000-00-00/' => '0001-01-01',
		'/datetime/i' => 'timestamp',
		'/unsigned/i' => '',
		'/double/i' => 'double precision',
		'/VARCHAR\((\d+)\)\s+BINARY/i' => 'varchar(\1)',
		'/ENUM *[(][^)]*[)]/i' => 'varchar(255)',
		'/(timestamp .* )ON .*$/is' => '\\1',
	];

	return preg_replace(array_keys($remplace), array_values($remplace), $v);
}

// Renvoie false si on n'a pas les fonctions pg (pour l'install)
function spip_versions_dsql() {
	return function_exists('pg_connect');
}
