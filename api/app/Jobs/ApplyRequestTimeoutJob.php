<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsClusterReconcileStep;
use App\Models\KnativeRegion;
use App\Services\Helpers\RunrClusterIdentity;
use App\Services\RegionReconcile\Steps\RequestTimeoutStep;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * A region's request_timeout changed (PUT /knative-regions/{id}/settings): the region Traefik's
 * read / write timeouts and Knative's maximum revision timeout follow now (RequestTimeoutStep),
 * for the region and its sibling regions on the same cluster.
 */
class ApplyRequestTimeoutJob implements ShouldQueue
{
    use Queueable, RunsClusterReconcileStep;

    public int $timeout = 300;

    public int $tries = 1;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public KnativeRegion $region) {}

    public function handle(RunrClusterIdentity $identity): void
    {
        foreach ($this->runStepForRegion($this->region, new RequestTimeoutStep, $identity) ?? [] as $line) {
            Log::info('Region request timeout: '.$line, ['region' => $this->region->name]);
        }
    }

    public function failed(?\Throwable $e): void
    {
        Log::warning('Region request timeout could not be applied — run php artisan region:reconcile --region='.$this->region->id, [
            'region' => $this->region->name,
            'error' => $e?->getMessage(),
        ]);
    }
}
