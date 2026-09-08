# Logging

[Français](../fr/logging.md) · **English**

## Architecture

SPIP logs → `logs_stderr` plugin → PHP `error_log()` → stderr → CloudWatch Logs

The `logs_stderr` plugin (`spip/plugins-vendor/logs_stderr/`) overrides SPIP's `inc_log()` function to write to stderr instead of `tmp/log/` files. On Lambda, stderr goes directly to CloudWatch.

## Configuration

Environment variables on Lambda (set in `iac/spip/app/locals.tf`):

| Variable | Values | Default | Description |
|---|---|---|---|
| `LOG_LEVEL` | `ERROR`, `WARNING`, `INFO`, `DEBUG` | `WARNING` | Minimum level to output |
| `LOG_FORMAT` | `json`, `text` | `json` | Output format |

## Output Format

### JSON (default)
```json
{"level":"ERROR","channel":"dsql","context":"prive","message":"errcode: 1000 : ..."}
```

Fields:
- `level` — ERROR, WARNING, INFO, DEBUG
- `channel` — log channel (spip, dsql, base, etc.)
- `context` — `prive` (admin) or `public`
- `message` — log message

### Text
```
[spip][ERROR][dsql][prive][pid:5] errcode: 1000 : ...
```

## Level Filtering

The plugin extracts the level from SPIP's log message prefix:
- `ERREUR:`, `ERROR:`, `HS:` → ERROR
- `WARNING:`, `AVERTISSEMENT:` → WARNING
- `INFO:`, `!INFO:` → INFO
- Everything else → INFO

Messages below `LOG_LEVEL` are dropped.

## CloudWatch Logs Insights Queries

### All errors in last hour
```
fields @timestamp, @message
| filter @message like /\"level\":\"ERROR\"/
| sort @timestamp desc
| limit 50
```

### DB errors
```
fields @timestamp, @message
| filter @message like /\"channel\":\"dsql\"/
| sort @timestamp desc
```

### Pattern analysis
```
fields @timestamp, @message
| filter @message like /spip/
| pattern @message
```

## Plugin Location

`spip/plugins-vendor/logs_stderr/` — from https://git.spip.net/spip-contrib-extensions/logs_stderr

Key file: `inc/log.php` — overrides `inc_log()` (SPIP's logging function).

## PHP Errors

PHP errors (Fatal, Warning, Notice) go to stderr independently of the SPIP log plugin — they're controlled by `error_reporting` in PHP ini. Currently all PHP errors are logged. To suppress warnings, add to the Dockerfile:
```dockerfile
RUN printf '...\nerror_reporting=E_ALL & ~E_WARNING & ~E_NOTICE\n' > /opt/bref/etc/php/conf.d/spip-lambda.ini
```

## DSQL Driver Logging

The DSQL driver (`spip/overlay/ecrire/req/dsql.php`) logs SQL errors via `spip_log()` on the `dsql` channel. These appear as:
```json
{"level":"ERROR","channel":"dsql","context":"prive","message":"errcode: 1000 : <error detail>","aws.xray.trace_id":"1-abc123-def456@span123"}
```

## X-Ray Trace Correlation

Log entries automatically include `aws.xray.trace_id` when a trace is active. Format: `<trace-id>@<span-id>`.

```json
{"level":"HS","channel":"spip","context":"public","message":"...","aws.xray.trace_id":"1-69fc9929-52095a885168a76063ddeaf6@f725f76da07265d7"}
```

This enables:
- **Trace → Logs:** In X-Ray console, click a trace to see associated log entries
- **Logs → Trace:** In CloudWatch Logs, click the trace ID link to jump to the trace

### How it works
The `logs_stderr` plugin checks for `$GLOBALS['_otel_root_span']` (set by `prepend.php` OTEL setup). If present, it extracts the trace ID and span ID and adds them to the JSON output.

### CloudWatch Insights query (find logs for a trace)
```
fields @timestamp, @message
| filter @message like "1-69fc9929-52095a885168a76063ddeaf6"
| sort @timestamp asc
```
