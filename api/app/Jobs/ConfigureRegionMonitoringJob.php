<?php

namespace App\Jobs;

use App\Models\KnativeRegion;
use App\Services\KnativeInstallService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * PUT /knative-regions/{id}/monitoring on a region with Knative Serving: RunR's own Prometheus /
 * Loki installed where the region switched back to them, the scrape resources for a Prometheus
 * it switched to (KnativeInstallService::applyMonitoring()).
 */
class ConfigureRegionMonitoringJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public bool $deleteWhenMissingModels = true;

    /**
     * @param  list<string>  $managed  prometheus, loki
     */
    public function __construct(
        public KnativeRegion $region,
        public array $managed,
        public bool $externalPrometheus,
    ) {}

    public function handle(KnativeInstallService $installer): void
    {
        foreach ($installer->applyMonitoring($this->region->fresh(), $this->managed, $this->externalPrometheus) as $line) {
            Log::info('Region monitoring: '.$line, ['region' => $this->region->name]);
        }
    }

    public function failed(?\Throwable $e): void
    {
        Log::warning('Region monitoring not applied', ['region' => $this->region->name, 'error' => $e?->getMessage()]);
    }
}
