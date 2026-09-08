# Bootstrap the DSQL database for a new environment (SPIP)

How to **initialise the SPIP schema** (the `spip_*` tables) on an empty Aurora DSQL
cluster — once per environment (test / prep / prod).

Method: run **`spip/scripts/bootstrap-db.php` inside the container**. Reproducible
across accounts, no web wizard, no need to undo the baked `connect.php`.

## Why a CLI script (not the wizard / an exec / an HTTP route)

- `config/connect.php` is baked into the image → SPIP always thinks it is installed and
  never launches the wizard.
- The CLI script loads the SPIP kernel (`inc_version.php`) like the front controller,
  then calls `creer_base()` + plugin upgrades + admin creation. No HTTP, no auth,
  idempotent. There is no need to remove `connect.php`: it just supplies the cluster
  connection, and `creer_base()` creates the schema on it regardless.

## What the script does (idempotent)

1. `creer_base()` — SPIP core tables (re-runs `alterer_base`, tolerates "already exists")
2. metas `version_installee` / `nouvelle_install` (on a fresh DB)
3. `actualise_plugins_actifs()` + `plugin_installes_meta()` — runs each active plugin's
   `*_upgrade()` (your plugin migrations, plus any bundled ones)
4. creates the admin author (`0minirezo`, webmestre) with a **peppered** hash via
   `Spip\Chiffrer\Password::hacher` (see `docs/spip-passwords.md`)

## Run it (per environment)

From the **Lambda image** (recommended: `prepend.php` writes `cles.php` from `SPIP_CLES`
and sets the `_DIR_*`, so the auth secret is available to hash the admin password):

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
  -e SPIP_CLES="$SPIP_CLES" \
  -e AWS_ACCESS_KEY_ID -e AWS_SECRET_ACCESS_KEY -e AWS_SESSION_TOKEN \
  -e AWS_REGION=$REGION \
  --entrypoint php \
  <account>.dkr.ecr.$REGION.amazonaws.com/spip-serverless:<tag> \
  php -d auto_prepend_file= /var/task/scripts/bootstrap-db.php \
    --admin-login=admin --admin-email=you@example.org --admin-pass='<pass>'
```

**Important — `-d auto_prepend_file=`**: `prepend.php` (auto_prepend on Lambda) breaks
`inc_version.php` in a CLI context; disable it. The script rewrites `cles.php` from
`$SPIP_CLES` itself so the admin password hash works.

SSM prerequisite: `/spip-serverless/<env>/spip/cles` must hold real JSON
`{"secret_du_site":"<b64 32B>","secret_des_auth":"<b64 32B>"}` (the static stack creates
it with a `CHANGE_ME_AFTER_CREATION` placeholder — fill it once per account):

```bash
SITE=$(openssl rand -base64 32); AUTH=$(openssl rand -base64 32)
AWS_PROFILE=$PROFILE aws ssm put-parameter --name /spip-serverless/$ENV/spip/cles \
  --type SecureString --overwrite --region $REGION \
  --value "$(printf '{"secret_du_site": "%s", "secret_des_auth": "%s"}' "$SITE" "$AUTH")"
```

Expected output (line-by-line report):
```
db_connection   ok
creer_base      ok
metas           created
plugins_upgrade ok
admin           created (id_auteur=1)
status          done
```

## Order

1. **test** — run the script, then check `https://<cloudfront-domain>/spip.php?page=backend` → 200.
2. **prep**, then **prod** — same script, change cluster + creds + SPIP_CLES + admin.

## Files involved

- `spip/scripts/bootstrap-db.php` — the CLI script
- `spip/overlay/config/connect.php` — dynamic DSQL connection (IAM token)
- `spip/overlay/php/prepend.php` — writes `cles.php` from `SPIP_CLES`, sets `_DIR_*`
- `docs/spip-passwords.md` — peppered admin author hash
- `docs/dsql.md` — psql connection to the cluster via IAM token

## Technical notes (pitfalls solved)

Running `creer_base()` from the CLI on an **empty** database required working around
several SPIP behaviours:

1. **prepend.php breaks `inc_version.php` in CLI** → run with `php -d auto_prepend_file=`.
2. **`inc_version.php` early-returns without the SpipLeague kernel** → load
   `vendor/autoload.php` first (like `spip.php`), then `param('spip.dirs.core')`.
3. **`spip_connect()` fails on an empty DB**: `spip_connect_main()` reads the charset from
   `spip_meta` (absent) → returns false → every `sql_*` renders the 503 `MinipageAdmin`
   page in an **infinite loop**. Work around it (like `install/etape_3.php`) by
   pre-filling the default connection by hand. The default connection index is the
   integer **`0`** (`$index = $serveur ?: 0`), so populate `$GLOBALS['connexions'][0]`
   (NOT `['']`) with the description + the `$GLOBALS['spip_dsql_functions_1']` function
   set + prefix/db/version.
4. **`_ECRIRE_INSTALL`** defined for install mode.
5. **`cles.php` absent** (auto_prepend off) → the script rewrites it from `SPIP_CLES`
   into `_DIR_ETC` so `SpipCles::getSecretAuth()` returns the auth secret.

## SVP / plugin_installes gotcha

The private "Plugins" page (`?exec=admin_plugin`) can hang on a freshly-bootstrapped
environment because of **SVP**: `svp_actualiser_paquets_locaux()` does
`in_array($x, lire_config('plugin_installes'))`, and when `plugin_installes` is absent
(never initialised by the CLI bootstrap) → `in_array(x, null)` → fatal → page blocked.

The script guards against it:
- `actualise_plugins_actifs()` (refresh the `plugin` meta)
- if `plugin_installes` is absent, initialise it to `[]`
- then `plugin_installes_meta()` — this is what the first admin visit does: it runs each
  active plugin's install/upgrade AND fills `plugin_installes`. It is the reliable trigger
  for ALL migrations, unlike `actualise_plugins_actifs()` alone.

Manual repair (if an env is already stuck): force in the DB
`UPDATE spip_meta SET valeur='0.6.2' WHERE nom='svp_base_version';` and insert a
serialised `plugin_installes` array (copy it from a healthy env).
