<?php

/*
|--------------------------------------------------------------------------
| Cross-origin requests to the API (Illuminate\Http\Middleware\HandleCors)
|--------------------------------------------------------------------------
|
| The console calls the API on its own origin (the gateway serves both under one domain), so it
| needs no CORS at all. Allowed origins:
|
|  - the console's own origin: APP_URL and FRONTEND_URL (the Helm chart sets both to
|    https://<domain>);
|  - CORS_ALLOWED_ORIGINS: further origins whose browser pages call the API, comma-separated
|    (Helm: cors.allowedOrigins);
|  - with APP_ENV=local the development servers (Vite, the docker compose UI, runr.test).
|
*/

$origin = static function (?string $url): ?string {
    $parts = parse_url(trim((string) $url));
    if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
        return null;
    }

    return strtolower($parts['scheme'].'://'.$parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
};

$origins = array_map($origin, [
    env('APP_URL'),
    env('FRONTEND_URL'),
    ...explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
]);

if (env('APP_ENV') === 'local') {
    array_push($origins, 'http://localhost:5173', 'http://localhost:8061', 'http://runr.test', 'https://runr.test');
}

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_unique(array_filter($origins))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,   // for Sanctum cookie auth
];
