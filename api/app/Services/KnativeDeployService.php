<?php

namespace App\Services;

use App\Jobs\CheckDeploymentReadyJob;
use App\Mcp\Hosting\KnativeMcpServerTrait;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Version;
use App\Services\Concerns\KnativeManifestTrait;
use App\Services\Concerns\KnativeQueueConsumerTrait;
use App\Services\Helpers\ResourceReservation;
use App\Services\Helpers\WorkloadSizing;
use App\Services\Projects\LiveDeployments;
use App\Services\Runtimes\ProjectEntrypoint;
use App\Services\Runtimes\RuntimeRegistry;
use App\Support\DeployGate;
use App\Support\SchemaColumns;
use App\Workflows\Deploy\KnativeWorkflowTrait;
use Illuminate\Support\Facades\Log;

class KnativeDeployService
{
    use KnativeManifestTrait;

    // Hosted MCP servers: RUNR_MCP_GATEWAY_KEY (docs/mcp.md)
    use KnativeMcpServerTrait;
    use KnativeQueueConsumerTrait;
    use KnativeWorkflowTrait;

    private GatewayService $gatewayService;

    public function __construct(
        private GitService $git,
    ) {
        $this->gatewayService = app(GatewayService::class);
    }

    /**
     * Deploy a project version to the cluster.
     *
     * A deploy queued before the project was stopped (or before the deployment was shut down or
     * deleted) does nothing: the deployment is read back first, and every status this deploy writes
     * is conditional on the deployment still being pending / running (Deployment::transition) — a
     * stop in the middle of the apply wins, and what the apply created is removed again.
     */
    public function deploy(Project $project, Version $version, Deployment $deployment, ?string $initiatorId = null): void
    {
        $current = Deployment::query()->find($deployment->getKey());
        if (! $current || ! in_array($current->status, ['pending', 'running'], true)) {
            Log::info('Deploy skipped: the deployment is no longer pending', [
                'project' => $project->slug, 'deployment' => $deployment->id, 'status' => $current?->status,
            ]);

            return;
        }
        $deployment->setRawAttributes($current->getAttributes(), true);

        if ($this->isStopped($project)) {
            $this->fail($deployment, 'The project was stopped before this deploy ran');
            // A stop that raced this deploy may have torn the previous deployment down as superseded
            // by this one, keeping the shared Knative Service: with nothing else live it goes now
            if (! $project->deployments()->whereKeyNot($deployment->id)->whereIn('status', Deployment::LIVE)->exists()) {
                $this->teardown($project, $deployment);
            }

            return;
        }

        $region = $project->knativeRegion;

        // Only xaas / faas / caas deploy; rows of the retired AI types are view-and-delete only
        if (! $project->isSupportedType()) {
            $this->fail($deployment, "Projects of type {$project->service_type} can no longer be deployed");

            return;
        }

        if ($region && $region->is_demo) {
            $this->recordSizing($project, $deployment);
            app(LiveDeployments::class)->activate($deployment, ['knative_ready' => true, 'ready_at' => now()]);

            return;
        }

        if (! $region || ! $region->kubeconfig) {
            $this->fail($deployment, 'Region kubeconfig not configured');

            return;
        }

        // e.g. the tenant is being deleted (Tenancy module): nothing new may start behind the teardown
        if ($blocked = DeployGate::blockedReason()) {
            $this->fail($deployment, $blocked);

            return;
        }

        // Registry retention deleted this version's build image (RegistryImageLifecycleService)
        if ($version->build_status === Version::STATUS_PRUNED) {
            $this->fail($deployment, "Version v{$version->number} build image was pruned by registry retention; build it again");

            return;
        }

        if (! $region->is_active) {
            $this->fail($deployment, 'Region not active (status: '.($region->region_status ?? 'unknown').')');

            return;
        }

        // Preflight: queue trigger requires Redis
        if ($this->getTriggerType($project) === 'queue' && ! $region->has_redis) {
            $this->fail($deployment, 'Queue trigger requires Redis on region: '.$region->name);

            return;
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $namespace = $this->getNamespace($region);

            $manifests = $this->buildManifests($project, $version, $deployment->deploy_config ?? []);
            // Metering counts the deployment with the size it runs with (WorkloadSizing)
            $this->recordSizing($project, $deployment);

            foreach ($manifests as $manifest) {
                // Deployment selector is immutable — delete first to avoid conflict
                if ($manifest['kind'] === 'Deployment') {
                    $k8s->deleteResource('apps/v1', 'Deployment', $manifest['metadata']['name'], $namespace);
                }

                $success = $k8s->applyResource($manifest, $namespace);
                if (! $success) {
                    $this->fail($deployment, "Failed to apply {$manifest['kind']}: {$manifest['metadata']['name']}");

                    return;
                }
            }

            // Region capacity counts the Kata pod overhead of the RuntimeClass the pods now run
            // with, not the region's mapping at the time of counting (ResourceReservation)
            $this->recordRuntimeClass($project, $manifests);

            // The project's last secret env var was removed: its Secret goes too (404 = none)
            if (! $this->secretEnvValues($project)) {
                $k8s->deleteResource('v1', 'Secret', $this->secretEnvName($project), $namespace);
            }

            $triggerType = $this->getTriggerType($project);

            // Stopped (or shut down) while the manifests were applied: remove them again
            $stillWanted = $triggerType === 'schedule'
                // CronJob has no Knative Service to check — active right away (unless replaced)
                ? app(LiveDeployments::class)->activate($deployment, ['knative_ready' => true, 'ready_at' => now()])
                : $deployment->transition(['pending', 'running'], ['status' => 'pending']);
            if (! $stillWanted || $this->isStopped($project)) {
                $this->withdraw($project, $deployment);

                return;
            }

            if ($triggerType !== 'schedule') {
                CheckDeploymentReadyJob::start($deployment, $project, $initiatorId);
            }

            // Sync gateway IngressRoute if project uses gateway endpoint
            if ($project->endpoint_type === 'gateway') {
                $gwRoute = $project->gatewayRoute;
                if ($gwRoute) {
                    $this->gatewayService->syncToCluster($gwRoute->gateway->fresh(['routes.project']));
                }
            }

            // Custom domains: routed only while the project is HTTP-triggered
            try {
                app(ProjectDomainService::class)->syncAfterDeploy($project);
            } catch (\Throwable $e) {
                Log::warning('Custom domain sync after deploy failed', [
                    'project' => $project->slug,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info('Deployment applied', [
                'project' => $project->slug,
                'version' => $version->number,
                'deployment' => $deployment->id,
                'trigger' => $triggerType,
                'resources' => count($manifests),
            ]);

        } catch (\Throwable $e) {
            $this->fail($deployment, $e->getMessage());
            Log::error('Deployment failed', [
                'project' => $project->slug,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Tear down a deployment from the cluster: the project's Knative Service, the deployment's
     * code ConfigMap, the CronJob (schedule trigger) and the queue consumer.
     *
     * @return bool true when every delete succeeded (404 = already gone)
     */
    public function teardown(Project $project, Deployment $deployment): bool
    {
        $targets = [
            ['serving.knative.dev/v1', 'Service', $this->serviceName($project)],
            ['apps/v1', 'Deployment', $this->queueConsumerName($project)],
            ['v1', 'Secret', $this->queueConsumerSecretName($project)],
            ['v1', 'Secret', $this->secretEnvName($project)],
        ];

        if ($project->service_type !== 'caas' && $deployment->version) {
            $targets[] = ['v1', 'ConfigMap', $this->configMapName($project, $deployment->version)];
        }

        if ($this->getTriggerType($project) === 'schedule') {
            $targets[] = ['batch/v1', 'CronJob', $this->cronJobName($project)];
        }

        return $this->deleteFromRegion($project, $targets, ['deployment' => $deployment->id]);
    }

    /**
     * Tear down a deployment that a newer deploy of the same project replaced. The new deploy
     * applies the Knative Service, CronJob and queue consumer under the same names, so only what
     * it does not use is removed: the old version's code ConfigMap (unless the new deployment
     * runs the same version) and the resources of a trigger type the project no longer uses.
     *
     * @return bool true when every delete succeeded (404 = already gone)
     */
    public function teardownSuperseded(Project $project, Deployment $deployment): bool
    {
        $trigger = $this->getTriggerType($project);
        $targets = [];

        $versionStillLive = $project->deployments()
            ->whereKeyNot($deployment->id)
            ->where('version_id', $deployment->version_id)
            ->whereIn('status', ['pending', 'running', 'active'])
            ->exists();

        if ($project->service_type !== 'caas' && $deployment->version && ! $versionStillLive) {
            $targets[] = ['v1', 'ConfigMap', $this->configMapName($project, $deployment->version)];
        }

        $targets[] = $trigger === 'schedule'
            ? ['serving.knative.dev/v1', 'Service', $this->serviceName($project)]
            : ['batch/v1', 'CronJob', $this->cronJobName($project)];

        // The workflow queue bridge reuses the queue consumer's name (KnativeWorkflowTrait)
        if ($trigger !== 'queue' && ! $this->workflowQueueBridgeEnabled($project)) {
            $targets[] = ['apps/v1', 'Deployment', $this->queueConsumerName($project)];
            $targets[] = ['v1', 'Secret', $this->queueConsumerSecretName($project)];
        }

        return $this->deleteFromRegion($project, $targets, ['deployment' => $deployment->id, 'superseded' => true]);
    }

    /**
     * Remove everything a project runs in its region, whatever the status of its deployments:
     * a pending or failed deployment (e.g. a revision that never became ready) still has a
     * Knative Service and pods. Used when the project goes away (delete, region detach).
     *
     * @return bool true when every delete succeeded (or there was nothing to delete)
     */
    public function teardownProject(Project $project): bool
    {
        $targets = [
            ['serving.knative.dev/v1', 'Service', $this->serviceName($project)],
            ['batch/v1', 'CronJob', $this->cronJobName($project)],
            ['apps/v1', 'Deployment', $this->queueConsumerName($project)],
            ['v1', 'Secret', $this->queueConsumerSecretName($project)],
            ['v1', 'Secret', $this->secretEnvName($project)],
        ];

        // Code ConfigMaps of every version (<slug>-v<N>) carry the project label
        return $this->deleteFromRegion($project, $targets, ['project_teardown' => true], true);
    }

    /**
     * Delete the given [apiVersion, kind, name] resources from the project's region namespace.
     * Every delete is attempted even if one fails; demo regions and regions without a
     * kubeconfig have nothing to delete.
     */
    private function deleteFromRegion(Project $project, array $targets, array $context, bool $withProjectConfigMaps = false): bool
    {
        $region = $project->knativeRegion;

        if (! $region || $region->is_demo || ! $region->kubeconfig) {
            return true;
        }

        $namespace = $this->getNamespace($region);
        $failed = [];

        try {
            $k8s = new KubernetesClient($region->kubeconfig);

            if ($withProjectConfigMaps) {
                $configMaps = $k8s->listResources('v1', 'ConfigMap', $namespace, ['runr/project-id' => $project->id]);
                foreach ($configMaps['items'] ?? [] as $cm) {
                    if (! empty($cm['metadata']['name'])) {
                        $targets[] = ['v1', 'ConfigMap', $cm['metadata']['name']];
                    }
                }
            }

            foreach ($targets as [$apiVersion, $kind, $name]) {
                try {
                    if (! $k8s->deleteResource($apiVersion, $kind, $name, $namespace)) {
                        $failed[] = "{$kind}/{$name}";
                    }
                } catch (\Throwable $e) {
                    $failed[] = "{$kind}/{$name}: {$e->getMessage()}";
                }
            }
        } catch (\Throwable $e) {
            $failed[] = $e->getMessage();
        }

        $context += ['project' => $project->slug, 'namespace' => $namespace];

        if ($failed) {
            Log::error('Teardown incomplete', $context + ['failed' => $failed]);

            return false;
        }

        Log::info('Teardown successful', $context + [
            'deleted' => array_map(fn ($t) => "{$t[1]}/{$t[2]}", $targets),
        ]);

        return true;
    }

    // ── File & config helpers ───────────────────────────────────────────────
    // KnativeManifestTrait and KnativeContainerTrait call these (see the trait's contract)

    /**
     * The version's code files for the code ConfigMap: read through the injected GitService —
     * the organization's repository, or the project remote for preview versions (RemoteRepoFiles).
     *
     * @return array<string, string>
     */
    private function getCodeFiles(Project $project, Version $version): array
    {
        if (! $version->commit_id) {
            return [];
        }

        return $this->git->getAllFilesAtVersion($project, $version->commit_id);
    }

    private function getSchedule(Project $project): string
    {
        return $this->getConfig($project)->schedule ?? '*/5 * * * *';
    }

    private function getTimeout(Project $project): int
    {
        return (int) ($this->getConfig($project)->timeout_seconds ?? 30);
    }

    private function getFilename(Project $project, object $config): string
    {
        return $this->entrypoint($project, $config)->filename;
    }

    private function getHandlerName(Project $project, object $config): string
    {
        return $this->entrypoint($project, $config)->handler;
    }

    /** Handler file + function (one rule for editor, build and deploy: ProjectEntrypoint). */
    private function entrypoint(Project $project, object $config): ProjectEntrypoint
    {
        return ProjectEntrypoint::for($project, app(RuntimeRegistry::class)->forProject($project), $config);
    }

    private function getConfig(Project $project): object
    {
        return match ($project->service_type) {
            'caas' => $project->containerConfig,
            'xaas' => $project->xaasConfig,
            'faas' => $project->faasConfig,
        };
    }

    private function getTriggerType(Project $project): string
    {
        $config = $this->getConfig($project);

        return $config->trigger_type ?? 'http';
    }

    /**
     * Get the target namespace from region.
     */
    private function getNamespace($region): string
    {
        if (! $region) {
            return 'runr';
        }

        return $region->project_namespace ?? $region->name;
    }

    // ── Naming helpers ──────────────────────────────────────────────────────

    /**
     * Names of the long-lived workloads a project runs in its region namespace.
     *
     * @return array{service: string, cronJob: string, queueConsumer: string}
     */
    public function workloadNames(Project $project): array
    {
        return [
            'service' => $this->serviceName($project),
            'cronJob' => $this->cronJobName($project),
            'queueConsumer' => $this->queueConsumerName($project),
        ];
    }

    private function serviceName(Project $project): string
    {
        return $project->slug;
    }

    private function configMapName(Project $project, Version $version): string
    {
        return $project->slug.'-v'.$version->number;
    }

    private function cronJobName(Project $project): string
    {
        return $project->slug.'-trigger';
    }

    /**
     * projects.deployed_runtime_class: the RuntimeClass of the workload just applied (the
     * Knative Service template or the CronJob pod), "runc" without one.
     *
     * @param  list<array>  $manifests
     */
    private function recordRuntimeClass(Project $project, array $manifests): void
    {
        foreach ($manifests as $manifest) {
            $podSpec = match (true) {
                ($manifest['kind'] ?? null) === 'Service' && str_starts_with((string) ($manifest['apiVersion'] ?? ''), 'serving.knative.dev/') => $manifest['spec']['template']['spec'] ?? null,
                ($manifest['kind'] ?? null) === 'CronJob' => $manifest['spec']['jobTemplate']['spec']['template']['spec'] ?? null,
                default => null,
            };
            if (! is_array($podSpec)) {
                continue;
            }

            $class = ResourceReservation::runtimeClassOfPodSpec($podSpec);
            if ($project->getAttribute('deployed_runtime_class') !== $class) {
                Project::whereKey($project->id)->update(['deployed_runtime_class' => $class]);
                $project->setAttribute('deployed_runtime_class', $class);
                $project->syncOriginalAttribute('deployed_runtime_class');
            }

            return;
        }
    }

    private function fail(Deployment $deployment, string $reason): void
    {
        $deployment->transition(['pending', 'running'], [
            'status' => 'failed',
            'knative_ready' => false,
            'knative_message' => mb_substr($reason, 0, 255),
        ]);
        Log::error('Deployment failed: '.$reason, ['deployment' => $deployment->id]);
    }

    /** The size the deployment is applied with (deployments.sizing; skipped before TenantMigrations/0062). */
    private function recordSizing(Project $project, Deployment $deployment): void
    {
        if (! SchemaColumns::has($deployment, 'sizing')) {
            return;
        }

        $sizing = WorkloadSizing::of($project, (array) ($deployment->deploy_config ?? []));
        Deployment::query()->whereKey($deployment->getKey())->update(['sizing' => json_encode($sizing)]);
        $deployment->setAttribute('sizing', $sizing);
        $deployment->syncOriginalAttribute('sizing');
    }

    /**
     * The deployment was stopped or shut down while its manifests were applied: whatever the apply
     * created goes again (the teardown that the stop queued may already have run). A newer live
     * deployment of the project keeps the resources it shares by name.
     */
    private function withdraw(Project $project, Deployment $deployment): void
    {
        Log::warning('Deploy withdrawn: the deployment was stopped while it was applied', [
            'project' => $project->slug, 'deployment' => $deployment->id, 'status' => $deployment->status,
        ]);

        // Still live (the project was stopped, its deployments not yet marked — a CronJob deploy
        // is already active): it ends here
        $deployment->transition(Deployment::LIVE, [
            'status' => 'failed',
            'knative_ready' => false,
            'knative_message' => 'The project was stopped while this deploy was applied',
        ]);

        $this->teardownEnded($project, $deployment);
    }

    /**
     * Remove what a deployment that ended applied: only what a newer live deployment of the project
     * does not apply again under the same names (teardownSuperseded), or everything when there is
     * none — a stopped project has none: nothing of it may keep serving, whatever a deploy that
     * raced the stop left live.
     *
     * @return bool true when every delete succeeded (404 = already gone)
     */
    public function teardownEnded(Project $project, Deployment $deployment): bool
    {
        $newer = ! $this->isStopped($project)
            && $project->deployments()->whereKeyNot($deployment->id)->whereIn('status', Deployment::LIVE)->exists();

        return $newer ? $this->teardownSuperseded($project, $deployment) : $this->teardown($project, $deployment);
    }

    /** Whether the project is stopped now (read from the database, not the copy in memory). */
    private function isStopped(Project $project): bool
    {
        return Project::query()->whereKey($project->getKey())->whereNotNull('stopped_at')->exists();
    }
}
