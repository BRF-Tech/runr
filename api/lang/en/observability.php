<?php

return [
    'tracing' => [
        'no_cluster' => 'The region has no cluster connection (kubeconfig).',
        'no_region' => 'The project has no region.',
        'not_ready' => 'The tracing backend of the project\'s region is not ready. Install it in the region settings (Observability).',
        'region_not_ready' => 'Tracing needs the region\'s tracing backend: install it in the region settings first.',
        'external_backend' => 'This region exports its traces to :endpoint. Open them in that backend.',
        'tempo_unavailable' => 'Tempo of the region did not answer. Try again in a moment.',
        'trace_not_found' => 'Trace not found for this project (it may have expired from the region\'s retention).',
        'invalid_range' => '"from" must be before "to".',
        'header_name_invalid' => 'Header names may contain letters, digits and dashes only.',
        'install_started' => 'The tracing backend is being installed.',
        'uninstall_started' => 'The tracing backend is being removed.',
    ],

    'replay' => [
        'no_endpoint' => 'Replay sends the request to the project\'s HTTP endpoint; this project has none (queue or schedule trigger).',
        'code_runtimes_only' => 'Request capture runs in RunR\'s runtime wrapper and is available for code projects (XaaS / FaaS) other than WebAssembly functions.',
        'header_invalid' => 'Invalid header name.',
    ],

    'uptime' => [
        'no_endpoint' => 'The project has no HTTP endpoint: give the check an absolute URL.',
        'timeout' => 'No answer within :seconds s.',
        'no_response' => 'No response.',
        'unexpected_status' => 'Status :status, expected :expected.',
        'body_mismatch' => 'The response body does not match the expected pattern.',
        'invalid_regex' => 'Invalid regular expression.',
        'expected_status_invalid' => 'Use status codes (200), ranges (200-299), classes (2xx), comma-separated.',
        'too_many' => 'A project can have at most :max uptime checks.',
        'region_unknown' => 'Unknown region.',
        'incident_title' => ':name is down',
        'incident_message' => 'We are investigating failed checks.',
        'incident_resolved' => 'The service answers again.',
        'alert_title' => 'Uptime check ":name" of :project is down',
        'alert_resolved' => 'Uptime check ":name" is up again.',
    ],

    'status_page' => [
        'default_title' => 'Service status',
        'slug_taken' => 'This address is already used by another status page.',
        'domain_taken' => 'This domain is already used by another status page.',
        'not_found' => 'Status page not found.',
    ],

    'alerts' => [
        'test_title' => 'Test alert from RunR',
        'test_text' => 'The alert channel ":channel" works.',
        'resolved_text' => 'The condition is back to normal.',
        'no_recipients' => 'The channel has no recipients.',
        'channel_unknown' => 'Unknown alert channel.',
        'project_unknown' => 'Unknown project.',
        'retry_failed_only' => 'Only failed alert deliveries can be retried.',
        'metric_title' => [
            'error_rate' => 'High error rate on :project',
            'p95_latency' => 'High p95 latency on :project',
            'cold_start_rate' => 'High cold-start rate on :project',
        ],
        'metric_text' => ':value over the last :window minutes (threshold :threshold).',
        'metric_resolved' => 'Back to :value.',
        'resource_text' => ':resource reservation of region :region is at :percentage % (threshold :threshold %).',
    ],

    'url' => [
        'invalid' => 'Invalid URL.',
        'invalid_scheme' => 'Only http:// and https:// URLs are allowed.',
        'https_required' => 'The URL must use https://.',
        'credentials' => 'URLs with user name or password are not allowed.',
        'private' => 'The URL points to a private, loopback or cluster-internal address.',
        'unresolvable' => 'The host name does not resolve.',
    ],
];
