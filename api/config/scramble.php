<?php

use App\Support\ApiActionReturnType;

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

    /*
     * RunR: the OpenAPI document GET /api/docs.json serves (App\Support\ApiDocument), built with
     * the API image by `php artisan runr:api-docs` (api/Dockerfile.prod). Never committed (.gitignore)
     * and kept out of the image's build context (.dockerignore), so the image always builds its own.
     * Without the file (a development checkout) the document is generated on each request.
     */
    'document_path' => resource_path('api-docs/openapi.json'),

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

    // A controller action's responses are those of its returns (Scramble 0.13 would document an
    // action that returns `$error` of `if ($error = $this->check())` by its declared type alone)
    'extensions' => [ApiActionReturnType::class],
];
