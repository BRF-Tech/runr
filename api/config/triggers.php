<?php

/*
|--------------------------------------------------------------------------
| Triggers (docs/triggers.md)
|--------------------------------------------------------------------------
*/

return [

    // Public base URL of webhook inbox URLs (<base>/api/hooks/<context>/<key>); empty: the git
    // deploy webhook base, else the console URL
    'webhook_base_url' => env('TRIGGERS_WEBHOOK_BASE_URL', ''),

    // Largest webhook body the inbox stores (KiB); a trigger may set less
    'webhook_max_body_kb' => (int) env('TRIGGERS_WEBHOOK_MAX_BODY_KB', 1024),

    // Retention of stored webhook / platform events (days): default and most a trigger may set
    'event_retention_days' => (int) env('TRIGGERS_EVENT_RETENTION_DAYS', 7),
    'event_retention_max_days' => (int) env('TRIGGERS_EVENT_RETENTION_MAX_DAYS', 30),

    // Event gateway: how often an inbox source polls the API when nothing nudged it
    'inbox_poll_seconds' => (int) env('TRIGGERS_INBOX_POLL_SECONDS', 15),

    // Delivery defaults of gateway triggers (a trigger's `delivery` overrides them)
    'delivery' => [
        'concurrency' => 4,
        'max_retries' => 5,
        'retry_backoff_seconds' => 2,
        'retry_backoff_max_seconds' => 300,
    ],

    // Topics: entries a topic stream keeps (XADD MAXLEN ~), largest event (bytes)
    'topic_max_len' => (int) env('TRIGGERS_TOPIC_MAX_LEN', 10000),
    'topic_max_bytes' => (int) env('TRIGGERS_TOPIC_MAX_BYTES', 262144),

    // Dead-letter stream length of Redis-backed triggers
    'dead_letter_max_len' => 10000,
];
