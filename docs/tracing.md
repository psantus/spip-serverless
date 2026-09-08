# OpenTelemetry Tracing (X-Ray)

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│ Lambda execution environment                                 │
│                                                              │
│  ┌──────────────┐    OTLP/HTTP     ┌──────────────────────┐ │
│  │  PHP-FPM     │ ──────────────── │  ADOT Collector      │ │
│  │  (OTEL SDK)  │   localhost:4318 │  (Lambda extension)  │ │
│  └──────────────┘                  └──────────┬───────────┘ │
│                                               │              │
└───────────────────────────────────────────────┼──────────────┘
                                                │ PutTraceSegments
                                                ▼
                                          AWS X-Ray API
```

## Components

### 1. ADOT Collector Extension (`/opt/extensions/collector`)

- Binary from the official AWS ADOT Lambda Layer (ARM64)
- Runs as a Lambda extension (registers with Extensions API)
- Listens on `localhost:4318` for OTLP HTTP
- Exports to X-Ray via `awsxray` exporter
- Config: `/var/task/collector-config.yaml`
- **Gitignored** — download via `make download-adot`

Source layer: `arn:aws:lambda:us-east-1:901920570463:layer:aws-otel-collector-arm64-ver-0-115-0:1`

### 2. OpenTelemetry PHP SDK (composer packages)

Installed in Dockerfile vendors stage:
```
open-telemetry/sdk:^1
open-telemetry/exporter-otlp:^1
```

### 3. TracerProvider setup (`spip/overlay/php/prepend.php`)

```php
if (getenv('OTEL_EXPORTER_OTLP_ENDPOINT') && class_exists(\OpenTelemetry\SDK\Trace\TracerProviderBuilder::class, true)) {
    $tracerProvider = (new TracerProviderBuilder())
        ->addSpanProcessor(new SimpleSpanProcessor(new SpanExporter(...)))
        ->setSampler(new AlwaysOnSampler())
        ->build();
    $GLOBALS['_otel_tracer'] = $tracerProvider->getTracer('spip');
    // ... trace context extraction + root span creation
}
```

### 4. Bref Subscriber (`spip/overlay/bref_xray_subscriber.php`)

Writes the full trace header (with `Parent=`) to `/tmp/xray_trace_id` before each invocation. This is necessary because Bref FPM workers don't have access to `_X_AMZN_TRACE_ID` env var — only the Bref runtime process does.

Registered via `autoload_static.php` patch in Dockerfile:
```dockerfile
RUN sed -i "s|public static \$files = array (|...|" /var/task/vendor/composer/autoload_static.php
```

### 5. DB Instrumentation (`spip/overlay/ecrire/req/dsql.php`)

```php
if (isset($GLOBALS['_otel_tracer'])) {
    $_span = $GLOBALS['_otel_tracer']->spanBuilder('DSQL')
        ->setAttribute('db.system', 'postgresql')
        ->setAttribute('db.statement', substr($query, 0, 200))
        ->startSpan();
}
$r = spip_dsql_query_simple($link, $query);
if (isset($_span)) { $_span->end(); unset($_span); }
```

## Trace Context Propagation

The challenge: in Bref FPM mode, the `_X_AMZN_TRACE_ID` env var (which has `Root=...;Parent=...;Sampled=1`) is only available in the Bref runtime process, not in PHP-FPM workers. The HTTP header `$_SERVER['HTTP_X_AMZN_TRACE_ID']` only has `Root=...;Sampled=1` (no Parent).

**Solution:**
1. `bref_xray_subscriber.php` runs in the Bref runtime process via `beforeInvoke`
2. It writes `$context->getTraceId()` (full header with Parent) to `/tmp/xray_trace_id`
3. `prepend.php` reads this file, extracts `Root` and `Parent`
4. Creates a `SpanContext::createFromRemoteParent(traceId, parentSpanId)`
5. Creates root span `SPIP` as child of the Lambda function segment
6. All DB spans are children of the root span (via `activate()`)

**Result:** Full trace hierarchy in X-Ray:
```
API Gateway → Lambda → SPIP (root) → DSQL (×N queries)
```

## Environment Variables (Lambda)

```
OTEL_SERVICE_NAME=spip-serverless
OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4318
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_TRACES_EXPORTER=otlp
OPENTELEMETRY_COLLECTOR_CONFIG_FILE=/var/task/collector-config.yaml
```

## Collector Config (`spip/overlay/otel-collector-config.yaml`)

```yaml
receivers:
  otlp:
    protocols:
      http:
        endpoint: 0.0.0.0:4318
exporters:
  awsxray:
    region: us-east-1
service:
  pipelines:
    traces:
      receivers: [otlp]
      exporters: [awsxray]
```

## Viewing Traces

- **Console:** CloudWatch → X-Ray traces
- **Filter by service:** `spip-serverless`
- **Cron traces:** Duration > 3s (cron processes many jobs)
- **Web traces:** Duration < 2s, have API Gateway segment

## Adding More Instrumentation

To trace additional operations (S3 calls, DynamoDB, HTTP), add spans in the relevant code:

```php
if (isset($GLOBALS['_otel_tracer'])) {
    $span = $GLOBALS['_otel_tracer']->spanBuilder('operation-name')
        ->setAttribute('key', 'value')
        ->startSpan();
}
// ... operation ...
if (isset($span)) { $span->end(); }
```

## Cold Start Impact

The ADOT collector adds ~3s to cold start (extension initialization). Warm requests have zero overhead from the collector (it runs asynchronously). The PHP OTEL SDK adds ~1ms per span (SimpleSpanProcessor sends to localhost immediately).

## Troubleshooting

- **No traces:** Check `OTEL_EXPORTER_OTLP_ENDPOINT` env var is set
- **Traces not linked:** Check `/tmp/xray_trace_id` is being written (Bref subscriber loaded)
- **Collector not starting:** Check logs for `EXTENSION Name: collector State: Ready`
- **Export errors:** Check collector logs for `awsxray` exporter errors (usually IAM permissions)
