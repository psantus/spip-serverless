# Initialiser la base DSQL pour un nouvel environnement (SPIP)

**Français** · [English](../en/db-bootstrap.md)

Comment **initialiser le schéma SPIP** (les tables `spip_*`) sur un cluster Aurora DSQL
vide — une fois par environnement (test / prep / prod).

Méthode : exécuter **`spip/scripts/bootstrap-db.php` dans le conteneur**. Reproductible
d'un compte à l'autre, sans assistant web, sans avoir à annuler le `connect.php` intégré.

## Pourquoi un script CLI (et non l'assistant / un exec / une route HTTP)

- `config/connect.php` est intégré à l'image → SPIP se croit toujours installé et ne
  lance jamais l'assistant.
- Le script CLI charge le noyau SPIP (`inc_version.php`) comme le contrôleur frontal,
  puis appelle `creer_base()` + les mises à niveau des plugins + la création de l'admin.
  Pas de HTTP, pas d'authentification, idempotent. Nul besoin de retirer `connect.php` :
  il ne fait que fournir la connexion au cluster, et `creer_base()` y crée le schéma quoi
  qu'il en soit.

## Ce que fait le script (idempotent)

1. `creer_base()` — tables du cœur SPIP (relance `alterer_base`, tolère « already exists »)
2. metas `version_installee` / `nouvelle_install` (sur une base neuve)
3. `actualise_plugins_actifs()` + `plugin_installes_meta()` — exécute le `*_upgrade()` de
   chaque plugin actif (vos migrations de plugin, plus celles éventuellement fournies)
4. crée l'auteur admin (`0minirezo`, webmestre) avec un hash **poivré** via
   `Spip\Chiffrer\Password::hacher` (voir `docs/fr/spip-passwords.md`)

## L'exécuter (par environnement)

Depuis l'**image Lambda** (recommandé : `prepend.php` écrit `cles.php` à partir de
`SPIP_CLES` et positionne les `_DIR_*`, de sorte que le secret d'authentification est
disponible pour hacher le mot de passe admin) :

```bash
ENV=test ; PROFILE=<your-profile> ; REGION=eu-west-3
CLUSTER=$(cd iac/spip/static && AWS_PROFILE=$PROFILE terraform output -raw dsql_endpoint)

# key material (secret_du_site + secret_des_auth) from SSM
SPIP_CLES=$(AWS_PROFILE=$PROFILE aws ssm get-parameter --name /spip-serverless/$ENV/spip/cles \
  --with-decryption --region $REGION --query Parameter.Value --output text)

# temporary credentials for the target account
eval "$(AWS_PROFILE=$PROFILE aws configure export-credentials --format env)"

docker run --rm \
  -e SPIP_DSQL_CLUSTER=$CLUSTER \
  -e SPIP_TABLE_PREFIX=spip \
  -e SPIP_PUBLIC_URL=https://<cloudfront-or-custom-domain> \
  -e SPIP_CLES="$SPIP_CLES" \
  -e AWS_ACCESS_KEY_ID -e AWS_SECRET_ACCESS_KEY -e AWS_SESSION_TOKEN \
  -e AWS_REGION=$REGION \
  --entrypoint php \
  <account>.dkr.ecr.$REGION.amazonaws.com/spip-serverless:<tag> \
  -d auto_prepend_file= /var/task/scripts/bootstrap-db.php \
    --admin-login=admin --admin-email=you@example.org --admin-pass='<pass>'
```

**Important — `-d auto_prepend_file=`** : `prepend.php` (auto_prepend sur Lambda) casse
`inc_version.php` dans un contexte CLI ; le désactiver. Le script réécrit lui-même
`cles.php` à partir de `$SPIP_CLES` pour que le hash du mot de passe admin fonctionne.

Prérequis SSM : `/spip-serverless/<env>/spip/cles` doit contenir un vrai JSON
`{"secret_du_site":"<b64 32B>","secret_des_auth":"<b64 32B>"}` (la stack statique le crée
avec un placeholder `CHANGE_ME_AFTER_CREATION` — à renseigner une fois par compte) :

