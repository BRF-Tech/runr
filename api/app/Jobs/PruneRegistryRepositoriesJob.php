<?php

namespace App\Jobs;

use App\Services\RegionReconcile\RegionReconciler;
use App\Services\RegistryImageLifecycleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Daily: build repositories without a tag in every region's internal registry are handed to Zot's
 * garbage collection (RegistryImageLifecycleService::pruneEmptyRepositories). Regions as
 * `region:reconcile` sees them: the platform's and every organization's own (each cluster once).
 */
class PruneRegistryRepositoriesJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function handle(RegistryImageLifecycleService $registry, RegionReconciler $reconciler): void
    {
        $seen = [];
        foreach ($reconciler->targets() as $target) {
            $region = $target->region;
            // Regions on one cluster share its registry
            $key = $region->registry_address.'|'.$region->server_url;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            try {
                $registry->pruneEmptyRepositories($region);
            } catch (\Throwable $e) {
                Log::warning('Registry empty repository cleanup failed', ['region' => $region->name, 'error' => $e->getMessage()]);
            }
        }
    }
}
