# Reset d'un mot de passe SPIP (SPIP-serverless / DSQL / Lambda)

Procédure pour réinitialiser le mot de passe d'un auteur SPIP directement en base,
quand l'accès à l'espace privé est perdu.

## Contexte technique

- SPIP tourne en **Lambda** (image Docker), base **Aurora DSQL** (PostgreSQL).
- Préfixe des tables : `spip` (défaut SPIP, configurable via SPIP_TABLE_PREFIX). La table auteurs est donc `spip_auteurs`.
- SPIP 4.x moderne ne stocke **pas** un simple bcrypt du mot de passe : le hash est
  **poivré (peppered)** avec le `secret_des_auth` du site avant bcrypt.

## Formule de hachage (SPIP 4.x — `Spip\Chiffrer\Password::hacher`)

```php
$pass_poivre = hash_hmac('sha256', $password_clair, $key);   // $key = secret_des_auth (BINAIRE)
$hash        = password_hash($pass_poivre, PASSWORD_DEFAULT); // bcrypt ($2y$…)
```

La vérification au login (`Password::verifier`) fait :

```php
$pass_poivre = hash_hmac('sha256', $password_clair, $key);
password_verify($pass_poivre, $hash_stocke);
```

**Piège n°1** — `Password::verifier` échoue si `$secret` est vide. Le secret vient du
fichier `cles.php` (rempli dans le Lambda depuis la var d'env `SPIP_CLES`).

**Piège n°2 (le plus important)** — `SpipCles` **base64-décode** les clés lues depuis
`cles.php` (`array_map('base64_decode', $json)` dans `SpipCles::read()`, et
`base64_decode()` dans `getKey`/`getMetaKey`). La clé HMAC est donc le secret
**binaire décodé**, PAS la chaîne base64 telle qu'elle apparaît dans SSM/le JSON.

## Procédure

### 1. Récupérer le `secret_des_auth`

La var d'env `SPIP_CLES` du Lambda pointe vers un paramètre SSM :

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

### 2. Générer le hash (secret base64-DÉCODÉ)

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

### 3. Écrire en base (DSQL)

```bash
TOKEN=$(AWS_PROFILE=<your-profile> aws dsql generate-db-connect-admin-auth-token \
  --hostname <cluster>.dsql.us-east-1.on.aws --region us-east-1)

PGPASSWORD="$TOKEN" psql \
  "host=<cluster>.dsql.us-east-1.on.aws port=5432 dbname=postgres user=admin sslmode=require" \
  -c "UPDATE spip_auteurs SET pass = '$HASH' WHERE id_auteur = 1 RETURNING id_auteur, login, email"
```

L'auteur admin actuel : `id_auteur=1`, login `admin`, email `admin@example.com`,
statut `0minirezo`, `webmestre=oui`.

## Notes / dépannage

- Le login peut se faire avec le **login** (`admin`) ou l'**email**.
- Ne PAS toucher `alea_actuel` (utilisé pour la vérif des anciens hashs sha256/md5, sans
  impact sur les hashs modernes).
- Le fichier `src/…` du repo doit correspondre à la version SPIP bakée dans l'image
  Lambda ; vérifier au besoin dans l'image :
  `docker run --rm --entrypoint sh spip-serverless:latest -c "grep -n hash_hmac /var/task/ecrire/src/Chiffrer/Password.php"`
- **Changement de mot de passe depuis l'espace privé** : peut échouer en environnement
  Lambda (écriture du fichier `cles.php`/backup des clés sur système de fichiers
  read-only `/var/task`, seul `/tmp` est writable). Le reset en base ci-dessus reste
  la méthode fiable.

## Chemins de code SPIP concernés

- `ecrire/auth/spip.php` — `auth_spip_verifier_pass()` (dispatch selon `strlen($pass)`)
- `ecrire/src/Chiffrer/Password.php` — `hacher()` / `verifier()`
- `ecrire/src/Chiffrer/SpipCles.php` — `getSecretAuth()`, `getKey()`, `read()` (base64_decode)
