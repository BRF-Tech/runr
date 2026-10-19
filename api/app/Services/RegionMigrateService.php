<?php

namespace App\Services;

use App\Events\PlatformEvent;
use App\Events\RegionDomainsConfigured;
use App\Http\Resources\KnativeRegionResource;
use App\Models\BindingRegionService;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\PrivateDatabaseBinding;
use App\Models\Project;
use App\Models\RegionSupplyChainSetting;
use App\Services\Ai\Gpu\GpuInstallService;
use App\Services\Bindings\DataServiceManager;
use App\Services\Concerns\ConfigDomainTrait;
use App\Services\Concerns\KnativeClusterTrait;
use App\Services\Dns\RegionDnsCheck;
use App\Services\Skupper\SkupperService;
use App\Services\SupplyChain\AdmissionPolicyService;
use App\Services\Wasm\WasmInstallService;
use App\Support\Projects\DeployIntent;
use App\Support\Projects\ProjectAdmission;
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
        // The dedicated workers first: the Serving install, the registry and the sandbox runtimes
        // place their components by them
        $this->step($region, 'dedicated workers', fn () => $this->reapplyWorkers($region));

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
        $this->reapplyRegionFeatures($region);
    }

    /**
     * What else the region had on its old cluster, installed on the new one: WebAssembly support,
     * GPU support, the data services RunR runs (their data stays on the old cluster), the
     * admission policy, function TLS, the Skupper site of private database connections and the
     * status block of a region that is not active. A step that fails is logged and the move goes on.
     */
    public function reapplyRegionFeatures(KnativeRegion $region): void
    {
        $region->refresh();

        if ($region->sandbox_wasm_status === 'ready') {
            $this->step($region, 'WebAssembly', fn () => app(WasmInstallService::class)->install(
                $region,
                (array) ($region->sandbox_wasm_shims ?? []),
                $this->existingNodes($region, $region->sandbox_wasm_nodes),
            ));
        }

        if (($region->gpu_mode ?? 'none') !== 'none') {
            $settings = (array) ($region->gpu_settings ?? []);
            if (array_key_exists('gpu_nodes', $settings)) {
                $settings['gpu_nodes'] = $this->existingNodes($region, $settings['gpu_nodes']);
            }
            $this->step($region, 'GPU support', fn () => app(GpuInstallService::class)->install($region, $region->gpu_mode, $settings));
        }

        foreach (BindingRegionService::SERVICES as $service) {
            $row = BindingRegionService::forRegion($region, $service);
            // An external store runs nowhere in the cluster; the others are installed again (empty)
            if ($row && $row->isReady() && in_array($row->mode, ['managed', 'region'], true)) {
                $this->step($region, "data service {$service}", fn () => app(DataServiceManager::class)->install($region, $service, ['mode' => $row->mode]));
            }
        }

        if (RegionSupplyChainSetting::forRegion($region)->admission_status === 'ready') {
            $this->step($region, 'admission policy', fn () => app(AdmissionPolicyService::class)->install($region));
        }

        $this->step($region, 'function TLS', function () use ($region) {
            $line = app(KnativeFunctionTlsService::class)->reapply($region, $region->migration_old_kubeconfig);
            if ($line) {
                Log::info("RegionMigrate: {$region->name} {$line}");
            }
        });

        $usesSkupper = PrivateDatabaseBinding::query()
            ->whereIn('project_id', Project::where('knative_region_id', $region->id)->select('id'))
            ->exists();
        if ($usesSkupper) {
            $this->step($region, 'Skupper site', function () use ($region) {
                $skupper = app(SkupperService::class);
                if ($skupper->available($region)) {
                    $skupper->ensureSite($region);
                }
            });
        }

        if (! $region->is_active) {
            $this->step($region, 'status block', fn () => app(RegionStatusService::class)->syncBlock($region->fresh()));
        }
    }

    /**
     * The region's dedicated workers on the new cluster: the selected nodes that exist there keep
     * the label and the taint; when none of them exists the selection is cleared (projects run on
     * every schedulable node) and can be made again in the Workers tab.
     */
    private function reapplyWorkers(KnativeRegion $region): void
    {
        $selected = array_values((array) ($region->selected_workers ?? []));
        if ($selected === []) {
            return;
        }

        $keep = $this->existingNodes($region, $selected) ?? [];
        app(KnativeNodeService::class)->applyWorkerSelection($region, $keep);

        if (count($keep) !== count($selected)) {
            Log::info("RegionMigrate: {$region->name} dedicated workers on the new cluster: "
                .($keep ? implode(', ', $keep) : 'none (every schedulable node)')
                .'; not on it: '.implode(', ', array_diff($selected, $keep)));
        }
    }

    /**
     * The nodes of $names that exist on the region's (new) cluster; null for null (every node).
     *
     * @param  list<string>|null  $names
     * @return list<string>|null
     */
    private function existingNodes(KnativeRegion $region, ?array $names): ?array
    {
        if ($names === null) {
            return null;
        }

        $nodes = (new KubernetesClient($region->kubeconfig))->listClusterResources('v1', 'Node')['items'] ?? [];
        $present = array_map(fn ($node) => $node['metadata']['name'] ?? '', $nodes);

        return array_values(array_intersect($names, $present));
    }

    private function step(KnativeRegion $region, string $what, callable $run): void
    {
        try {
            $run();
        } catch (\Throwable $e) {
            Log::warning("RegionMigrate: {$what} of {$region->name} not applied on the new cluster", ['error' => $e->getMessage()]);
        }
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

    /**
     * The function domain's wildcard record points at the new cluster's external address (an IP
     * address or a load balancer's host name, RegionDnsCheck).
     */
    public function checkMigrationDns(KnativeRegion $region): bool
    {
        return app(RegionDnsCheck::class)->pointsAt($region->effective_settings['knative_domain'] ?? null, $region->external_ip) === true;
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

    /**
     * Deploy what the project ran on the old cluster onto the new one: the version and overrides
     * of its live deployment. A project that runs nothing — stopped, never deployed, shut down —
     * moves with the region and stays as it is (a stopped one deploys on the new cluster when it is
     * resumed). ProjectAdmission's guards decide as for any deploy (e.g. a fixed region's
     * allocation); a refused project is not redeployed, its live deployments are shut down (the
     * reason on each, ended_at set: nothing of it runs on the new cluster) and the reason is logged.
     */
    private function redeployProject(Project $project): void
    {
        try {
            if ($project->isStopped()) {
                Log::info("RegionMigrate: {$project->slug} is stopped, not redeployed");

                return;
            }

            $live = Deployment::query()->where('project_id', $project->id)->whereIn('status', Deployment::LIVE)->latest('created_at')->first();
            $version = $live?->version;
            if (! $live || ! $version || $version->build_status !== 'ready') {
                return;
            }

            if ($refusal = ProjectAdmission::refusal($project, DeployIntent::production($live->deploy_config))) {
                // Nothing of it runs on the new cluster: its deployments end (the old cluster's
                // resources stay with the old cluster), so it is neither metered nor counted as running
                foreach (Deployment::query()->where('project_id', $project->id)->whereIn('status', Deployment::LIVE)->get() as $old) {
                    $old->transition(Deployment::LIVE, [
                        'status' => 'shut_down',
                        'knative_ready' => false,
                        'knative_message' => mb_substr('Not moved to the new cluster of the region: '.$refusal, 0, 255),
                    ]);
                }
                Log::warning("RegionMigrate: {$project->slug} not redeployed, its deployments are shut down", ['reason' => $refusal]);

                return;
            }

            // The old cluster's resources are left behind with the old cluster
            foreach (Deployment::query()->where('project_id', $project->id)->whereIn('status', Deployment::LIVE)->get() as $old) {
                $old->update(['status' => 'shut_down']);
            }

            $deployment = $project->deployments()->create([
                'version_id' => $version->id,
                'status' => 'pending',
                'k8s_job_name' => 'run-'.$project->slug.'-v'.$version->number,
                'deployed_by' => 'region-migration',
                'deploy_config' => $live->deploy_config,
            ]);

            $this->deployer->deploy($project, $version, $deployment);
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
