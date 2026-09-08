# spip-serverless

**Français** · [English](README.en.md)

Faire tourner [SPIP](https://www.spip.net/) — le CMS libre français — **serverless sur AWS** :
PHP sur AWS Lambda (via [Bref](https://bref.sh/)), une base Aurora DSQL, les sessions dans
DynamoDB, les assets statiques sur S3, le tout derrière CloudFront. Aucun serveur à patcher,
scale-to-zero, facturation à l'usage.

Ce dépôt est une **plateforme générique et réutilisable** — apportez votre contenu, vos
plugins et vos squelettes. Région par défaut : **eu-west-3** (Paris) 🇫🇷.

## Architecture

```
                  ┌────────────┐
   visiteur ─────▶│ CloudFront │
                  └─────┬──────┘
             /IMG,/plugins-dist,…      tout le reste
                  │                        │
            ┌─────▼─────┐        ┌─────────▼───────┐
            │ assets S3 │        │  API Gateway     │
            └───────────┘        │  (proxy, X-Ray)  │
                                 └─────┬───────────┘
                                       │ AWS_PROXY
                                 ┌─────▼───────────┐   jeton IAM
                                 │  Lambda (SPIP)   │──────────────▶ Aurora DSQL
                                 │  Bref, PHP 8.5   │──────────────▶ DynamoDB (sessions)
                                 └─────┬───────────┘──────────────▶ S3 (média, lecture/écriture)
                                       │
                                    SSM (clés spip), CloudWatch, X-Ray
```

- **`spip/`** — l'image runtime SPIP. Le cœur SPIP est **récupéré au build** (épinglé dans
  `spip/SPIP_VERSION`), non vendorisé. Des overlays adaptent le cœur à Lambda/DSQL ; deux
  plugins maison (`s3upload`, `sessions_dynamodb`) et `logs_stderr` sont embarqués.
- **`iac/spip/static/`** — Terraform : cluster DSQL, bucket S3, dépôt ECR, table DynamoDB,
  paramètre SSM (clés).
- **`iac/spip/app/`** — Terraform : Lambda + alias, API Gateway (proxy transparent),
  CloudFront (+ domaine custom optionnel via ACM/Route53).
- **`docs/`** — comment tout s'articule.

## Prérequis

- Un compte AWS autorisé à créer les ressources ci-dessus (Aurora DSQL est disponible en
  eu-west-3, entre autres).
- **Docker** installé et **démarré** (buildx ; l'image est `linux/arm64`).
- **Terraform ≥ 1.14**.
- **AWS CLI v2** avec une **session active** pour votre profil — `aws sso login --profile <p>`
  ou des credentials exportés. Le principal doit pouvoir créer DSQL, S3, ECR, DynamoDB, SSM,
  Lambda, API Gateway, CloudFront et des rôles IAM.
- **make**, `git`, `curl`, `unzip`.

## Démarrage rapide

```bash
# 0. choisir un nom d'environnement + un profil AWS
export ENV=test AWS_PROFILE=votre-profil

# 1. créer le bucket S3 qui contiendra l'état Terraform (une fois par compte)
aws s3 mb s3://votre-bucket-tfstate --region eu-west-3

# 2. créer la config par env depuis le modèle
for s in static app; do cp -r iac/spip/$s/var/example iac/spip/$s/var/$ENV; done
#    puis éditer iac/spip/*/var/$ENV/{values.tfvars,backend.tfbackend} :
#    renseigner le bucket d'état (les deux backend.tfbackend + static_state_bucket de app),
#    la région, et éventuellement un domaine custom.

# 3. infra de base (DSQL, S3, ECR, DynamoDB, SSM)
make deploy-static ENV=$ENV

# 4. remplir les clés SPIP dans SSM  → voir docs/db-bootstrap.md

# 5. build + push de l'image, sync des assets, déploiement de la stack app
make deploy ENV=$ENV

# 6. créer le schéma + l'auteur admin  → voir docs/db-bootstrap.md
```

`make deploy` affiche l'URL CloudFront ; l'admin est sur `/ecrire`. Vérifié de bout en bout
sur un compte AWS vierge (eu-west-3) : CloudFront sert le site public et `/spip.php?page=login`.

## Travailler avec le cœur SPIP en local

Le cœur est git-ignoré. Pour l'indexer dans votre IDE ou le lancer avec Apache en local :

```bash
make fetch-spip     # télécharge la version épinglée dans spip/src/
make run-local      # SPIP sur http://localhost:8080 (Apache)
```

## Tâches courantes

| Tâche | Doc |
|---|---|
| Ajouter un plugin (tiers ou maison) | [docs/plugins.md](docs/fr/plugins.md) |
| Mettre à jour le cœur SPIP | [docs/spip-upgrade.md](docs/fr/spip-upgrade.md) |
| Build de l'image / dépendances Composer / shrink-vendor | [docs/build.md](docs/fr/build.md) |
| Emails transactionnels via Amazon SES (optionnel) | [docs/email-ses.md](docs/fr/email-ses.md) |
| Ajouter / configurer un environnement | [docs/environments.md](docs/fr/environments.md) |
| Initialiser la base + l'admin | [docs/db-bootstrap.md](docs/fr/db-bootstrap.md) |
| Réinitialiser un mot de passe admin | [docs/spip-passwords.md](docs/fr/spip-passwords.md) |
| DSQL, sessions, S3, secrets, tracing, logs, cron | `docs/fr/*.md` |

> Les docs détaillées sous `docs/fr/*.md` sont en anglais pour l'instant.

## Notes de sécurité

- L'API Gateway est un **proxy transparent** — elle n'authentifie pas. C'est **SPIP** qui
  gère l'auth du back-office (`/ecrire`). Si vous ajoutez vos propres routes API, ajoutez
  vous-même un authorizer dans `iac/spip/app/`.
- Les clés secrètes SPIP vivent dans SSM (SecureString) et sont réécrites par le Lambda au
  premier boot ; le bucket S3 est privé (OAC CloudFront uniquement) ; DSQL utilise des jetons
  IAM à courte durée (aucun mot de passe DB stocké).
- Vous pouvez couper le site public (admin/login seulement) avec `spip_public_disabled = true`.

## Licence

Le cœur SPIP récupéré au build est en GPL-3.0 (© la communauté SPIP). Le code de glue propre
à ce dépôt est fourni selon les termes du fichier [LICENSE](LICENSE).
