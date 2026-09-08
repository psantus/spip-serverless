<?php

if (!defined('_ECRIRE_INC_VERSION')) {
    return;
}

/**
 * Surcharge de inc_log_dist() — JSON structured logs to stderr.
 *
 * Env vars:
 *   LOG_LEVEL: ERROR|WARNING|INFO|DEBUG (default: WARNING)
 *   LOG_FORMAT: json|text (default: json)
 */
function inc_log($message, $logname = null, $logdir = null, $logsuf = null): void {
    static $compteur = [];
    static $level_map = [
        'ERREUR' => 0, 'ERROR' => 0, 'HS' => 0,
        'WARNING' => 1, 'AVERTISSEMENT' => 1,
        'INFO' => 2, '!INFO' => 2,
        'DEBUG' => 3,
    ];
    static $min_level = null;

    if ($min_level === null) {
        $env = strtoupper(getenv('LOG_LEVEL') ?: 'WARNING');
        $min_level = $level_map[$env] ?? 1;
    }

    if (is_null($logname) || !is_string($logname)) {
        $logname = defined('_FILE_LOG') ? _FILE_LOG : 'spip';
    }

    if (!isset($compteur[$logname])) {
        $compteur[$logname] = 0;
    }

    if (
        $logname !== 'maj'
        && defined('_MAX_LOG')
        && $compteur[$logname]++ > _MAX_LOG
    ) {
        return;
    }

    if (!is_string($message)) {
        $message = var_export($message, true);
    }

    $message = rtrim($message, "\r\n");

    // Extract level from message prefix (e.g. "ERREUR: ..." or "WARNING: ...")
    $level = 'INFO';
    if (preg_match('/^(ERREUR|ERROR|HS|WARNING|AVERTISSEMENT|!?INFO|DEBUG)\s*[:]/i', $message, $m)) {
        $level = strtoupper($m[1]);
        $message = trim(substr($message, strlen($m[0])));
    } elseif ($logname === 'dsql' || str_contains($message, 'errcode:')) {
        $level = 'ERROR';
    }

    // Filter by level
    $msg_level = $level_map[$level] ?? 2;
    if ($msg_level > $min_level) {
        return;
    }

    $contexte = test_espace_prive() ? 'prive' : 'public';

    $format = getenv('LOG_FORMAT') ?: 'json';
    if ($format === 'json') {
        $entry = [
            'level' => $level,
            'channel' => $logname,
            'context' => $contexte,
            'message' => $message,
        ];
        // Add X-Ray trace correlation (enables CloudWatch Logs → X-Ray linking)
        if (isset($GLOBALS['_otel_root_span'])) {
            $ctx = $GLOBALS['_otel_root_span']->getContext();
            $tid = $ctx->getTraceId();
            $entry['aws.xray.trace_id'] = '1-' . substr($tid, 0, 8) . '-' . substr($tid, 8) . '@' . $ctx->getSpanId();
        }
        error_log(json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    } else {
        $pid = @getmypid() ?: '-';
        error_log("[spip][$level][$logname][$contexte][pid:$pid] $message");
    }
}
