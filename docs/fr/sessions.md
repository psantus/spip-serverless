# Sessions (DynamoDB)

**Français** · [English](../en/sessions.md)

## Problème

SPIP stocke les sessions sous forme de fichiers PHP dans `tmp/sessions/`. Sur Lambda, `/tmp` est éphémère et propre à chaque instance — les sessions sont perdues au démarrage à froid et ne sont pas partagées entre les instances.

## Solution

Les sessions sont stockées dans DynamoDB via le plugin `sessions_dynamodb`. Cela fournit :
- La persistance à travers les démarrages à froid
- Le partage entre les instances Lambda concurrentes
- Le nettoyage automatique fondé sur le TTL

## Architecture

```
SPIP session functions → squelettes/inc/session.php override → DynamoDB
```

La surcharge intercepte les opérations de fichiers de session de SPIP (`ecrire_fichier`, `lire_fichier`, `supprimer_fichier`) pour les chemins contenant `sessions/` et les redirige vers DynamoDB.

## Table DynamoDB

- **Nom :** `spip-serverless-{env}-sessions` (p. ex. `spip-serverless-test-sessions`)
- **Clé de partition :** `id` (String) — nom du fichier de session (p. ex. `1_abc123def.php`)
- **Attributs :** `data` (String) — contenu du fichier de session PHP, `ttl` (Number) — horodatage d'expiration
- **TTL :** Activé sur l'attribut `ttl`

Défini dans la stack terraform `iac/spip/static/`.

## Emplacement du plugin

`spip/plugins/sessions_dynamodb/`

### Fichiers clés :
- `inc/session.php` — la surcharge de session (copiée dans `squelettes/inc/session.php` dans Docker)
- `sessions_dynamodb_options.php` — configuration du client DynamoDB + surcharges de fonctions
- `paquet.xml` — déclaration du plugin

### Pourquoi il est chargé via squelettes/ (et non comme un plugin normal)

Le plugin surcharge `ecrire_fichier()` qui est défini dans le cœur de SPIP (`ecrire/inc/flock.php`). Le charger comme un plugin `plugins-dist/` standard provoque une erreur fatale « Cannot redeclare function » parce que le cœur de SPIP se charge en premier. Le chemin `squelettes/inc/session.php` est un mécanisme de surcharge de SPIP — SPIP vérifie `squelettes/inc/` avant `ecrire/inc/` pour les fichiers d'inclusion.

```dockerfile
COPY spip/plugins/sessions_dynamodb/inc/session.php /var/task/squelettes/inc/session.php
```

## Configuration

Variable d'environnement sur Lambda :
```
SPIP_SESSION_TABLE=spip-serverless-test-sessions
```

Le plugin la lit via `getenv('SPIP_SESSION_TABLE')`.

## Fonctionnement

1. SPIP appelle `fichier_session($id_auteur, $hash)` → renvoie le chemin du fichier de session
2. SPIP appelle `lire_fichier($path)` → notre surcharge détecte `sessions/` dans le chemin → lit depuis DynamoDB
3. SPIP appelle `ecrire_fichier($path, $content)` → notre surcharge écrit dans DynamoDB avec un TTL
4. SPIP appelle `supprimer_sessions($id_auteur)` → notre surcharge supprime depuis DynamoDB

## Format de session

Item DynamoDB :
```json
{
  "id": "1_abc123def456.php",
  "data": "<?php\n$GLOBALS['visiteur_session']['id_auteur'] = 1;\n...",
  "ttl": 1778160000
}
```

Le champ `data` contient le PHP exact que SPIP écrirait dans un fichier de session.

## Permissions IAM

Le rôle Lambda a besoin d'un accès à DynamoDB (défini dans `iac/spip/app/lambda.tf`) :
```json
{
  "Effect": "Allow",
  "Action": ["dynamodb:GetItem", "dynamodb:PutItem", "dynamodb:DeleteItem", "dynamodb:Query", "dynamodb:Scan"],
  "Resource": "arn:aws:dynamodb:*:*:table/spip-serverless-*-sessions"
}
```

## Dépannage

- **La connexion ne persiste pas :** Vérifiez que la variable d'environnement `SPIP_SESSION_TABLE` est définie
- **« Cannot redeclare ecrire_fichier » :** Le plugin a été chargé comme `plugins-dist/` — il ne doit être chargé que via `squelettes/inc/session.php`
- **Session perdue entre les requêtes :** Plusieurs instances Lambda ne posent pas de problème (DynamoDB est partagé). Vérifiez si le TTL n'est pas trop court.
