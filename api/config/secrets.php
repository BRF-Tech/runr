<?php

/*
|--------------------------------------------------------------------------
| Project secrets (docs/security.md, "Secret environment variables"; docs/secret-stores.md)
|--------------------------------------------------------------------------
|
| storage   Where RunR writes the value of a secret variable:
|           'region'   (default) straight into the Kubernetes Secret <slug>-secret-env of the
|                      project's region. RunR's database keeps the name, a version, who changed
|                      it and when, and a keyed fingerprint — never the value.
|           'database' the behaviour of RunR up to 0.5.10: the value encrypted with APP_KEY in
|                      project_secrets, written into the region by every deploy. Deprecated; an
|                      install that keeps it moves the values later with
|                      `php artisan secrets:move-to-region`.
|           Values of either kind keep working whatever the setting is: a value still in the
|           database is written into the region by the next deploy until it is moved.
|
| fingerprint_key  Key of the value fingerprints (HMAC-SHA256). Empty: derived from APP_KEY —
|           a fingerprint made with a previous key (APP_PREVIOUS_KEYS) still matches.
|
| external  External Secrets Operator, the optional region feature that syncs references to an
|           organization's secret stores into the region (docs/secret-stores.md). Pinned chart
|           and image: the image comes from RunR's reqs mirror like every other third-party
|           image (REQS_IMAGES of lib/mirror.sh in BRF-Tech/runr-runtimes).
*/

return [
    'storage' => env('RUNR_SECRET_STORAGE', 'region'),

    'fingerprint_key' => env('RUNR_SECRET_FINGERPRINT_KEY', ''),

    'external' => [
        'chart_repo_name' => 'external-secrets',
        'chart_repo' => env('RUNR_ESO_CHART_REPO', 'https://charts.external-secrets.io'),
        'chart' => 'external-secrets/external-secrets',
        // Changes together with 'image' and its REQS_IMAGES entry
        'chart_version' => env('RUNR_ESO_CHART_VERSION', '0.19.2'),
        'image' => env('RUNR_ESO_IMAGE', 'ghcr.io/brf-tech/runr/reqs/external-secrets:v0.19.2'),
        'namespace' => 'external-secrets',
        'release' => 'external-secrets',
        // How often an ExternalSecret reads its store again when the store sets nothing (seconds)
        'refresh_interval' => (int) env('RUNR_ESO_REFRESH_INTERVAL', 3600),
        // Lifetime of the service account tokens the stores log in with (seconds)
        'token_ttl' => 600,
    ],

    /*
    | openbao   The region's own OpenBao (docs/secret-stores.md, "OpenBao in the region"), which
    |           RunR installs and operates: one instance per cluster in namespace runr-openbao,
    |           TLS with a certificate RunR issues, file storage on a volume, unsealed by a
    |           sidecar from the region's Secret runr-openbao-unseal. RunR reaches it through the
    |           API server and logs in as the service account runr-control (Kubernetes auth): no
    |           token is kept. The image comes from the reqs mirror (REQS_IMAGES).
    */
    'openbao' => [
        'image' => env('RUNR_OPENBAO_IMAGE', 'ghcr.io/brf-tech/runr/reqs/openbao:2.4.1'),
        'namespace' => 'runr-openbao',
        'storage_size' => env('RUNR_OPENBAO_STORAGE_SIZE', '2Gi'),
        // Empty: the cluster's default StorageClass
        'storage_class' => env('RUNR_OPENBAO_STORAGE_CLASS', ''),
        // Lifetime of the tokens RunR and the workloads' SecretStores get (seconds)
        'token_ttl' => 600,
        // Dynamic Postgres credentials of bindings (DynamicDbCredentials): how long a user lives
        // and how often the region asks for a new one (each new one restarts the workload's pods
        // through a new revision; the previous user lives on until its own TTL ends)
        'db_default_ttl' => env('RUNR_OPENBAO_DB_TTL', '168h'),
        'db_max_ttl' => env('RUNR_OPENBAO_DB_MAX_TTL', '720h'),
        'db_refresh' => env('RUNR_OPENBAO_DB_REFRESH', '24h'),
    ],
];
