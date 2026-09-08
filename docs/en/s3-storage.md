# S3 File Storage

[Français](../fr/s3-storage.md) · **English**

## Problem

SPIP stores uploaded files (images, documents) in `IMG/` on the local filesystem. On Lambda, the filesystem is read-only (except `/tmp` which is ephemeral). Files must be stored externally.

## Solution

All uploaded files are stored in S3. SPIP accesses them via the S3 stream wrapper (`s3://bucket/...`). URLs are rewritten in HTML output to serve via CloudFront.

## Architecture

```
Upload:   Browser → presigned PUT URL → S3 (direct, no Lambda)
Read:     SPIP → s3://bucket/IMG/... (stream wrapper)
Serve:    CloudFront → S3 (/IMG/* behavior)
Display:  HTML rewrite: s3://bucket/... → /IMG/...
```

## Components

### 1. S3 Stream Wrapper (`prepend.php`)

Registered on cold start:
```php
$s3Client = new Aws\S3\S3Client([...]);
$s3Client->registerStreamWrapper();
define('_S3_BUCKET', $s3Bucket);
```

This allows `file_exists('s3://bucket/IMG/...')`, `filesize(...)`, `copy(...)` to work transparently.

### 2. Presigned URL Upload (`spip/plugins/s3upload/`)

- `exec/s3upload_presign.php` — generates presigned PUT URL for browser
- `s3upload.js` — intercepts file inputs, uploads directly to S3, resubmits form with `_s3key`
- `s3upload_pipelines.php` — injects JS via `header_prive` pipeline

**Flow:**
1. User selects file in SPIP admin
2. JS intercepts form submit
3. JS calls `/ecrire/?exec=s3upload_presign` → gets presigned S3 PUT URL
4. JS uploads file directly to S3 (browser → S3, never through Lambda)
5. JS adds hidden `_s3key` field to form and resubmits
6. `mes_options_lambda.php` converts `_s3key` to fake `$_FILES` entry with `tmp_name = s3://bucket/key`
7. SPIP processes the "upload" normally (copies s3→s3 via stream wrapper)

### 3. Document Patches (`scripts/patch-documents.php`)

Build-time patches to `ecrire/inc/documents.php`:
- `deplacer_fichier_upload()` — handles S3→S3 copy instead of local move
- `get_spip_doc()` — returns `s3://bucket/IMG/...` for filesystem operations
- `set_spip_doc()` — strips `s3://` prefix for DB storage
- `creer_repertoire_documents()` — creates `.ok` marker in S3

### 4. Renseigner Document Patch (`scripts/patch-documents.php`)

Patches `plugins-dist/medias/inc/renseigner_document.php`:
- Resolves relative paths to `s3://bucket/...` before `file_exists`/`filesize` checks

### 5. HTML URL Rewriting (`prepend.php`)

```php
ob_start(function($html) use ($__s3bucket) {
    $html = str_replace('s3://' . $__s3bucket . '/', '/', $html);
    return $html;
});
```

Rewrites `s3://bucket/IMG/logo/file.png` → `/IMG/logo/file.png` in all HTML output.

### 6. CloudFront S3 Behaviors (`iac/spip/app/api-gateway.tf`)

```hcl
for_each = ["/IMG/*", "/plugins-dist/*", "/plugins/*", "/prive/*", "/squelettes-dist/*", "/local/*"]
```

Requests to these paths go directly to S3 (not Lambda). Cached with 1-day default TTL.

### 7. Local Cache Sync (`prepend.php` shutdown)

SPIP generates CSS/JS/thumbnails in `/tmp/spip/local/`. These are synced to S3 on shutdown:
```php
register_shutdown_function(function() {
    // Recursively copy /tmp/spip/local/* to s3://bucket/local/*
});
```

CloudFront serves `/local/*` from S3.

## S3 Bucket

- **Name:** `spip-serverless-{env}-assets` (e.g. `spip-serverless-test-assets`)
- **CORS:** Configured for presigned PUT uploads (browser → S3 direct)
- **Structure:**
  ```
  IMG/              # Uploaded documents (logo/, pdf/, png/, etc.)
  local/            # Generated CSS/JS/thumbnails
  plugins-dist/     # Static plugin assets
  plugins/          # Our plugin static assets
  prive/            # SPIP admin theme assets
  squelettes-dist/  # Default template assets
  ```

Defined in `iac/spip/static/` terraform stack.

## Configuration

Environment variables:
```
S3_BUCKET=spip-serverless-test-assets
S3_REGION=us-east-1
```

## Syncing Static Assets

After build, sync static assets from the image to S3:
```bash
make sync-assets
```

This extracts `plugins-dist/`, `plugins/`, `prive/`, `squelettes-dist/` from the Docker image and uploads to S3 (excluding `.php` files).

## Key Constants

- `_S3_BUCKET` — defined in `prepend.php`, used by document patches
- `_DIR_IMG` — stays as `IMG/` (relative, for URL generation)
- `get_spip_doc($fichier)` — returns `s3://bucket/IMG/...` for filesystem ops

## Troubleshooting

- **Upload fails:** Check S3 CORS config allows PUT from the domain
- **Image not displayed:** Check CloudFront `/IMG/*` behavior points to S3
- **"Unable to copy file":** Check Lambda IAM has `s3:PutObject` on the bucket
- **Thumbnails missing:** They're generated per-instance in `/tmp/spip/local/` and synced to S3 on shutdown. First request generates, second request serves from CloudFront.
- **CSS/JS 404:** Run `make sync-assets` after deploy, or wait for first request to generate + sync
