<?php

namespace Modules\Tenancy\Jobs;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Setting;
use App\Services\Concerns\ConfigDomainTrait;
use App\Services\Environments\EnvironmentManager;
use App\Services\KnativeDeployService;
use App\Services\KubernetesClient;
use App\Services\Observability\LoadTest\LoadTestRunner;
use App\Services\Observability\LoadTest\LoadTestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantLocalRegion;
use Modules\Tenancy\Services\TenantSchema;

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
        // Assigned again before the job ran: the organization keeps the region
        if ($this->tenant->regions()->where('knative_region_id', $this->regionId)->exists()) {
            return;
        }

        // Load region from central (need kubeconfig)
        $region = KnativeRegion::on('central')->find($this->regionId);
        if (! $region || ! $region->kubeconfig) {
            Log::warning("DetachRegionCleanup: region {$this->regionId} not found or no kubeconfig");
        } else {
            TenantSchema::inTenant($this->tenant, function () use ($deployer, $region) {
                // The environments that run in the region first — also those of projects in other
                // regions (docs/environments.md): their deployments end, their workloads, domains,
                // gateway routes and private connections leave the region; an environment that
                // still ran would otherwise keep the region when its project leaves it
                try {
                    app(EnvironmentManager::class)->leaveRegion($region);
                } catch (\Throwable $e) {
                    Log::warning("DetachRegionCleanup: environments in {$region->name} not removed", ['error' => $e->getMessage()]);
                }

                $projects = Project::where('knative_region_id', $this->regionId)->get();

                foreach ($projects as $project) {
                    $this->teardownProject($deployer, $project, $region);
                }

                $this->removeTenantDomain($region);
            });

            $this->stopLoadTests($region);
        }

        // The organization's copy of the region goes once nothing of its own runs there (gateways
        // are removed from the region with it, and so does its own queue consumer choice there)
        TenantSchema::inTenant($this->tenant, fn () => TenantLocalRegion::removeCopy($this->regionId, detached: true));

        // The platform's queue consumer choice for the organization in this region (Billing
        // module): a share made again later starts from the region's setting
        $this->forgetQueueConsumerChoice();

        $this->broadcastSuccess('region.detached', [
            'tenant_id' => $this->tenant->id,
            'region_id' => $this->regionId,
        ], "Region detached from {$this->tenant->slug}");
    }

    private function teardownProject(KnativeDeployService $deployer, Project $project, KnativeRegion $region): void
    {
        // All of the project's resources, not only those of an "active" deployment:
        // a pending or failed deployment still runs a Knative Service. The project loses the
        // region, its secret values there go with it (docs/security.md): set them again after
        // assigning another region
        try {
            $deployer->teardownProject($project, withSecretValues: true);
        } catch (\Throwable $e) {
            Log::warning("DetachRegionCleanup: teardown failed for {$project->slug}", ['error' => $e->getMessage()]);
        }

        // Each through the model: a deployment that stops running records when (metering)
        foreach ($project->deployments()->where('status', '!=', 'shut_down')->get() as $deployment) {
            $deployment->update(['status' => 'shut_down']);
        }
        $project->update(['knative_region_id' => null]);
    }

    /**
     * The organization's load tests in the region (docs/load-test.md): their k6 Jobs leave the
     * shared load-test namespace and the tests end as failed — their projects no longer run there.
     */
    private function stopLoadTests(KnativeRegion $region): void
    {
        try {
            TenantSchema::inTenant($this->tenant, fn () => app(LoadTestService::class)->endForRegion($this->regionId, __('observability.load_test.region_detached')));
            app(LoadTestRunner::class)->removeOrganizationJobs(new KubernetesClient($region->kubeconfig), (string) $this->tenant->getKey());
        } catch (\Throwable $e) {
            Log::warning("DetachRegionCleanup: load tests of {$this->tenant->slug} not stopped", ['error' => $e->getMessage()]);
        }
    }

    private function forgetQueueConsumerChoice(): void
    {
        $central = (string) config('tenancy.database.central_connection', 'central');
        $connection = config("database.connections.{$central}") ? $central : (string) config('database.default');

        try {
            if (Schema::connection($connection)->hasTable('billing_tenant_region_overrides')) {
                DB::connection($connection)->table('billing_tenant_region_overrides')
                    ->where('tenant_id', (string) $this->tenant->getKey())
                    ->where('region_id', $this->regionId)
                    ->delete();
            }
        } catch (\Throwable $e) {
            Log::warning('DetachRegionCleanup: the platform\'s queue consumer choice for the tenant was not removed', ['error' => $e->getMessage()]);
        }
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
