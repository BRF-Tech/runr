<?php

namespace App\Services\Shell;

use Laravel\Reverb\Servers\Reverb\Factory;
use Laravel\Reverb\Servers\Reverb\Http\Route;
use Laravel\Reverb\Servers\Reverb\Http\Router;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

class ShellAwareFactory extends Factory
{
    /**
     * Override to use SafeRouter that handles non-WS requests gracefully.
     */
    public static function makePusherRouter(string $path): Router
    {
        // Call parent to register singletons, discard the returned Router
        parent::makePusherRouter($path);

        // Re-create with SafeRouter
        return new SafeRouter(new UrlMatcher(static::pusherRoutes($path), new RequestContext));
    }

    /**
     * Extend Pusher routes with a raw WebSocket shell handler.
     */
    protected static function pusherRoutes(string $path): RouteCollection
    {
        $routes = parent::pusherRoutes($path);

        // Raw WS handler — no Pusher protocol, direct PTY stream
        // Route is /shell (not /ws/shell) because Vite proxy strips /ws prefix
        $routes->add('shell', Route::get('/shell', new ShellController));

        // Sandbox API exec stream — one command, stdout/stderr frames, stdin from the client
        // (token from POST /api/sandboxes/{id}/exec/ws)
        $routes->add('sandbox-exec', Route::get('/sandbox-exec', new SandboxExecSocket));

        // Agent WS handler — Go agent connects here to push metrics/events
        // Route is /agent/{regionId} (Caddy strips /ws prefix)
        $routes->add('agent', Route::get('/agent/{regionId}', new AgentController));

        return $routes;
    }
}
