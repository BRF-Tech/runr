<?php

namespace App\Providers;

use App\Mcp\Console\McpPruneCommand;
use App\Mcp\Console\McpTemplatesBundleCommand;
use App\Mcp\Hosting\HostedServerIndex;
use App\Mcp\Http\Middleware\McpCors;
use App\Mcp\Models\HostedServer;
use App\Mcp\Models\HostedServerCall;
use App\Mcp\Support\McpAuth;
use App\Models\Project;
use App\Support\ApiRateLimits;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * AI agents (docs/mcp.md): the RunR MCP server at /mcp, its OAuth 2.1 authorization server, the
 * gateway in front of hosted MCP servers and the console API around them.
 *
 * Permissions: mcp.servers.manage, mcp.servers.call (config/permissions.php, group "mcp"); the
 * tools of the MCP server follow the permissions of the API routes they wrap.
 */
class McpServiceProvider extends ServiceProvider
{
    public const PERMISSIONS = ['mcp.servers.manage', 'mcp.servers.call'];

    public function register(): void
    {
        // Hosted servers of the organization, read once per request (ProjectResource badges)
        $this->app->scoped(HostedServerIndex::class);
    }

    public function boot(): void
    {
        $this->registerRoutes();
        $this->registerMiddleware();
        $this->registerAuthenticationChallenge();
        $this->registerRateLimits();
        $this->registerProjectCleanup();
        $this->registerSchedule();

        if ($this->app->runningInConsole()) {
            $this->commands([McpPruneCommand::class, McpTemplatesBundleCommand::class]);
        }
    }

    protected function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        if (config('mcp.enabled', true)) {
            Route::group([], base_path('routes/mcp.php'));
        }

        Route::middleware(['api', 'auth:sanctum'])
            ->prefix('api')
            ->group(base_path('routes/mcp-api.php'));
    }

    /** Origin check + CORS of /mcp, /mcp/servers/*, /oauth2/*, /.well-known/oauth-* (before routing). */
    protected function registerMiddleware(): void
    {
        $kernel = $this->app->make(Kernel::class);
        if (method_exists($kernel, 'prependMiddleware')) {
            $kernel->prependMiddleware(McpCors::class);
        }
    }

    /** A request to /mcp without a valid token: 401 naming the protected resource metadata. */
    protected function registerAuthenticationChallenge(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        if (method_exists($handler, 'renderable')) {
            $handler->renderable(function (AuthenticationException $e, Request $request) {
                if ($request->is('mcp')) {
                    return McpAuth::challenge($request, '', $request->bearerToken() ? 'invalid_token' : null);
                }

                return null;
            });
        }
    }

    protected function registerRateLimits(): void
    {
        // Registration, authorization, token and revocation endpoints: per client IP
        RateLimiter::for('mcp-oauth', fn (Request $request) => config('ratelimit.enabled', true)
            ? Limit::perMinute((int) config('mcp.oauth.per_minute', 60))
                ->by('mcp-oauth:'.$request->ip())
                ->response(ApiRateLimits::tooManyRequests(...))
            : Limit::none());
    }

    /** A deleted project stops being served (its server row and call log are central). */
    protected function registerProjectCleanup(): void
    {
        Project::deleted(function (Project $project) {
            try {
                $server = HostedServer::forProject($project);
                if ($server) {
                    HostedServerCall::query()->where('mcp_server_id', $server->id)->delete();
                    $server->delete();
                }
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    protected function registerSchedule(): void
    {
        $this->app->booted(function () {
            $this->app->make(Schedule::class)
                ->command(McpPruneCommand::class)
                ->dailyAt('03:45')
                ->name('mcp-prune')
                ->withoutOverlapping(60);
        });
    }
}
