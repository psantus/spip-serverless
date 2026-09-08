# Cron (file de jobs SPIP)

**Français** · [English](../en/cron.md)

## Problème

La file de jobs de SPIP (`genie`) s'exécute normalement en ligne à chaque requête web — elle vérifie la présence de jobs en attente et les exécute. Sur Lambda, cela ajoute de la latence à chaque requête et n'est pas fiable (les instances Lambda sont éphémères).

## Solution

1. **Bloquer la file sur les requêtes web** — `_DEBUG_BLOCK_QUEUE = true` dans `prepend.php`
2. **Déclencher via EventBridge** — une règle planifiée invoque Lambda toutes les 5 minutes avec `?action=cron`

## Fonctionnement

### Requêtes web (bloquées)
`prepend.php` s'exécute avant SPIP et définit :
```php
if (empty($_GET['action']) || $_GET['action'] !== 'cron') {
    define('_DEBUG_BLOCK_QUEUE', true);
}
```
Cela empêche SPIP d'exécuter le moindre job en attente. Un SELECT léger sur `spip_jobs` s'exécute quand même (~3 ms) — c'est SPIP qui vérifie la présence de tâches en attente sans les exécuter.

### Requêtes cron (EventBridge)
EventBridge invoque Lambda toutes les 5 minutes avec :
```json
{
  "version": "2.0",
  "rawPath": "/spip.php",
  "rawQueryString": "action=cron",
  "queryStringParameters": {"action": "cron"},
  ...
}
```
Comme `$_GET['action'] === 'cron'`, `_DEBUG_BLOCK_QUEUE` n'est PAS défini, et SPIP traite tous les jobs en attente.

## Ressources Terraform (`iac/spip/app/cron.tf`)

- `aws_cloudwatch_event_rule.spip_cron` — planification : `rate(5 minutes)`
- `aws_cloudwatch_event_target.spip_cron` — invoque Lambda avec l'événement cron
- `aws_lambda_permission.eventbridge_cron` — autorise EventBridge à invoquer Lambda

## Types de jobs SPIP

Jobs courants qui s'exécutent via le cron :
- `queue_watch` — surveille la file de jobs elle-même
- `optimiser` — optimisation de la base de données
- `maintenance` — tâches de maintenance générales
- `mise_a_jour` — vérifications de mises à jour
- `revisions_optimiser_revisions` — nettoyage de l'historique des révisions
- `medias_nettoyer_repertoire_upload` — nettoyage du répertoire d'upload
- `svp_actualiser_depots` — rafraîchissement des infos du dépôt de plugins

## Modifier la fréquence

Modifiez `iac/spip/app/cron.tf` :
```hcl
schedule_expression = "rate(5 minutes)"  # Change to "rate(15 minutes)" etc.
```

## Déclenchement manuel

```bash
curl https://cms.example.com/spip.php?action=cron
```

## Supervision

Les invocations du cron apparaissent dans X-Ray sous forme de traces avec :
- Durée > 3 s (traitement de nombreux jobs)
- 30 à 50 requêtes BD
- Aucun segment API Gateway (invocation directe de Lambda depuis EventBridge)

## Impact sur les requêtes web

Avant : ~25 requêtes BD par page d'admin, exécution des jobs comprise
Après : ~11 requêtes BD par page d'admin (jobs bloqués)

Le SELECT restant sur `spip_jobs` (~3 ms) ne peut pas être éliminé sans patcher le cœur de SPIP (`ecrire/inc/queue.php`).