```bash
SITE=$(openssl rand -base64 32); AUTH=$(openssl rand -base64 32)
AWS_PROFILE=$PROFILE aws ssm put-parameter --name /spip-serverless/$ENV/spip/cles \
  --type SecureString --overwrite --region $REGION \
  --value "$(printf '{"secret_du_site": "%s", "secret_des_auth": "%s"}' "$SITE" "$AUTH")"
```

Sortie attendue (rapport ligne par ligne) :
```
db_connection   ok
creer_base      ok
metas           created
plugins_upgrade ok
admin           created (id_auteur=1)
status          done
```

## Ordre

1. **test** — exécuter le script, puis vérifier `https://<cloudfront-domain>/spip.php?page=backend` → 200.
2. **prep**, puis **prod** — même script, changer cluster + credentials + SPIP_CLES + admin.

## Fichiers concernés

- `spip/scripts/bootstrap-db.php` — le script CLI
- `spip/overlay/config/connect.php` — connexion DSQL dynamique (jeton IAM)
- `spip/overlay/php/prepend.php` — écrit `cles.php` à partir de `SPIP_CLES`, positionne les `_DIR_*`
- `docs/fr/spip-passwords.md` — hash poivré de l'auteur admin
- `docs/fr/dsql.md` — connexion psql au cluster via jeton IAM

## Notes techniques (pièges résolus)

Exécuter `creer_base()` depuis la CLI sur une base **vide** a nécessité de contourner
plusieurs comportements de SPIP :

1. **prepend.php casse `inc_version.php` en CLI** → exécuter avec `php -d auto_prepend_file=`.
2. **`inc_version.php` sort prématurément sans le noyau SpipLeague** → charger d'abord
   `vendor/autoload.php` (comme `spip.php`), puis `param('spip.dirs.core')`.
3. **`spip_connect()` échoue sur une base vide** : `spip_connect_main()` lit le charset
   depuis `spip_meta` (absent) → renvoie false → chaque `sql_*` rend la page 503
   `MinipageAdmin` dans une **boucle infinie**. Contourner (comme `install/etape_3.php`)
   en pré-remplissant la connexion par défaut à la main. L'index de la connexion par
   défaut est l'entier **`0`** (`$index = $serveur ?: 0`), donc peupler
   `$GLOBALS['connexions'][0]` (PAS `['']`) avec la description + le jeu de fonctions
   `$GLOBALS['spip_dsql_functions_1']` + prefix/db/version.
4. **`_ECRIRE_INSTALL`** défini pour le mode installation.
5. **`cles.php` absent** (auto_prepend désactivé) → le script le réécrit à partir de
   `SPIP_CLES` dans `_DIR_ETC` pour que `SpipCles::getSecretAuth()` renvoie le secret
   d'authentification.

## Piège SVP / plugin_installes

La page privée « Plugins » (`?exec=admin_plugin`) peut se bloquer sur un environnement
fraîchement initialisé à cause de **SVP** : `svp_actualiser_paquets_locaux()` fait
`in_array($x, lire_config('plugin_installes'))`, et quand `plugin_installes` est absent
(jamais initialisé par le bootstrap CLI) → `in_array(x, null)` → fatal → page bloquée.

Le script s'en prémunit :
- `actualise_plugins_actifs()` (rafraîchit la meta `plugin`)
- si `plugin_installes` est absent, l'initialiser à `[]`
- puis `plugin_installes_meta()` — c'est ce que fait la première visite admin : il exécute
  l'installation/mise à niveau de chaque plugin actif ET remplit `plugin_installes`. C'est
  le déclencheur fiable pour TOUTES les migrations, contrairement à
  `actualise_plugins_actifs()` seul.

Réparation manuelle (si un env est déjà bloqué) : forcer en base
`UPDATE spip_meta SET valeur='0.6.2' WHERE nom='svp_base_version';` et insérer un tableau
`plugin_installes` sérialisé (le copier depuis un env sain).
