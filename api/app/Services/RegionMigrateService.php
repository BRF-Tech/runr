<?php

namespace App\Services;

use App\Events\PlatformEvent;
use App\Events\RegionDomainsConfigured;
use App\Http\Resources\KnativeRegionResource;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\Concerns\ConfigDomainTrait;
use App\Services\Concerns\KnativeClusterTrait;
use Illuminate\Support\Facades\Log;

class RegionMigrateService
{
    use ConfigDomainTrait, KnativeClusterTrait;

    public function __construct(
        private KnativeInstallService $installer,
        private KnativeDeployService $deployer,
        private ZotRegistryService $registryService,
        private KnativeRedisService $redisService,
        private SandboxInstallService $sandboxService,
    ) {}

    public function migrate(KnativeRegion $region, string $newKubeconfig): void
    {
        $this->saveOldState($region);
        $this->validateNewKubeconfig($newKubeconfig);

        $region->update([
            'kubeconfig' => $newKubeconfig,
            'migration_status' => 'in_progress',
        ]);
        $this->broadcast($region);

        $this->installInfrastructure($region);
        $this->setupConfigDomains($region);
        $this->redeployProjects($region);

        $region->update([
            'migration_status' => 'dns_check',
            'migration_started_at' => now(),
        ]);
        $this->broadcast($region);
    }

    public function installInfrastructure(KnativeRegion $region): void
    {
        // A target cluster that already runs Knative (another region on it, or the region's own
        // cluster) keeps its operator and KnativeServing: installing the operator again would
        // first remove it and the Serving CRDs — every Knative Service on that cluster
        $running = $this->installer->runningKnative($region);

        if ($running['operator']) {
            Log::info("RegionMigrate: {$region->name} keeps the Knative Operator running on the target cluster");
        } else {
            $this->installer->installOperator($region, $region->knative_operator_namespace ?? 'knative-operator');
        }

        if ($running['serving']) {
            Log::info("RegionMigrate: {$region->name} keeps the Ready KnativeServing on the target cluster");
        } else {
            $this->installer->installServing(
                $region,
                $region->knative_serving_namespace ?? 'knative-serving',
                $region->knative_serving_version,
            );
        }

        $this->installRedis($region);
        $this->installRegistry($region);
        $this->installSandbox($region);
    }

    public function redeployProjects(KnativeRegion $region): void
    {
        // Rows of the retired AI types have nothing to redeploy
        $projects = Project::where('knative_region_id', $region->id)
            ->whereIn('service_type', Project::SERVICE_TYPES)
            ->get();

        foreach ($projects as $project) {
            $this->redeployProject($project);
        }
    }

    public function checkMigrationDns(KnativeRegion $region): bool
    {
        $domain = $region->effective_settings['knative_domain'] ?? null;
        $newIp = $region->external_ip;

        if (! $domain || ! $newIp) {
            return false;
        }

        $testHost = 'dns-check.'.$domain;
        $resolved = @gethostbyname($testHost);

        return $resolved !== $testHost && $resolved === $newIp;
    }

    public function completeMigration(KnativeRegion $region): void
    {
        $region->update([
            'migration_status' => 'completed',
            'migration_old_ip' => null,
            'migration_old_kubeconfig' => null,
        ]);
        $this->broadcast($region);
    }

    public function rollbackMigration(KnativeRegion $region): void
    {
        $oldKubeconfig = $region->migration_old_kubeconfig;
        $oldIp = $region->migration_old_ip;

        if (! $oldKubeconfig) {
            throw new \RuntimeException('No old kubeconfig saved for rollback.');
        }

        $region->update([
            'kubeconfig' => $oldKubeconfig,
            'external_ip' => $oldIp,
            'migration_status' => null,
            'migration_started_at' => null,
            'migration_old_ip' => null,
            'migration_old_kubeconfig' => null,
        ]);

        $this->redeployProjects($region);
        $this->broadcast($region);
    }

    private function saveOldState(KnativeRegion $region): void
    {
        $region->update([
            'migration_old_ip' => $region->external_ip,
            'migration_old_kubeconfig' => $region->kubeconfig,
            'migration_status' => 'pending',
            'migration_started_at' => now(),
        ]);
    }

