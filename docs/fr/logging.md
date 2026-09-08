# Journalisation

**Français** · [English](../en/logging.md)

## Architecture

Logs SPIP → plugin `logs_stderr` → PHP `error_log()` → stderr → CloudWatch Logs

Le plugin `logs_stderr` (`spip/plugins-vendor/logs_stderr/`) remplace la fonction `inc_log()` de SPIP pour écrire vers stderr au lieu des fichiers `tmp/log/`. Sur Lambda, stderr part directement vers CloudWatch.

## Configuration

Variables d'environnement sur Lambda (définies dans `iac/spip/app/locals.tf`) :

| Variable | Valeurs | Défaut | Description |
|---|---|---|---|
| `LOG_LEVEL` | `ERROR`, `WARNING`, `INFO`, `DEBUG` | `WARNING` | Niveau minimal à émettre |
| `LOG_FORMAT` | `json`, `text` | `json` | Format de sortie |

## Format de sortie

### JSON (par défaut)
```json
{"level":"ERROR","channel":"dsql","context":"prive","message":"errcode: 1000 : ..."}
```

Champs :
- `level` — ERROR, WARNING, INFO, DEBUG
- `channel` — canal de log (spip, dsql, base, etc.)
- `context` — `prive` (admin) ou `public`
- `message` — message de log

### Texte
```
[spip][ERROR][dsql][prive][pid:5] errcode: 1000 : ...
```

## Filtrage par niveau

Le plugin extrait le niveau à partir du préfixe du message de log SPIP :
- `ERREUR:`, `ERROR:`, `HS:` → ERROR
- `WARNING:`, `AVERTISSEMENT:` → WARNING
- `INFO:`, `!INFO:` → INFO
- Tout le reste → INFO

Les messages en dessous de `LOG_LEVEL` sont écartés.

## Requêtes CloudWatch Logs Insights

### Toutes les erreurs de la dernière heure
```
fields @timestamp, @message
| filter @message like /\"level\":\"ERROR\"/
| sort @timestamp desc
| limit 50
```

### Erreurs BD
```
fields @timestamp, @message
| filter @message like /\"channel\":\"dsql\"/
| sort @timestamp desc
```

### Analyse de motifs
```
fields @timestamp, @message
| filter @message like /spip/
| pattern @message
```

## Emplacement du plugin

`spip/plugins-vendor/logs_stderr/` — depuis https://git.spip.net/spip-contrib-extensions/logs_stderr

Fichier clé : `inc/log.php` — remplace `inc_log()` (la fonction de journalisation de SPIP).

## Erreurs PHP

Les erreurs PHP (Fatal, Warning, Notice) partent vers stderr indépendamment du plugin de log SPIP — elles sont contrôlées par `error_reporting` dans le PHP ini. Actuellement toutes les erreurs PHP sont journalisées. Pour supprimer les avertissements, ajoutez au Dockerfile :
```dockerfile
RUN printf '...\nerror_reporting=E_ALL & ~E_WARNING & ~E_NOTICE\n' > /opt/bref/etc/php/conf.d/spip-lambda.ini
```

## Journalisation du pilote DSQL

Le pilote DSQL (`spip/overlay/ecrire/req/dsql.php`) journalise les erreurs SQL via `spip_log()` sur le canal `dsql`. Elles apparaissent ainsi :
```json
{"level":"ERROR","channel":"dsql","context":"prive","message":"errcode: 1000 : <error detail>","aws.xray.trace_id":"1-abc123-def456@span123"}
```

## Corrélation avec les traces X-Ray

Les entrées de log incluent automatiquement `aws.xray.trace_id` lorsqu'une trace est active. Format : `<trace-id>@<span-id>`.

```json
{"level":"HS","channel":"spip","context":"public","message":"...","aws.xray.trace_id":"1-69fc9929-52095a885168a76063ddeaf6@f725f76da07265d7"}
```

Cela permet :
- **Trace → Logs :** dans la console X-Ray, cliquez sur une trace pour voir les entrées de log associées
- **Logs → Trace :** dans CloudWatch Logs, cliquez sur le lien de l'identifiant de trace pour sauter vers la trace

### Fonctionnement
Le plugin `logs_stderr` vérifie la présence de `$GLOBALS['_otel_root_span']` (défini par la configuration OTEL de `prepend.php`). S'il est présent, il extrait l'identifiant de trace et l'identifiant de span et les ajoute à la sortie JSON.

### Requête CloudWatch Insights (trouver les logs d'une trace)
```
fields @timestamp, @message
| filter @message like "1-69fc9929-52095a885168a76063ddeaf6"
| sort @timestamp asc
```
