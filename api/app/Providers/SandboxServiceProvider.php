<?php

namespace App\Providers;

use App\Http\Middleware\SandboxNoStore;
use App\Jobs\Sandboxes\MeterSandboxUsageJob;
use App\Jobs\Sandboxes\ReapSandboxesJob;
use App\Jobs\Sandboxes\RefillSandboxPoolsJob;
use App\Services\Sandboxes\DnsHostResolver;
use App\Services\Sandboxes\Exec\ExecTransport;
use App\Services\Sandboxes\Exec\KubectlExecTransport;
use App\Services\Sandboxes\HostResolver;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Sandbox API (docs/sandbox-api.md): exec transport and DNS bindings, the /api/sandboxes routes
 * (routes/sandboxes.php) and the scheduled jobs (reaper, warm pool refill, usage metering). The
 * abilities sandboxes.view / create / exec / manage are catalog permissions (config/permissions.php,
 * route rules in config/route_permissions.php).
 */
class SandboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(ExecTransport::class, KubectlExecTransport::class);
        $this->app->bindIf(HostResolver::class, DnsHostResolver::class);
    }

    public function boot(): void
    {
        if (! config('sandbox.enabled', true)) {
            return;
        }

        $this->registerRoutes();
        $this->registerSchedule();
    }

    private function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        // no-store: command output, file contents and sandbox state stay out of every cache
        Route::middleware(['api', 'auth:sanctum', SandboxNoStore::class])
            ->prefix('api')
            ->group(base_path('routes/sandboxes.php'));
    }

    /** Every minute: reaper and pool refill; every five minutes: usage metering. */
    private function registerSchedule(): void
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            $schedule->job(new ReapSandboxesJob)
                ->everyMinute()
                ->name('sandbox-reaper')
                ->withoutOverlapping(10);

            $schedule->job(new RefillSandboxPoolsJob)
                ->everyMinute()
                ->name('sandbox-pool-refill')
                ->withoutOverlapping(10);

            $schedule->job(new MeterSandboxUsageJob)
                ->everyFiveMinutes()
                ->name('sandbox-usage-metering')
                ->withoutOverlapping(30);
        });
    }
}
