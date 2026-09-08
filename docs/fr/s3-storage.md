# S3 File Storage

**Français** · [English](../en/s3-storage.md)

## Problème

SPIP stocke les fichiers téléversés (images, documents) dans `IMG/` sur le système de fichiers local. Sur Lambda, le système de fichiers est en lecture seule (sauf `/tmp` qui est éphémère). Les fichiers doivent être stockés à l'extérieur.

## Solution

Tous les fichiers téléversés sont stockés dans S3. SPIP y accède via le stream wrapper S3 (`s3://bucket/...`). Les URL sont réécrites dans la sortie HTML pour être servies via CloudFront.

## Architecture

```
Upload:   Browser → presigned PUT URL → S3 (direct, no Lambda)
Read:     SPIP → s3://bucket/IMG/... (stream wrapper)
Serve:    CloudFront → S3 (/IMG/* behavior)
Display:  HTML rewrite: s3://bucket/... → /IMG/...
```

## Composants

### 1. Stream wrapper S3 (`prepend.php`)

Enregistré au démarrage à froid :
```php
$s3Client = new Aws\S3\S3Client([...]);
$s3Client->registerStreamWrapper();
define('_S3_BUCKET', $s3Bucket);
```

Cela permet à `file_exists('s3://bucket/IMG/...')`, `filesize(...)`, `copy(...)` de fonctionner de façon transparente.

### 2. Téléversement par URL présignée (`spip/plugins/s3upload/`)

- `exec/s3upload_presign.php` — génère l'URL PUT présignée pour le navigateur
- `s3upload.js` — intercepte les champs de fichier, téléverse directement vers S3, resoumet le formulaire avec `_s3key`
- `s3upload_pipelines.php` — injecte le JS via le pipeline `header_prive`

**Flux :**
1. L'utilisateur sélectionne un fichier dans l'admin SPIP
2. Le JS intercepte la soumission du formulaire
3. Le JS appelle `/ecrire/?exec=s3upload_presign` → obtient l'URL PUT S3 présignée
4. Le JS téléverse le fichier directement vers S3 (navigateur → S3, jamais via Lambda)
5. Le JS ajoute un champ caché `_s3key` au formulaire et le resoumet
6. `mes_options_lambda.php` convertit `_s3key` en une fausse entrée `$_FILES` avec `tmp_name = s3://bucket/key`
7. SPIP traite le « téléversement » normalement (copie s3→s3 via le stream wrapper)

### 3. Correctifs Document (`scripts/patch-documents.php`)

Correctifs appliqués à `ecrire/inc/documents.php` au moment du build :
- `deplacer_fichier_upload()` — gère la copie S3→S3 au lieu d'un déplacement local
- `get_spip_doc()` — renvoie `s3://bucket/IMG/...` pour les opérations sur le système de fichiers
- `set_spip_doc()` — retire le préfixe `s3://` pour le stockage en base
- `creer_repertoire_documents()` — crée un marqueur `.ok` dans S3

### 4. Correctif Renseigner Document (`scripts/patch-documents.php`)

Corrige `plugins-dist/medias/inc/renseigner_document.php` :
- Résout les chemins relatifs en `s3://bucket/...` avant les vérifications `file_exists`/`filesize`

### 5. Réécriture des URL HTML (`prepend.php`)

```php
ob_start(function($html) use ($__s3bucket) {
    $html = str_replace('s3://' . $__s3bucket . '/', '/', $html);
    return $html;
});
```

Réécrit `s3://bucket/IMG/logo/file.png` → `/IMG/logo/file.png` dans toute la sortie HTML.

### 6. Comportements S3 CloudFront (`iac/spip/app/api-gateway.tf`)

```hcl
for_each = ["/IMG/*", "/plugins-dist/*", "/plugins/*", "/prive/*", "/squelettes-dist/*", "/local/*"]
```

Les requêtes vers ces chemins vont directement vers S3 (pas Lambda). Mises en cache avec un TTL par défaut d'un jour.

### 7. Synchronisation du cache local (`prepend.php` shutdown)

SPIP génère du CSS/JS/vignettes dans `/tmp/spip/local/`. Ceux-ci sont synchronisés vers S3 à l'arrêt :
```php
register_shutdown_function(function() {
    // Recursively copy /tmp/spip/local/* to s3://bucket/local/*
});
```

CloudFront sert `/local/*` depuis S3.

## Bucket S3

- **Nom :** `spip-serverless-{env}-assets` (p. ex. `spip-serverless-test-assets`)
- **CORS :** Configuré pour les téléversements PUT présignés (navigateur → S3 direct)
- **Structure :**
  ```
  IMG/              # Uploaded documents (logo/, pdf/, png/, etc.)
  local/            # Generated CSS/JS/thumbnails
  plugins-dist/     # Static plugin assets
  plugins/          # Our plugin static assets
  prive/            # SPIP admin theme assets
  squelettes-dist/  # Default template assets
  ```

Défini dans la stack terraform `iac/spip/static/`.

## Configuration

Variables d'environnement :
```
S3_BUCKET=spip-serverless-test-assets
S3_REGION=us-east-1
```

## Synchronisation des assets statiques

Après le build, synchronisez les assets statiques de l'image vers S3 :
```bash
make sync-assets
```

Cela extrait `plugins-dist/`, `plugins/`, `prive/`, `squelettes-dist/` de l'image Docker et les téléverse vers S3 (en excluant les fichiers `.php`).

## Constantes clés

- `_S3_BUCKET` — définie dans `prepend.php`, utilisée par les correctifs Document
- `_DIR_IMG` — reste `IMG/` (relatif, pour la génération d'URL)
- `get_spip_doc($fichier)` — renvoie `s3://bucket/IMG/...` pour les opérations sur le système de fichiers

## Dépannage

- **Le téléversement échoue :** Vérifiez que la config CORS de S3 autorise le PUT depuis le domaine
- **Image non affichée :** Vérifiez que le comportement CloudFront `/IMG/*` pointe vers S3
- **« Unable to copy file » :** Vérifiez que l'IAM Lambda dispose de `s3:PutObject` sur le bucket
- **Vignettes manquantes :** Elles sont générées par instance dans `/tmp/spip/local/` et synchronisées vers S3 à l'arrêt. La première requête génère, la seconde requête sert depuis CloudFront.
- **CSS/JS 404 :** Lancez `make sync-assets` après le déploiement, ou attendez que la première requête génère + synchronise
