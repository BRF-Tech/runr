<?php

namespace Modules\Tenancy\Jobs;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Setting;
use App\Services\Concerns\ConfigDomainTrait;
use App\Services\KnativeDeployService;
use App\Services\KubernetesClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Tenancy\Models\Tenant;

class DetachRegionCleanupJob implements ShouldQueue
{
    use BroadcastsJobCompletion;
    use ConfigDomainTrait, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public Tenant $tenant,
        public string $regionId,
        ?string $initiatorId = null,
    ) {
        $this->initiatorId = $initiatorId;
    }

    public function handle(KnativeDeployService $deployer): void
    {
        // Load region from central (need kubeconfig)
        $region = KnativeRegion::on('central')->find($this->regionId);
        if (! $region || ! $region->kubeconfig) {
            Log::warning("DetachRegionCleanup: region {$this->regionId} not found or no kubeconfig");

            return;
        }

        $this->tenant->run(function () use ($deployer, $region) {
            $projects = Project::where('knative_region_id', $this->regionId)->get();

            foreach ($projects as $project) {
                $this->teardownProject($deployer, $project, $region);
            }

            $this->removeTenantDomain($region);
        });

        $this->broadcastSuccess('region.detached', [
            'tenant_id' => $this->tenant->id,
            'region_id' => $this->regionId,
        ], "Region detached from {$this->tenant->slug}");
    }

    private function teardownProject(KnativeDeployService $deployer, Project $project, KnativeRegion $region): void
    {
        // All of the project's resources, not only those of an "active" deployment:
        // a pending or failed deployment still runs a Knative Service
        try {
            $deployer->teardownProject($project);
        } catch (\Throwable $e) {
            Log::warning("DetachRegionCleanup: teardown failed for {$project->slug}", ['error' => $e->getMessage()]);
        }

        $project->deployments()->update(['status' => 'shut_down']);
        $project->update(['knative_region_id' => null]);
    }

    private function removeTenantDomain(KnativeRegion $region): void
    {
        $baseDomain = null;
        $this->tenant->run(function () use (&$baseDomain) {
            $baseDomain = Setting::get('base_domain');
        });

        if (! $baseDomain || ! $region->kubeconfig) {
            return;
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $servingNs = $region->knative_serving_namespace ?? 'knative-serving';
            $tenantDomain = "{$region->name}.{$baseDomain}";
            $this->removeConfigDomain($k8s, $servingNs, $tenantDomain);
        } catch (\Throwable $e) {
            Log::warning('DetachRegionCleanup: failed to remove tenant domain', ['error' => $e->getMessage()]);
        }
    }

    public function failed(?\Throwable $e): void
    {
        Log::error("DetachRegionCleanupJob failed for tenant {$this->tenant->slug}, region {$this->regionId}: ".$e?->getMessage());

        if ($e) {
            $this->broadcastFailure('region.detach_failed', [
                'tenant_id' => $this->tenant->id,
                'region_id' => $this->regionId,
            ], $e, "Region detach failed for {$this->tenant->slug}");
        }
    }
}
