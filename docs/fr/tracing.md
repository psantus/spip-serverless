# Traçage OpenTelemetry (X-Ray)

**Français** · [English](../en/tracing.md)

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

## Composants

### 1. Extension ADOT Collector (`/opt/extensions/collector`)

- Binaire issu de la couche officielle AWS ADOT Lambda Layer (ARM64)
- S'exécute comme une extension Lambda (s'enregistre auprès de l'Extensions API)
- Écoute sur `localhost:4318` pour l'OTLP HTTP
- Exporte vers X-Ray via l'exportateur `awsxray`
- Config : `/var/task/collector-config.yaml`
- **Ignoré par git** — téléchargé via `make download-adot`

Couche source : `arn:aws:lambda:us-east-1:901920570463:layer:aws-otel-collector-arm64-ver-0-115-0:1`

### 2. SDK PHP OpenTelemetry (paquets composer)

Installé dans l'étape « vendors » du Dockerfile :
```
open-telemetry/sdk:^1
open-telemetry/exporter-otlp:^1
```

### 3. Configuration du TracerProvider (`spip/overlay/php/prepend.php`)

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

### 4. Souscripteur Bref (`spip/overlay/bref_xray_subscriber.php`)

Écrit l'en-tête de trace complet (avec `Parent=`) dans `/tmp/xray_trace_id` avant chaque invocation. C'est nécessaire car les workers Bref FPM n'ont pas accès à la variable d'environnement `_X_AMZN_TRACE_ID` — seul le processus du runtime Bref l'a.

Enregistré via un patch de `autoload_static.php` dans le Dockerfile :
```dockerfile
RUN sed -i "s|public static \$files = array (|...|" /var/task/vendor/composer/autoload_static.php
```

### 5. Instrumentation BD (`spip/overlay/ecrire/req/dsql.php`)

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

## Propagation du contexte de trace

Le défi : en mode Bref FPM, la variable d'environnement `_X_AMZN_TRACE_ID` (qui contient `Root=...;Parent=...;Sampled=1`) n'est disponible que dans le processus du runtime Bref, pas dans les workers PHP-FPM. L'en-tête HTTP `$_SERVER['HTTP_X_AMZN_TRACE_ID']` ne contient que `Root=...;Sampled=1` (pas de Parent).

**Solution :**
1. `bref_xray_subscriber.php` s'exécute dans le processus du runtime Bref via `beforeInvoke`
2. Il écrit `$context->getTraceId()` (en-tête complet avec Parent) dans `/tmp/xray_trace_id`
3. `prepend.php` lit ce fichier, extrait `Root` et `Parent`
4. Crée un `SpanContext::createFromRemoteParent(traceId, parentSpanId)`
5. Crée le span racine `SPIP` comme enfant du segment de la fonction Lambda
6. Tous les spans BD sont enfants du span racine (via `activate()`)

**Résultat :** hiérarchie de trace complète dans X-Ray :
```
API Gateway → Lambda → SPIP (root) → DSQL (×N queries)
```

## Variables d'environnement (Lambda)

```
OTEL_SERVICE_NAME=spip-serverless
OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4318
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_TRACES_EXPORTER=otlp
OPENTELEMETRY_COLLECTOR_CONFIG_FILE=/var/task/collector-config.yaml
```

## Config du Collector (`spip/overlay/otel-collector-config.yaml`)

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

## Consulter les traces

- **Console :** CloudWatch → X-Ray traces
- **Filtrer par service :** `spip-serverless`
- **Traces cron :** durée > 3 s (le cron traite de nombreux jobs)
- **Traces web :** durée < 2 s, comportent un segment API Gateway

## Ajouter de l'instrumentation

Pour tracer d'autres opérations (appels S3, DynamoDB, HTTP), ajoutez des spans dans le code concerné :

```php
if (isset($GLOBALS['_otel_tracer'])) {
    $span = $GLOBALS['_otel_tracer']->spanBuilder('operation-name')
        ->setAttribute('key', 'value')
        ->startSpan();
}
// ... operation ...
if (isset($span)) { $span->end(); }
```

## Impact sur le démarrage à froid

Le collector ADOT ajoute ~3 s au démarrage à froid (initialisation de l'extension). Les requêtes à chaud n'ont aucune surcharge liée au collector (il s'exécute de façon asynchrone). Le SDK PHP OTEL ajoute ~1 ms par span (le SimpleSpanProcessor envoie immédiatement vers localhost).

## Dépannage

- **Aucune trace :** vérifiez que la variable d'environnement `OTEL_EXPORTER_OTLP_ENDPOINT` est définie
- **Traces non liées :** vérifiez que `/tmp/xray_trace_id` est bien écrit (souscripteur Bref chargé)
- **Le collector ne démarre pas :** cherchez dans les logs `EXTENSION Name: collector State: Ready`
- **Erreurs d'export :** cherchez dans les logs du collector les erreurs de l'exportateur `awsxray` (généralement des permissions IAM)
