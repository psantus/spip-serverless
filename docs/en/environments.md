# Environments

[Français](../fr/environments.md) · **English**

An environment = one AWS account (or one account + region) running its own copy of the
two Terraform stacks and the SPIP image. The default region is **eu-west-3** (Paris).

## Terraform layout

```
iac/spip/
├── static/   # DSQL cluster, S3 assets bucket, ECR repo, DynamoDB sessions, SSM key
└── app/      # Lambda, API Gateway, CloudFront (+ optional custom domain)
```

`app` reads `static`'s outputs via `terraform_remote_state`, so **apply `static` first**.

## Per-environment files

Each stack has `var/<env>/`:
- `values.tfvars`   — region, env name, domain, remote-state location, …
- `backend.tfbackend` — where THIS stack's own state lives (S3 bucket + key)

A template lives in `var/example/`. Create a new environment by copying it:

```bash
for stack in static app; do
  cp -r iac/spip/$stack/var/example iac/spip/$stack/var/prod
  $EDITOR iac/spip/$stack/var/prod/values.tfvars
  $EDITOR iac/spip/$stack/var/prod/backend.tfbackend
done
```

Fill in:
- `aws_region` — e.g. `eu-west-3`
- the state S3 bucket in both `backend.tfbackend` files (create it once per account)
- `static_state_bucket` / `static_state_region` in the app stack (point at the static
  stack's state)
- optionally `domain_name` + `hosted_zone_name` for a custom domain. Setting
  `domain_name` (via Terraform, not the CloudFront console) drives everything from one
  variable: the CloudFront alias, a us-east-1 ACM certificate (DNS-validated against the
  `hosted_zone_name` Route53 zone, which must exist **in the same account**), the Route53
  A-record, **and** the Lambda's `SPIP_PUBLIC_URL` (so SPIP builds its absolute links on
  that domain). Leave both empty to use the default `*.cloudfront.net` domain.

  > If you change `domain_name` on an already-bootstrapped environment, the runtime host
  > (prepend.php / `SPIP_PUBLIC_URL`) follows automatically, but the stored `adresse_site`
  > meta does not — re-run the bootstrap (or `UPDATE spip_meta SET valeur='https://<new>'
  > WHERE nom='adresse_site'`). See `docs/en/db-bootstrap.md`.

## First bring-up (per environment)

```bash
# 1. static stack (DSQL, S3, ECR, DynamoDB, SSM)
make deploy-static ENV=prod AWS_PROFILE=<profile>

# 2. fill the SPIP key material placeholder in SSM (see docs/en/db-bootstrap.md)

# 3. build + push image, sync assets, apply app stack
make deploy ENV=prod AWS_PROFILE=<profile>

# 4. initialise the SPIP schema + admin author (see docs/en/db-bootstrap.md)
```

## CI/CD

`.github/workflows/deploy.yml` deploys one environment per run. Configure each
environment under **GitHub → Settings → Environments** with these variables:
- `AWS_ACCOUNT_ID`, `AWS_REGION`, `CI_ROLE_NAME` (OIDC role to assume)

and commit the matching `var/<env>/` files. The workflow assumes an IAM role via GitHub
OIDC — no long-lived keys.