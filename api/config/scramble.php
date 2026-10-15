<?php

return [
    /*
     * Your API path. By default, all routes starting with this path will be added to the docs.
     */
    'api_path' => 'api',

    /*
     * Your API domain. When null, app domain is used.
     */
    'api_domain' => null,

    /*
     * The path where your OpenAPI specification will be exported via `php artisan scramble:export`.
     */
    'export_path' => 'api.json',

    'info' => [
        'version' => env('API_VERSION', '1.0.0'),
        'description' => 'RunR API',
    ],

    'ui' => [
        'title' => 'RunR API Docs',
        'theme' => 'dark',
    ],

    /*
     * Middleware applied to the docs routes only.
     * auth:sanctum is intentionally excluded so docs are publicly accessible.
     */
    'middleware' => [
        'api',
    ],

    /*
     * Exclude internal routes from API docs.
     */
    'routes' => null, // The closure is defined in AppServiceProvider (config:cache compatibility)

    'servers' => null,

    'extensions' => [],
];
