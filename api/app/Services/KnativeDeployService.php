<?php

namespace App\Services;

use App\Jobs\CheckDeploymentReadyJob;
use App\Jobs\Environments\ResumeEnvironmentDeployJob;
use App\Jobs\GlobalDns\SyncGlobalZoneJob;
use App\Mcp\Hosting\KnativeMcpServerTrait;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectRegion;
use App\Models\Version;
use App\Services\Concerns\KnativeManifestTrait;
use App\Services\Concerns\KnativeQueueConsumerTrait;
use App\Services\Environments\DeployTarget;
use App\Services\Environments\EnvironmentConfig;
use App\Services\Environments\EnvironmentScope;
use App\Services\Environments\RegionImages;
use App\Services\GlobalProjects\GlobalProjectRegions;
use App\Services\GlobalProjects\GlobalReplicaDeployer;
use App\Services\Helpers\ResourceReservation;
use App\Services\Helpers\WorkloadSizing;
use App\Services\InternalNetwork\InternalNetworkService;
use App\Services\Projects\LiveDeployments;
use App\Services\Projects\WarmScaleService;
use App\Services\Rollouts\RolloutService;
use App\Services\Runtimes\Patches\RuntimePatches;
use App\Services\Runtimes\ProjectEntrypoint;
use App\Services\Runtimes\RuntimeRegistry;
use App\Services\Secrets\SecretBackendException;
use App\Services\Secrets\SecretScope;
use App\Support\DeployGate;
use App\Support\OrgTimezone;
use App\Support\Projects\ScaleSchedule;
use App\Support\SchemaColumns;
use App\Triggers\Topics\KnativeTopicsTrait;
use App\Workflows\Deploy\KnativeWorkflowTrait;
use Illuminate\Support\Facades\Log;

class KnativeDeployService
{
    use KnativeManifestTrait;

    // Hosted MCP servers: RUNR_MCP_GATEWAY_KEY (docs/mcp.md)
    use KnativeMcpServerTrait;
    use KnativeQueueConsumerTrait;

