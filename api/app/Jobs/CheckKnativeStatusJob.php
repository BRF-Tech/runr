<?php

namespace App\Jobs;

use App\Events\PlatformEvent;
use App\Http\Resources\KnativeRegionResource;
use App\Models\KnativeRegion;
use App\Services\CertManager\CertManagerService;
use App\Services\Dns\RegionDnsCheck;
use App\Services\DomainMigrateService;
use App\Services\KnativeStatusService;
use App\Services\KubernetesClient;
use App\Services\PublicHostRewrites;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

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

        $this->syncPublicHosts();
    }

    /**
     * Custom domains and gateway routes follow their Knative Service's public host (a domain move
     * changes it, PublicHostRewrites).
     */
    private function syncPublicHosts(): void
    {
        if (! $this->region->has_serving) {
            return;
        }

        try {
            $lines = array_filter(
                PublicHostRewrites::sync(new KubernetesClient($this->region->kubeconfig), $this->region->project_namespace ?: $this->region->name,
                    servingNamespace: $this->region->knative_serving_namespace ?: 'knative-serving'),
                fn (string $line) => ! str_starts_with($line, 'left alone'),   // region:reconcile names those
            );
            if ($lines !== []) {
                Log::info('Host rewrites of region '.$this->region->name.' updated', ['changes' => $lines]);
            }
        } catch (Throwable $e) {
            Log::warning('Host rewrites of region '.$this->region->name.' not checked', ['error' => $e->getMessage()]);
        }

        // cert-manager installed with Serving gets its default ClusterIssuer once it runs (a job of
        // its own: the issuer may take longer than this check)
        app(CertManagerService::class)->queueRefresh($this->region->fresh() ?? $this->region);
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
