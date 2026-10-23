<?php

use App\Support\Images\RegistryMove;

/*
|--------------------------------------------------------------------------
| Organization export / import (docs/org-export.md)
|--------------------------------------------------------------------------
|
| An organization packed into one versioned file (tar + zstd, manifest.json with the schema
| version, the RunR version and a checksum per entry) and created again from it — in another
| organization, another RunR installation or the same one. Export and import are queued jobs
| (Horizon); the packages live on the API's storage volume until they expire.
|
*/

return [
    // Where packages and their working copies live; empty: storage/app/private/org-transfers
    // (the storage volume the API, Horizon and the scheduler share)
    'path' => env('RUNR_ORG_TRANSFER_PATH', ''),

    // zstd (default) or none (an uncompressed tar — for debugging; the importer reads both)
    'compression' => env('RUNR_ORG_TRANSFER_COMPRESSION', 'zstd'),
    'zstd_level' => max(1, min(19, (int) env('RUNR_ORG_TRANSFER_ZSTD_LEVEL', 3))),
    // The zstd binary when the PHP zstd extension is not loaded
    'zstd_binary' => env('RUNR_ZSTD_BINARY', 'zstd'),

    // Bytes per upload chunk the clients send (the API's request body limit is 64 MiB)
    'chunk_bytes' => max(1024 * 1024, (int) env('RUNR_ORG_TRANSFER_CHUNK_BYTES', 8 * 1024 * 1024)),

    // An import read from an object binding or an S3 bucket: bytes per ranged read, and minutes
    // without progress after which the hourly expiry marks the fetch failed (resumable)
    'fetch_chunk_bytes' => max(1024 * 1024, (int) env('RUNR_ORG_TRANSFER_FETCH_CHUNK_BYTES', 16 * 1024 * 1024)),
    'fetch_stall_minutes' => max(5, (int) env('RUNR_ORG_TRANSFER_FETCH_STALL_MINUTES', 30)),

    // Largest package an import accepts, in GiB
    'max_package_gb' => max(1, (int) env('RUNR_ORG_TRANSFER_MAX_GB', 50)),

    // A finished export's package (and an import's upload) is removed after this many hours
    'retention_hours' => max(1, (int) env('RUNR_ORG_TRANSFER_RETENTION_HOURS', 168)),

    // An import whose upload is not completed within this many hours expires
    'upload_ttl_hours' => max(1, (int) env('RUNR_ORG_TRANSFER_UPLOAD_TTL_HOURS', 48)),

    // Running jobs at once: per organization and kind (one export, one import), per installation
    'max_running_per_organization' => max(1, (int) env('RUNR_ORG_TRANSFER_MAX_PER_ORG', 1)),
    'max_running' => max(1, (int) env('RUNR_ORG_TRANSFER_MAX_RUNNING', 4)),

    // Seconds an export or import job may run (Horizon's timeout for the org-transfer jobs)
    'job_timeout' => max(600, (int) env('RUNR_ORG_TRANSFER_JOB_TIMEOUT', 4 * 3600)),

    // The passphrase that encrypts secrets in a package: shortest length
    'passphrase_min_length' => 12,

    // Region side of binding data and image transfers (runtime/image-upload `transfer`): serves
    // the dump a tool container writes, or receives a file the tool container restores — through
    // the Kubernetes API server, so the region needs no route to RunR
    'transfer_image' => RegistryMove::ref(env('RUNR_TRANSFER_IMAGE', 'ghcr.io/brf-tech/runr/image-upload:1.1.0')),

    // Seconds one transfer pod may run (dump / restore of one binding, one image)
    'transfer_timeout' => max(300, (int) env('RUNR_ORG_TRANSFER_POD_TIMEOUT', 3 * 3600)),

    // Bytes per request between the API and a transfer pod
    'transfer_chunk_bytes' => max(1024 * 1024, (int) env('RUNR_ORG_TRANSFER_POD_CHUNK_BYTES', 16 * 1024 * 1024)),

    // emptyDir of a transfer pod, in GiB (a binding's dump or an image; raise it for bigger data)
    'transfer_disk_gb' => max(1, (int) env('RUNR_ORG_TRANSFER_POD_DISK_GB', 20)),

    // Deployment history entries a package keeps per project (a summary, not imported as deployments)
    'deployment_history' => 50,
];
