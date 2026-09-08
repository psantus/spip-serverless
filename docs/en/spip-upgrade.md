# Upgrading SPIP core

[Français](../fr/spip-upgrade.md) · **English**

SPIP core is **not vendored** in this repo. It is fetched at build time from the official
archive (`files.spip.net`) at a single pinned version. Upgrading SPIP is therefore just:
**bump the pinned version, rebuild, test on a non-prod environment, promote.**

## Repo side vs online side (the mental model)

The image is **immutable and stateless**. Two clean halves:

| You change it in the REPO (→ build → deploy) | It happens ONLINE, automatically, in DSQL |
|---|---|
| SPIP version (`spip/SPIP_VERSION`), plugins, overlays, config | The SPIP/plugin **DB schema migration** replays on the **first authenticated `/ecrire` visit** after deploy (if `spip_version_base` bumped) — no manual DB step |
| Rebuild the Docker image, `make deploy` / CI | Your **content** (articles, media, users) stays in DSQL, untouched by a deploy |

So: **all code/version changes are repo → build → deploy; all data/migration changes are
runtime**, triggered by the first admin request (same cold-start mechanism as the initial
plugin migrations — see `docs/en/db-bootstrap.md`). You never run a DB migration by hand for
an upgrade; you deploy the image and open the admin once.

## Where the version is pinned

One source of truth: **`spip/SPIP_VERSION`** (e.g. `4.4.22`).

It is consumed by:
- `spip/Dockerfile` — the `spip-core` stage downloads `spip-v<VERSION>.zip` (the `ARG
  SPIP_VERSION` default mirrors the file; the Makefile/CI pass it explicitly)
- `Makefile` — `SPIP_VERSION := $(shell cat spip/SPIP_VERSION)`, passed as `--build-arg`
- `.github/workflows/deploy.yml` — reads the file into `$SPIP_VERSION`
- `spip/scripts/fetch-spip.sh` — populates the git-ignored `spip/src/` for local tooling

## Patch / minor upgrade (e.g. 4.4.21 → 4.4.22)

1. Find the target version on <https://www.spip.net/fr_download>.
2. Bump it:
   ```bash
   echo 4.4.22 > spip/SPIP_VERSION
   ```
3. (Optional, for local IDE indexing) refresh the local copy:
   ```bash
   make fetch-spip        # rsync --delete into spip/src/ (git-ignored)
   ```
4. Rebuild and test on a disposable environment:
   ```bash
   make deploy ENV=test
   ```
   Check the admin (`/ecrire`), a public page, and login.
5. Commit and let CI promote (see `docs/en/environments.md`).

Our customisations live **outside** SPIP core, so a core upgrade never touches them:
- `spip/overlay/**` — files that override core at build time (connect.php, mes_options*,
  install.php, dsql.php, prepend.php, router.php)
- `spip/plugins/**`, `spip/plugins-vendor/**` — our + third-party plugins
- `spip/scripts/patch-documents.php` — the S3 patch applied to `ecrire/inc/documents.php`

## Major/minor version bump (e.g. 4.4 → 4.5)

Riskier — core can add/remove files and change APIs:

- Review the files our overlays replace (`spip/overlay/ecrire/**`, `config/**`): if core
  changed their signature/logic, adapt the overlay.
- Re-check `spip/scripts/patch-documents.php` — the patched core files may have moved
  (the build will fail loudly if the patch no longer applies).
- Bump plugin `compatibilite` ranges in your `paquet.xml` files.
- Verify the PHP version (Dockerfile: PHP 8.5 via the Bref base image).

## Notes

- The core upgrade does **not** replay SPIP's own DB migrations: if core bumps its
  `spip_version_base`, the SPIP upgrade runs on the first authenticated admin visit (same
  cold-start mechanism as plugin migrations — see `docs/en/db-bootstrap.md`).
- Never commit a downloaded zip or `spip/src/` — both are git-ignored.