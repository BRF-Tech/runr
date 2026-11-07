<?php

/*
|--------------------------------------------------------------------------
| Runtime sources (docs/runtimes.md#runtime-sources)
|--------------------------------------------------------------------------
|
| Runtimes come from sources RunR reads periodically: RunR's own signed catalog (the built-in
| runtimes, the default source) and the sources platform administrators and organizations connect
| — a signed OCI catalog or a Git repository with runtimes/<id>/runtime.yaml. The catalog the API
| image ships (resources/runtimes/builtin.json) is only the fallback of an installation that cannot
| read the default catalog (air-gapped, or RUNR_RUNTIME_CATALOG_REF set empty).
|
*/

return [
    'default' => [
        // OCI reference of RunR's runtime catalog (BRF-Tech/runr-runtimes publishes it); empty: the
        // bundled catalog only
        'reference' => (string) env('RUNR_RUNTIME_CATALOG_REF', 'ghcr.io/brf-tech/runr/runtime-catalog:stable'),

        // cosign public key (PEM) the catalog must be signed with. Default: the key of
        // BRF-Tech/runr-runtimes (keys/patch-feed.pub there; the catalog and the patch feed share it)
        'public_key' => (string) env('RUNR_RUNTIME_CATALOG_PUBLIC_KEY', <<<'PEM'
-----BEGIN PUBLIC KEY-----
MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEGdKhMmbBAKxXJUxpxtBRgGQstN8y
sa4lqGU1wKXXWEQ3hEPCdQNbBoDmTDgC4XRxI6sXfxJ9V6eslYxKgITcRw==
-----END PUBLIC KEY-----
PEM),

        // Read the catalog through this region's internal registry (its ghcr.io pull-through) instead
        // of from ghcr.io: an API without a way out (region id or name; empty = direct). Defaults to
        // the patch feed's region
        'via_region' => (string) env('RUNR_RUNTIME_CATALOG_VIA_REGION', (string) env('RUNR_PATCH_FEED_VIA_REGION', '')),

        // Minutes between two checks of the catalog (a HEAD of its tag; read only when it changed)
        'interval_minutes' => (int) env('RUNR_RUNTIME_CATALOG_INTERVAL', 15),
    ],

    // Seconds of one registry request (OCI catalogs, patch feeds, version discovery of a source)
    'timeout' => (int) env('RUNR_RUNTIME_SOURCE_TIMEOUT', 20),

    // Seconds of a git ls-remote / clone of a Git source
    'git_timeout' => (int) env('RUNR_RUNTIME_SOURCE_GIT_TIMEOUT', 120),

    // Days an orphaned runtime (no longer in its source) stays deployable: its sunset
    'orphan_grace_days' => (int) env('RUNR_RUNTIME_ORPHAN_GRACE_DAYS', 90),

    // Sync interval of a connected source: default and the smallest allowed, in minutes
    'interval_minutes' => 60,
    'min_interval_minutes' => 5,

    // Allow http:// and git:// URLs for Git sources (an internal Git server without TLS; the e2e
    // suite serves a repository in the cluster this way). Off: https://, ssh:// and git@host:path
    'allow_insecure' => (bool) env('RUNR_RUNTIME_SOURCES_ALLOW_INSECURE', false),

    // Further git protocols a Git source may use besides https / ssh: the test suite's file://
    // repositories only (not read from the environment)
    'git_extra_protocols' => [],

    // Limits of one source: the catalog payload, the runtimes it may define
    'max_catalog_bytes' => 8 * 1024 * 1024,
    'max_runtimes' => 200,

    // Sources an organization may connect
    'max_per_organization' => 10,
];
