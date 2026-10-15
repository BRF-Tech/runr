<?php

use App\Http\Middleware\AuthorizeApiRoute;
use App\Http\Middleware\CheckAccountDisabled;
use App\Http\Middleware\EnsureRegionMutable;
use App\Http\Middleware\ETagResponse;
use App\Http\Middleware\RecordAuditTrail;
use App\Http\Middleware\ResponseCache;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrackTokenSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Private broadcast channels: POST /api/broadcasting/auth inside the api stack (Sanctum token,
    // X-Tenant-ID tenant identification); who may subscribe: routes/channels.php
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustHosts(at: ['runr.test', 'runr.sh', 'localhost']);
        // Client IP from X-Forwarded-For only as far as the known proxy hops wrote it
        // (config/trustedproxy.php: TRUSTED_PROXIES / TRUSTED_PROXY_HOPS)
        $middleware->replace(TrustProxies::class, App\Http\Middleware\TrustProxies::class);
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->api(prepend: [
            // Outermost: records the final outcome of every audited request (docs/security.md)
            RecordAuditTrail::class,
            HandleCors::class,
            SetLocale::class,
            ETagResponse::class,
            ResponseCache::class,
        ]);

        $middleware->api(append: [
            TrackTokenSession::class,
            CheckAccountDisabled::class,
        ]);

        // Code and file contents are kept byte for byte — no trimmed trailing newline or leading
        // indentation: the code editor (the git remote mirrors its saves), runtime files, sandbox
        // files and the MCP server's tool arguments
        $middleware->trimStrings(except: [
            fn (Request $request) => $request->is(
                'api/projects/*/files', 'api/projects/*/code', 'api/runtimes', 'api/runtimes/*',
                'api/sandboxes/*/files/content', 'mcp',
            ),
        ]);

        // Named limiter `api` (per tenant / per user / per IP): App\Support\ApiRateLimits,
        // config/ratelimit.php. Runs after auth + tenant identification (middleware priority).
        $middleware->throttleApi('api');

        // Region-mutating routes (routes/api.php); the Tenancy module binds its own implementation
        $middleware->alias([
            'region.mutable' => EnsureRegionMutable::class,
        ]);

        // Roles & permissions: every authenticated API route is authorized against
        // config/route_permissions.php — after authentication, tenant identification and route
        // model binding; response caching only after that (cached bodies are per user).
        $middleware->api(append: [AuthorizeApiRoute::class]);
        $middleware->appendToPriorityList(SubstituteBindings::class, EnsureRegionMutable::class);
        $middleware->appendToPriorityList(EnsureRegionMutable::class, AuthorizeApiRoute::class);
        $middleware->appendToPriorityList(AuthorizeApiRoute::class, ETagResponse::class);
        $middleware->appendToPriorityList(ETagResponse::class, ResponseCache::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);
    })->create();
