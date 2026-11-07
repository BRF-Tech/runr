<?php

use App\Support\Images\RegistryMove;

return [
    /*
     * The first default of the platform setting "Built-in runtimes" (Build & Run → Runtimes,
     * Setting builtin_runtimes, App\Services\Runtimes\Sources\BuiltinOffer): the built-in runtimes
     * offered, ids of the catalog (runtimes/<id>/runtime.yaml of BRF-Tech/runr-runtimes). Empty (the
     * default) = the whole catalog, so a runtime the catalog gains later is offered without a RunR
     * release. Once a platform administrator saves the setting, this is no longer read.
     */
    'runtimes' => array_values(array_filter(array_map('trim', explode(',', (string) env('EXECUTOR_RUNTIMES', ''))))),

    /*
     * Directory of the built-in catalog sources: runtimes/ of a BRF-Tech/runr-runtimes checkout
     * (development, runtimes:bundle). Default: ../runtime when it has <id>/runtime.yaml files, else
     * the bundled resources/runtimes/builtin.json. Either is used only while RunR's default
     * catalog (config/runtime_sources.php) has never been read: once it has, its runtimes win.
     */
    'catalog_path' => env('RUNR_RUNTIME_CATALOG'),

    /*
     * RunR's universal wrapper (wrapper/ of BRF-Tech/runr-runtimes): PID 1 of exec / persistent
     * runtimes, copied into code-mounted functions by an init container and baked into built
     * function images. The e2e harness pulls the same tag (runtime/lib/runtime-images.sh).
     */
    'wrapper_image' => RegistryMove::ref(env('RUNR_WRAPPER_IMAGE', 'ghcr.io/brf-tech/runr/wrapper:1.1.0')),

    /*
     * Deadline of a function build Job (Kaniko, WebAssembly toolchain), in seconds (at least 60).
     * It covers the first pull of a base image through the region registry: Zot fetches an image
     * it does not hold yet from its upstream before it answers (minutes for a large one).
     */
    'build_deadline_seconds' => (int) env('BUILD_DEADLINE_SECONDS', 900),
];
