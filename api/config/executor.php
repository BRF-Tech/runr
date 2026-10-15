<?php

return [
    /*
     * Built-in runtimes offered on this platform (ids of the built-in catalog,
     * runtime/<id>/runtime.yaml). Empty = the whole catalog.
     */
    'runtimes' => array_filter(explode(',', env('EXECUTOR_RUNTIMES', 'python,nodejs,go,php,tensorflow,pytorch,wasm-rust,wasm-go,wasm-js,wasm-python,wasm-exec'))),

    /*
     * Directory of the built-in catalog sources (runtime/). Default: ../runtime when this
     * checkout has it, else the bundled resources/runtimes/builtin.json.
     */
    'catalog_path' => env('RUNR_RUNTIME_CATALOG'),

    /*
     * RunR's universal wrapper (runtime/wrapper): PID 1 of exec / persistent runtimes, copied into
     * code-mounted functions by an init container and baked into built function images.
     */
    'wrapper_image' => env('RUNR_WRAPPER_IMAGE', 'ghcr.io/runrsh/runr-wrapper:1.1.0'),
];
