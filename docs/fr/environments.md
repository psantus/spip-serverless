# Environnements

**Français** · [English](../en/environments.md)

Un environnement = un compte AWS (ou un compte + une région) faisant tourner sa propre copie
des deux stacks Terraform et de l'image SPIP. La région par défaut est **eu-west-3** (Paris).

## Organisation Terraform

```
iac/spip/
├── static/   # DSQL cluster, S3 assets bucket, ECR repo, DynamoDB sessions, SSM key
└── app/      # Lambda, API Gateway, CloudFront (+ optional custom domain)
```

`app` lit les outputs de `static` via `terraform_remote_state`, donc **appliquez `static`
en premier**.

## Fichiers par environnement

Chaque stack possède `var/<env>/` :
- `values.tfvars`   — région, nom d'env, domaine, emplacement du remote-state, …
- `backend.tfbackend` — où vit l'état PROPRE à CE stack (bucket S3 + clé)

Un modèle se trouve dans `var/example/`. Créez un nouvel environnement en le copiant :

```bash
for stack in static app; do
  cp -r iac/spip/$stack/var/example iac/spip/$stack/var/prod
  $EDITOR iac/spip/$stack/var/prod/values.tfvars
  $EDITOR iac/spip/$stack/var/prod/backend.tfbackend
done
```

Renseignez :
- `aws_region` — p. ex. `eu-west-3`
- le bucket S3 d'état dans les deux fichiers `backend.tfbackend` (à créer une fois par compte)
- `static_state_bucket` / `static_state_region` dans le stack app (pointer vers l'état du
  stack static)
- optionnellement `domain_name` + `hosted_zone_name` pour un domaine personnalisé. Définir
  `domain_name` (via Terraform, pas la console CloudFront) pilote tout depuis une seule
  variable : l'alias CloudFront, un certificat ACM en us-east-1 (validé par DNS contre la
  zone Route53 `hosted_zone_name`, qui doit exister **dans le même compte**), l'enregistrement
  A Route53, **et** le `SPIP_PUBLIC_URL` du Lambda (afin que SPIP construise ses liens absolus
  sur ce domaine). Laissez les deux vides pour utiliser le domaine `*.cloudfront.net` par défaut.

  > Si vous changez `domain_name` sur un environnement déjà amorcé (bootstrapped), l'hôte
  > runtime (prepend.php / `SPIP_PUBLIC_URL`) suit automatiquement, mais la méta `adresse_site`
  > stockée ne suit pas — relancez le bootstrap (ou `UPDATE spip_meta SET valeur='https://<new>'
  > WHERE nom='adresse_site'`). Voir `docs/fr/db-bootstrap.md`.

## Première mise en route (par environnement)

```bash
# 1. static stack (DSQL, S3, ECR, DynamoDB, SSM)
make deploy-static ENV=prod AWS_PROFILE=<profile>

# 2. fill the SPIP key material placeholder in SSM (see docs/fr/db-bootstrap.md)

# 3. build + push image, sync assets, apply app stack
make deploy ENV=prod AWS_PROFILE=<profile>

# 4. initialise the SPIP schema + admin author (see docs/fr/db-bootstrap.md)
```

## CI/CD

`.github/workflows/deploy.yml` déploie un environnement par exécution. Configurez chaque
environnement sous **GitHub → Settings → Environments** avec ces variables :
- `AWS_ACCOUNT_ID`, `AWS_REGION`, `CI_ROLE_NAME` (rôle OIDC à assumer)

et committez les fichiers `var/<env>/` correspondants. Le workflow assume un rôle IAM via
GitHub OIDC — pas de clés à longue durée de vie.