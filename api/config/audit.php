<?php

/*
|--------------------------------------------------------------------------
| Audit log (docs/security.md, "Audit log")
|--------------------------------------------------------------------------
*/

return [
    // Master switch for recording (tests and tooling may turn it off; production keeps it on)
    'enabled' => env('AUDIT_ENABLED', true),

    /*
     * Events older than this many days are deleted by the daily retention prune. The platform
     * setting `audit_retention_days` (PUT /api/audit-settings) overrides it. 0 keeps everything.
     */
    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 365),

    /*
     * SIEM forwarding: every event is also written as one JSON line to the `audit` log channel
     * (stdout by default, config/logging.php) — the container log a collector ships to the SIEM.
     */
    'forward' => [
        'enabled' => env('AUDIT_FORWARD_ENABLED', false),
        'channel' => env('AUDIT_FORWARD_CHANNEL', 'audit'),
    ],

    // Export cap per request (CSV / JSON), newest first
    'export_max_rows' => (int) env('AUDIT_EXPORT_MAX_ROWS', 100000),
];
