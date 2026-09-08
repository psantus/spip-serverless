# Sessions (DynamoDB)

## Problem

SPIP stores sessions as PHP files in `tmp/sessions/`. On Lambda, `/tmp` is ephemeral and per-instance — sessions are lost on cold starts and not shared across instances.

## Solution

Sessions are stored in DynamoDB via the `sessions_dynamodb` plugin. This provides:
- Persistence across cold starts
- Sharing across concurrent Lambda instances
- TTL-based automatic cleanup

## Architecture

```
SPIP session functions → squelettes/inc/session.php override → DynamoDB
```

The override intercepts SPIP's session file operations (`ecrire_fichier`, `lire_fichier`, `supprimer_fichier`) for paths containing `sessions/` and redirects them to DynamoDB.

## DynamoDB Table

- **Name:** `spip-serverless-{env}-sessions` (e.g. `spip-serverless-test-sessions`)
- **Partition key:** `id` (String) — session filename (e.g. `1_abc123def.php`)
- **Attributes:** `data` (String) — PHP session file content, `ttl` (Number) — expiry timestamp
- **TTL:** Enabled on `ttl` attribute

Defined in `iac/spip/static/` terraform stack.

## Plugin Location

`spip/plugins/sessions_dynamodb/`

### Key files:
- `inc/session.php` — the session override (copied to `squelettes/inc/session.php` in Docker)
- `sessions_dynamodb_options.php` — DynamoDB client setup + function overrides
- `paquet.xml` — plugin declaration

### Why it's loaded via squelettes/ (not as a normal plugin)

The plugin overrides `ecrire_fichier()` which is defined in SPIP core (`ecrire/inc/flock.php`). Loading it as a standard `plugins-dist/` plugin causes a "Cannot redeclare function" fatal error because SPIP core loads first. The `squelettes/inc/session.php` path is a SPIP override mechanism — SPIP checks `squelettes/inc/` before `ecrire/inc/` for include files.

```dockerfile
COPY spip/plugins/sessions_dynamodb/inc/session.php /var/task/squelettes/inc/session.php
```

## Configuration

Environment variable on Lambda:
```
SPIP_SESSION_TABLE=spip-serverless-test-sessions
```

The plugin reads this via `getenv('SPIP_SESSION_TABLE')`.

## How It Works

1. SPIP calls `fichier_session($id_auteur, $hash)` → returns session file path
2. SPIP calls `lire_fichier($path)` → our override detects `sessions/` in path → reads from DynamoDB
3. SPIP calls `ecrire_fichier($path, $content)` → our override writes to DynamoDB with TTL
4. SPIP calls `supprimer_sessions($id_auteur)` → our override deletes from DynamoDB

## Session Format

DynamoDB item:
```json
{
  "id": "1_abc123def456.php",
  "data": "<?php\n$GLOBALS['visiteur_session']['id_auteur'] = 1;\n...",
  "ttl": 1778160000
}
```

The `data` field contains the exact PHP that SPIP would write to a session file.

## IAM Permissions

Lambda role needs DynamoDB access (defined in `iac/spip/app/lambda.tf`):
```json
{
  "Effect": "Allow",
  "Action": ["dynamodb:GetItem", "dynamodb:PutItem", "dynamodb:DeleteItem", "dynamodb:Query", "dynamodb:Scan"],
  "Resource": "arn:aws:dynamodb:*:*:table/spip-serverless-*-sessions"
}
```

## Troubleshooting

- **Login doesn't persist:** Check `SPIP_SESSION_TABLE` env var is set
- **"Cannot redeclare ecrire_fichier":** The plugin was loaded as `plugins-dist/` — it must only be loaded via `squelettes/inc/session.php`
- **Session lost between requests:** Multiple Lambda instances are fine (DynamoDB is shared). Check if TTL is too short.