    // Topics: RUNR_TOPICS_URL / RUNR_TOPICS_TOKEN and the region's publisher (docs/triggers.md)
    use KnativeTopicsTrait;
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
     *
     * The deployment decides what is built (DeployTarget): the project's own workload, or one of
     * its environments (docs/environments.md) — names, region, config layers and secrets — with
     * the config a rollback restored, and a CaaS project's version image. The effective config is
     * recorded on the deployment (config_snapshot) for a later rollback.
     *
     * An environment runs a built version from its region's registry: a version built into another
     * one is built there first (RegionImages, docs/environments.md#another-region) — the deployment
     * waits, pending, and this deploy runs again when the build ends (ResumeEnvironmentDeployJob;
     * $attempt counts the waits for a runtime image the region builds).
     */
    public function deploy(Project $project, Version $version, Deployment $deployment, ?string $initiatorId = null, int $attempt = 0): void
    {
        $current = Deployment::query()->find($deployment->getKey());
        if (! $current || ! in_array($current->status, ['pending', 'running'], true)) {
            Log::info('Deploy skipped: the deployment is no longer pending', [
                'project' => $project->slug, 'deployment' => $deployment->id, 'status' => $current?->status,
            ]);

            return;
        }
        $deployment->setRawAttributes($current->getAttributes(), true);
        $project = DeployTarget::project($project, $deployment->setRelation('version', $version));

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

        // Preflight: queue / topic triggers (and other Redis-backed ones) need the region's Redis
        $plan = $this->triggerPlan($project);
        if ($plan->requiresRedis() && ! $region->has_redis) {
            $this->fail($deployment, 'Queue trigger requires Redis on region: '.$region->name);

            return;
        }

        try {
            // An environment's image in its region's registry: ready, or built first (the deploy waits)
            if (EnvironmentScope::idOf($project) !== null) {
                [$state, $detail] = app(RegionImages::class)->prepare($project, $version, $deployment, $attempt);
                if ($state === 'waiting' || $state === 'retry') {
                    $deployment->transition(['pending', 'running'], ['status' => 'pending', 'knative_message' => mb_substr((string) $detail, 0, 255)]);
                    if ($state === 'retry') {
                        ResumeEnvironmentDeployJob::dispatch((string) $deployment->id, $attempt + 1)->delay(now()->addSeconds(RegionImages::WAIT_SECONDS));
                    }
                    Log::info('Deploy waits for its image in the region', ['project' => $project->slug, 'deployment' => $deployment->id, 'reason' => $detail]);

                    return;
                }
                if ($state === 'failed') {
                    $this->fail($deployment, (string) $detail);

                    return;
                }
                if ($state === 'ready') {
                    // the same version, from the region's registry (never saved)
                    $version = (clone $version)->forceFill(['build_image' => (string) $detail]);
                    $deployment->setRelation('version', $version);
                }
            }

            $k8s = new KubernetesClient($region->kubeconfig);
            $namespace = $this->getNamespace($region);

            // The runtime image it runs, recorded on the deployment: by digest of the patch it
            // was given (a migration's rollback), the project's pin or the newest patch
            $runtimeImage = app(RuntimePatches::class)->forDeployment($project, $version, $deployment);
            $manifests = $this->buildManifests($project, $version, $deployment->deploy_config ?? [], $runtimeImage);
            // Blue-green / canary: the new revision is named and the traffic stays with the one
            // that serves until the rollout moves it (docs/deploy-strategies.md)
            $manifests = app(RolloutService::class)->manifestsFor($project, $version, $deployment, $manifests, $k8s, $namespace);
            // Metering counts the deployment with the size it runs with (WorkloadSizing)
            $this->recordSizing($project, $deployment);
            // A rollback to this deployment restores the config it was applied with (docs/versioning.md)
            $this->recordConfigSnapshot($project, $deployment);

            // Secret variables (KnativeSecretEnvTrait): RunR's own keys next to the values in the
            // region, removed variables dropped, every value there — or the deploy stops here
            try {
                $this->prepareSecretEnv($project);
            } catch (SecretBackendException $e) {
                $this->fail($deployment, $e->getMessage());

                return;
            }

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

            // The topic publisher behind the RUNR_TOPICS_URL the workload just got
            $this->ensureTopicPublisher($project);

            $triggerType = $plan->runtimeTrigger();

            // Stopped (or shut down) while the manifests were applied: remove them again
            $stillWanted = $plan->cronJob()
                // CronJob has no Knative Service to check — active right away (unless replaced)
                ? app(LiveDeployments::class)->activate($deployment, ['knative_ready' => true, 'ready_at' => now()])
                : $deployment->transition(['pending', 'running'], ['status' => 'pending']);
            if (! $stillWanted || $this->isStopped($project)) {
                $this->withdraw($project, $deployment);

                return;
            }

            if (! $plan->cronJob()) {
                CheckDeploymentReadyJob::start($deployment, $project, $initiatorId);
            } elseif ($project->isGlobal()) {
                // a global project's CronJob is active at once: the other regions hold it on standby
                try {
                    app(GlobalReplicaDeployer::class)->afterPrimaryReady($project, $deployment);
                } catch (\Throwable $e) {
                    Log::warning('Global project rollout not started', ['project' => $project->slug, 'error' => $e->getMessage()]);
                }
            }

            // Internal network: the runr.internal route and the allowlist route of the public host
            // (a schedule trigger has no Knative Service: its routes go)
            try {
                $plan->cronJob()
                    ? app(InternalNetworkService::class)->removeRoutes($project, $k8s)
                    : app(InternalNetworkService::class)->applyRoutes($project, $k8s);
            } catch (\Throwable $e) {
                Log::warning('Internal network routes after deploy failed', ['project' => $project->slug, 'error' => $e->getMessage()]);
            }

            // Sync the gateway IngressRoute of the workload's gateway route: the project's own when
            // it uses the gateway endpoint, an environment's own route (docs/environments.md)
            if ($project->endpoint_type === 'gateway' || EnvironmentScope::idOf($project) !== null) {
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
     * The region side of the project's secret variables before something else reads them — a
     * preview deploy (PreviewService) reads the project's Secrets: what a deploy of the project
     * prepares and applies for them (KnativeSecretEnvTrait), also before its first deploy.
     *
     * @throws SecretBackendException
     * @throws \RuntimeException an object was not applied
     */
    public function prepareSecrets(Project $project): void
    {
        $this->prepareSecretEnv($project);

        $manifests = $this->secretEnvManifests($project);
        if ($manifests === []) {
            return;
        }
        $region = $project->knativeRegion;
        $k8s = new KubernetesClient((string) $region?->kubeconfig);
        foreach ($manifests as $manifest) {
            if (! $k8s->applyResource($manifest, $this->getNamespace($region))) {
                throw new \RuntimeException("Failed to apply {$manifest['kind']}: {$manifest['metadata']['name']}");
            }
        }
    }

    /**
     * Tear down a deployment from the cluster: the project's Knative Service, the deployment's
     * code ConfigMap, the CronJob (a schedule alone), the event gateway and the ExternalSecret of
     * its secret references. The values Secret stays (KnativeSecretEnvTrait): the region holds the
     * only copy of the values, the next deploy reads them.
     *
     * @return bool true when every delete succeeded (404 = already gone)
     */
    public function teardown(Project $project, Deployment $deployment): bool
    {
        $project = DeployTarget::project($project, $deployment);
        $targets = [
            ['serving.knative.dev/v1', 'Service', $this->serviceName($project)],
            ...$this->eventGatewayTargets($project),
            ...$this->secretEnvTeardownTargets($project, false),
            ...InternalNetworkService::routeTargets($project),
        ];

        if ($project->service_type !== 'caas' && $deployment->version) {
            $targets[] = ['v1', 'ConfigMap', $this->configMapName($project, $deployment->version)];
        }

        if ($this->getTriggerType($project) === 'schedule' || $this->triggerPlan($project)->cronJob()) {
            $targets[] = ['batch/v1', 'CronJob', $this->cronJobName($project)];
        }

        // a global project's copies in its other regions go too (docs/global-projects.md)
        return $this->deleteFromRegion($project, $targets, ['deployment' => $deployment->id], allRegions: true);
    }

    /**
     * The event gateway's resources: Deployment <slug>-qc, its Redis Secret (env-only form), its
     * gateway.json Secret and its Service (config form).
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function eventGatewayTargets(Project $project): array
    {
        return [
            ['apps/v1', 'Deployment', $this->queueConsumerName($project)],
            ['v1', 'Secret', $this->queueConsumerSecretName($project)],
            ['v1', 'Secret', $this->eventGatewayConfigName($project)],
            ['v1', 'Service', $this->queueConsumerName($project)],
        ];
    }

    /**
     * Tear down a deployment that a newer deploy of the same project replaced. The new deploy
     * applies the Knative Service, CronJob and event gateway under the same names, so only what
     * it does not use is removed: the old version's code ConfigMap (unless the new deployment
     * runs the same version) and the resources of triggers the project no longer uses.
     *
     * @return bool true when every delete succeeded (404 = already gone)
     */
    public function teardownSuperseded(Project $project, Deployment $deployment): bool
    {
        $project = DeployTarget::project($project, $deployment);
        // The triggers the replacing deployment applies (a rollback may restore another config)
        /** @var Deployment|null $replacement */
        $replacement = $project->deployments()->whereKeyNot($deployment->id)->whereIn('status', Deployment::LIVE)->first();
        $plan = $this->triggerPlan($replacement ? DeployTarget::project($project, $replacement) : $project);
        $targets = [];

        $versionStillLive = $project->deployments()
            ->whereKeyNot($deployment->id)
            ->where('version_id', $deployment->version_id)
            ->whereIn('status', ['pending', 'running', 'active'])
            ->exists();

        if ($project->service_type !== 'caas' && $deployment->version && ! $versionStillLive) {
            $targets[] = ['v1', 'ConfigMap', $this->configMapName($project, $deployment->version)];
        }

        $targets[] = $plan->cronJob()
            ? ['serving.knative.dev/v1', 'Service', $this->serviceName($project)]
            : ['batch/v1', 'CronJob', $this->cronJobName($project)];
        if ($plan->cronJob()) {
            array_push($targets, ...InternalNetworkService::routeTargets($project));
        }

        // The workflow queue bridge reuses the queue consumer's name (KnativeWorkflowTrait); a
        // gateway keeps what its form uses and loses the other form's leftovers
        if ($plan->gateway() && $plan->legacyQueue() === null) {
            $targets[] = ['v1', 'Secret', $this->queueConsumerSecretName($project)];
        } elseif ($plan->gateway() || $this->workflowQueueBridgeEnabled($project)) {
            $targets[] = ['v1', 'Secret', $this->eventGatewayConfigName($project)];
            $targets[] = ['v1', 'Service', $this->queueConsumerName($project)];
        } else {
            array_push($targets, ...$this->eventGatewayTargets($project));
        }

        return $this->deleteFromRegion($project, $targets, ['deployment' => $deployment->id, 'superseded' => true]);
    }

    /**
     * Remove everything a project runs in its region, whatever the status of its deployments:
     * a pending or failed deployment (e.g. a revision that never became ready) still has a
     * Knative Service and pods. Used when the project goes away (delete, region detach) and when a
     * migration rollback does not deploy it again.
     *
     * @param  bool  $withSecretValues  the values Secret of its secret variables goes too — only
     *                                  when the project goes away: the region holds the only copy
     * @return bool true when every delete succeeded (or there was nothing to delete)
     */
    public function teardownProject(Project $project, bool $withSecretValues = false): bool
    {
        $targets = [
            ['serving.knative.dev/v1', 'Service', $this->serviceName($project)],
            ['batch/v1', 'CronJob', $this->cronJobName($project)],
            ...$this->eventGatewayTargets($project),
            ...$this->secretEnvTeardownTargets($project, $withSecretValues),
            ...InternalNetworkService::routeTargets($project),
        ];

        // Code ConfigMaps of every version (<slug>-v<N>) carry the project label; a global project's
        // copies in its other regions go too
        $removed = $this->deleteFromRegion($project, $targets, ['project_teardown' => true], true, allRegions: true);

        // The project goes: its values in the region's OpenBao too (SecretBackend::purge())
        if ($withSecretValues && $project->knativeRegion && ! $project->knativeRegion->is_demo && $project->knativeRegion->kubeconfig) {
            $removed = $this->purgeSecretValues($project) && $removed;
        }

        return $removed;
    }

    /**
     * Remove everything an environment of a project runs (docs/environments.md), whatever the
     * status of its deployments — the environment is deleted, or its project. $project: the
     * environment's view (EnvironmentProject): its names, its region. Its code ConfigMaps are found
     * by runr/environment-id (the project's own carry runr/project-id too, and stay).
     *
     * @return bool true when every delete succeeded (or there was nothing to delete)
     */
    public function teardownEnvironment(Project $project): bool
    {
        $environmentId = EnvironmentScope::idOf($project);
        if ($environmentId === null) {
            return $this->teardownProject($project);
        }

        $targets = [
            ['serving.knative.dev/v1', 'Service', $this->serviceName($project)],
            ['batch/v1', 'CronJob', $this->cronJobName($project)],
            ['apps/v1', 'Deployment', $this->queueConsumerName($project)],
            ['v1', 'Secret', $this->queueConsumerSecretName($project)],
            // its values Secret (its own values and the inherited copies) and the ExternalSecrets of
            // its references; the project's values elsewhere (its Secret, its OpenBao path) stay
            ...$this->secretEnvTeardownTargets($project, true),
        ];

        return $this->deleteFromRegion($project, $targets, ['environment_teardown' => $environmentId], false, [EnvironmentScope::LABEL_ENVIRONMENT_ID => $environmentId]);
    }

    /**
     * Delete the given [apiVersion, kind, name] resources from the project's region namespace —
     * with $allRegions, from every region of a global project (GlobalProjectRegions), whose copies
     * then serve nothing until the next rollout. Every delete is attempted even if one fails; demo
     * regions and regions without a kubeconfig have nothing to delete.
     */
    private function deleteFromRegion(Project $project, array $targets, array $context, bool $withProjectConfigMaps = false, array $configMapLabels = [], bool $allRegions = false): bool
    {
        if ($withProjectConfigMaps) {
            $configMapLabels = ['runr/project-id' => $project->id];
        }

        $regions = $allRegions ? GlobalProjectRegions::of($project) : array_filter([$project->knativeRegion]);
        $ok = true;

        foreach ($regions as $region) {
            $ok = $this->deleteFromOneRegion($project, $region, $targets, $context, $configMapLabels) && $ok;
        }

        if ($allRegions && $project->isGlobal() && GlobalProjectRegions::tableReady()) {
            ProjectRegion::query()->where('project_id', $project->getKey())->where('status', '!=', ProjectRegion::STATUS_REMOVING)->update([
                'served_version_id' => null,
                'target_version_id' => null,
                'rollout_token' => null,
                'status' => ProjectRegion::STATUS_PENDING,
                'status_message' => null,
            ]);
            SyncGlobalZoneJob::dispatch();
        }

        return $ok;
    }

    /** @param  array<string, string>  $configMapLabels  ConfigMaps to delete too, by label (code of every version) */
    private function deleteFromOneRegion(Project $project, ?KnativeRegion $region, array $targets, array $context, array $configMapLabels): bool
    {
        if (! $region || $region->is_demo || ! $region->kubeconfig) {
            return true;
        }

        $namespace = $this->getNamespace($region);
        $failed = [];

        try {
            $k8s = new KubernetesClient($region->kubeconfig);

            if ($configMapLabels !== []) {
                $configMaps = $k8s->listResources('v1', 'ConfigMap', $namespace, $configMapLabels);
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
        if ($project->isGlobal()) {
            $context['region'] = $region->name;
        }

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
     * Names of the long-lived workloads a project runs in its region namespace, and of the
     * Secrets they read: its secret environment variables (the values RunR wrote; the references
     * External Secrets Operator syncs — an ExternalSecret and the Secret it fills share the name)
     * and the queue consumer's Redis access.
     *
     * @return array{service: string, cronJob: string, queueConsumer: string, secretEnv: string, externalEnv: string, openBaoEnv: string, queueConsumerSecret: string, eventGatewayConfig: string}
     */
    public function workloadNames(Project $project): array
    {
        return [
            'service' => $this->serviceName($project),
            'cronJob' => $this->cronJobName($project),
            'queueConsumer' => $this->queueConsumerName($project),
            'secretEnv' => $this->secretEnvName($project),
            'externalEnv' => $this->externalEnvName($project),
            'openBaoEnv' => SecretScope::openBaoEnvName((string) $project->slug),
            'queueConsumerSecret' => $this->queueConsumerSecretName($project),
            'eventGatewayConfig' => $this->eventGatewayConfigName($project),
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
                // An environment's workload runs in its own region: it keeps its own record
                $environment = EnvironmentScope::of($project);
                $environment !== null
                    ? $environment->newQuery()->whereKey($environment->getKey())->update(['deployed_runtime_class' => $class])
                    : Project::whereKey($project->id)->update(['deployed_runtime_class' => $class]);
                $environment?->setAttribute('deployed_runtime_class', $class);
                $project->setAttribute('deployed_runtime_class', $class);
                $project->syncOriginalAttribute('deployed_runtime_class');
            }

            return;
        }
    }

    private function fail(Deployment $deployment, string $reason): void
    {
        $failed = $deployment->transition(['pending', 'running'], [
            'status' => 'failed',
            'knative_ready' => false,
            'knative_message' => mb_substr($reason, 0, 255),
        ]);
        Log::error('Deployment failed: '.$reason, ['deployment' => $deployment->id]);

        // A rollout's new deployment: its rollout fails, the traffic stays where it was
        if ($failed) {
            app(RolloutService::class)->candidateFailed($deployment, $reason);
        }
    }

    /**
     * The size the deployment is applied with (deployments.sizing; skipped before
     * TenantMigrations/0062). With a timed warmer the min-scale the template got starts its
     * warm_log (WarmScaleService: metering counts the warm containers from it).
     */
    private function recordSizing(Project $project, Deployment $deployment): void
    {
        if (! SchemaColumns::has($deployment, 'sizing')) {
            return;
        }

        $sizing = WorkloadSizing::of($project, (array) ($deployment->deploy_config ?? []));
        $schedule = ScaleSchedule::rows(ResourceReservation::configOf($project));
        if ($schedule !== null && ResourceReservation::runsKnativeService($sizing['trigger_type'])) {
            $sizing['warm_log'] = [[now()->getTimestamp(), WarmScaleService::renderedValue($sizing, $schedule, now(), OrgTimezone::get())]];
        }
        Deployment::query()->whereKey($deployment->getKey())->update(['sizing' => json_encode($sizing)]);
        $deployment->setAttribute('sizing', $sizing);
        $deployment->syncOriginalAttribute('sizing');
    }

    /**
     * The effective config the deployment is applied with (deployments.config_snapshot, encrypted;
     * skipped before TenantMigrations/0080). A restored deployment runs the snapshot it was created
     * with (the view's config): it records the same fields, with the secret and binding names it
     * gets now.
     */
    private function recordConfigSnapshot(Project $project, Deployment $deployment): void
    {
        if (! SchemaColumns::has($deployment, 'config_snapshot')) {
            return;
        }

        $deployment->forceFill([
            'config_snapshot' => EnvironmentConfig::snapshot($project),
            'config_source' => $deployment->getAttribute('config_source') ?? Deployment::CONFIG_CURRENT,
        ]);
        $stored = $deployment->getAttributes();
        Deployment::query()->whereKey($deployment->getKey())->update([
            'config_snapshot' => $stored['config_snapshot'],
            'config_source' => $stored['config_source'],
        ]);
        $deployment->syncOriginalAttributes(['config_snapshot', 'config_source']);
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
        $project = DeployTarget::project($project, $deployment);
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
