<?php

use App\Support\Images\RegistryMove;

/*
|--------------------------------------------------------------------------
| Uploaded images (docs/images.md)
|--------------------------------------------------------------------------
|
| Container images (`docker save` / OCI image-layout tars, gzip'ed or not) and WebAssembly
| modules (.wasm) uploaded through the console, the API or `runr images push`, pushed into a
| region's internal registry by a receiver Job in the region (runtime/image-upload). The API
| forwards every chunk through the Kubernetes API server and keeps nothing on disk.
|
*/

return [
    // Receiver / pusher image (runtime/image-upload); regions without internet pull it through
    // their registry mirror or a mirror of your own
    'upload_image' => RegistryMove::ref(env('RUNR_IMAGE_UPLOAD_IMAGE', 'ghcr.io/brf-tech/image-upload:1.0.0')),

    // Bytes per chunk the clients send (the API's request body limit is 64 MiB)
    'chunk_bytes' => max(1024 * 1024, (int) env('RUNR_IMAGE_UPLOAD_CHUNK_BYTES', 8 * 1024 * 1024)),

    // Largest file of one upload, in GiB
    'max_upload_gb' => max(1, (int) env('RUNR_IMAGE_UPLOAD_MAX_GB', 20)),

    // An upload that is not completed within this many hours is aborted (receiver removed)
    'upload_ttl_hours' => max(1, (int) env('RUNR_IMAGE_UPLOAD_TTL_HOURS', 24)),

    // Storage of uploaded images per organization (the whole install when single-tenant), in GiB,
    // counted over every region. 0 = unlimited.
    'quota_gb' => max(0, (int) env('RUNR_IMAGES_QUOTA_GB', 50)),

    // Repository prefix in the region registry: <prefix>/<owner>/<repository>
    'repository_prefix' => 'runr-uploads',

    // The receiver Job's container
    'receiver' => [
        'cpu_request' => env('RUNR_IMAGE_UPLOAD_CPU_REQUEST', '100m'),
        'memory_request' => env('RUNR_IMAGE_UPLOAD_MEMORY_REQUEST', '128Mi'),
        'cpu_limit' => env('RUNR_IMAGE_UPLOAD_CPU_LIMIT', '2'),
        'memory_limit' => env('RUNR_IMAGE_UPLOAD_MEMORY_LIMIT', '1Gi'),
        // seconds the push into the registry may take once the file is verified
        'push_timeout' => max(60, (int) env('RUNR_IMAGE_UPLOAD_PUSH_TIMEOUT', 3600)),
    ],
];
