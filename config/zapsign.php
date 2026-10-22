<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API
    |--------------------------------------------------------------------------
    |
    | API token from ZapSign > Settings > Integration > ZapSign API.
    | Sandbox accounts use https://sandbox.api.zapsign.com.br with their own
    | token (or send `sandbox: true` when creating documents in production).
    |
    */

    'token' => env('ZAPSIGN_TOKEN'),

    'url' => env('ZAPSIGN_URL', 'https://api.zapsign.com.br'),

    'timeout' => (int) env('ZAPSIGN_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Rate limit
    |--------------------------------------------------------------------------
    |
    | ZapSign allows 500 requests per minute per token/IP and answers 429
    | above that. Requests are throttled client-side through the cache store
    | (shared by every worker using the same store); when the window is full
    | the client waits up to `max_wait` seconds for a free slot, otherwise it
    | throws a RateLimitException (queued jobs release themselves instead).
    |
    | A 429/503 that still reaches the API is retried `retry_times` times,
    | honouring the Retry-After header when present.
    |
    */

    'rate_limit' => [
        'enabled' => (bool) env('ZAPSIGN_RATE_LIMIT_ENABLED', true),
        'max_attempts' => (int) env('ZAPSIGN_RATE_LIMIT_MAX_ATTEMPTS', 500),
        'decay_seconds' => (int) env('ZAPSIGN_RATE_LIMIT_DECAY_SECONDS', 60),
        'max_wait' => (int) env('ZAPSIGN_RATE_LIMIT_MAX_WAIT', 60),
        'cache_store' => env('ZAPSIGN_RATE_LIMIT_CACHE_STORE'),
        'retry_times' => (int) env('ZAPSIGN_RETRY_TIMES', 3),
        'retry_sleep_ms' => (int) env('ZAPSIGN_RETRY_SLEEP_MS', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | ZapSign does not sign webhooks: register the webhook with a custom
    | header (`header` => `secret`) in ZapSign and the route only accepts
    | requests carrying it. The route is registered only when a secret is set.
    |
    | Valid payloads are stored in `zapsign_webhook_calls` (duplicates of the
    | same body are ignored) and processed by the ProcessWebhookCall job,
    | which updates the local documents/signers and dispatches
    | WebhookReceived. Calls older than `prune_after_days` are removed by
    | `php artisan model:prune`.
    |
    */

    'webhook' => [
        'secret' => env('ZAPSIGN_WEBHOOK_SECRET'),
        'header' => env('ZAPSIGN_WEBHOOK_HEADER', 'X-ZapSign-Secret'),
        'path' => env('ZAPSIGN_WEBHOOK_PATH', 'zapsign/webhook'),
        'middleware' => [],
        'prune_after_days' => (int) env('ZAPSIGN_WEBHOOK_PRUNE_AFTER_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Signed files
    |--------------------------------------------------------------------------
    |
    | ZapSign file links expire after 60 minutes. When a disk is set, the
    | signed PDF of every document that reaches `signed` is downloaded by the
    | StoreSignedFile job and its path saved in `signed_file_path`.
    |
    */

    'files' => [
        'disk' => env('ZAPSIGN_FILES_DISK'),
        'directory' => env('ZAPSIGN_FILES_DIRECTORY', 'zapsign'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Connection and queue used by the package jobs (null = application defaults).
    |
    */

    'queue' => [
        'connection' => env('ZAPSIGN_QUEUE_CONNECTION'),
        'name' => env('ZAPSIGN_QUEUE'),
    ],

];
