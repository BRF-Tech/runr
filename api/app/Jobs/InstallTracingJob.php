<?php

namespace App\Jobs;

use App\Models\KnativeRegion;
use App\Services\Observability\TenantScope;
use App\Services\Observability\TracingInstallService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/** Install / reconfigure (or remove) a region's tracing backend in the background. */
class InstallTracingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public string $regionId,
        public ?string $tenantId = null,
        public bool $uninstall = false,
    ) {}

    public function handle(TracingInstallService $service): void
    {
        TenantScope::run($this->tenantId, function () use ($service) {
            $region = app(KnativeRegion::class)->newQuery()->find($this->regionId);
            if (! $region) {
                return;
            }

            try {
                $this->uninstall ? $service->uninstall($region) : $service->install($region);
            } catch (\Throwable $e) {
                // the error is stored on the region's tracing status
                Log::warning('Tracing backend job failed', ['region' => $region->name, 'error' => $e->getMessage()]);
            }
        });
    }
}
