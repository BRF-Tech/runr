<?php

namespace App\Jobs\Bindings;

use App\Services\Bindings\BindingManager;
use App\Services\Bindings\BindingReconciler;
use App\Services\Bindings\BindingTenancy;
use App\Services\Bindings\BindingUsageMeter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled binding work, once per organization context (BindingServiceProvider schedule):
 *
 *  - measure    hourly: size of every binding → usage_bytes, usage records, size limits
 *  - purge      hourly: bindings whose retention ended
 *  - reconcile  every 10 minutes: ACL users on a region Redis (no ACL file there)
 */
class BindingsMaintenanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public string $task) {}

    public function handle(BindingManager $manager, BindingUsageMeter $meter, BindingReconciler $reconciler): void
    {
        BindingTenancy::eachContext(function () use ($manager, $meter, $reconciler) {
            try {
                match ($this->task) {
                    'measure' => $meter->measure(),
                    'purge' => $manager->purgeDue(),
                    'reconcile' => $reconciler->reconcile(),
                    default => null,
                };
            } catch (\Throwable $e) {
                // One organization's failure must not stop the others
                Log::warning('Bindings maintenance failed', ['task' => $this->task, 'tenant' => BindingTenancy::tenantKey(), 'error' => $e->getMessage()]);
                report($e);
            }
        });
    }
}
