# Build & image internals

[Français](../fr/build.md) · **English**

The SPIP runtime is a single **immutable, multi-stage Docker image** (ARM64, PHP 8.5 via
Bref). `make build` builds it; `make deploy` builds + pushes it to ECR and points the
Lambda at the new digest. Nothing is installed at runtime — everything is baked here.

## Dockerfile stages (`spip/Dockerfile`)

| Stage | Role |
|---|---|
| `spip-core` | Downloads the official SPIP release zip for `SPIP_VERSION` into `/spip-src` (core is **not** vendored — see [spip-upgrade.md](spip-upgrade.md)). |
| `ext-pgsql` | Compiles the PHP `pgsql` extension — Aurora DSQL speaks the PostgreSQL wire protocol. |
| `ext-gd` | Compiles the PHP `gd` extension (image resizing/thumbnails). |
| `vendors` | Runs Composer **on top of** the fetched SPIP core, then shrinks `vendor/`. |
| `local` | Apache image for `make run-local` (local dev only). |
| `lambda` | Final image: assembles core + vendor + extensions + overlays + plugins, patches `documents.php` for S3, pre-warms opcache, adds the ADOT collector. |

## Composer dependencies (the `vendors` stage)

SPIP core ships its own `vendor/` in the release zip. On top of it we add the pieces that
make SPIP run on Lambda:

```
composer require \
  bref/bref:^3.0             # PHP runtime for Lambda (FPM)
  bref/secrets-loader:^1     # resolves bref-ssm:/... env values from SSM at boot
  aws/aws-sdk-php:^3.0       # DSQL IAM token, S3, DynamoDB sessions, SSM, CloudFront…
  open-telemetry/sdk:^1      # tracing
  open-telemetry/exporter-otlp:^1
```

Then `spip/scripts/shrink-vendor.sh vendor` runs (see below).

## `shrink-vendor.sh` — why and what

The full `aws/aws-sdk-php` ships data + client classes for **every** AWS service (~hundreds
of MB). A Lambda image only needs a handful, and a smaller image means faster cold starts
and cheaper storage. The script:

- **Keeps only the AWS SDK services SPIP uses** (a `KEEP_SERVICES` allow-list) and deletes
  the rest of `aws-sdk-php/src/data/*` and `src/<Service>/`.
- **Strips docs/tests/examples** from the whole `vendor/` tree (`*.md`, `CHANGELOG*`,
  `LICENSE*`, `README*`, `tests/`, `docs/`, `examples/`).
- Prints the saved size (`AWS SDK: <before>MB → <after>MB`).

> Note: the current `KEEP_SERVICES` allow-list still contains a few services inherited from
> the original application (`bedrock-*`, `translate`) that the bare platform does not use.
> Trim them to `dsql, dynamodb, s3, ssm, sts, cloudfront, ses` for a leaner image, and add
> back whatever your own plugins need.

To add an SDK service your plugin needs: add it to `KEEP_SERVICES` (and to the `src/`
`case` allow-list) in `spip/scripts/shrink-vendor.sh`, then rebuild.

## Opcache pre-warm

`compile-opcache.php` (run with `opcache-build.ini`) compiles the PHP files into a
read-only opcache **file cache** baked into the image (`/bref/opcache`), read at runtime.
This removes first-request compilation from the cold-start path.

## Observability bits

- **ADOT collector**: `make download-adot` fetches the AWS OTEL collector Lambda extension
  into `spip/overlay/adot-collector` (git-ignored); the image ships it as a `/opt`
  extension. Traces flow OTEL → collector → X-Ray. See [tracing.md](tracing.md).
- **Logs**: `logs_stderr` sends `spip_log()` to stderr → CloudWatch. See [logging.md](logging.md).

## Where to change what

| You want to… | Edit |
|---|---|
| Bump SPIP | `spip/SPIP_VERSION` (see [spip-upgrade.md](spip-upgrade.md)) |
| Add a PHP/Composer dependency | the `composer require` line in `spip/Dockerfile` |
| Keep another AWS SDK service | `KEEP_SERVICES` in `spip/scripts/shrink-vendor.sh` |
| Add a PHP extension | a new `ext-*` stage in `spip/Dockerfile` |
| Add a plugin | see [plugins.md](plugins.md) |
