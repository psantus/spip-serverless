# Resetting a SPIP password (SPIP-serverless / DSQL / Lambda)

[Français](../fr/spip-passwords.md) · **English**

Procedure to reset a SPIP author's password directly in the database,
when access to the private area is lost.

## Technical context

- SPIP runs in **Lambda** (Docker image), database **Aurora DSQL** (PostgreSQL).
- Table prefix: `spip` (SPIP default, configurable via SPIP_TABLE_PREFIX). The authors table is therefore `spip_auteurs`.
- Modern SPIP 4.x does **not** store a plain bcrypt of the password: the hash is
  **peppered** with the site's `secret_des_auth` before bcrypt.

## Hashing formula (SPIP 4.x — `Spip\Chiffrer\Password::hacher`)

```php
$pass_poivre = hash_hmac('sha256', $password_clair, $key);   // $key = secret_des_auth (BINAIRE)
$hash        = password_hash($pass_poivre, PASSWORD_DEFAULT); // bcrypt ($2y$…)
```

Verification at login (`Password::verifier`) does:

```php
$pass_poivre = hash_hmac('sha256', $password_clair, $key);
password_verify($pass_poivre, $hash_stocke);
```

**Pitfall #1** — `Password::verifier` fails if `$secret` is empty. The secret comes from
the `cles.php` file (populated in the Lambda from the `SPIP_CLES` env var).

**Pitfall #2 (the most important)** — `SpipCles` **base64-decodes** the keys read from
`cles.php` (`array_map('base64_decode', $json)` in `SpipCles::read()`, and
`base64_decode()` in `getKey`/`getMetaKey`). The HMAC key is therefore the **decoded
binary** secret, NOT the base64 string as it appears in SSM/the JSON.

## Procedure

### 1. Retrieve the `secret_des_auth`

The Lambda's `SPIP_CLES` env var points to an SSM parameter:

```bash
AWS_PROFILE=<your-profile> aws lambda get-function-configuration \
  --function-name spip-serverless-test-web --region us-east-1 \
  --query "Environment.Variables.SPIP_CLES" --output text
# -> bref-ssm:/spip-serverless/test/spip/cles

AWS_PROFILE=<your-profile> aws ssm get-parameter \
  --name "/spip-serverless/test/spip/cles" --with-decryption --region us-east-1 \
  --query "Parameter.Value" --output text
# -> {"secret_du_site": "...", "secret_des_auth": "<secret_des_auth-base64>"}
```

### 2. Generate the hash (base64-DECODED secret)

```bash
SECRET_B64='<secret_des_auth-base64>'   # secret_des_auth du SSM
NEWPASS='ChangeMe2026!'

HASH=$(php -r '
$secret = base64_decode($argv[1]);            // IMPORTANT : décoder le base64
$poivre = hash_hmac("sha256", $argv[2], $secret);
echo password_hash($poivre, PASSWORD_DEFAULT);
' "$SECRET_B64" "$NEWPASS")

# Vérifier localement AVANT d'écrire en base
php -r '
$secret = base64_decode($argv[1]);
$poivre = hash_hmac("sha256", $argv[3], $secret);
echo password_verify($poivre, $argv[2]) ? "VERIFY OK\n" : "VERIFY FAIL\n";
' "$SECRET_B64" "$HASH" "$NEWPASS"
```

### 3. Write to the database (DSQL)

```bash
TOKEN=$(AWS_PROFILE=<your-profile> aws dsql generate-db-connect-admin-auth-token \
  --hostname <cluster>.dsql.us-east-1.on.aws --region us-east-1)

PGPASSWORD="$TOKEN" psql \
  "host=<cluster>.dsql.us-east-1.on.aws port=5432 dbname=postgres user=admin sslmode=require" \
  -c "UPDATE spip_auteurs SET pass = '$HASH' WHERE id_auteur = 1 RETURNING id_auteur, login, email"
```

The current admin author: `id_auteur=1`, login `admin`, email `admin@example.com`,
status `0minirezo`, `webmestre=oui`.

## Notes / troubleshooting

- Login can be done with the **login** (`admin`) or the **email**.
- Do NOT touch `alea_actuel` (used to verify old sha256/md5 hashes, with no
  impact on modern hashes).
- The repo's `src/…` file must match the SPIP version baked into the Lambda
  image; check it in the image if needed:
  `docker run --rm --entrypoint sh spip-serverless:latest -c "grep -n hash_hmac /var/task/ecrire/src/Chiffrer/Password.php"`
- **Changing the password from the private area**: may fail in a Lambda
  environment (writing the `cles.php` file / backing up the keys on the read-only
  `/var/task` filesystem, only `/tmp` is writable). The in-database reset above remains
  the reliable method.

## Relevant SPIP code paths

- `ecrire/auth/spip.php` — `auth_spip_verifier_pass()` (dispatch based on `strlen($pass)`)
- `ecrire/src/Chiffrer/Password.php` — `hacher()` / `verifier()`
- `ecrire/src/Chiffrer/SpipCles.php` — `getSecretAuth()`, `getKey()`, `read()` (base64_decode)