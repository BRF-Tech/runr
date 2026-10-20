<?php

namespace App\Services;

use App\Events\PlatformEvent;
use App\Events\RegionDomainsConfigured;
use App\Http\Resources\KnativeRegionResource;
use App\Jobs\BuildVersionJob;
use App\Jobs\GitDeploy\BuildPreviewJob;
use App\Jobs\GitDeploy\DeployPreviewJob;
use App\Jobs\ProcessQueuedDeployJob;
use App\Models\BindingRegionService;
use App\Models\Deployment;
use App\Models\Git\GitDeploy;
use App\Models\Git\PreviewEnvironment;
use App\Models\KnativeRegion;
use App\Models\Observability\RegionTracingConfig;
use App\Models\PrivateDatabaseBinding;
use App\Models\Project;
use App\Models\QueuedDeploy;
use App\Models\RegionSupplyChainSetting;
use App\Models\RuntimeBuild;
use App\Models\Version;
use App\Services\Ai\Gpu\GpuInstallService;
use App\Services\Bindings\DataServiceManager;
use App\Services\Concerns\ConfigDomainTrait;
use App\Services\Concerns\KnativeClusterTrait;
use App\Services\Dns\RegionDnsCheck;
use App\Services\GitDeploy\GitTables;
use App\Services\GitDeploy\PreviewService;
use App\Services\GitDeploy\PreviewVersionScope;
use App\Services\GitDeploy\TenantContext;
use App\Services\Helpers\KataRuntime;
use App\Services\Images\UploadedImages;
use App\Services\Projects\QueuedDeploys;
use App\Services\Projects\VersionRebuilds;
use App\Services\Runtimes\RuntimeRegistry;
use App\Services\Skupper\SkupperService;
use App\Services\SupplyChain\AdmissionPolicyService;
use App\Services\Wasm\WasmInstallService;
use App\Support\Projects\DeployIntent;
use App\Support\Projects\ProjectAdmission;
use App\Support\SchemaColumns;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

class RegionMigrateService
{
    use ConfigDomainTrait, KnativeClusterTrait;

    /** knative_message of the deployments a move shut down: the project's redeploy was refused */
    public const NOT_MOVED = 'Not moved to the new cluster of the region: ';

    /** knative_message of the deployments a rollback shut down: the redeploy on the old cluster was refused */
    public const NOT_RESTORED = 'Not deployed again on the cluster the migration rollback restored: ';

    /**
     * The region's columns a move sets from the new cluster, besides the kubeconfig and the
     * external address (kept in migration_old_kubeconfig / migration_old_ip): the registry address
     * (installRegistry: the new cluster's Zot), the dedicated workers and the capacity they give
     * (reapplyWorkers), the install state of the Knative Operator and Serving with the
     * observability URLs and the runr-agent's token (a Serving install deploys the agent with a new
     * one), of the managed Redis, the sandbox runtimes (with Firecracker's thin-pool size),
     * WebAssembly and GPU support (their nodes and inventory) and function TLS. The move keeps what
     * they held before it (migration_old_state); a rollback gives it back, as the old cluster is
     * still the way the move found it.
     */
    public const CLUSTER_STATE = [
        'registry_address',
        'selected_workers', 'total_cpu_m', 'total_memory_mb',
        'knative_operator_status', 'knative_operator_status_message', 'knative_operator_namespace',
        'knative_serving_status', 'knative_serving_status_message', 'knative_serving_namespace',
        'knative_serving_ingress_class', 'knative_serving_version',
        'prometheus_url', 'loki_url', 'loki_status', 'agent_token_hash',
        'knative_redis_status', 'knative_redis_status_message',
        'sandbox_kata_status', 'sandbox_kata_status_message', 'sandbox_kata_hypervisor', 'sandbox_kata_devmapper_pool_gb',
        'sandbox_gvisor_status', 'sandbox_gvisor_status_message',
        'sandbox_wasm_status', 'sandbox_wasm_status_message', 'sandbox_wasm_shims', 'sandbox_wasm_nodes',
        'gpu_mode', 'gpu_status', 'gpu_status_message', 'gpu_settings', 'gpu_available', 'gpu_allocated',
        'function_tls_mode', 'function_tls_secret_name', 'function_tls_expires_at',
    ];

    /**
     * The status columns of the region's rows in other tables that the move's installs set from
     * the new cluster, kept with CLUSTER_STATE under this key of migration_old_state (table =>
     * what the rows held) and given back by the rollback: the data services RunR runs
     * (binding_region_services, per service), the admission policy (region_supply_chain_settings)
     * and the tracing backend a Serving install brings (region_tracing_configs). Their settings
     * and credentials are the region's own and stay as they are.
     */
    public const OTHER_TABLES = 'other_tables';

    /**
     * The runtime images RunR built from source for the region (runtime_builds), kept under this key
     * of migration_old_state (id => status, image, digest, job and error): the old cluster's
     * registry still has them, the rollback gives them back.
     */
    public const RUNTIME_BUILDS = 'runtime_builds';

    /** When the move started, under this key of migration_old_state (what the move did since then: migrationReport()). */
    public const MOVED_AT = 'moved_at';

    /**
     * knative_message of a project's live deployment while what it runs is built again on the new
     * cluster (its image is in the registry of the cluster the region left): it still runs on the
     * old cluster, which serves the function domain until DNS points at the new one.
     */
    public const MOVING = 'Runs on the cluster the region left until it is deployed on the new one: ';

    /** build_error of a version whose build on the cluster the region left was cancelled by a rollback */
    public const BUILD_CANCELLED = 'Cancelled by the migration rollback: the build ran on the cluster the region left. Save a change or push the commit again to build it on the restored cluster.';

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

        // The images in the registry the region leaves stay with the old cluster
        $left = $this->registryLeft($region->refresh());
        if ($left !== null) {
            $this->leaveRuntimeImages($region, $left);
        }
        $this->withCopies($region, fn (KnativeRegion $local) => $this->submitBuildsAgain($local));
        $this->redeployProjects($region, leftRegistry: $left);
        $this->withCopies($region, fn (KnativeRegion $local) => $this->movePreviews($local));

