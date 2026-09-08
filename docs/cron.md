# Cron (SPIP Job Queue)

## Problem

SPIP's job queue (`genie`) normally runs inline on every web request — it checks for pending jobs and executes them. On Lambda, this adds latency to every request and is unreliable (Lambda instances are ephemeral).

## Solution

1. **Block queue on web requests** — `_DEBUG_BLOCK_QUEUE = true` in `prepend.php`
2. **Trigger via EventBridge** — scheduled rule invokes Lambda every 5 minutes with `?action=cron`

## How It Works

### Web requests (blocked)
`prepend.php` runs before SPIP and defines:
```php
if (empty($_GET['action']) || $_GET['action'] !== 'cron') {
    define('_DEBUG_BLOCK_QUEUE', true);
}
```
This prevents SPIP from executing any queued jobs. A lightweight SELECT on `spip_jobs` still runs (~3ms) — this is SPIP checking for pending tasks but not executing them.

### Cron requests (EventBridge)
EventBridge invokes Lambda every 5 minutes with:
```json
{
  "version": "2.0",
  "rawPath": "/spip.php",
  "rawQueryString": "action=cron",
  "queryStringParameters": {"action": "cron"},
  ...
}
```
Since `$_GET['action'] === 'cron'`, `_DEBUG_BLOCK_QUEUE` is NOT defined, and SPIP processes all pending jobs.

## Terraform Resources (`iac/spip/app/cron.tf`)

- `aws_cloudwatch_event_rule.spip_cron` — schedule: `rate(5 minutes)`
- `aws_cloudwatch_event_target.spip_cron` — invokes Lambda with cron event
- `aws_lambda_permission.eventbridge_cron` — allows EventBridge to invoke Lambda

## SPIP Job Types

Common jobs that run via cron:
- `queue_watch` — monitors the job queue itself
- `optimiser` — database optimization
- `maintenance` — general maintenance tasks
- `mise_a_jour` — update checks
- `revisions_optimiser_revisions` — clean up revision history
- `medias_nettoyer_repertoire_upload` — clean upload directory
- `svp_actualiser_depots` — refresh plugin repository info

## Changing Frequency

Edit `iac/spip/app/cron.tf`:
```hcl
schedule_expression = "rate(5 minutes)"  # Change to "rate(15 minutes)" etc.
```

## Manual Trigger

```bash
curl https://cms.example.com/spip.php?action=cron
```

## Monitoring

Cron invocations appear in X-Ray as traces with:
- Duration > 3s (processing many jobs)
- 30-50 DB queries
- No API Gateway segment (direct Lambda invoke from EventBridge)

## Impact on Web Requests

Before: ~25 DB queries per admin page, including job execution
After: ~11 DB queries per admin page (jobs blocked)

The remaining `spip_jobs` SELECT (~3ms) cannot be eliminated without patching SPIP core (`ecrire/inc/queue.php`).
