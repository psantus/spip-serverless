# Build & rouages de l'image

**Français** · [English](../en/build.md)

Le runtime SPIP est une **image Docker unique, immuable et multi-étages** (ARM64, PHP 8.5
via Bref). `make build` la construit ; `make deploy` la build + push sur ECR et pointe le
Lambda sur le nouveau digest. Rien n'est installé au runtime — tout est embarqué ici.

## Étapes du Dockerfile (`spip/Dockerfile`)

| Étape | Rôle |
|---|---|
| `spip-core` | Télécharge le zip de release SPIP officiel pour `SPIP_VERSION` dans `/spip-src` (le cœur n'est **pas** vendorisé — voir [spip-upgrade.md](spip-upgrade.md)). |
| `ext-pgsql` | Compile l'extension PHP `pgsql` — Aurora DSQL parle le protocole PostgreSQL. |
| `ext-gd` | Compile l'extension PHP `gd` (redimensionnement d'images/vignettes). |
| `vendors` | Lance Composer **par-dessus** le cœur SPIP récupéré, puis réduit `vendor/`. |
| `local` | Image Apache pour `make run-local` (dev local uniquement). |
| `lambda` | Image finale : assemble cœur + vendor + extensions + overlays + plugins, patche `documents.php` pour S3, pré-chauffe l'opcache, ajoute le collecteur ADOT. |

## Dépendances Composer (étape `vendors`)

Le cœur SPIP embarque déjà son propre `vendor/` dans le zip de release. On y ajoute les
briques qui font tourner SPIP sur Lambda :

```
composer require \
  bref/bref:^3.0             # runtime PHP pour Lambda (FPM)
  bref/secrets-loader:^1     # résout les valeurs bref-ssm:/... depuis SSM au boot
  aws/aws-sdk-php:^3.0       # token IAM DSQL, S3, sessions DynamoDB, SSM, CloudFront…
  open-telemetry/sdk:^1      # tracing
  open-telemetry/exporter-otlp:^1
```

Puis `spip/scripts/shrink-vendor.sh vendor` s'exécute (voir plus bas).

## `shrink-vendor.sh` — pourquoi et quoi

Le paquet complet `aws/aws-sdk-php` embarque les données + classes client de **tous** les
services AWS (~plusieurs centaines de Mo). Une image Lambda n'en a besoin que de quelques-uns,
et une image plus petite = cold start plus rapide et stockage moins cher. Le script :

- **Ne garde que les services SDK AWS utilisés par SPIP** (une liste blanche `KEEP_SERVICES`)
  et supprime le reste de `aws-sdk-php/src/data/*` et `src/<Service>/`.
- **Retire docs/tests/exemples** de tout l'arbre `vendor/` (`*.md`, `CHANGELOG*`,
  `LICENSE*`, `README*`, `tests/`, `docs/`, `examples/`).
- Affiche la taille économisée (`AWS SDK: <avant>MB → <après>MB`).

> `KEEP_SERVICES` est réduit à ce que la plateforme utilise réellement : `dsql`, `dynamodb`,
> `s3`, `ssm`, `sts` (chaîne de credentials) et `ses`/`email` (mail optionnel — voir
> [email-ses.md](email-ses.md)). Ajoute le service dont tes propres plugins ont besoin.

Pour ajouter un service SDK dont ton plugin a besoin : ajoute-le à `KEEP_SERVICES` (et à la
liste `case` de `src/`) dans `spip/scripts/shrink-vendor.sh`, puis rebuild.

## Pré-chauffage de l'opcache

`compile-opcache.php` (lancé avec `opcache-build.ini`) compile les fichiers PHP dans un
**cache fichier** opcache en lecture seule, embarqué dans l'image (`/bref/opcache`) et lu au
runtime. Ça retire la compilation du premier appel du chemin de cold start.

## Briques d'observabilité

- **Collecteur ADOT** : `make download-adot` récupère l'extension Lambda du collecteur OTEL
  AWS dans `spip/overlay/adot-collector` (git-ignoré) ; l'image l'embarque comme extension
  `/opt`. Les traces vont OTEL → collecteur → X-Ray. Voir [tracing.md](tracing.md).
- **Logs** : `logs_stderr` envoie `spip_log()` sur stderr → CloudWatch. Voir [logging.md](logging.md).

## Où changer quoi

| Tu veux… | Éditer |
|---|---|
| Monter SPIP de version | `spip/SPIP_VERSION` (voir [spip-upgrade.md](spip-upgrade.md)) |
| Ajouter une dépendance PHP/Composer | la ligne `composer require` dans `spip/Dockerfile` |
| Garder un autre service SDK AWS | `KEEP_SERVICES` dans `spip/scripts/shrink-vendor.sh` |
| Ajouter une extension PHP | une nouvelle étape `ext-*` dans `spip/Dockerfile` |
| Ajouter un plugin | voir [plugins.md](plugins.md) |
