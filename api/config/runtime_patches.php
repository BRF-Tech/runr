<?php

/*
|--------------------------------------------------------------------------
| Runtime patches (docs/runtimes.md#runtime-patches, docs/security.md#runtime-patch-feed)
|--------------------------------------------------------------------------
|
| Runtime images get immutable patch tags (exec-php:8.3.36-r1; the floating exec-php:8.3 points
| to the newest). One signed feed — an OCI artifact with one layer of media type
| application/vnd.runr.patch-feed.v1+json, signed with cosign — tells every RunR instance which
| patches exist, why they came (level, reason, CVEs) and until when a critical or zero-day patch
| may wait. RunR polls the feed's manifest digest (a HEAD) and reads the feed only when it
| changed; a feed whose signature does not verify with public_key is ignored (the last good one
| stays). The default public_key is the key BRF-Tech/runr-runtimes signs the feed with
| (keys/patch-feed.pub there); set RUNR_PATCH_FEED_PUBLIC_KEY to another key for a feed of your
| own, or to an empty value to switch the feed off (platform administrators then see a warning).
|
*/

return [
    // OCI reference of the feed (registry/repository:tag)
    'feed' => env('RUNR_PATCH_FEED', 'ghcr.io/brf-tech/runr/runtime-patches:feed'),

    // cosign public key (PEM, ECDSA P-256) the feed's signature must verify with; empty = feed off.
    // Default: runr-runtimes' feed key (DER SHA-256 24d79a49162ffec0b866ab7274a451a566f7e27fdb6804bffb6d9859d4b1c0df)
    'public_key' => (string) env('RUNR_PATCH_FEED_PUBLIC_KEY', <<<'PEM'
-----BEGIN PUBLIC KEY-----
MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEGdKhMmbBAKxXJUxpxtBRgGQstN8y
sa4lqGU1wKXXWEQ3hEPCdQNbBoDmTDgC4XRxI6sXfxJ9V6eslYxKgITcRw==
-----END PUBLIC KEY-----
PEM),

    // Read the feed through this region's internal registry (its ghcr.io pull-through) instead of
    // from the feed's registry: installs whose API has no way out (region id or name; empty = direct)
    'via_region' => (string) env('RUNR_PATCH_FEED_VIA_REGION', ''),

    // Seconds of one registry request
    'timeout' => (int) env('RUNR_PATCH_FEED_TIMEOUT', 15),

    // Patches per runtime version a project can be pinned to (the newest ones)
    'keep' => 5,

    // At a critical / zero-day patch's deadline RunR redeploys the affected projects onto it
    // (platform administrators switch this off on the Patches page; this is the default)
    'auto_migrate' => (bool) env('RUNR_PATCH_AUTO_MIGRATE', true),

    // Deadline of a critical / zero-day patch whose feed entry names none, in days after publication
    'default_deadline_days' => [
        'critical' => 14,
        'zero-day' => 3,
    ],

    // End-of-life reminders, in weeks before a runtime version's eol_at
    'eol_reminder_weeks' => [13, 4, 1],

    // ...of which these also go out by mail
    'eol_mail_weeks' => [1],
];
