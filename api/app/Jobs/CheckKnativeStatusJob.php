<?php

namespace App\Jobs;

use App\Events\PlatformEvent;
use App\Http\Resources\KnativeRegionResource;
use App\Models\KnativeRegion;
use App\Services\Dns\RegionDnsCheck;
use App\Services\DomainMigrateService;
use App\Services\KnativeStatusService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckKnativeStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(
        public KnativeRegion $region,
    ) {}

    public function handle(KnativeStatusService $statusService): void
    {
        // Auto-repair missing knative_domain before DNS check
        app(DomainMigrateService::class)->repairDomainForRegion($this->region);
        $this->region->refresh();

        $result = $statusService->checkStatus($this->region);

        $update = [
            'knative_status' => $result['status'],
            'knative_status_message' => $result['message'],
            'knative_status_checked_at' => now(),
            'cluster_status' => $result['clusterStatus'],
            'cluster_status_message' => $result['clusterMessage'],
        ];

        // Update individual component statuses (only those checked live)
        $componentMap = [
            'operator' => 'knative_operator_status',
            'serving' => 'knative_serving_status',
        ];
        foreach ($result['components'] as $name => $status) {
            if (isset($componentMap[$name])) {
                $update[$componentMap[$name]] = $status;
            }
        }

        // DNS resolution check
        $update['dns_resolved'] = $this->checkDns();

        $oldStatus = $this->region->knative_serving_status;
        $this->region->update($update);

        if ($oldStatus !== ($update['knative_serving_status'] ?? $oldStatus)) {
            $fresh = $this->region->fresh();
            event(new PlatformEvent('region.updated', ['region' => (new KnativeRegionResource($fresh))->resolve()]));
        }

        // Trigger migration DNS check if region is in dns_check phase
        if ($this->region->migration_status === 'dns_check') {
            CheckMigrationDnsJob::dispatch($this->region);
        }

        // A base-domain migration completes once DNS of the new function domain answers
        if ($this->region->is_domain_migrating) {
            app(DomainMigrateService::class)->completeIfResolved($this->region->fresh());
        }
    }

    /**
     * The function domain's wildcard record points at the external address (an IP address or a
     * load balancer's host name, RegionDnsCheck); null when there is nothing to check yet.
     */
    private function checkDns(): ?bool
    {
        if (! $this->region->has_serving) {
            return null;
        }

        return app(RegionDnsCheck::class)->pointsAt(
            $this->region->effective_settings['knative_domain'] ?? null,
            $this->region->external_ip,
        );
    }
}
