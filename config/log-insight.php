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
    */

    'fingerprint' => [
        'app_paths' => ['app/', 'src/', 'routes/', 'database/'],
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
    | cover secrets, tokens, passwords, e-mail and IP addresses. Add your own
    | regular expressions here: pattern => replacement.
    |
    */

    'redaction' => [
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
        'base_path' => env('LOG_INSIGHT_BASE_PATH', base_path()),
    ],

    /*
    |--------------------------------------------------------------------------
    | LLM Provider
    |--------------------------------------------------------------------------
    */

    'llm' => [
        'driver' => env('LOG_INSIGHT_LLM_DRIVER', 'anthropic'),

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('LOG_INSIGHT_MODEL', 'claude-sonnet-5-5'),
            'base_url' => env('LOG_INSIGHT_ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'api_version' => env('LOG_INSIGHT_ANTHROPIC_VERSION', '2023-06-01'),
            'timeout' => (int) env('LOG_INSIGHT_TIMEOUT', 60),
            'max_tokens' => (int) env('LOG_INSIGHT_MAX_TOKENS', 2048),
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
