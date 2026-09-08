# Mise à jour du cœur SPIP

**Français** · [English](../en/spip-upgrade.md)

Le cœur de SPIP n'est **pas embarqué (vendored)** dans ce dépôt. Il est récupéré au moment
du build depuis l'archive officielle (`files.spip.net`) à une seule version épinglée. Mettre
à jour SPIP se résume donc à : **incrémenter la version épinglée, rebuilder, tester sur un
environnement non-prod, promouvoir.**

## Côté dépôt vs côté en ligne (le modèle mental)

L'image est **immuable et sans état**. Deux moitiés bien nettes :

| Vous le changez dans le DÉPÔT (→ build → deploy) | Ça se produit EN LIGNE, automatiquement, dans DSQL |
|---|---|
| Version de SPIP (`spip/SPIP_VERSION`), plugins, overlays, config | La **migration du schéma de base** SPIP/plugin se rejoue à la **première visite authentifiée sur `/ecrire`** après le déploiement (si `spip_version_base` a été incrémenté) — aucune étape manuelle en base |
| Rebuild de l'image Docker, `make deploy` / CI | Votre **contenu** (articles, médias, utilisateurs) reste dans DSQL, intact après un déploiement |

Donc : **tous les changements de code/version passent par dépôt → build → deploy ; tous les
changements de données/migration se font au runtime**, déclenchés par la première requête
admin (même mécanisme de cold-start que les migrations de plugins initiales — voir
`docs/fr/db-bootstrap.md`). Vous ne lancez jamais une migration de base à la main pour une
mise à jour ; vous déployez l'image et ouvrez l'admin une fois.

## Où la version est épinglée

Une seule source de vérité : **`spip/SPIP_VERSION`** (p. ex. `4.4.22`).

Elle est consommée par :
- `spip/Dockerfile` — l'étape `spip-core` télécharge `spip-v<VERSION>.zip` (la valeur par
  défaut de `ARG SPIP_VERSION` reflète le fichier ; le Makefile/CI la passent explicitement)
- `Makefile` — `SPIP_VERSION := $(shell cat spip/SPIP_VERSION)`, passé en `--build-arg`
- `.github/workflows/deploy.yml` — lit le fichier dans `$SPIP_VERSION`
- `spip/scripts/fetch-spip.sh` — remplit le `spip/src/` (git-ignoré) pour l'outillage local

## Mise à jour patch / mineure (p. ex. 4.4.21 → 4.4.22)

1. Trouvez la version cible sur <https://www.spip.net/fr_download>.
2. Incrémentez-la :
   ```bash
   echo 4.4.22 > spip/SPIP_VERSION
   ```
3. (Optionnel, pour l'indexation locale de l'IDE) rafraîchissez la copie locale :
   ```bash
   make fetch-spip        # rsync --delete into spip/src/ (git-ignored)
   ```
4. Rebuildez et testez sur un environnement jetable :
   ```bash
   make deploy ENV=test
   ```
   Vérifiez l'admin (`/ecrire`), une page publique, et la connexion.
5. Committez et laissez la CI promouvoir (voir `docs/fr/environments.md`).

Nos personnalisations vivent **en dehors** du cœur SPIP, si bien qu'une mise à jour du cœur
ne les touche jamais :
- `spip/overlay/**` — fichiers qui remplacent le cœur au moment du build (connect.php,
  mes_options*, install.php, dsql.php, prepend.php, router.php)
- `spip/plugins/**`, `spip/plugins-vendor/**` — nos plugins + les plugins tiers
- `spip/scripts/patch-documents.php` — le patch S3 appliqué à `ecrire/inc/documents.php`

## Montée de version majeure/mineure (p. ex. 4.4 → 4.5)

Plus risquée — le cœur peut ajouter/retirer des fichiers et changer des API :

- Passez en revue les fichiers que nos overlays remplacent (`spip/overlay/ecrire/**`,
  `config/**`) : si le cœur a changé leur signature/logique, adaptez l'overlay.
- Revérifiez `spip/scripts/patch-documents.php` — les fichiers du cœur patchés ont pu
  bouger (le build échouera bruyamment si le patch ne s'applique plus).
- Incrémentez les plages `compatibilite` des plugins dans vos fichiers `paquet.xml`.
- Vérifiez la version de PHP (Dockerfile : PHP 8.5 via l'image de base Bref).

## Notes

- La mise à jour du cœur ne **rejoue pas** les migrations de base propres à SPIP : si le
  cœur incrémente son `spip_version_base`, la mise à jour SPIP se lance à la première visite
  admin authentifiée (même mécanisme de cold-start que les migrations de plugins — voir
  `docs/fr/db-bootstrap.md`).
- Ne committez jamais un zip téléchargé ni `spip/src/` — les deux sont git-ignorés.