# Gérer les plugins SPIP sur Lambda

**Français** · [English](../en/plugins.md)

SPIP tourne ici sous forme d'**image Docker immuable**. Les plugins sont intégrés à l'image
au moment du build — il n'y a pas d'installation de plugin à l'exécution. Ajouter un plugin
signifie donc : le déposer dans le dépôt, ajouter un COPY dans le Dockerfile si nécessaire,
reconstruire, redéployer.

## Où vivent les plugins dans ce dépôt

```
spip/
├── plugins/                # OUR custom plugins (source of truth)
│   ├── s3upload/           # presigned-URL uploads to S3
│   └── sessions_dynamodb/  # DynamoDB session storage (loaded via a squelettes override)
├── plugins-vendor/         # third-party plugins vendored into the repo
│   └── logs_stderr/        # redirect spip_log() to stderr → CloudWatch
└── Dockerfile              # COPY-s the above into the image
```

Les plugins du cœur SPIP (`plugins-dist/`) ne sont **pas** dans ce dépôt — ils proviennent
du cœur SPIP récupéré au moment du build (voir `docs/fr/spip-upgrade.md`). Quelques-uns sont
retirés dans le Dockerfile (`bigup`, `forum`, `statistiques`, …) car ils ne conviennent pas
à un déploiement serverless/majoritairement en lecture.

## Comment l'image mappe les dossiers vers SPIP

| Source | Chemin dans l'image | Activation |
|---|---|---|
| `plugins-dist/` récupéré (cœur SPIP) | `/var/task/plugins-dist/` | toujours actif |
| `spip/plugins-vendor/*` | `/var/task/plugins-dist/*` | toujours actif |
| `spip/plugins/s3upload/` | `/var/task/plugins-dist/s3upload/` | toujours actif |
| `spip/plugins/sessions_dynamodb/` | via `squelettes/inc/session.php` | surcharge, pas un plugin |

Tout ce qui est placé sous `plugins-dist/` est scanné et activé par SPIP au démarrage à
froid ; SVP l'enregistre en base (`spip_paquets` avec `actif='oui'`) et câble
automatiquement les déclarations de pipeline du `paquet.xml`.

### `plugins/` vs `plugins-dist/` — pourquoi la distinction disparaît à l'exécution

Dans une installation SPIP **normale**, les deux répertoires ont des sens différents :

- `plugins-dist/` — plugins **livrés avec le cœur SPIP**, toujours actifs, sans étape d'activation.
- `plugins/` — plugins **que vous avez ajoutés**, qui doivent être **activés** (enregistrés en
  base via SVP, normalement en cliquant sur « activer » dans l'espace privé).

Cette étape d'activation est interactive et écrit sur le disque — ni l'un ni l'autre n'est
possible sur une **Lambda immuable et en lecture seule** qui redescend à zéro. Ce build
**copie donc délibérément nos propres plugins dans `plugins-dist/`** (voir le Dockerfile),
où ils sont toujours actifs dès le premier démarrage à froid, sans étape manuelle.

Par conséquent, à l'**exécution** la distinction `plugins/` vs `plugins-dist/` n'existe
plus — tout vit dans `/var/task/plugins-dist/`. Dans le **dépôt**, les dossiers sources
séparés (`spip/plugins/` = les nôtres, `spip/plugins-vendor/` = tiers) ne sont conservés
que pour l'organisation et la provenance ; il n'y a pas de `spip/plugins-dist/` (ce nom
appartient au cœur SPIP récupéré).

## Ajouter un plugin TIERS

1. Télécharger le plugin dans `spip/plugins-vendor/<plugin-name>/` (il doit avoir un
   `paquet.xml` valide).
2. Rien d'autre à changer — le Dockerfile fait déjà
   `COPY spip/plugins-vendor/ /var/task/plugins-dist/`.
3. Reconstruire + déployer. Le plugin est actif au prochain démarrage à froid.

> Épinglez la version du plugin (committez la copie vendorisée) pour que les builds
> restent reproductibles.

## Ajouter un plugin PERSONNALISÉ (le vôtre)

1. Créer `spip/plugins/<prefix>/` avec au moins un `paquet.xml` :

   ```xml
   <paquet
       prefix="myplugin"
       categorie="outil"
       version="1.0.0"
       etat="stable"
       compatibilite="[4.0.0;4.*]"
   >
       <nom>My Plugin</nom>
       <auteur>Your name</auteur>
       <licence>GPL</licence>
       <pipeline nom="header_prive" inclure="myplugin_pipelines.php" />
   </paquet>
   ```

2. Ajouter les lignes COPY dans `spip/Dockerfile` (dans l'étape `lambda`), à côté de celle
   de s3upload :

   ```dockerfile
   COPY spip/plugins/<prefix>/ /var/task/plugins-dist/<prefix>/
   ```
   et l'ajouter à la ligne `rm -rf /var/task/plugins/...` pour que le doublon sous
   `plugins/` ne soit pas embarqué.

3. Les migrations du modèle de données vont dans `<prefix>_administrations.php` (le
   versionnage de schéma natif de SPIP — `spip_<prefix>_metas`/`maj_tables`). Elles
   s'exécutent à la première visite admin authentifiée, ou via
   `spip/scripts/bootstrap-db.php` (voir `docs/fr/db-bootstrap.md`).

4. Reconstruire + déployer.

## Exposer une API REST depuis un plugin

Le contrôleur frontal Lambda (`spip/overlay/router.php`) relaie tout vers SPIP. Pour servir
une API personnalisée sous, par exemple, `/api/*`, ajouter une branche **avant** celle de
`/ecrire` qui require le point d'entrée de votre plugin — le routeur documente déjà le
motif dans un commentaire. Ajoutez ensuite un comportement CloudFront correspondant dans
`iac/spip/app/cloudfront.tf` si vous voulez un cache par chemin.

## Ordre de chargement des plugins (Lambda)

1. `auto_prepend_file` → `prepend.php` (wrapper de flux S3, OTEL, répertoires tmp)
2. bootstrap SPIP → `ecrire/inc/utils.php`
3. `config/mes_options.php`
4. fichiers `_options.php` des plugins (depuis le cache de plugins SPIP dans `/tmp`)
5. fichiers `_fonctions.php` des plugins
6. exécution des pipelines

## Cas particuliers dans ce dépôt

### sessions_dynamodb
Surcharge `ecrire_fichier()`/`lire_fichier()` pour les fichiers de session, ce qui entre en
conflit avec le cœur lorsqu'il est chargé comme un plugin normal. Chargé via une surcharge
de chemin squelettes à la place :
```dockerfile
COPY spip/plugins/sessions_dynamodb/inc/session.php /var/task/squelettes/inc/session.php
```

### s3upload
- JS servi depuis S3 à `/plugins-dist/s3upload/s3upload.js`
- le pipeline `header_prive` injecte la balise `<script>`
- `exec/s3upload_presign.php` copié dans `/var/task/ecrire/exec/` pour l'endpoint de presign
- `mes_options.php` convertit les champs POST `_s3key` en fausses entrées `$_FILES`

### logs_stderr
Redirige `spip_log()` vers stderr pour que CloudWatch capture les logs de SPIP. Essentiel
sur Lambda — à conserver.
