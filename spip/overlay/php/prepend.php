<?php
/**
 * Lambda prepend — runs before any SPIP code via auto_prepend_file.
 *
 * 1. Creates writable dirs in /tmp
 * 2. Copies pre-baked caches + config from image to /tmp
 * 3. Overrides SPIP _DIR_* constants to point to /tmp
 * 4. Registers DynamoDB session handler (shared across Lambda instances)
 */

// ── 0. Ensure $_SERVER keys SPIP core expects exist (Bref/Lambda) ──────────
// SPIP 4.4.18 inc_version.php reads $_SERVER['DOCUMENT_ROOT'] unconditionally.
if (!isset($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = '/var/task';
}

// ── 0b. Pin SPIP's public base URL (host + scheme) ─────────────────────────
// Behind CloudFront + API Gateway the origin sees the execute-api host (CloudFront
// strips the viewer Host so API Gateway accepts the request), so SPIP's url_de_base()
// would build absolute URLs (login "converser", redirects, canonical, emails) on the
// wrong host, without the stage path. Force the real public host/scheme here so every
// absolute URL SPIP emits points at the CloudFront (or custom) domain.
$publicUrl = getenv('SPIP_PUBLIC_URL');
if ($publicUrl && ($p = parse_url($publicUrl)) && !empty($p['host'])) {
    $_SERVER['HTTP_HOST']   = $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $_SERVER['SERVER_NAME'] = $p['host'];
    if (($p['scheme'] ?? 'https') === 'https') {
        $_SERVER['HTTPS']       = 'on';
        $_SERVER['SERVER_PORT'] = '443';
    }
    unset($p);
}
unset($publicUrl);


// ── 1. Create writable dirs ────────────────────────────────────────────────
if (!is_dir('/tmp/spip/cache')) {
    @mkdir('/tmp/spip/cache/skel', 0777, true);
    @mkdir('/tmp/spip/cache/calcul', 0777, true);
    @mkdir('/tmp/spip/cache/xml', 0777, true);
    @mkdir('/tmp/spip/cache/wheels', 0777, true);
    @mkdir('/tmp/spip/sessions', 0777, true);
    @mkdir('/tmp/spip/log', 0777, true);
    @mkdir('/tmp/spip/dump', 0777, true);
    @mkdir('/tmp/spip/upload', 0777, true);
    @mkdir('/tmp/spip/local/cache-css', 0777, true);
    @mkdir('/tmp/spip/local/cache-js', 0777, true);
    @mkdir('/tmp/spip/IMG', 0777, true);
    @mkdir('/tmp/spip/etc', 0777, true);
}

// ── 2. Copy pre-baked files on cold start ──────────────────────────────────
// Plugin caches (deterministic per deployment, pre-baked in Docker image)
if (!file_exists('/tmp/spip/cache/charger_pipelines.php')) {
    foreach (glob('/var/task/tmp/cache/*.{php,txt,gz}', GLOB_BRACE) as $f) {
        copy($f, '/tmp/spip/cache/' . basename($f));
    }
}
// Config files (connect.php, mes_options.php)
if (!file_exists('/tmp/spip/etc/connect.php')) {
    foreach (glob('/var/task/config/*.php') as $f) {
        copy($f, '/tmp/spip/etc/' . basename($f));
    }
    // cles.php: Bref resolves bref-ssm: AFTER FPM starts, so first cold-start request
    // may not have the resolved value yet. Write it now if available.
    $cles = getenv('SPIP_CLES');
    if ($cles && !str_starts_with($cles, 'bref-ssm:')) {
        file_put_contents('/tmp/spip/etc/cles.php', "<?php die ('Acces interdit'); ?>\n" . $cles);
    }
} elseif (!file_exists('/tmp/spip/etc/cles.php')) {
    // Second request: Bref has resolved by now
    $cles = getenv('SPIP_CLES');
    if ($cles && !str_starts_with($cles, 'bref-ssm:')) {
        file_put_contents('/tmp/spip/etc/cles.php', "<?php die ('Acces interdit'); ?>\n" . $cles);
    }
}

// ── 3. Override SPIP filesystem paths ──────────────────────────────────────
define('_DIR_TMP', '/tmp/spip/');
define('_DIR_CACHE', '/tmp/spip/cache/');
define('_DIR_CACHE_XML', '/tmp/spip/cache/xml/');
define('_DIR_SKELS', '/tmp/spip/cache/skel/');
define('_DIR_AIDE', '/tmp/spip/cache/aide/');
define('_DIR_LOG', '/tmp/spip/log/');
define('_DIR_SESSIONS', '/tmp/spip/sessions/');
define('_DIR_DUMP', '/tmp/spip/dump/');
define('_DIR_TRANSFERT', '/tmp/spip/upload/');
define('_FILE_META', '/tmp/spip/meta_cache.php');
define('_DIR_ETC', '/tmp/spip/etc/');
define('_DIR_CONNECT', '/tmp/spip/etc/');

// ── 4. Sessions handled by sessions_dynamodb plugin (inc/session.php) ───────
// The plugin reads SPIP_SESSION_TABLE env var directly.

// ── 4. S3 for IMG/ (uploaded files) ─────────────────────────────────────────
// Register S3 stream wrapper. _DIR_IMG stays as IMG/ for URL generation.
// The squelettes/inc/documents.php override handles S3 writes.
$s3Bucket = getenv('S3_BUCKET');
if ($s3Bucket) {
    require_once '/var/task/vendor/autoload.php';
    $s3Client = new Aws\S3\S3Client([
        'region'  => getenv('S3_REGION') ?: getenv('AWS_REGION') ?: 'us-east-1',
        'version' => 'latest',
    ]);
    $s3Client->registerStreamWrapper();
    // Don't override _DIR_IMG — keep it as IMG/ for URLs
    // Store bucket name for use by documents.php override
    define('_S3_BUCKET', $s3Bucket);
    unset($s3Client);
}
unset($s3Bucket);

// ── 5. Fix missing SCRIPT_NAME for API Gateway ────────────────────────────
if (empty($_SERVER['SCRIPT_NAME'])) {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
}

// ── 5a. Block SPIP cron on web requests (EventBridge triggers it separately)
if (empty($_GET['action']) || $_GET['action'] !== 'cron') {
    define('_DEBUG_BLOCK_QUEUE', true);
}

// ── 5b. OpenTelemetry tracing (ADOT collector on localhost:4318) ───────────
if (getenv('OTEL_EXPORTER_OTLP_ENDPOINT') && class_exists(\OpenTelemetry\SDK\Trace\TracerProviderBuilder::class, true)) {
    $tracerProvider = (new \OpenTelemetry\SDK\Trace\TracerProviderBuilder())
        ->addSpanProcessor(
            // Batch (not Simple): accumulate spans and flush once at shutdown instead
            // of one synchronous HTTP POST per span DURING the request. The Simple
            // processor added ~700ms/request (30+ inline exports). In Lambda the batch
            // flush happens post-response, so the user no longer waits for it.
            new \OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor(
                new \OpenTelemetry\Contrib\Otlp\SpanExporter(
                    (new \OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory())->create(
                        getenv('OTEL_EXPORTER_OTLP_ENDPOINT') . '/v1/traces',
                        'application/x-protobuf'
                    )
                ),
                \OpenTelemetry\SDK\Common\Time\ClockFactory::getDefault()
            )
        )
        // Respect the parent (API Gateway / X-Ray) sampling decision: only trace when
        // the incoming X-Amzn-Trace-Id has Sampled=1. Root (no parent) → don't trace.
        // This offloads sampling to AWS and avoids building/exporting spans for the
        // requests X-Ray would drop anyway.
        ->setSampler(new \OpenTelemetry\SDK\Trace\Sampler\ParentBased(
            new \OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler()
        ))
        ->build();
    $GLOBALS['_otel_tracer'] = $tracerProvider->getTracer('spip');

    // Extract X-Ray trace context from ADOT extension env or HTTP header
    $_xh = @file_get_contents('/tmp/xray_trace_id') ?: ($_SERVER['HTTP_X_AMZN_TRACE_ID'] ?? '');
    if ($_xh && str_contains($_xh, 'Sampled=1')
        && preg_match('/Root=1-([0-9a-f]{8})-([0-9a-f]{24})/', $_xh, $rm)
        && preg_match('/Parent=([0-9a-f]{16})/', $_xh, $pm)) {
        $traceId = $rm[1] . $rm[2]; // 32 hex chars
        $parentSpanId = $pm[1];     // 16 hex chars
        $parentContext = \OpenTelemetry\API\Trace\SpanContext::createFromRemoteParent(
            $traceId, $parentSpanId,
            \OpenTelemetry\API\Trace\TraceFlags::SAMPLED
        );
        $ctx = \OpenTelemetry\Context\Context::getCurrent()->withContextValue(
            \OpenTelemetry\API\Trace\Span::wrap($parentContext)
        );
        $GLOBALS['_otel_root_span'] = $GLOBALS['_otel_tracer']->spanBuilder('SPIP')
            ->setParent($ctx)
            ->startSpan();
        $GLOBALS['_otel_scope'] = $GLOBALS['_otel_root_span']->activate();
    }

    register_shutdown_function(function() use ($tracerProvider) {
        if (isset($GLOBALS['_otel_scope'])) { $GLOBALS['_otel_scope']->detach(); }
        if (isset($GLOBALS['_otel_root_span'])) { $GLOBALS['_otel_root_span']->end(); }
        $tracerProvider->shutdown();
    });
}

// ── 6. Sync local/ cache files to S3 on shutdown ──────────────────────────
if (getenv('S3_BUCKET')) {
    // Capture request start once, to only sync files (re)generated during THIS request.
    $__req_start = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
    register_shutdown_function(function() use ($__req_start) {
        $bucket = getenv('S3_BUCKET');
        if (!is_dir('/tmp/spip/local/')) { return; }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator('/tmp/spip/local/', RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getBasename() === '.ok') { continue; }
            // Only consider files created/modified during this request. Stable cache
            // files (the vast majority) are skipped → no per-request S3 round-trips.
            // This is the hot-path optimization: previously every file triggered a
            // filesize("s3://...") network call on every request.
            if ($file->getMTime() < $__req_start - 1) { continue; }
            $key = 'local/' . substr($file->getPathname(), strlen('/tmp/spip/local/'));
            // Re-upload when absent OR size differs (a later complete render overwrites
            // a previously truncated cache file, whose name is a hash of the context).
            $localSize = $file->getSize();
            $remoteSize = @filesize("s3://$bucket/$key"); // false if absent
            if ($remoteSize === false || $remoteSize !== $localSize) {
                @copy($file->getPathname(), "s3://$bucket/$key");
            }
        }
    });
}

// ── 6. Inject S3 upload JS + rewrite s3:// URLs in HTML output ──────────────
if (getenv('S3_BUCKET')) {
    $__s3bucket = getenv('S3_BUCKET');
    ob_start(function($html) use ($__s3bucket) {
        // Rewrite s3://bucket/IMG/... to /IMG/... (absolute) in HTML output
        $html = str_replace('s3://' . $__s3bucket . '/', '/', $html);
        return $html;
    });
}
