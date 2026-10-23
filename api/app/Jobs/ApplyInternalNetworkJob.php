<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsClusterReconcileStep;
use App\Models\KnativeRegion;
use App\Services\Helpers\RunrClusterIdentity;
use App\Services\RegionReconcile\RegionReconciler;
use App\Services\RegionReconcile\Steps\InternalNetworkStep;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The region's internal network follows a changed setting now (InternalNetworkStep): its
 * internal_auth (enforce / audit), or the platform's switch for cross-organization grants —
 * for the region and its sibling regions on the same cluster.
 */
class ApplyInternalNetworkJob implements ShouldQueue
{
    use Queueable, RunsClusterReconcileStep;

    public int $timeout = 600;

    public int $tries = 1;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public KnativeRegion $region) {}

    /** The platform's switch changed: every region of the current database context */
    public static function dispatchForAllRegions(): void
    {
        foreach (RegionReconciler::reconcilable(KnativeRegion::query())->get() as $region) {
            self::dispatch($region);
        }
    }

    public function handle(RunrClusterIdentity $identity): void
    {
        foreach ($this->runStepForRegion($this->region, new InternalNetworkStep, $identity) ?? [] as $line) {
            Log::info('Region internal network: '.$line, ['region' => $this->region->name]);
        }
    }

    public function failed(?\Throwable $e): void
    {
        Log::warning('Region internal network could not be applied — run php artisan region:reconcile --region='.$this->region->id, [
            'region' => $this->region->name,
            'error' => $e?->getMessage(),
        ]);
    }
}
