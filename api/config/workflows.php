<?php

/*
|--------------------------------------------------------------------------
| Durable workflows (docs/workflows.md)
|--------------------------------------------------------------------------
| The engine drives workflow functions that live in projects with the `workflow` trigger.
| Every value here is a platform default; a workflow definition may lower or raise the
| per-workflow ones (retries, timeout, concurrency) within the `limits`.
*/

return [

    // Queue (connection + name) the engine's jobs run on. Horizon processes `default`.
    'queue' => env('WORKFLOWS_QUEUE', 'default'),
    'connection' => env('WORKFLOWS_QUEUE_CONNECTION'),

    // Engine → function HTTP call
    'request_timeout' => (int) env('WORKFLOWS_REQUEST_TIMEOUT', 300),
    'connect_timeout' => (int) env('WORKFLOWS_CONNECT_TIMEOUT', 10),
    'verify_tls' => (bool) env('WORKFLOWS_VERIFY_TLS', true),
    // Path appended to the project's function URL (the SDK handler answers on any path)
    'endpoint_path' => env('WORKFLOWS_ENDPOINT_PATH', ''),
    // Endpoint overrides (per project) may point at private / loopback addresses only when true
    'allow_private_endpoints' => (bool) env('WORKFLOWS_ALLOW_PRIVATE_ENDPOINTS', false),

    // Signed requests: the SDK refuses a signature older / newer than this many seconds
    'signature_tolerance' => 300,
    // Grace period during which a rotated signing key still signs requests (seconds)
    'key_rotation_grace' => (int) env('WORKFLOWS_KEY_ROTATION_GRACE', 86400),

    // Public base URL of the RunR API as seen from a region cluster (queue bridge sink).
    // Empty: APP_URL.
    'public_api_url' => env('WORKFLOWS_PUBLIC_API_URL', ''),

    'defaults' => [
        'retries' => 3,        // attempts per step = retries + 1
        'run_timeout' => '30d',
        'wait_timeout' => '7d',     // waitForEvent / invoke without an explicit timeout
    ],

    // Exponential backoff between attempts: base × factor^(attempt-1), capped, ± jitter
    'backoff' => [
        'base_seconds' => 2,
        'factor' => 2,
        'max_seconds' => 600,
        'jitter' => 0.2,
    ],

    'limits' => [
        'max_event_bytes' => 262144,     // 256 KiB per event (name + data)
        'max_events_per_request' => 100,
        'max_step_output_bytes' => 1048576,    // 1 MiB per step output
        'max_run_output_bytes' => 1048576,
        'max_request_bytes' => 8388608,    // 8 MiB: event + memoized step state sent to the function
        'max_steps_per_run' => 1000,
        'max_retries' => 20,
        'max_concurrency' => 1000,
        'max_run_timeout' => '365d',
        'max_workflows_per_project' => 200,
    ],

    'retention' => [
        'runs_days' => (int) env('WORKFLOWS_RUN_RETENTION_DAYS', 30),
        'events_days' => (int) env('WORKFLOWS_EVENT_RETENTION_DAYS', 7),
        'usage_days' => (int) env('WORKFLOWS_USAGE_RETENTION_DAYS', 400),
    ],

    // Delayed jobs up to this many seconds are queued with a delay; longer sleeps are woken
    // by the per-minute `workflows:tick`.
    'delayed_dispatch_max_seconds' => 900,
    // A run deferred by its concurrency limit checks for a free slot again after this delay
    'deferred_poll_seconds' => 5,
    // A queued run whose job has not started within this many seconds is dispatched again
    'redispatch_after_seconds' => 120,
    // Runs handled per context per tick (each kind)
    'tick_batch' => 500,
];
