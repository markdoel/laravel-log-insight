<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Log Source
    |--------------------------------------------------------------------------
    |
    | Where the analyzer looks for log files when no --file option is given.
    | The pattern matches both "single" (laravel.log) and "daily"
    | (laravel-2026-01-31.log) channel files.
    |
    */

    'logs' => [
        'path' => env('LOG_INSIGHT_LOG_PATH', storage_path('logs')),
        'pattern' => env('LOG_INSIGHT_LOG_PATTERN', 'laravel*.log'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fingerprinting
    |--------------------------------------------------------------------------
    |
    | Path prefixes (relative to the base path) that count as application
    | code. The topmost stack frame inside one of them becomes part of the
    | error fingerprint. Vendor frames are ignored.
    |
    | "frame" decides how that frame is identified:
    | - "line":   "app/Http/Controllers/OrderController.php:42"
    | - "method": "app/Http/Controllers/OrderController.php::App\Http\Controllers\OrderController::show",
    |             so deploys that only shift lines keep the same clusters.
    |             Falls back to the line form when the method is unknown.
    | Switching modes starts new clusters for errors seen afterwards.
    |
    */

    'fingerprint' => [
        'app_paths' => ['app/', 'src/', 'routes/', 'database/'],
        'frame' => env('LOG_INSIGHT_FINGERPRINT_FRAME', 'line'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Clustering
    |--------------------------------------------------------------------------
    |
    | Only entries at or above this level are clustered and stored.
    | One of: debug, info, notice, warning, error, critical, alert, emergency.
    |
    */

    'clustering' => [
        'min_level' => env('LOG_INSIGHT_MIN_LEVEL', 'error'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Redaction
    |--------------------------------------------------------------------------
    |
    | Every payload is redacted before it leaves your server. Built-in rules
    | cover secrets, tokens, passwords, e-mail and IP addresses.
    |
    | "trace_arguments" also replaces string arguments in stack frames, e.g.
    | login('jane', 'hunter2'), since no pattern can tell what they contain.
    |
    | Add your own regular expressions under "patterns": pattern => replacement.
    |
    */

    'redaction' => [
        'trace_arguments' => (bool) env('LOG_INSIGHT_REDACT_TRACE_ARGUMENTS', true),

        'patterns' => [
            // '/ORDER-\d{6}/' => '[ORDER_ID]',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Source Context
    |--------------------------------------------------------------------------
    |
    | Number of source lines sent before and after the affected line, and the
    | root directory that file paths from the stack trace are resolved against.
    |
    */

    'context' => [
        'lines' => (int) env('LOG_INSIGHT_CONTEXT_LINES', 15),
        'max_trace_lines' => (int) env('LOG_INSIGHT_MAX_TRACE_LINES', 60),
        'base_path' => env('LOG_INSIGHT_BASE_PATH', base_path()),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Analysis
    |--------------------------------------------------------------------------
    |
    | When enabled, log-insight:analyze queues an AnalyzeClusterJob for every
    | cluster without an analysis. Analyzed clusters are never sent again.
    | Disable it to only parse and cluster.
    |
    */

    'analysis' => [
        'enabled' => (bool) env('LOG_INSIGHT_ANALYSIS_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | LLM Provider
    |--------------------------------------------------------------------------
    |
    | Thinking tokens count towards max_tokens, so keep it generous.
    | "effort" (low, medium, high, xhigh, max) trades depth for cost.
    | "fallbacks" lets the API retry a request the model declines on
    | Anthropic's recommended fallback model; turn it off for models without
    | server-side fallback support.
    |
    */

    'llm' => [
        'driver' => env('LOG_INSIGHT_LLM_DRIVER', 'anthropic'),

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('LOG_INSIGHT_MODEL', 'claude-opus-5-5'),
            'base_url' => env('LOG_INSIGHT_ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'api_version' => env('LOG_INSIGHT_ANTHROPIC_VERSION', '2026-10-21'),
            'timeout' => (int) env('LOG_INSIGHT_TIMEOUT', 300),
            'max_tokens' => (int) env('LOG_INSIGHT_MAX_TOKENS', 16000),
            'effort' => env('LOG_INSIGHT_EFFORT', 'high'),
            'fallbacks' => (bool) env('LOG_INSIGHT_FALLBACKS', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Connection and queue name for AnalyzeClusterJob. Null uses the defaults.
    |
    */

    'queue' => [
        'connection' => env('LOG_INSIGHT_QUEUE_CONNECTION'),
        'name' => env('LOG_INSIGHT_QUEUE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | Database connection for the log_insight_* tables. Null uses the default.
    |
    */

    'database' => [
        'connection' => env('LOG_INSIGHT_DB_CONNECTION'),
    ],

];