    private function validateNewKubeconfig(string $kubeconfig): void
    {
        $k8s = new KubernetesClient($kubeconfig);
        $version = $k8s->getServerVersion();

        if (! $version) {
            throw new \RuntimeException('Cannot connect to the new cluster.');
        }
    }

    private function setupConfigDomains(KnativeRegion $region): void
    {
        if (! $region->kubeconfig) {
            return;
        }

        $k8s = new KubernetesClient($region->kubeconfig);
        $servingNs = $region->knative_serving_namespace ?? 'knative-serving';

        // Platform domain
        $platformDomain = $region->effective_settings['knative_domain'] ?? null;
        if ($platformDomain) {
            $this->addConfigDomain($k8s, $servingNs, $platformDomain);
        }

        // Let modules (e.g. Tenancy) handle their own domains via listener
        event(new RegionDomainsConfigured($region, $k8s, $servingNs));
    }

    private function redeployProject(Project $project): void
    {
        try {
            $latestVersion = $project->versions()->latest('id')->first();
            if (! $latestVersion) {
                return;
            }

            $active = $project->deployments()->where('status', 'active')->first();
            if ($active) {
                $active->update(['status' => 'shut_down']);
            }

            $deployment = $project->deployments()->create([
                'version_id' => $latestVersion->id,
                'status' => 'deploying',
            ]);

            $this->deployer->deploy($project, $latestVersion, $deployment);
        } catch (\Throwable $e) {
            Log::warning("RegionMigrate: failed to redeploy {$project->slug}", ['error' => $e->getMessage()]);
        }
    }

    private function installRedis(KnativeRegion $region): void
    {
        if (! $region->redis_mode) {
            return;
        }

        try {
            // Only RunR-managed Redis lives in the cluster; in_cluster / external keep their address
            if ($region->redis_mode === 'managed') {
                $this->redisService->install($region, 'managed', null, $region->redis_password);
            }
        } catch (\Throwable $e) {
            Log::warning('RegionMigrate: Redis install failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * The region's internal registry (Zot) on the target cluster, and the region's
     * registry_address pointed at it (a new cluster's Service has its own ClusterIP). A Zot that
     * already serves on the target — the region's own cluster, or a region sharing it — stays as
     * it is, as at region creation (InstallRegistryAndCreateRegionJob): upgrading it with this
     * region's placement would move it onto the dedicated workers, where a node-bound volume
     * cannot follow (the pod stays Pending). An external registry has nothing to install.
     */
    private function installRegistry(KnativeRegion $region): void
    {
        if ($region->registry_mode !== 'internal') {
            return;
        }

        try {
            $ns = $region->registry_namespace ?? 'runr-registry';
            $existing = $this->registryService->detectExisting($region->kubeconfig, $ns);
            if ($existing) {
                Log::info("RegionMigrate: {$region->name} keeps the Zot registry running on the target cluster", $existing);
            }

            $address = $existing['address'] ?? $this->registryService->install($region->kubeconfig, [
                'namespace' => $ns,
                'storage_type' => $region->registry_storage_type,
                'storage_config' => $region->registry_storage_config,
                'workers_only' => ! empty($region->selected_workers),
            ])['address'];

            if ($address !== $region->registry_address) {
                $region->update(['registry_address' => $address]);
            }
        } catch (\Throwable $e) {
            Log::warning('RegionMigrate: Registry install failed', ['error' => $e->getMessage()]);
        }
    }

    private function installSandbox(KnativeRegion $region): void
    {
        try {
            if ($region->has_sandbox_kata) {
                $this->sandboxService->installKata($region);
            }
            if ($region->has_sandbox_gvisor) {
                $this->sandboxService->installGvisor($region);
            }
        } catch (\Throwable $e) {
            Log::warning('RegionMigrate: Sandbox install failed', ['error' => $e->getMessage()]);
        }
    }

    private function broadcast(KnativeRegion $region): void
    {
        $fresh = $region->fresh();
        event(new PlatformEvent('region.updated', [
            'region' => (new KnativeRegionResource($fresh))->resolve(),
        ]));
    }
}
