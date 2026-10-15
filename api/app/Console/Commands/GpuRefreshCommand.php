<?php

namespace App\Console\Commands;

use App\Models\KnativeRegion;
use App\Services\Ai\Gpu\GpuInstallService;
use App\Services\Ai\Gpu\GpuInventory;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Keeps gpu_available / gpu_allocated of the regions current (scheduled every two minutes):
 * regions with GPU support or GPU nodes on every run, the others every
 * ai.gpu.refresh_idle_minutes (30) — a GPU node added to any cluster shows up on its own.
 */
class GpuRefreshCommand extends Command
{
    protected $signature = 'gpu:refresh {--region= : only this region (id)} {--all : every region now}';

    protected $description = 'Refresh the GPU inventory and allocation of the regions';

    public function handle(GpuInventory $inventory, GpuInstallService $install): int
    {
        $query = KnativeRegion::syncedQuery()->where('is_active', true);
        if ($id = $this->option('region')) {
            $query->whereKey($id);
        }

        $idle = (int) config('ai.gpu.refresh_idle_minutes', 30);
        $checked = 0;

        foreach ($query->get() as $region) {
            if (! $this->option('all') && ! $this->option('region') && ! self::due($region, $idle)) {
                continue;
            }

            // New GPU nodes of a restricted device plugin get nvidia.com/gpu.present=true first
            $install->syncNodeLabels($region);
            $result = $inventory->refresh($region);
            $checked++;

            if ($result !== null && $this->getOutput()->isVerbose()) {
                $s = $result['summary'];
                $this->line("{$region->name}: {$s['allocatable']} GPU(s) allocatable on {$s['gpu_nodes']} node(s), {$s['allocated']} allocated");
            }
        }

        $this->info("GPU inventory refreshed for {$checked} region(s).");

        return self::SUCCESS;
    }

    /** GPU regions every run; regions without GPUs when their last check is older than $idle minutes. */
    public static function due(KnativeRegion $region, int $idle): bool
    {
        if ($region->is_demo || ! $region->kubeconfig) {
            return false;
        }

        $hasGpu = ($region->gpu_mode ?? 'none') !== 'none'
            || (int) (($region->gpu_available['summary']['total'] ?? 0)) > 0
            || ($region->gpu_available['summary']['hardware_without_plugin'] ?? []) !== [];

        if ($hasGpu) {
            return true;
        }

        $last = $region->gpu_available['detected_at'] ?? null;

        return ! is_string($last) || Carbon::parse($last)->lt(now()->subMinutes($idle));
    }
}
