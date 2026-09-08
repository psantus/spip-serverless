# Managing SPIP plugins on Lambda

SPIP runs as an **immutable Docker image** here. Plugins are baked into the image at
build time — there is no runtime plugin installation. Adding a plugin therefore means:
drop it in the repo, add a COPY in the Dockerfile if needed, rebuild, redeploy.

## Where plugins live in this repo

```
spip/
├── plugins/                # OUR custom plugins (source of truth)
│   ├── s3upload/           # presigned-URL uploads to S3
│   └── sessions_dynamodb/  # DynamoDB session storage (loaded via a squelettes override)
├── plugins-vendor/         # third-party plugins vendored into the repo
│   └── logs_stderr/        # redirect spip_log() to stderr → CloudWatch
└── Dockerfile              # COPY-s the above into the image
```

SPIP core plugins (`plugins-dist/`) are **not** in this repo — they come from the SPIP
core fetched at build time (see `docs/spip-upgrade.md`). A few of them are removed in the
Dockerfile (`bigup`, `forum`, `statistiques`, …) because they don't fit a serverless/
read-mostly deployment.

## How the image maps folders to SPIP

| Source | Image path | Activation |
|---|---|---|
| fetched `plugins-dist/` (SPIP core) | `/var/task/plugins-dist/` | always active |
| `spip/plugins-vendor/*` | `/var/task/plugins-dist/*` | always active |
| `spip/plugins/s3upload/` | `/var/task/plugins-dist/s3upload/` | always active |
| `spip/plugins/sessions_dynamodb/` | via `squelettes/inc/session.php` | override, not a plugin |

Everything placed under `plugins-dist/` is scanned and activated by SPIP on cold start;
SVP registers it in the DB (`spip_paquets` with `actif='oui'`) and wires the
`paquet.xml` pipeline declarations automatically.

## Add a THIRD-PARTY plugin

1. Download the plugin into `spip/plugins-vendor/<plugin-name>/` (it must have a valid
   `paquet.xml`).
2. Nothing else to change — the Dockerfile already does
   `COPY spip/plugins-vendor/ /var/task/plugins-dist/`.
3. Rebuild + deploy. The plugin is active on the next cold start.

> Pin the plugin version (commit the vendored copy) so builds stay reproducible.

## Add a CUSTOM plugin (yours)

1. Create `spip/plugins/<prefix>/` with at least a `paquet.xml`:

   ```xml
   <paquet
       prefix="myplugin"
       categorie="outil"
       version="1.0.0"
       etat="stable"
       compatibilite="[4.0.0;4.*]"
   >
       <nom>My Plugin</nom>
       <auteur>Your name</auteur>
       <licence>GPL</licence>
       <pipeline nom="header_prive" inclure="myplugin_pipelines.php" />
   </paquet>
   ```

2. Add COPY lines in `spip/Dockerfile` (in the `lambda` stage), next to the s3upload one:

   ```dockerfile
   COPY spip/plugins/<prefix>/ /var/task/plugins-dist/<prefix>/
   ```
   and add it to the `rm -rf /var/task/plugins/...` line so the duplicate under
   `plugins/` is not shipped.

3. Data-model migrations go in `<prefix>_administrations.php` (SPIP's native schema
   versioning — `spip_<prefix>_metas`/`maj_tables`). They run on the first authenticated
   admin visit, or via `spip/scripts/bootstrap-db.php` (see `docs/db-bootstrap.md`).

4. Rebuild + deploy.

## Exposing a REST API from a plugin

The Lambda front controller (`spip/overlay/router.php`) proxies everything to SPIP. To
serve a custom API under, say, `/api/*`, add a branch **before** the `/ecrire` one that
requires your plugin's entry point — the router already documents the pattern in a
comment. Then add a matching CloudFront behavior in `iac/spip/app/cloudfront.tf` if you
want per-path caching.

## Plugin loading order (Lambda)

1. `auto_prepend_file` → `prepend.php` (S3 stream wrapper, OTEL, tmp dirs)
2. SPIP bootstrap → `ecrire/inc/utils.php`
3. `config/mes_options.php`
4. plugin `_options.php` files (from the SPIP plugin cache in `/tmp`)
5. plugin `_fonctions.php` files
6. pipeline execution

## Special cases in this repo

### sessions_dynamodb
Overrides `ecrire_fichier()`/`lire_fichier()` for session files, which conflicts with
core when loaded as a normal plugin. Loaded via a squelettes path override instead:
```dockerfile
COPY spip/plugins/sessions_dynamodb/inc/session.php /var/task/squelettes/inc/session.php
```

### s3upload
- JS served from S3 at `/plugins-dist/s3upload/s3upload.js`
- `header_prive` pipeline injects the `<script>` tag
- `exec/s3upload_presign.php` copied to `/var/task/ecrire/exec/` for the presign endpoint
- `mes_options.php` converts `_s3key` POST fields into fake `$_FILES` entries

### logs_stderr
Redirects `spip_log()` to stderr so CloudWatch captures SPIP logs. Essential on Lambda —
keep it.
