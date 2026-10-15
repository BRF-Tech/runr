<?php

namespace App\Providers;

use App\Jobs\Bindings\BindingsMaintenanceJob;
use App\Services\Bindings\BindingsCluster;
use App\Services\Bindings\Ops\BindingOpsRunner;
use App\Services\Bindings\Ops\PodOpsRunner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Modules\Tenancy\Services\Bindings\TenancyBindingsBridge;

/**
 * Bindings (docs/bindings.md): container bindings, the scheduled maintenance (usage, purge,
 * reconcile) and — with the Tenancy module on — its bridge (per-tenant contexts, quota
 * overrides). Permissions are catalog permissions (config/permissions.php): bindings.view,
 * bindings.manage, bindings.view_credentials, regions.data_services.manage.
 */
class BindingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(BindingsCluster::class);
        $this->app->bind(BindingOpsRunner::class, PodOpsRunner::class);
    }

    public function boot(): void
    {
        $this->registerTenancyBridge();
        $this->registerSchedule();
    }

    private function registerTenancyBridge(): void
    {
        $bridge = TenancyBindingsBridge::class;

        if (config('tenancy.enabled') && class_exists($bridge)) {
            $bridge::register();
        }
    }

    private function registerSchedule(): void
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            $schedule->job(new BindingsMaintenanceJob('measure'))
                ->hourly()
                ->name('bindings-measure')
                ->withoutOverlapping(90);

            $schedule->job(new BindingsMaintenanceJob('purge'))
                ->hourlyAt(30)
                ->name('bindings-purge')
                ->withoutOverlapping(90);

            $schedule->job(new BindingsMaintenanceJob('reconcile'))
                ->everyTenMinutes()
                ->name('bindings-reconcile')
                ->withoutOverlapping(20);
        });
    }
}
