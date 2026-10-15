<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Build Image Retention
    |--------------------------------------------------------------------------
    |
    | Number of most recent ready builds kept per project in the internal (Zot) registry
    | after a successful build (minimum 1). Older build images are deleted, except those
    | still used by a deployment that is not finished or by the project's live Knative
    | Service / CronJob / queue consumer; their versions become build_status "pruned" and
    | can no longer be deployed. Only applies to regions with registry_mode === 'internal'
    | — an external registry is never touched.
    |
    */
    'retain_versions' => max(1, (int) env('REGISTRY_RETAIN_VERSIONS', 10)),
];
