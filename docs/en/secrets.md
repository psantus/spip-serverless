# Secrets Management (SSM Parameter Store)

[Français](../fr/secrets.md) · **English**

## Architecture

```
SSM Parameter Store (SecureString) → Bref secrets-loader → Lambda env var → prepend.php → /tmp/spip/etc/cles.php
```

Secrets are stored in AWS SSM Parameter Store as SecureString. Bref's `secrets-loader` package resolves `bref-ssm:/path` prefixed env vars at Lambda cold start, replacing them with the actual secret value.

## Current Secrets

| Parameter path | Env var | Purpose |
|---|---|---|
| `/spip-serverless/{env}/spip/cles` | `SPIP_CLES` | SPIP site secret key (used for session signing, CSRF tokens) |

## How It Works

1. **Terraform** creates the SSM parameter (`iac/spip/static/ssm.tf`)
2. **Lambda env var** is set to `bref-ssm:/spip-serverless/test/spip/cles` (`iac/spip/app/locals.tf`)
3. **Bref runtime** resolves `bref-ssm:` prefix at cold start → replaces env var with actual value
4. **prepend.php** reads `getenv('SPIP_CLES')` and writes `/tmp/spip/etc/cles.php`

## Terraform Resources

### Static stack (`iac/spip/static/ssm.tf`)
```hcl
resource "aws_ssm_parameter" "spip_cles" {
  name  = "/spip-serverless/${var.env}/spip/cles"
  type  = "SecureString"
  value = "CHANGE_ME_AFTER_CREATION"
  lifecycle { ignore_changes = [value] }
}
```

### App stack (`iac/spip/app/locals.tf`)
```hcl
SPIP_CLES = "bref-ssm:${local.spip_cles_ssm}"
```

### IAM (`iac/spip/app/lambda.tf`)
```hcl
Action   = ["ssm:GetParameter", "ssm:GetParameters"]
Resource = "arn:aws:ssm:*:*:parameter/spip-serverless/${var.env}/*"
```

## Setting a Secret Value

After terraform creates the parameter (with placeholder value), set the real value:
```bash
aws ssm put-parameter \
  --name "/spip-serverless/test/spip/cles" \
  --type SecureString \
  --value '{"secret_du_site":"your-base64-secret-here"}' \
  --overwrite --region us-east-1 --profile <your-profile>
```

## Adding a New Secret

1. Add SSM parameter in `iac/spip/static/ssm.tf`
2. Add output for the parameter name
3. Add `bref-ssm:` env var in `iac/spip/app/locals.tf`
4. Read it in `prepend.php` via `getenv('YOUR_VAR')`
5. Deploy static stack first, set value, then deploy app stack

## Dependencies

- `bref/secrets-loader:^1` — composer package that resolves `bref-ssm:` at runtime
- Lambda IAM role needs `ssm:GetParameter` permission

## Security

- Secrets are encrypted at rest (SSM SecureString uses AWS KMS)
- Never appear in Docker image or git
- Resolved once at cold start, cached in memory for the instance lifetime
- `/tmp/spip/etc/cles.php` is ephemeral (lost on instance termination)

## Troubleshooting

- **"bref/secrets-loader package is required"** — add `bref/secrets-loader:^1` to composer require in Dockerfile
- **"Access denied" on SSM** — check Lambda IAM role has `ssm:GetParameter` for the parameter ARN
- **Secret not updating** — Lambda caches env vars per instance. Force cold start by deploying a new image.
