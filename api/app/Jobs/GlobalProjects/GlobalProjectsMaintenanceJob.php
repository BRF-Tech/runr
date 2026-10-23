<?php

namespace App\Jobs\GlobalProjects;

use App\Models\Binding;
use App\Models\BindingReplica;
use App\Services\Bindings\BindingTenancy;
use App\Services\GlobalProjects\Data\RegionalData;
use App\Services\GlobalProjects\GlobalFailover;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every minute, once per organization context (docs/global-projects.md):
 *
 *  - failover  the health of every global project's regions, the automatic failover of a primary
 *              that stopped answering, the rejoin of a fenced region that answers again
 *  - data      how far each binding's replica clusters and data links got (RegionalData::refresh),
 *              the regions' data status that keeps a region out of the DNS answers until its data
 *              is reachable
 */
class GlobalProjectsMaintenanceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(public string $task) {}

    public function uniqueId(): string
    {
        return 'global-projects-'.$this->task;
    }

    public function handle(GlobalFailover $failover, RegionalData $data): void
    {
        BindingTenancy::eachContext(function () use ($failover, $data) {
            try {
                if ($this->task === 'failover') {
                    $failover->check();

                    return;
                }
                if (! RegionalData::tableReady()) {
                    return;
                }
                $ids = BindingReplica::query()->distinct()->pluck('binding_id');
                foreach (Binding::query()->whereIn('id', $ids)->with('region')->get() as $binding) {
                    $data->refresh($binding);
                }
            } catch (Throwable $e) {
                Log::warning('Global projects maintenance failed', ['task' => $this->task, 'tenant' => BindingTenancy::tenantKey(), 'error' => $e->getMessage()]);
                report($e);
            }
        });
    }
}
