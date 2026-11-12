<?php

namespace App\Jobs\Ai;

use App\Jobs\Concerns\RetriesInternalNetworkWrites;
use App\Models\AiModelService;
use App\Services\InternalNetwork\InternalNetworkService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * A model service's callers onto its Knative Service again (InternalNetworkService::
 * writeModelCallers): a binding came or went, or a bound project was renamed or deleted, while the
 * region's API server did not take the write. Until they are there the authz refuses the bound
 * project (403) and admits one that is no longer bound.
 */
class SyncModelServiceCallersJob implements ShouldQueue
{
    use Queueable, RetriesInternalNetworkWrites;

    public function __construct(public string $serviceId) {}

    public function handle(InternalNetworkService $network): void
    {
        $service = AiModelService::with('region')->find($this->serviceId);
        if ($service && ! $network->writeModelCallers($service)) {
            $this->tryAgain();
        }
    }

    public function failed(?\Throwable $e): void
    {
        Log::error('Model service callers not on its Knative Service; the bound projects are refused until php artisan region:reconcile writes them', [
            'service' => $this->serviceId,
            'error' => $e?->getMessage(),
        ]);
    }
}
