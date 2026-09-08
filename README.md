# spip-serverless

Run [SPIP](https://www.spip.net/) — the French free-software CMS — **serverless on AWS**:
PHP on AWS Lambda (via [Bref](https://bref.sh/)), an Aurora DSQL database, sessions in
DynamoDB, static assets on S3, all fronted by CloudFront. No servers to patch, scale to
zero, pay per request.

This repo is a **generic, reusable platform** — bring your own content, plugins and
skeletons. Default region: **eu-west-3** (Paris) 🇫🇷.

## Architecture

```
                  ┌────────────┐
   visitor ──────▶│ CloudFront │
                  └─────┬──────┘
             /IMG,/plugins-dist,…   everything else
                  │                    │
            ┌─────▼─────┐        ┌─────▼───────────┐
            │ S3 assets │        │  API Gateway     │
            └───────────┘        │  (proxy, X-Ray)  │
                                 └─────┬───────────┘
                                       │ AWS_PROXY
                                 ┌─────▼───────────┐   IAM-auth token
                                 │  Lambda (SPIP)   │──────────────▶ Aurora DSQL
                                 │  Bref, PHP 8.5   │──────────────▶ DynamoDB (sessions)
                                 └─────┬───────────┘──────────────▶ S3 (media, read/write)
                                       │
                                    SSM (spip keys), CloudWatch, X-Ray
```

- **`spip/`** — the SPIP runtime image. SPIP core is **fetched at build time** (pinned in
  `spip/SPIP_VERSION`), not vendored. Overlays adapt core to Lambda/DSQL; two custom
  plugins (`s3upload`, `sessions_dynamodb`) and `logs_stderr` are baked in.
- **`iac/spip/static/`** — Terraform: DSQL cluster, S3 bucket, ECR repo, DynamoDB table,
  SSM key parameter.
- **`iac/spip/app/`** — Terraform: Lambda + alias, API Gateway (transparent proxy),
  CloudFront (+ optional custom domain via ACM/Route53).
- **`docs/`** — how everything fits together.

## Prerequisites

- An AWS account with permission to create the resources above (Aurora DSQL is available
  in eu-west-3 among others).
- Docker (buildx, ARM64), Terraform ≥ 1.14, the AWS CLI, and `make`.
- Aurora DSQL, Bedrock etc. are **not** used by the bare platform — only DSQL, S3,
  DynamoDB, Lambda, API Gateway, CloudFront, ECR, SSM.

## Quick start

```bash
# 0. pick an environment name + AWS profile
export ENV=test AWS_PROFILE=your-profile

# 1. create per-env config from the template
for s in static app; do cp -r iac/spip/$s/var/example iac/spip/$s/var/$ENV; done
#    then edit iac/spip/*/var/$ENV/{values.tfvars,backend.tfbackend}
#    (state bucket, region, optional domain)

# 2. base infra (DSQL, S3, ECR, DynamoDB, SSM)
make deploy-static ENV=$ENV

# 3. fill the SPIP key material in SSM  → see docs/db-bootstrap.md

# 4. build + push image, sync assets, deploy the app stack
make deploy ENV=$ENV

# 5. create the schema + admin author  → see docs/db-bootstrap.md
```

`make deploy` prints the CloudFront URL; the admin is at `/ecrire`.

## Working with SPIP core locally

Core is git-ignored. To index it in your IDE or run it with Apache locally:

```bash
make fetch-spip     # downloads the pinned version into spip/src/
make run-local      # SPIP on http://localhost:8080 (Apache)
```

## Common tasks

| Task | Doc |
|---|---|
| Add a plugin (third-party or custom) | [docs/plugins.md](docs/plugins.md) |
| Upgrade SPIP core | [docs/spip-upgrade.md](docs/spip-upgrade.md) |
| Add / configure an environment | [docs/environments.md](docs/environments.md) |
| Initialise the database + admin | [docs/db-bootstrap.md](docs/db-bootstrap.md) |
| Reset an admin password | [docs/spip-passwords.md](docs/spip-passwords.md) |
| DSQL, sessions, S3, secrets, tracing, logging, cron | `docs/*.md` |

## Security notes

- API Gateway is a **transparent proxy** — it does not authenticate. **SPIP** enforces
  auth for the back-office (`/ecrire`). If you add your own API routes, add an authorizer
  in `iac/spip/app/` yourself.
- SPIP secret keys live in SSM (SecureString) and are written back by the Lambda on first
  boot; the S3 bucket is private (CloudFront OAC only); DSQL uses short-lived IAM auth
  tokens (no stored DB password).
- You can turn the public site off (admin/login only) with `spip_public_disabled = true`.

## License

The SPIP core fetched at build time is GPL-3.0 (© the SPIP community). This repo's own
glue code is provided under the terms in [LICENSE](LICENSE).
