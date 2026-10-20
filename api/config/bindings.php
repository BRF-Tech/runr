<?php

use App\Support\Images\RegistryMove;

/*
|--------------------------------------------------------------------------
| Bindings — managed KV, object storage and Postgres for projects (docs/bindings.md)
|--------------------------------------------------------------------------
| A binding is a data service instance (a Redis ACL user + key prefix, a bucket + scoped key,
| a Postgres database + role, or user-provided credentials) that projects of the same
| organization attach to. Its credentials reach the functions as env vars through the
| per-project Secret <project>-bindings.
|
| Region data services run in their own namespace (`namespace`) behind NetworkPolicies:
| only pods of attached projects reach a binding's service port.
*/

return [

    // Namespace of the region data services (KV Redis, object store, Postgres clusters)
    'namespace' => env('BINDINGS_NAMESPACE', 'runr-data'),

    // Days a deleted binding keeps its data before it is purged (restore works until then)
    'retention_days' => (int) env('BINDINGS_RETENTION_DAYS', 7),

    // Seconds the old credentials keep working after a rotation (rolling restart window)
    'rotation_grace_seconds' => (int) env('BINDINGS_ROTATION_GRACE', 600),

    // Seconds between status polls of in-cluster operations (install, ops pods)
    'poll_interval' => (int) env('BINDINGS_POLL_INTERVAL', 3),

    // One-shot operation pods (redis-cli / psql / rclone / admin API calls) in the data namespace
    'ops' => [
        'timeout' => (int) env('BINDINGS_OPS_TIMEOUT', 180),
        // Python toolbox of the admin API calls (Garage); mirrored python:3.12-slim
        'toolbox_image' => RegistryMove::ref(env('BINDINGS_OPS_TOOLBOX_IMAGE', 'ghcr.io/brf-tech/runr/reqs/python:3.12-slim')),
    ],

    /*
    | Per-organization limits (the whole install when Tenancy is off). 0 = unlimited.
    | The Tenancy module stores per-tenant overrides (tenant_binding_quotas).
    */
    'quotas' => [
        'max_bindings' => (int) env('BINDINGS_QUOTA_MAX_BINDINGS', 20),
        'kv_mb' => (int) env('BINDINGS_QUOTA_KV_MB', 1024),
        'object_gb' => (int) env('BINDINGS_QUOTA_OBJECT_GB', 100),
        'postgres_gb' => (int) env('BINDINGS_QUOTA_POSTGRES_GB', 50),
        'dedicated_postgres' => (int) env('BINDINGS_QUOTA_DEDICATED_POSTGRES', 2),
    ],

    // ── KV: Redis with one ACL user + key prefix per binding ─────────────────────────────
    'kv' => [
        'image' => RegistryMove::ref(env('BINDINGS_KV_IMAGE', 'ghcr.io/brf-tech/runr/reqs/redis:7-alpine')),
        // Instance memory (maxmemory, noeviction) and AOF volume of the managed KV Redis
        'maxmemory_mb' => (int) env('BINDINGS_KV_MAXMEMORY_MB', 512),
        'storage' => env('BINDINGS_KV_STORAGE', '2Gi'),
        'storage_class' => env('BINDINGS_STORAGE_CLASS', ''),
        'default_memory_mb' => 64,
        'max_memory_mb' => 4096,
    ],

    // ── Object storage: Garage (managed) or an external S3 endpoint ──────────────────────
    'object' => [
        // Mirror of dxflrs/garage (lib/mirror.sh of BRF-Tech/runr-runtimes)
        'image' => RegistryMove::ref(env('BINDINGS_OBJECT_IMAGE', 'ghcr.io/brf-tech/runr/reqs/garage:v2.1.0')),
        'storage' => env('BINDINGS_OBJECT_STORAGE', '20Gi'),
        'meta_storage' => env('BINDINGS_OBJECT_META_STORAGE', '1Gi'),
        'storage_class' => env('BINDINGS_STORAGE_CLASS', ''),
        's3_region' => env('BINDINGS_OBJECT_S3_REGION', 'garage'),
        'default_quota_gb' => 5,
        'max_quota_gb' => 1000,
        // S3 client of the purge / external-bucket operations (mirror of rclone/rclone)
        'client_image' => RegistryMove::ref(env('BINDINGS_OBJECT_CLIENT_IMAGE', 'ghcr.io/brf-tech/runr/reqs/rclone:1.68.2')),
    ],

    // ── Postgres: CloudNativePG operator, shared cluster or a dedicated one per binding ──
    'postgres' => [
        'chart_repo_name' => 'cnpg',
        'chart_repo' => env('BINDINGS_CNPG_CHART_REPO', 'https://cloudnative-pg.github.io/charts'),
        'chart' => 'cnpg/cloudnative-pg',
        'chart_version' => env('BINDINGS_CNPG_CHART_VERSION', '0.26.0'),
        'operator_namespace' => env('BINDINGS_CNPG_NAMESPACE', 'cnpg-system'),
        'image' => env('BINDINGS_POSTGRES_IMAGE', 'ghcr.io/cloudnative-pg/postgresql:17.5'),
        'storage_class' => env('BINDINGS_STORAGE_CLASS', ''),
        'shared' => [
            'instances' => (int) env('BINDINGS_POSTGRES_SHARED_INSTANCES', 1),
            'storage' => env('BINDINGS_POSTGRES_SHARED_STORAGE', '10Gi'),
            'max_connections' => 200,
        ],
        'default_storage_gb' => 1,
        'max_storage_gb' => 500,
        'default_connection_limit' => 20,
        'max_connection_limit' => 500,
        'default_statement_timeout' => '30s',
        'idle_in_transaction_timeout' => '60s',
        'dedicated' => [
            'default_instances' => 1,
            'max_instances' => 3,
            'default_storage_gb' => 5,
        ],
        // Backups to the region object store (barman-cloud): retention + daily schedule (6-field cron)
        'backup' => [
            'bucket' => 'runr-pg-backups',
            'retention' => env('BINDINGS_POSTGRES_BACKUP_RETENTION', '7d'),
            'schedule' => env('BINDINGS_POSTGRES_BACKUP_SCHEDULE', '0 0 3 * * *'),
        ],
    ],

    // ── External: user-provided URL / credentials, injected as-is ──────────────────────
    'external' => [
        'max_values' => 30,
    ],
];