        $region->update([
            'migration_status' => 'dns_check',
            'migration_started_at' => now(),
        ]);
        $this->broadcast($region);
    }

    /**
     * The address of the registry the region's images were pushed to before the move, when the move
     * pointed the region at another one (installRegistry: the new cluster's Zot); null when the
     * registry stays (an external one, or a Zot the target cluster already ran for the region).
     */
    private function registryLeft(KnativeRegion $region): ?string
    {
        $before = rtrim((string) (((array) ($region->migration_old_state ?? []))['registry_address'] ?? ''), '/');
        $now = rtrim((string) $region->registry_address, '/');

        return $before !== '' && $now !== '' && $before !== $now ? $before : null;
    }

    /**
     * The runtime images RunR built from source for the region (runtime_builds) are in the registry
     * it left: they are built again into the new one when a function build or a deploy needs them
     * (RuntimeImageBuildService starts the build then; a deploy waits for it, QueuedDeploys). What
     * they held is in migration_old_state for the rollback (saveOldState()).
     */
    private function leaveRuntimeImages(KnativeRegion $region, string $left): void
    {
        try {
            foreach (RuntimeBuild::query()->where('knative_region_id', $region->getKey())->get() as $build) {
                if (str_starts_with((string) $build->image, $left.'/')) {
                    $build->forceFill([
                        'status' => RuntimeBuild::STATUS_PENDING,
                        'image' => null,
                        'error' => 'The region moved to another cluster: built again into its registry when a project needs it.',
                    ])->save();
                }
            }
        } catch (\Throwable $e) {
            Log::warning("RegionMigrate: runtime images of {$region->name} not marked for a build on the new cluster", ['error' => $e->getMessage()]);
        }
    }

    /**
     * A build that was running when the region switched clusters ran its Kaniko Job on the old
     * cluster, which the build check no longer looks at ("Build Job not found"): it is submitted
     * again on the new cluster, into its registry. A git deploy waiting for it goes on with it.
     */
    private function submitBuildsAgain(KnativeRegion $region): void
    {
        $versions = Version::query()
            ->whereIn('project_id', Project::query()->where('knative_region_id', $region->getKey())->select('id'))
            ->where('build_status', 'building')
            ->get();

        foreach ($versions as $version) {
            try {
                $version->forceFill(['build_status' => 'pending', 'build_error' => null])->save();
                BuildVersionJob::dispatch($version);
                Log::info("RegionMigrate: the build of v{$version->number} of project {$version->project_id} ran on the old cluster; built again on the new one");
            } catch (\Throwable $e) {
                Log::warning("RegionMigrate: the build of v{$version->number} of project {$version->project_id} not submitted again", ['error' => $e->getMessage()]);
            }
        }
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

    /**
     * Every project on the region that runs is deployed again (redeployProject) — in a multi-tenant
     * install the organizations' projects on a platform region too, each in its organization's
     * context (withCopies).
     *
     * @param  bool  $rollback  the region has its old cluster back (rollbackMigration)
     * @param  string|null  $leftRegistry  the address of the registry the region left (the move: the
     *                                     old cluster's; a rollback: the new cluster's), when it
     *                                     differs from the region's registry now
     */
    public function redeployProjects(KnativeRegion $region, bool $rollback = false, ?string $leftRegistry = null): void
    {
        $this->withCopies($region, function (KnativeRegion $local) use ($rollback, $leftRegistry) {
            foreach ($this->projectsOn($local) as $project) {
                $this->redeployProject($project, $rollback, $leftRegistry);
            }
        });
    }

    /**
     * Rows of the retired AI types have nothing to redeploy.
     *
     * @return Collection<int, Project>
     */
    private function projectsOn(KnativeRegion $region): Collection
    {
        return Project::where('knative_region_id', $region->getKey())
            ->whereIn('service_type', Project::SERVICE_TYPES)
            ->get();
    }

    /**
     * $callback with the region, then — a platform region in a multi-tenant install — with its
     * copy in each organization it is assigned to, in that organization's context: the
     * organizations' projects on the region live in their own schemas, and deploy through the
     * copy (its kubeconfig is read from the central row, the rest comes with the region sync). A
     * tenant's own region is moved in the tenant's context and exists in that schema only. An
     * organization whose projects fail is logged and the others go on.
     *
     * @param  \Closure(KnativeRegion): void  $callback
     */
    private function withCopies(KnativeRegion $region, \Closure $callback): void
    {
        $callback($region);

        if (TenantContext::currentId() !== null || $region->getAttribute('owner_tenant_id') !== null) {
            return;
        }

        $id = (string) $region->getKey();
        TenantContext::eachAssigned($id, function (string $tenantId) use ($id, $region, $callback) {
            try {
                $copy = KnativeRegion::query()->find($id);
                if ($copy) {
                    $callback($copy);
                }
            } catch (\Throwable $e) {
                Log::warning("RegionMigrate: projects of tenant {$tenantId} on {$region->name} not deployed again", ['error' => $e->getMessage()]);
            }
        });
    }

    /**
     * The function domain's wildcard record points at the new cluster's external address (an IP
     * address or a load balancer's host name, RegionDnsCheck).
     */
    public function checkMigrationDns(KnativeRegion $region): bool
    {
        return app(RegionDnsCheck::class)->pointsAt($region->effective_settings['knative_domain'] ?? null, $region->external_ip) === true;
    }

    /**
     * What the move did with each project on the region since it started — in a multi-tenant
     * install with the organizations' projects on a platform region too (organization: the
     * tenant's slug) — for the operator, as long as the move keeps its saved state (until it is
     * completed or rolled back). Outcomes:
     *
     *  - moved: deployed on the new cluster (deployment_status: pending, active, failed, …);
     *  - building: what it runs is built again on the new cluster, it runs on the old one until
     *    its deploy there starts;
     *  - deployed: built again and deployed on the new cluster;
     *  - not_moved: refused, or its build or deploy on the new cluster failed (message: why);
     *  - cancelled: its waiting deploy was replaced by a newer deploy of the project.
     *
     * A deploy of an older version queued while the move's deploy of the project waits — and the
     * project still runs on the old cluster — takes its place instead (QueuedDeploys::queue()):
     * building, deployed or not_moved then tell what became of that deploy, with its version.
     *
     * Projects that run nothing (stopped, never deployed) are not listed.
     *
     * @return list<array{organization: string|null, project_id: string, project: string, outcome: string, version: int|null, deployment_status: string|null, message: string|null}>
     */
    public function migrationReport(KnativeRegion $region): array
    {
        $since = self::movedAt((array) ($region->migration_old_state ?? []));
        if ($since === null) {
            return [];
        }

        $report = [];
        $this->withCopies($region, function (KnativeRegion $local) use ($since, &$report) {
            $queued = GitTables::present('queued_deploys');
            foreach ($this->projectsOn($local) as $project) {
                try {
                    $outcome = $this->projectOutcome($project, $since, $queued);
                } catch (\Throwable $e) {
                    Log::warning("RegionMigrate: what the move did with {$project->slug} could not be read", ['error' => $e->getMessage()]);

                    continue;
                }
                if ($outcome !== null) {
                    $report[] = ['organization' => TenantContext::currentSlug(), 'project_id' => (string) $project->id, 'project' => (string) $project->name] + $outcome;
                }
            }
        });

        return $report;
    }

    /**
     * @return array{outcome: string, version: int|null, deployment_status: string|null, message: string|null}|null
     */
    private function projectOutcome(Project $project, Carbon $since, bool $withQueued = true): ?array
    {
        // The move's deploy of the project, or the newer queued deploy that took its place (a
        // deploy of an older version during the move: it carries the move's reason on)
        $takenOver = $withQueued && SchemaColumns::has(new QueuedDeploy, 'replaces_reason');
        $queued = ! $withQueued ? null : QueuedDeploy::query()->where('project_id', $project->id)
            ->where(fn ($q) => $takenOver
                ? $q->where('reason', QueuedDeploys::REASON_REGION_MIGRATION)->orWhere('replaces_reason', QueuedDeploys::REASON_REGION_MIGRATION)
                : $q->where('reason', QueuedDeploys::REASON_REGION_MIGRATION))
            ->where('created_at', '>=', $since)
            ->latest('created_at')->latest('id')->first();
        if ($queued) {
            $deployment = $queued->deployment_id ? Deployment::query()->find($queued->deployment_id) : null;

            return [
                'outcome' => match ($queued->status) {
                    QueuedDeploy::WAITING, QueuedDeploy::STARTING => 'building',
                    QueuedDeploy::DEPLOYED => 'deployed',
                    QueuedDeploy::FAILED => 'not_moved',
                    default => 'cancelled',
                },
                'version' => Version::query()->find($queued->version_id)?->number,
                'deployment_status' => $deployment?->status,
                'message' => $queued->message,
            ];
        }

        $moved = Deployment::query()->where('project_id', $project->id)
            ->where('deployed_by', 'region-migration')
            ->where('created_at', '>=', $since)
            ->latest('created_at')->latest('id')->first();
        if ($moved) {
            return [
                'outcome' => 'moved',
                'version' => Version::query()->find($moved->version_id)?->number,
                'deployment_status' => $moved->status,
                'message' => $moved->status === 'failed' ? $moved->knative_message : null,
            ];
        }

        $notMoved = Deployment::query()->where('project_id', $project->id)
            ->where('status', 'shut_down')
            ->where('knative_message', 'like', self::NOT_MOVED.'%')
            ->where('updated_at', '>=', $since)
            ->latest('updated_at')->first();
        if ($notMoved) {
            return [
                'outcome' => 'not_moved',
                'version' => Version::query()->find($notMoved->version_id)?->number,
                'deployment_status' => $notMoved->status,
                'message' => substr((string) $notMoved->knative_message, strlen(self::NOT_MOVED)),
            ];
        }

        return null;
    }

    public function completeMigration(KnativeRegion $region): void
    {
        $region->forceFill([
            'migration_status' => 'completed',
            'migration_old_ip' => null,
            'migration_old_kubeconfig' => null,
            'migration_old_state' => null,
        ])->save();
        $this->broadcast($region);
    }

    /**
     * The region gets its old cluster back: the kubeconfig, the external address and what the
     * CLUSTER_STATE columns held before the move — before its projects are deployed there again,
     * which build their image references from the restored registry address and their placement
     * from the restored dedicated workers. One save, so the copies of a platform region in the
     * tenants it is assigned to get the restored values with the same sync as the move's. The
     * statuses of the region's rows in other tables come back too (OTHER_TABLES).
     *
     * A version built while the region was on the new cluster was pushed to the new cluster's
     * registry only: its image reference names that registry, which the old cluster's nodes do
     * not pull from. Such a version is not deployed again (redeployProject). A version the move
     * built again on the new cluster gets the image back that it has in the old cluster's registry,
     * and its deploy that still waits is cancelled; a build still running on the new cluster is
     * cancelled (settleBuilds()). The runtime image builds come back as they were
     * (RUNTIME_BUILDS).
     */
    public function rollbackMigration(KnativeRegion $region): void
    {
        $oldKubeconfig = $region->migration_old_kubeconfig;
        $oldIp = $region->migration_old_ip;

        if (! $oldKubeconfig) {
            throw new \RuntimeException('No old kubeconfig saved for rollback.');
        }

        // A move started before the state was kept has none: kubeconfig and address only
        $saved = (array) ($region->migration_old_state ?? []);
        $oldState = array_intersect_key($saved, array_flip(self::CLUSTER_STATE));

        // The registry the region leaves: builds of the move window pushed their images there
        $leftRegistry = rtrim((string) $region->getAttribute('registry_address'), '/');
        $leftRegistry = $leftRegistry !== '' && array_key_exists('registry_address', $oldState)
            && rtrim((string) $oldState['registry_address'], '/') !== $leftRegistry ? $leftRegistry : null;

        // The cluster the region leaves and when the move started: builds submitted since ran there
        $leftCluster = (string) $region->kubeconfig;
        [$leftServer, $oldServer] = [self::serverOf($leftCluster), self::serverOf((string) $oldKubeconfig)];
        $otherCluster = $leftServer === null || $leftServer !== $oldServer;
        $since = self::movedAt($saved) ?? $region->migration_started_at;

        $region->forceFill(array_merge($oldState, [
            'kubeconfig' => $oldKubeconfig,
            'external_ip' => $oldIp,
            'migration_status' => null,
            'migration_started_at' => null,
            'migration_old_ip' => null,
            'migration_old_kubeconfig' => null,
            'migration_old_state' => null,
        ]))->save();

        if (is_array($saved[self::OTHER_TABLES] ?? null)) {
            $this->restoreOtherTables($region, $saved[self::OTHER_TABLES]);
        }
        if (is_array($saved[self::RUNTIME_BUILDS] ?? null)) {
            $this->restoreRuntimeBuilds($region, $saved[self::RUNTIME_BUILDS], $leftRegistry);
        }

        $this->withCopies($region, fn (KnativeRegion $local) => $this->settleBuilds($local, $otherCluster ? $leftCluster : null, $since));
        $this->redeployProjects($region, rollback: true, leftRegistry: $leftRegistry);
        $this->restoreProjectsNotMoved($region, $leftRegistry);
        $this->withCopies($region, fn (KnativeRegion $local) => $this->movePreviews($local, true, $otherCluster ? $leftCluster : null));
        $this->broadcast($region);
    }

    /**
     * The builds of the region's projects when the rollback gives the region its old cluster back:
     *
     *  - a deploy queued since the move started that has not created its deployment yet — waiting
     *    for its build, or claimed by a step that is still on its way to the deployment
     *    (QueuedDeploys::cancelUnstarted(): that step then creates none) — is cancelled: the
     *    rollback deploys the project on the old cluster like every other project;
     *  - a version built again on the new cluster (its queued deploy holds the image it had
     *    before: the move's, QueuedDeploys::REASON_REGION_MIGRATION, or a deploy of an older
     *    version, REASON_REBUILD) gets that image back, ready, when the old cluster's registry has
     *    it;
     *  - a build still running on the new cluster (submitted since the move started) ends as
     *    cancelled, with BUILD_CANCELLED as its error: its build check would look for the Kaniko Job
     *    on the restored cluster, which never had it, and fail it with "Build Job not found". It is
     *    not a build failure: no build.failed goes out, and a git deploy or queued deploy waiting
     *    for it ends as cancelled too (their Version hooks).
     *
     * The Kaniko Jobs of both are deleted from the cluster the region leaves. A move to the same
     * cluster (another kubeconfig of it, $leftCluster null) runs its builds where the region is
     * now: they go on.
     */
    private function settleBuilds(KnativeRegion $region, ?string $leftCluster, ?Carbon $since): void
    {
        $projects = $this->projectsOn($region)->keyBy('id');
        if ($projects->isEmpty()) {
            return;
        }
        $restored = rtrim((string) $region->registry_address, '/');
        $jobs = [];
        $deploys = app(QueuedDeploys::class);

        $queuedSince = ! GitTables::present('queued_deploys') ? collect() : QueuedDeploy::query()
            ->whereIn('project_id', $projects->keys()->all())
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->orderBy('created_at')
            ->get();
        foreach ($queuedSince as $queued) {
            try {
                $previous = (string) $queued->previous_build_image;
                $restorable = $previous !== '' && $restored !== '' && str_starts_with($previous, $restored.'/');

                // A deploy that waits — or one a step has claimed but whose deployment is not
                // created yet — ends here: the rollback deploys the project on the restored cluster
                // itself. One that created its deployment is a deploy like any other, which the
                // rollback's redeploy takes over from (redeployProjects()).
                if (in_array($queued->status, [QueuedDeploy::WAITING, QueuedDeploy::STARTING], true)) {
                    $deploys->cancelUnstarted($queued, $restorable
                        ? 'Cancelled by the migration rollback: the version runs from the image the restored cluster\'s registry has.'
                        : 'Cancelled by the migration rollback: the region is back on its old cluster.');
                }
                if (! $restorable) {
                    continue;
                }

                $version = Version::query()->find($queued->version_id);
                if (! $version || ($version->build_status === 'ready' && $version->build_image === $previous)) {
                    continue;
                }
                if ($version->build_status === 'building') {
                    $jobs[] = [$projects[$queued->project_id], $version];
                }
                $version->forceFill(['build_status' => 'ready', 'build_image' => $previous, 'build_error' => null, 'build_token' => null])->save();
                Log::info("RegionMigrate: v{$version->number} of {$projects[$queued->project_id]->slug} runs from {$previous} again after the rollback");
            } catch (\Throwable $e) {
                Log::warning('RegionMigrate: the image of a version built again on the new cluster not restored by the rollback', ['queued_deploy' => $queued->id, 'error' => $e->getMessage()]);
            }
        }

        $running = $leftCluster === null ? collect() : Version::query()
            ->whereIn('project_id', $projects->keys()->all())
            ->where('build_status', 'building')
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->get();
        foreach ($running as $version) {
            $version->forceFill(['build_status' => Version::STATUS_CANCELLED, 'build_error' => self::BUILD_CANCELLED, 'build_token' => null])->save();
            $jobs[] = [$projects[$version->project_id], $version];
            Log::info("RegionMigrate: the build of v{$version->number} of {$projects[$version->project_id]->slug} on the cluster the rollback left is cancelled");
        }

        if ($leftCluster !== null) {
            $this->deleteBuildJobs($region, $leftCluster, $jobs);
        }
    }

    /**
     * The Kaniko Jobs (and source ConfigMaps) of builds on the cluster at $kubeconfig, which the
     * region leaves; a cluster that does not answer is logged.
     *
     * @param  list<array{0: Project, 1: Version}>  $builds
     */
    private function deleteBuildJobs(KnativeRegion $region, string $kubeconfig, array $builds): void
    {
        if ($builds === [] || $kubeconfig === '') {
            return;
        }

        try {
            $k8s = new KubernetesClient($kubeconfig);
            $namespace = $region->project_namespace ?? $region->name;
            foreach ($builds as [$project, $version]) {
                $name = "build-{$project->slug}-v{$version->number}";
                $k8s->deleteResource('batch/v1', 'Job', $name, $namespace, 'Background');
                $k8s->deleteResource('v1', 'ConfigMap', $name, $namespace);
            }
        } catch (\Throwable $e) {
            Log::warning("RegionMigrate: build Jobs on the cluster {$region->name} left not deleted", ['error' => $e->getMessage()]);
        }
    }

    /** The API server a kubeconfig points at (its first cluster); null when it cannot be read. */
    private static function serverOf(string $kubeconfig): ?string
    {
        try {
            $server = Yaml::parse($kubeconfig)['clusters'][0]['cluster']['server'] ?? null;
        } catch (\Throwable) {
            return null;
        }

        return is_string($server) ? rtrim($server, '/') : null;
    }

    /** MOVED_AT of a move's saved state; null for a move that saved none. */
    private static function movedAt(array $saved): ?Carbon
    {
        $at = $saved[self::MOVED_AT] ?? null;
        if (! is_string($at) || $at === '') {
            return null;
        }

        try {
            return Carbon::parse($at)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * After a rollback the region's cluster is the old one again, where what the move did not
     * deploy anew still runs: a project the move did not move (its deployments shut down with
     * NOT_MOVED, nothing live) and a project stopped while the region was on the new cluster (the
     * stop removed it from there) would serve with no live deployment — not metered. Each of them
     * that runs on the restored cluster is deployed again like every other project (the version
     * and overrides of the deployment the move shut down, through ProjectAdmission; metered from
     * when it is ready), or, when that is not possible (stopped, refused again, its version no
     * longer deployable), what it runs there is removed. A project that runs nothing there (e.g.
     * one an earlier, completed move did not move) is left as it is.
     */
    private function restoreProjectsNotMoved(KnativeRegion $region, ?string $leftRegistry = null): void
    {
        $region->refresh();
        if ($region->is_demo || ! $region->kubeconfig) {
            return;
        }

        $this->withCopies($region, function (KnativeRegion $local) use ($leftRegistry) {
            foreach ($this->projectsOn($local) as $project) {
                try {
                    $this->restoreProjectNotMoved($local, $project, $leftRegistry);
                } catch (\Throwable $e) {
                    Log::warning("RegionMigrate: rollback of {$project->slug} not checked", ['error' => $e->getMessage()]);
                }
            }
        });
    }

    private function restoreProjectNotMoved(KnativeRegion $region, Project $project, ?string $leftRegistry = null): void
    {
        // Deployed again by the rollback (redeployProjects)
        if (Deployment::query()->where('project_id', $project->id)->whereIn('status', Deployment::LIVE)->exists()) {
            return;
        }

        $last = Deployment::query()->where('project_id', $project->id)->latest('created_at')->latest('id')->first();
        $notMoved = $last && $last->status === 'shut_down' && str_starts_with((string) $last->knative_message, self::NOT_MOVED);
        if (! $notMoved && ! $project->isStopped()) {
            return;
        }

        if (! $this->runsOn($region, $project)) {
            return;
        }

        // The version it ran (deleted since: none)
        $version = $notMoved ? Version::query()->find($last->version_id) : null;
        $refusal = match (true) {
            ! $notMoved => 'the project was stopped',
            ! $version || $version->build_status !== 'ready' => 'its version can no longer be deployed',
            default => ProjectAdmission::refusal($project, DeployIntent::production($last->deploy_config))
                ?? $this->imageLeftBehind($project, $leftRegistry, true),
        };

        if ($refusal !== null) {
            $this->deployer->teardownProject($project);
            Log::warning("RegionMigrate: {$project->slug} not restored by the rollback, what it ran on the cluster is removed", ['reason' => $refusal]);

            return;
        }

        /** @var Deployment $deployment */
        $deployment = $project->deployments()->create([
            'version_id' => $version->id,
            'status' => 'pending',
            'k8s_job_name' => 'run-'.$project->slug.'-v'.$version->number,
            'deployed_by' => 'region-migration',
            'deploy_config' => $last->deploy_config,
        ]);

        $this->deployer->deploy($project, $version, $deployment);
        Log::info("RegionMigrate: {$project->slug} deployed again by the rollback");
    }

    /** Whether the project's Knative Service or CronJob exists on the region's cluster. */
    private function runsOn(KnativeRegion $region, Project $project): bool
    {
        $k8s = new KubernetesClient($region->kubeconfig);
        $namespace = $region->project_namespace ?? $region->name;
        $names = $this->deployer->workloadNames($project);

        return $k8s->getResourceStatus('serving.knative.dev/v1', 'Service', $names['service'], $namespace) !== null
            || $k8s->getResourceStatus('batch/v1', 'CronJob', $names['cronJob'], $namespace) !== null;
    }

    /**
     * What the region has on its current cluster, for a rollback: the kubeconfig, the external
     * address and the CLUSTER_STATE columns, read from the row as stored, and the statuses of
     * OTHER_TABLES. A column the region's table does not have (a tenant schema not migrated yet)
     * is not kept and not restored.
     *
     * A move that failed and is started again keeps what its first attempt saved: the region may
     * already be switched to the new cluster, partly installed there — that is not the cluster a
     * rollback has to go back to.
     */
    private function saveOldState(KnativeRegion $region): void
    {
        $region->refresh();

        if ($region->migration_status === 'failed' && $region->migration_old_kubeconfig) {
            Log::info("RegionMigrate: {$region->name} is moved again after a failed move; the state saved before it is kept for a rollback");
            $region->forceFill(['migration_status' => 'pending', 'migration_started_at' => now()])->save();

            return;
        }

        $columns = array_intersect(self::CLUSTER_STATE, array_keys($region->getAttributes()));
        $state = array_combine($columns, array_map(fn (string $column) => $region->getAttribute($column), $columns));

        $region->forceFill([
            'migration_old_ip' => $region->external_ip,
            'migration_old_kubeconfig' => $region->kubeconfig,
            'migration_old_state' => $state + [
                self::OTHER_TABLES => $this->otherTablesState($region),
                self::RUNTIME_BUILDS => $this->runtimeBuildsState($region),
                self::MOVED_AT => now()->toIso8601String(),
            ],
            'migration_status' => 'pending',
            'migration_started_at' => now(),
        ])->save();
    }

    /**
     * The region's runtime image builds as they are before the move (RUNTIME_BUILDS); empty when
     * they cannot be read.
     *
     * @return array<string, array<string, mixed>>
     */
    private function runtimeBuildsState(KnativeRegion $region): array
    {
        try {
            return RuntimeBuild::query()->where('knative_region_id', $region->getKey())->get()
                ->mapWithKeys(fn (RuntimeBuild $build) => [(string) $build->getKey() => $build->only(['status', 'image', 'digest', 'job_name', 'error'])])
                ->all();
        } catch (\Throwable $e) {
            Log::warning("RegionMigrate: runtime image builds of {$region->name} not kept for a rollback", ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * The runtime image builds as they were before the move: the old cluster's registry still has
     * their images. A build made for the new cluster only (no row before the move) whose image is
     * in the registry the rollback leaves, or that was still running there, is built again on the
     * restored cluster when a project needs it.
     *
     * @param  array<string, mixed>  $saved  runtimeBuildsState() of the move
     */
    private function restoreRuntimeBuilds(KnativeRegion $region, array $saved, ?string $left): void
    {
        try {
            foreach (RuntimeBuild::query()->where('knative_region_id', $region->getKey())->get() as $build) {
                $before = $saved[(string) $build->getKey()] ?? null;
                if (is_array($before)) {
                    $build->forceFill(array_intersect_key($before, array_flip(['status', 'image', 'digest', 'job_name', 'error'])))->save();
                } elseif ($build->status === RuntimeBuild::STATUS_BUILDING || ($left !== null && str_starts_with((string) $build->image, $left.'/'))) {
                    $build->forceFill([
                        'status' => RuntimeBuild::STATUS_PENDING,
                        'image' => null,
                        'error' => 'Built on the cluster the migration rollback left: built again on this one when a project needs it.',
                    ])->save();
                }
            }
        } catch (\Throwable $e) {
            Log::warning("RegionMigrate: runtime image builds of {$region->name} not restored by the rollback", ['error' => $e->getMessage()]);
        }
    }

    /**
     * The statuses OTHER_TABLES keeps: per data service its row's status and message (null: the
     * region has no row for it), the admission policy's status and message, the tracing
     * backend's status, message and install time (null: no row).
     *
     * A table that cannot be read is left out (and not restored).
     *
     * @return array<string, mixed>
     */
    private function otherTablesState(KnativeRegion $region): array
    {
        $read = function (string $table, callable $read) use ($region): array {
            try {
                return [$table => $read()];
            } catch (\Throwable $e) {
                Log::warning("RegionMigrate: {$table} of {$region->name} not kept for a rollback", ['error' => $e->getMessage()]);

                return [];
            }
        };

        return $read('binding_region_services', function () use ($region) {
            $services = [];
            foreach (BindingRegionService::SERVICES as $service) {
                $row = BindingRegionService::forRegion($region, $service);
                $services[$service] = $row ? ['status' => $row->status, 'status_message' => $row->status_message] : null;
            }

            return $services;
        }) + $read('region_supply_chain_settings', function () use ($region) {
            $settings = RegionSupplyChainSetting::forRegion($region);

            return ['admission_status' => $settings->admission_status, 'admission_message' => $settings->admission_message];
        }) + $read('region_tracing_configs', function () use ($region) {
            $tracing = RegionTracingConfig::query()->where('region_id', $region->getKey())->first();

            return $tracing ? [
                'status' => $tracing->status,
                'status_message' => $tracing->status_message,
                'installed_at' => $tracing->installed_at?->toDateTimeString(),
            ] : null;
        });
    }

    /**
     * The statuses of the region's rows in OTHER_TABLES as they were before the move. A row the
     * region had no row for then (the service or backend was installed on the new cluster only)
     * is not installed on the restored cluster. A table that fails is logged and the rollback goes
     * on.
     *
     * @param  array<string, mixed>  $saved  otherTablesState() of the move
     */
    private function restoreOtherTables(KnativeRegion $region, array $saved): void
    {
        $restore = function (string $table, callable $run) use ($region, $saved): void {
            if (! array_key_exists($table, $saved)) {
                return;
            }
            try {
                $run($saved[$table]);
            } catch (\Throwable $e) {
                Log::warning("RegionMigrate: {$table} of {$region->name} not restored by the rollback", ['error' => $e->getMessage()]);
            }
        };

        $restore('binding_region_services', function (mixed $services) use ($region) {
            foreach ((array) $services as $service => $before) {
                BindingRegionService::forRegion($region, (string) $service)?->forceFill(is_array($before) ? $before : [
                    'status' => BindingRegionService::STATUS_NOT_INSTALLED,
                    'status_message' => 'Installed on the cluster the migration rollback left, not on this one.',
                ])->save();
            }
        });

        $restore('region_supply_chain_settings', function (mixed $before) use ($region) {
            $row = RegionSupplyChainSetting::query()->find($region->getKey());
            if (is_array($before) && $row) {
                $row->forceFill($before)->save();
            }
        });

        $restore('region_tracing_configs', function (mixed $before) use ($region) {
            RegionTracingConfig::query()->where('region_id', $region->getKey())->first()?->forceFill(is_array($before)
                ? $before
                : ['status' => null, 'status_message' => null, 'installed_at' => null])->save();
        });
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
     * On a rollback the cluster is the old one, where the project ran before the move: a refused
     * project's workloads are removed from it as well, so nothing of it serves unmetered.
     *
     * On a rollback a live version built into $leftRegistry (built during the move, its image is
     * in the new cluster's registry only) is not deployed: the project's last deployment whose
     * version runs from the restored registry is deployed again instead — version and overrides,
     * usually what ran before the move, whose image the old cluster's registry still has. The
     * deployments of the version left behind are shut down with the reason; a project without
     * such a deployment is refused like any other.
     *
     * The move, when the region's registry is another one on the new cluster ($leftRegistry: the
     * old cluster's), whose nodes do not pull from the old one: a version built into the old
     * registry (dependencies, a compile step, WebAssembly) is built again into the new one, and a
     * version without a build of its own on a runtime image RunR built in the region waits for that
     * image to be built again (buildOnNewCluster()); the project is deployed on the new cluster when
     * it is ready, through the deploy checks of every deploy (QueuedDeploys), and its live
     * deployment keeps running on the old cluster until then. In both directions a project that
     * runs an image of the registry left behind that RunR cannot build again — an uploaded image —
     * is refused (imageLeftBehind()).
     */
    private function redeployProject(Project $project, bool $rollback = false, ?string $leftRegistry = null): void
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

            // What is deployed: the version and overrides of the live deployment, or of the one
            // deployed again in place of a version built into the registry the rollback left
            $source = $live;
            $note = null;
            if ($rollback && $leftRegistry !== null && self::builtInto($version, $leftRegistry)) {
                $note = "v{$version->number} was built into the registry of the cluster the rollback left";
                $source = $this->lastDeploymentNotBuiltInto($project, $leftRegistry);
            }

            $refusal = $source === null
                ? "{$note}, and no earlier version of the project runs from the restored one"
                : (ProjectAdmission::refusal($project, DeployIntent::production($source->deploy_config))
                    ?? $this->imageLeftBehind($project, $leftRegistry, $rollback));

            if ($source === null || $refusal !== null) {
                // Nothing of it runs on the new cluster: its deployments end (the old cluster's
                // resources stay with the old cluster), so it is neither metered nor counted as running
                foreach (Deployment::query()->where('project_id', $project->id)->whereIn('status', Deployment::LIVE)->get() as $old) {
                    $old->transition(Deployment::LIVE, [
                        'status' => 'shut_down',
                        'knative_ready' => false,
                        'knative_message' => mb_substr(($rollback ? self::NOT_RESTORED : self::NOT_MOVED).$refusal, 0, 255),
                    ]);
                }
                if ($rollback) {
                    $this->deployer->teardownProject($project);
                }
                Log::warning("RegionMigrate: {$project->slug} not redeployed, its deployments are shut down", ['reason' => $refusal]);

                return;
            }

            // The move: an image of the version in the registry the region left is built again
            // into the new cluster's first, and the project is deployed there once it is ready
            if (! $rollback && $leftRegistry !== null && GitTables::present('queued_deploys')
                && ($build = $this->buildOnNewCluster($project, $version, $leftRegistry)) !== null) {
                $this->deployWhenBuilt($project, $live, $version, $build['rebuild'], $build['what']);

                return;
            }

            $target = $source->version;
            $replaced = $note === null ? [] : ['knative_message' => mb_substr(self::NOT_RESTORED."{$note}; v{$target->number} runs instead", 0, 255)];

            // The old cluster's resources are left behind with the old cluster
            foreach (Deployment::query()->where('project_id', $project->id)->whereIn('status', Deployment::LIVE)->get() as $old) {
                $old->update(['status' => 'shut_down'] + $replaced);
            }

            $deployment = $project->deployments()->create([
                'version_id' => $target->id,
                'status' => 'pending',
                'k8s_job_name' => 'run-'.$project->slug.'-v'.$target->number,
                'deployed_by' => 'region-migration',
                'deploy_config' => $source->deploy_config,
            ]);

            $this->deployer->deploy($project, $target, $deployment);

            if ($note !== null) {
                Log::warning("RegionMigrate: {$project->slug} {$note}; v{$target->number} is deployed again instead");
            }
        } catch (\Throwable $e) {
            Log::warning("RegionMigrate: failed to redeploy {$project->slug}", ['error' => $e->getMessage()]);
        }
    }

    /** Whether the version's build image was pushed to the registry at $registry (its address). */
    private static function builtInto(Version $version, string $registry): bool
    {
        return $version->build_image !== null && str_starts_with($version->build_image, rtrim($registry, '/').'/');
    }

    /**
     * The project's latest deployment whose version is ready and was not built into $registry —
     * one the restored cluster can pull. Null when there is none.
     */
    private function lastDeploymentNotBuiltInto(Project $project, string $registry): ?Deployment
    {
        $deployments = Deployment::query()->where('project_id', $project->id)
            ->latest('created_at')->latest('id')->cursor();

        foreach ($deployments as $deployment) {
            /** @var Deployment $deployment */
            $candidate = Version::query()->find($deployment->version_id);
            if ($candidate && $candidate->build_status === 'ready' && ! self::builtInto($candidate, $registry)) {
                return $deployment->setRelation('version', $candidate);
            }
        }

        return null;
    }

    /**
     * Why the project cannot run on the region's cluster now: it runs an image in the registry the
     * region left that RunR has no way to put into the region's registry again — an image uploaded
     * there (docs/images.md: the upload streams through RunR into the registry, nothing of it is
     * kept), or any other reference into that registry. Null when nothing it runs is in that
     * registry, and when the registry stays ($left null).
     */
    private function imageLeftBehind(Project $project, ?string $left, bool $rollback): ?string
    {
        if ($left === null) {
            return null;
        }
        $where = $rollback ? 'the cluster the rollback left' : 'the cluster the region left';

        if ($project->service_type === 'caas') {
            $image = (string) $project->containerConfig?->image;
            if (! str_starts_with($image, $left.'/')) {
                return null;
            }
            $upload = app(UploadedImages::class)->nameOfRef($project->knativeRegion, $image, $left);

            return $upload !== null
                ? "its image {$upload} was uploaded into the registry of {$where}; upload it to the region again, select it as the project's image and deploy"
                : "its image {$image} is in the registry of {$where}; set an image the region's cluster can pull and deploy";
        }

        // A runtime version that runs an uploaded image (upload:): it has to be in the region's registry
        $spec = $this->runtimeVersion($project);
        if (($spec['upload'] ?? null) === null || ! $project->knativeRegion) {
            return null;
        }
        try {
            $runtimes = app(RuntimeRegistry::class);
            $runtimes->uploadedVersionImage($runtimes->forProject($project), $spec, $project->knativeRegion);
        } catch (\Throwable $e) {
            return rtrim($e->getMessage(), '.');
        }

        return null;
    }

    /**
     * What has to be built into the new cluster's registry before the project runs there: the
     * version itself, when its build image is in the registry the region left ($left), or — a
     * version without a build of its own — its runtime version's image, when RunR builds that from
     * source in the region (leaveRuntimeImages() marked it). Null when the version runs from
     * elsewhere.
     *
     * @return array{rebuild: bool, what: string}|null rebuild: the version is built again
     */
    private function buildOnNewCluster(Project $project, Version $version, string $left): ?array
    {
        if ($project->service_type === 'caas') {
            return null;
        }
        if ($version->build_image !== null) {
            return self::builtInto($version, $left) ? ['rebuild' => true, 'what' => "v{$version->number} is built again there"] : null;
        }

        $spec = $this->runtimeVersion($project);
        if ($spec !== null && ($spec['image'] ?? null) === null && (($spec['dockerfile'] ?? null) !== null || ($spec['git'] ?? null) !== null)) {
            return ['rebuild' => false, 'what' => "the runtime image {$project->runtime}:{$spec['version']} is built again there"];
        }

        return null;
    }

    /**
     * Deploy the project on the new cluster once what it runs is built there: a deploy of its live
     * version and overrides that waits for the build (QueuedDeploys: the deploy checks of every
     * deploy, ProjectAdmission included). Its live deployment keeps running on the old cluster
     * until the new one starts, with MOVING as its message; when the build or the deploy fails it
     * is shut down with NOT_MOVED and the reason, as a project the move refused. A deploy of an
     * older version queued meanwhile (VersionRebuilds) takes this one's place: when it fails, that
     * deployment ends the same way (QueuedDeploys::queue()).
     */
    private function deployWhenBuilt(Project $project, Deployment $live, Version $version, bool $rebuild, string $what): void
    {
        $queued = app(QueuedDeploys::class)->queue($project, $version, 'region-migration', [
            'deploy_config' => $live->deploy_config,
            'reason' => QueuedDeploys::REASON_REGION_MIGRATION,
            'replaces' => $live,
            'previous_build_image' => $rebuild ? $version->build_image : null,
            'message' => 'Moving to the new cluster of the region: '.$what.'; deployed there when it is ready.',
        ]);

        foreach (Deployment::query()->where('project_id', $project->id)->whereIn('status', Deployment::LIVE)->get() as $old) {
            $old->update(['knative_message' => mb_substr(self::MOVING.$what, 0, 255)]);
        }

        if ($rebuild) {
            $version->forceFill(['build_status' => 'pending', 'build_error' => null])->save();
            BuildVersionJob::dispatch($version);
        } else {
            ProcessQueuedDeployJob::dispatch($queued->id);
        }

        Log::info("RegionMigrate: {$project->slug} {$what}; deployed on the new cluster when it is ready, it runs on the old one until then");
    }

    /**
     * The live preview environments of the region's projects (docs/git-deploy.md#preview-environments)
     * on the cluster the region has now — the new one, or after a rollback ($rollback) the old one
     * again: each is deployed there through the preview path, as its **Redeploy** does
     * (PreviewService::deploy(): ProjectAdmission, data residency and the supply-chain gate). A
     * preview version whose image is in a registry the region's cluster does not pull from, or
     * whose build ran on the cluster the region left, is built again into the region's registry
     * first (BuildPreviewJob) — the rule of every deploy (VersionRebuilds). One whose version's
     * build failed is closed (failed) with NOT_MOVED / NOT_RESTORED and the reason: a new commit to
     * the request builds and deploys it. The cluster the region left keeps what it ran, like the
     * projects' Services; a rollback deletes the preview builds still running on the cluster it
     * leaves ($leftCluster, null when that is the same cluster).
     */
    private function movePreviews(KnativeRegion $region, bool $rollback = false, ?string $leftCluster = null): void
    {
        if (! GitTables::present('preview_environments')) {
            return;
        }
        $projects = $this->projectsOn($region)->keyBy('id');
        if ($projects->isEmpty()) {
            return;
        }

        $live = PreviewEnvironment::query()
            ->whereIn('project_id', $projects->keys()->all())
            ->whereIn('status', PreviewEnvironment::LIVE)
            ->whereNotNull('version_id')
            ->get();
        $jobs = [];
        foreach ($live as $preview) {
            try {
                $project = $projects->get($preview->project_id);
                $version = PreviewVersionScope::query()->find($preview->version_id);
                if ($project instanceof Project && $version instanceof Version && ! $project->isStopped()) {
                    $built = $this->movePreview($project, $preview, $version, $rollback);
                    if ($built && $leftCluster !== null) {
                        $jobs[] = [$project, $version];
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("RegionMigrate: preview #{$preview->number} of project {$preview->project_id} not deployed again", ['error' => $e->getMessage()]);
            }
        }

        if ($leftCluster !== null) {
            $this->deleteBuildJobs($region, $leftCluster, $jobs);
        }
    }

    /**
     * One live preview on the region's cluster now (movePreviews()).
     *
     * @return bool whether its build ran on the cluster the region left (its Kaniko Job is there)
     */
    private function movePreview(Project $project, PreviewEnvironment $preview, Version $version, bool $rollback): bool
    {
        $previews = app(PreviewService::class);
        $where = $rollback ? 'the cluster the migration rollback restored' : 'the new cluster of the region';
        $ranOnLeft = $version->build_status === 'building';

        // A build that has not started yet runs where the region is now (BuildPreviewJob)
        if ($version->build_status === 'pending') {
            return false;
        }

        if ($ranOnLeft || VersionRebuilds::needed($project, $version)) {
            $version->forceFill(['build_status' => 'pending', 'build_error' => null, 'build_token' => null])->save();
            $message = "Built again on {$where}: ".($ranOnLeft
                ? 'its build ran on the cluster the region left'
                : 'its image is in a registry that cluster does not pull from').'; deployed there when it is ready.';
            $preview->mark(PreviewEnvironment::BUILDING, $message);
            $deploy = $previews->currentDeploy($preview);
            $deploy?->mark(GitDeploy::BUILDING, $message);
            $previews->reportPreview($preview, $deploy);
            BuildPreviewJob::dispatch($preview->id, $version->id);
            Log::info("RegionMigrate: preview #{$preview->number} of {$project->slug} is built again on {$where}");

            return $ranOnLeft;
        }

        if ($version->build_status !== 'ready') {
            $previews->fail($preview, $previews->currentDeploy($preview), ($rollback ? self::NOT_RESTORED : self::NOT_MOVED)
                ."the build of its version failed ({$version->build_status}); push a new commit to the request to build and deploy it there");

            return false;
        }

        $preview->mark(PreviewEnvironment::DEPLOYING);
        DeployPreviewJob::dispatch($preview->id);
        Log::info("RegionMigrate: preview #{$preview->number} of {$project->slug} is deployed again on {$where}");

        return false;
    }

    /**
     * The project's runtime version as its runtime manifest declares it (image, upload, dockerfile
     * or git source); null for a CaaS project or one whose runtime is unknown.
     *
     * @return array<string, mixed>|null
     */
    private function runtimeVersion(Project $project): ?array
    {
        if ($project->service_type === 'caas') {
            return null;
        }

        try {
            $runtimes = app(RuntimeRegistry::class);

            return $runtimes->forProject($project)->version($runtimes->executorFor($project)->version);
        } catch (\Throwable) {
            return null;
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

    /**
     * Kata Containers with the hypervisors the region has (the default, Cloud Hypervisor, only
     * for a region that lists none) and gVisor, where the region has them ready. The Kata install
     * starts from nothing on the new cluster: a failure there leaves Kata in error, not "ready"
     * with the old cluster's hypervisors. Firecracker's thin-pool gets the size it has on the old
     * cluster's nodes (devmapperPoolGb()).
     */
    private function installSandbox(KnativeRegion $region): void
    {
        if ($region->has_sandbox_kata) {
            $hypervisors = array_values(array_filter((array) ($region->sandbox_kata_hypervisor ?? []), 'is_string')) ?: ['clh'];
            $options = ['previous_status' => 'not_installed'];
            if (array_filter($hypervisors, [KataRuntime::class, 'needsDevmapper']) && ($poolGb = $this->devmapperPoolGb($region)) !== null) {
                $options['devmapper_pool_gb'] = $poolGb;
            }
            $this->step($region, 'Kata Containers', fn () => $this->sandboxService->installKata($region, $hypervisors, $options));
        }
        if ($region->has_sandbox_gvisor) {
            $this->step($region, 'gVisor', fn () => $this->sandboxService->installGvisor($region));
        }
    }

    /**
     * The size of Firecracker's thin-pool on the old cluster's nodes: the region's
     * (sandbox_kata_devmapper_pool_gb), else — a pool set up before the region recorded it — the
     * size in the old cluster's kata-devmapper DaemonSet. Null when neither is known (the default
     * size then).
     */
    private function devmapperPoolGb(KnativeRegion $region): ?int
    {
        $stored = $region->getAttribute('sandbox_kata_devmapper_pool_gb');
        if (is_numeric($stored) && (int) $stored > 0) {
            return (int) $stored;
        }

        $old = $region->migration_old_kubeconfig;
        if (! $old) {
            return null;
        }

        try {
            return $this->sandboxService->installedDevmapperPoolGb($old);
        } catch (\Throwable $e) {
            Log::warning("RegionMigrate: Firecracker thin-pool size of {$region->name} not read from the old cluster", ['error' => $e->getMessage()]);

            return null;
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
