<?php

use App\Services\Helpers\ManagedTracing;

/*
|--------------------------------------------------------------------------
| Observability v2 — tracing, request replay, uptime checks, status page, alerting
|--------------------------------------------------------------------------
| docs/observability.md. Helm: values `observability.*` → the OBS_* variables below.
*/

return [

    /*
    | Tracing backend per region: OpenTelemetry Collector (gateway) + Grafana Tempo, installed
    | with Knative Serving (region mode "managed"), or a reused in-cluster Tempo / an external
    | OTLP endpoint behind RunR's collector.
    */
    'tracing' => [
        // Region default mode for regions without a tracing configuration:
        // managed | reuse | external | off
        'default_mode' => env('OBS_TRACING_DEFAULT_MODE', 'managed'),
        // Install the tracing backend together with Knative Serving (installServing)
        'install_with_serving' => (bool) env('OBS_TRACING_INSTALL_WITH_SERVING', true),

        // Chart versions (empty: the pinned ones, which the e2e of runtime/ci.sh
        // test-observability runs) and their images from the reqs mirror
        // (ManagedTracing::COLLECTOR_IMAGE / TEMPO_IMAGE). An image here is a full reference, e.g.
        // into a registry of your own; *_IMAGE_TAG replaces its tag.
        'collector_chart' => env('OBS_OTEL_COLLECTOR_CHART', 'open-telemetry/opentelemetry-collector'),
        'collector_chart_version' => env('OBS_OTEL_COLLECTOR_CHART_VERSION') ?: ManagedTracing::COLLECTOR_CHART_VERSION,
        'collector_image' => env('OBS_OTEL_COLLECTOR_IMAGE') ?: ManagedTracing::COLLECTOR_IMAGE,
        'collector_image_tag' => env('OBS_OTEL_COLLECTOR_IMAGE_TAG', ''),
        'tempo_chart' => env('OBS_TEMPO_CHART', 'grafana-community/tempo'),
        'tempo_chart_version' => env('OBS_TEMPO_CHART_VERSION') ?: ManagedTracing::TEMPO_CHART_VERSION,
        'tempo_image' => env('OBS_TEMPO_IMAGE') ?: ManagedTracing::TEMPO_IMAGE,
        'tempo_image_tag' => env('OBS_TEMPO_IMAGE_TAG', ''),
        'tempo_storage' => env('OBS_TEMPO_STORAGE', '10Gi'),
        'tempo_retention_hours' => (int) env('OBS_TEMPO_RETENTION_HOURS', 72),

        // Knative activator / queue-proxy spans, region default (region setting overrides)
        'knative_sample_rate' => (float) env('OBS_KNATIVE_TRACING_SAMPLE_RATE', 0.1),

        // Project default sampling ratio when tracing is switched on
        'default_sample_ratio' => (float) env('OBS_TRACING_DEFAULT_SAMPLE_RATIO', 1.0),

        // Language auto-instrumentation of the built-in images (OTEL_NODE_ENABLED_INSTRUMENTATIONS)
        'node_instrumentations' => env('OBS_NODE_INSTRUMENTATIONS', 'http,undici,dns,net,ioredis,redis,pg,mysql2,mongodb'),

        // Trace search: max time range (hours), result limit
        'search_max_hours' => (int) env('OBS_TRACE_SEARCH_MAX_HOURS', 168),
        'search_limit' => (int) env('OBS_TRACE_SEARCH_LIMIT', 50),
        'query_timeout' => (int) env('OBS_TEMPO_QUERY_TIMEOUT', 10),
    ],

    /*
    | Request replay: opt-in capture of failed requests (5xx / timeouts) per project.
    */
    'replay' => [
        'max_body_bytes' => (int) env('OBS_REPLAY_MAX_BODY_BYTES', 65536),
        'max_body_bytes_ceiling' => (int) env('OBS_REPLAY_MAX_BODY_BYTES_CEILING', 1048576),
        'retention_days' => (int) env('OBS_REPLAY_RETENTION_DAYS', 7),
        'retention_days_max' => (int) env('OBS_REPLAY_RETENTION_DAYS_MAX', 30),
        'max_captures' => (int) env('OBS_REPLAY_MAX_CAPTURES', 500),
        'per_minute' => (int) env('OBS_REPLAY_PER_MINUTE', 60),
        // Headers / query parameters never stored, on top of each project's own list
        'redact_headers' => [
            'authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'x-api-key', 'x-auth-token',
            'x-csrf-token', 'x-xsrf-token', 'x-amz-security-token', 'x-goog-iap-jwt-assertion',
        ],
        'redact_query' => ['access_token', 'api_key', 'apikey', 'password', 'secret', 'token'],
        // Replays go to the project's own endpoint (https://<slug>.<knative domain>)
        'target_scheme' => env('OBS_REPLAY_TARGET_SCHEME', 'https'),
        'verify_tls' => (bool) env('OBS_REPLAY_VERIFY_TLS', true),
        'timeout' => (int) env('OBS_REPLAY_TIMEOUT', 30),
        // Base URL the function pods reach RunR at; empty → derived from the agent URL of the region
        'ingest_url' => env('OBS_INGEST_URL', ''),
    ],

    /*
    | Uptime checks + status page.
    */
    'uptime' => [
        'min_interval' => (int) env('OBS_UPTIME_MIN_INTERVAL', 30),
        'max_interval' => (int) env('OBS_UPTIME_MAX_INTERVAL', 3600),
        'max_timeout' => (int) env('OBS_UPTIME_MAX_TIMEOUT', 60),
        'max_checks_per_project' => (int) env('OBS_UPTIME_MAX_CHECKS', 20),
        'results_retention_days' => (int) env('OBS_UPTIME_RETENTION_DAYS', 90),
        'concurrency' => (int) env('OBS_UPTIME_CONCURRENCY', 20),
        // Checks may target private addresses only when this is true (SSRF guard)
        'allow_private_targets' => (bool) env('OBS_UPTIME_ALLOW_PRIVATE', false),
    ],

    'status_page' => [
        'enabled' => (bool) env('OBS_STATUS_PAGE_ENABLED', true),
        'history_days' => (int) env('OBS_STATUS_PAGE_HISTORY_DAYS', 90),
    ],

    /*
    | Alert channels + rules.
    */
    'alerts' => [
        'delivery_tries' => (int) env('OBS_ALERT_DELIVERY_TRIES', 4),
        'delivery_backoff' => array_map('intval', explode(',', (string) env('OBS_ALERT_DELIVERY_BACKOFF', '30,120,600'))),
        'delivery_timeout' => (int) env('OBS_ALERT_DELIVERY_TIMEOUT', 10),
        'default_cooldown_minutes' => (int) env('OBS_ALERT_COOLDOWN_MINUTES', 15),
        'deliveries_retention_days' => (int) env('OBS_ALERT_DELIVERY_RETENTION_DAYS', 30),
        // Webhook / Slack / Teams URLs on private addresses only when this is true (SSRF guard)
        'allow_private_targets' => (bool) env('OBS_ALERT_ALLOW_PRIVATE', false),
        'telegram_api' => env('OBS_TELEGRAM_API', 'https://api.telegram.org'),

        // Span metrics (OpenTelemetry Collector spanmetrics connector → region Prometheus)
        'metrics' => [
            'calls' => env('OBS_SPANMETRICS_CALLS', 'traces_span_metrics_calls_total'),
            'duration' => env('OBS_SPANMETRICS_DURATION', 'traces_span_metrics_duration_milliseconds_bucket'),
            'service' => env('OBS_SPANMETRICS_SERVICE_LABEL', 'service_name'),
            'cold' => env('OBS_SPANMETRICS_COLD_LABEL', 'runr_cold_start'),
        ],
    ],
];
