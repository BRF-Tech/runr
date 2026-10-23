<?php

namespace App\Services\GlobalProjects;

use App\Jobs\GlobalDns\SyncGlobalZoneJob;
use App\Jobs\GlobalProjects\CopyGlobalImageJob;
use App\Jobs\GlobalProjects\RolloutGlobalReplicaJob;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectRegion;
use App\Models\Version;
use App\Models\VersionRegionImage;
use App\Services\Bindings\BindingEnv;
use App\Services\Bindings\BindingManifests;
use App\Services\Environments\RegionImages;
use App\Services\GlobalDns\GlobalRegionSetup;
use App\Services\GlobalProjects\Data\RegionalData;
use App\Services\KnativeDeployService;
use App\Services\KubernetesClient;
use App\Services\Manifests\BuildManifests;
use App\Services\RegistryImageLifecycleService;
use App\Services\Runtimes\RuntimeImagePendingException;
use App\Services\Secrets\ProjectSecrets;
use App\Services\Secrets\SecretBackends;
use App\Services\Secrets\SecretScope;
use App\Support\DeployGate;
use App\Triggers\ProjectTriggers;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Rolls a global project's version out to its other regions (docs/global-projects.md, "Deploys").
 *
 * A deploy of a global project is a deploy of its primary region, as for a regional project. When
 * that deployment is ready (CheckDeploymentReadyJob → afterPrimaryReady()), its version goes to
 * every other region, one RolloutGlobalReplicaJob per region:
 *
 *   building   the version has a build image (the primary's registry): the same build runs in the
 *              region (into the region's registry, FunctionBuildService::submitReplicaBuild), kept
 *              in the store environments use too (RegionImages, version_region_images) — one build
 *              per version and region; nothing has to reach one region's registry from another. A
 *              version without a build image (code in a ConfigMap) skips it; an uploaded CaaS
 *              image is copied instead (GlobalImageCopy)
 *   deploying  the deploy's manifests, built for the region (KnativeDeployService::buildManifests
 *              with the project's region set to it), applied; then the Knative Service is polled
 *              until the new revision is ready
 *   ready      the region serves the version: its global routes are applied, the code ConfigMaps of
 *              other versions go, the zone is synced
 *
 * A region keeps serving the version before while it rolls a new one out (the Knative Service
 * keeps its last ready revision), so the zone keeps it in the answers. A failed rollout leaves the
 * region on that version, failed, with the reason; POST /projects/{project}/global/sync tries again.
 * The primary goes first so that a version that does not start never leaves it.
 */
class GlobalReplicaDeployer
{
    public const POLL_SECONDS = 5;

    /** Seconds a revision has to become ready in a region. */
    public const READY_TIMEOUT_SECONDS = 600;

    public function __construct(
        private readonly KnativeDeployService $deployer,
        private readonly RegionImages $images,
    ) {}

    /**
     * The primary's deployment became active: the primary's row serves its version, the primary
     * gets its global routes, every other region the version.
     */
    public function afterPrimaryReady(Project $project, Deployment $deployment): void
    {
        if (! $project->isGlobal() || ! GlobalProjectRegions::tableReady()) {
            return;
        }
        // an environment of the project runs in its own region (docs/environments.md): only the
        // project's own workload is global
        $environment = $deployment->getAttribute('environment_id');
        if ($environment !== null && $environment !== '') {
            return;
        }

        $region = $project->knativeRegion;
        $primary = GlobalProjectRegions::primary($project) ?? ProjectRegion::query()->create([
            'project_id' => $project->getKey(),
            'knative_region_id' => (string) $project->knative_region_id,
            'position' => 0,
        ]);
        $primary->update([
            'status' => ProjectRegion::STATUS_READY,
            'status_message' => null,
            'served_version_id' => $deployment->version_id,
            'target_version_id' => null,
            'deployment_id' => $deployment->getKey(),
            'build_image' => $deployment->version->build_image ?? null,
            'rollout_token' => null,
            'ready_at' => now(),
            // the primary runs the triggers (GlobalTriggers)
            'triggers_active' => true,
        ]);

        if ($region) {
            try {
                app(GlobalRouteService::class)->apply($project, $region);
            } catch (Throwable $e) {
                Log::warning('Global routes of the primary region not applied', ['project' => $project->slug, 'error' => $e->getMessage()]);
                $primary->update(['status_message' => mb_substr($e->getMessage(), 0, 2000)]);
            }
        }

        foreach (GlobalProjectRegions::secondaries($project) as $row) {
            // a fenced region gets the version when it rejoins (GlobalFailover::rejoin)
            if (! in_array($row->status, [ProjectRegion::STATUS_REMOVING, ProjectRegion::STATUS_FENCED], true)) {
                $this->startRollout($project, $row, $deployment);
            }
        }

        SyncGlobalZoneJob::dispatch();
    }

    /** Roll the deployment's version out to one region (a rollout running there is replaced). */
    public function startRollout(Project $project, ProjectRegion $row, Deployment $deployment): void
    {
        $version = $deployment->version_id ? Version::query()->find($deployment->version_id) : null;
        if ($version === null) {
            return;
        }

        $token = Str::random(32);
        // building: the version's build into the region's registry, or — an uploaded CaaS image —
        // its copy into that registry (GlobalImageCopy)
        $prepare = $this->needsBuild($project, $version) || $this->needsImageCopy($project, $row);
        $row->update([
            'target_version_id' => $version->getKey(),
            'deployment_id' => $deployment->getKey(),
            'rollout_token' => $token,
            'attempts' => 0,
            'status' => $prepare ? ProjectRegion::STATUS_BUILDING : ProjectRegion::STATUS_DEPLOYING,
            'status_message' => null,
            'build_image' => $prepare || $project->service_type === 'caas' ? null : $row->build_image,
        ]);

        RolloutGlobalReplicaJob::dispatch($project, (string) $row->getKey(), $token);
    }

    /**
     * One step of a region's rollout. Returns the seconds until the next step, or null when the
     * rollout ended (ready, failed, replaced by a newer one, or no longer wanted).
     */
    public function step(Project $project, ProjectRegion $row, string $token): ?int
    {
        if ($row->rollout_token !== $token || ! in_array($row->status, ProjectRegion::ROLLING, true)) {
            return null;
        }

        $deployment = $row->deployment_id ? Deployment::query()->find($row->deployment_id) : null;
        $version = $row->target_version_id ? Version::query()->find($row->target_version_id) : null;
        if (! $deployment || ! $version || $deployment->status !== 'active' || $project->isStopped()) {
            // replaced by a newer deploy (its own rollout follows) or the project was stopped
            $row->update(['status' => $row->served_version_id ? ProjectRegion::STATUS_READY : ProjectRegion::STATUS_PENDING, 'target_version_id' => null, 'rollout_token' => null]);

            return null;
        }

        $region = $row->knativeRegion;
        if (! $region || $region->is_demo || ! $region->kubeconfig) {
            return $this->fail($row, __('api.global.region_unavailable'));
        }
        if (! $region->is_active) {
            return $this->fail($row, __('api.global.region_inactive', ['region' => $region->name]));
        }
        if ($blocked = DeployGate::blockedReason()) {
            return $this->fail($row, $blocked);
        }
        // a trigger that reads a stream may run here after a switchover or a failover
        if (! $region->has_redis && app(ProjectTriggers::class)->plan($project)->requiresRedis()) {
            return $this->fail($row, __('api.global.region_needs_redis', ['region' => $region->name]));
        }

        $row->increment('attempts');

        try {
            return $row->status === ProjectRegion::STATUS_BUILDING
                ? $this->build($project, $row, $version, $region)
                : $this->deploy($project, $row, $deployment, $version, $region);
        } catch (RuntimeImagePendingException) {
            // the runtime version is built from source in this region first: wait for it
            return self::POLL_SECONDS * 2;
        } catch (Throwable $e) {
            Log::warning('Global project rollout step failed', ['project' => $project->slug, 'region' => $region->name, 'error' => $e->getMessage()]);

            return $this->fail($row, $e->getMessage());
        }
    }

    private function build(Project $project, ProjectRegion $row, Version $version, KnativeRegion $region): ?int
    {
        // an uploaded CaaS image: its copy into the region's registry (CopyGlobalImageJob)
        if ($project->service_type === 'caas') {
            if ($row->build_image !== null) {
                $row->update(['status' => ProjectRegion::STATUS_DEPLOYING, 'attempts' => 0]);

                return 1;
            }
            if ($row->attempts <= 1) {
                CopyGlobalImageJob::dispatch($project, (string) $row->getKey(), (string) $row->rollout_token);
                $row->update(['status_message' => __('api.global.image_copying', ['region' => $region->name])]);
            }
            if ($row->attempts * self::POLL_SECONDS > (int) config('global_dns.image_copy_timeout', 3600)) {
                return $this->fail($row, __('api.global.image_copy_failed', ['region' => $region->name, 'reason' => 'timeout']));
            }

            return self::POLL_SECONDS;
        }

        // the version built into the region's registry: the store environments use too
        // (version_region_images) — one build per version and region, whoever asks first
        [$state, $value] = $this->images->forGlobalCopy($project, $version, $region, $row->created_at === null ? null : $this->rolloutStart($row));
        if ($state === 'ready') {
            $row->update(['build_image' => $value, 'status' => ProjectRegion::STATUS_DEPLOYING, 'attempts' => 0, 'status_message' => null]);

            return 1;
        }
        if ($state === 'failed') {
            return $this->fail($row, (string) $value);
        }
        if ($row->attempts * self::POLL_SECONDS > BuildManifests::deadlineSeconds() + 600) {
            return $this->fail($row, __('api.global.build_timeout', ['region' => $region->name]));
        }

        return self::POLL_SECONDS;
    }

    /** When the region's rollout began: its build failed before is retried, after is its own. */
    private function rolloutStart(ProjectRegion $row): \DateTimeInterface
    {
        return now()->subSeconds(max(0, $row->attempts) * self::POLL_SECONDS + self::POLL_SECONDS);
    }

    private function deploy(Project $project, ProjectRegion $row, Deployment $deployment, Version $version, KnativeRegion $region): ?int
    {
        $k8s = new KubernetesClient((string) $region->kubeconfig);
        $namespace = GlobalRegionSetup::projectNamespace($region);

        if ($row->attempts <= 1) {
            $this->apply($project, $version, $deployment, $region, $row, $k8s);

            return self::POLL_SECONDS;
        }

        // a project whose only trigger is a schedule runs its CronJob, no Knative Service: the
        // region holds it (suspended — GlobalTriggers) once it is applied
        $cronOnly = app(ProjectTriggers::class)->plan($project)->cronJob();
        $service = $cronOnly ? null : $k8s->getResourceStatus('serving.knative.dev/v1', 'Service', (string) $project->slug, $namespace);
        $ready = $cronOnly
            ? $k8s->getResourceStatus('batch/v1', 'CronJob', GlobalTriggers::cronJobName($project), $namespace) !== null
            : self::revisionReady($service, (string) $version->number);
        if ($ready) {
            $primary = (string) $region->getKey() === (string) $project->knative_region_id;
            $row->update([
                'status' => ProjectRegion::STATUS_READY,
                'status_message' => null,
                'served_version_id' => $version->getKey(),
                'target_version_id' => null,
                'rollout_token' => null,
                'attempts' => 0,
                'ready_at' => now(),
                'triggers_active' => $primary,
            ]);

            if (! $cronOnly) {
                app(GlobalRouteService::class)->apply($project, $region, $k8s);
                // the project's custom domains answer in this region too (GlobalDomains)
                app(GlobalDomains::class)->applyRegion($project, $region, $k8s);
            }
            $this->removeOtherVersions($project, $version, $namespace, $k8s);
            SyncGlobalZoneJob::dispatch();

            return null;
        }

        if ($row->attempts * self::POLL_SECONDS > self::READY_TIMEOUT_SECONDS) {
            $message = $this->readyMessage($service);

            return $this->fail($row, __('api.global.not_ready', ['region' => $region->name, 'reason' => $message]));
        }

        return self::POLL_SECONDS;
    }

    /**
     * The deploy's manifests for the region, applied there — the same Knative Service (name, env,
     * secrets, size) as in the primary, with the region's own image and settings.
     */
    public function apply(Project $project, Version $version, Deployment $deployment, KnativeRegion $region, ProjectRegion $row, ?KubernetesClient $k8s = null): void
    {
        $k8s ??= new KubernetesClient((string) $region->kubeconfig);
        $namespace = GlobalRegionSetup::projectNamespace($region);

        $regional = (clone $project)->setRelation('knativeRegion', $region);
        $regional->setAttribute('knative_region_id', $region->getKey());
        $regionalVersion = clone $version;
        if ($this->needsBuild($project, $version)) {
            $regionalVersion->setAttribute('build_image', $row->build_image);
        }

        // the bindings' env as this region sees it (docs/global-projects.md, "Data"): external
        // bindings as they are, managed ones through their data link, a dedicated Postgres
        // binding's replica of this region for reads
        if (BindingEnv::hasAttachments($project)) {
            $env = BindingEnv::projectEnv($regional);
            if ($env !== []) {
                $k8s->applyResource(BindingManifests::projectSecret($regional, $namespace, $env), $namespace);
            }
        }

        // Secret variables (docs/global-projects.md#secrets): the values the primary region holds,
        // copied into this one, then what a deploy prepares there — RunR's own keys of the workload
        // (its internal network token signed for this region), the secret backends' objects. The
        // Knative Service reads them from the region's Secrets: without them it never starts.
        $primary = $project->knativeRegion;
        if ($primary && (string) $primary->getKey() !== (string) $region->getKey()) {
            app(ProjectSecrets::class)->copyToReplica($project, $primary, $region);
        }
        $this->deployer->prepareSecrets($regional);

        $manifests = $this->deployer->buildManifests($regional, $regionalVersion, (array) ($deployment->deploy_config ?? []));
        // an uploaded image runs from this region's registry (its copy, GlobalImageCopy)
        if ($row->build_image && $project->service_type === 'caas') {
            $manifests = GlobalImageCopy::withImage($manifests, $project, (string) $row->build_image);
        }
        // the triggers run in the primary only: the CronJob suspended, the event gateway at zero
        // (GlobalTriggers; a switchover or a failover flips them)
        if ((string) $region->getKey() !== (string) $project->knative_region_id) {
            $manifests = GlobalTriggers::standbyManifests($manifests, $project);
        }

        foreach ($manifests as $manifest) {
            if ($manifest['kind'] === 'Deployment') {
                $k8s->deleteResource('apps/v1', 'Deployment', $manifest['metadata']['name'], $namespace);
            }
            if (! $k8s->applyResource($manifest, $namespace)) {
                throw new \RuntimeException("{$manifest['kind']} {$manifest['metadata']['name']} could not be applied in {$region->name}");
            }
        }
    }

    /**
     * Remove a region's copy (the region left the project): its workloads, Secrets, code
     * ConfigMaps, routes and build images there, then its row.
     */
    public function removeRegion(Project $project, ProjectRegion $row): bool
    {
        $region = $row->knativeRegion;
        $ok = true;

        if ($region && ! $region->is_demo && $region->kubeconfig) {
            try {
                $k8s = new KubernetesClient((string) $region->kubeconfig);
                $namespace = GlobalRegionSetup::projectNamespace($region);
                $names = $this->deployer->workloadNames($project);

                foreach ([
                    ['serving.knative.dev/v1', 'Service', $names['service']],
                    ['v1', 'Secret', $names['secretEnv']],
                    ['v1', 'Secret', BindingEnv::secretName($project)],
                    // the trigger workloads on standby there (GlobalTriggers)
                    ['batch/v1', 'CronJob', $names['cronJob']],
                    ['apps/v1', 'Deployment', $names['queueConsumer']],
                    ['v1', 'Secret', $names['queueConsumerSecret']],
                    ['v1', 'Secret', $names['eventGatewayConfig']],
                    ['v1', 'Service', $names['queueConsumer']],
                ] as [$apiVersion, $kind, $name]) {
                    $ok = $k8s->deleteResource($apiVersion, $kind, $name, $namespace) && $ok;
                }
                foreach ($k8s->listResources('v1', 'ConfigMap', $namespace, ['runr/project-id' => (string) $project->getKey()])['items'] ?? [] as $configMap) {
                    $ok = $k8s->deleteResource('v1', 'ConfigMap', (string) $configMap['metadata']['name'], $namespace) && $ok;
                }

                $ok = app(GlobalRouteService::class)->remove($project, $region, $k8s) && $ok;
                // its custom domain routes and certificates there (GlobalDomains)
                $ok = app(GlobalDomains::class)->removeRegion($project, $region, $k8s) && $ok;
                $this->forgetRegionImages($project, $region);
                // the copies of its secret values the region keeps outside that Secret (its OpenBao)
                $scope = SecretScope::forProject($project, $region);
                foreach (app(SecretBackends::class)->all() as $backend) {
                    $backend->purge($scope);
                }
            } catch (Throwable $e) {
                Log::warning('Global project region not fully removed', ['project' => $project->slug, 'region' => $region->name, 'error' => $e->getMessage()]);
                $ok = false;
            }
        }

        if ($ok) {
            $row->delete();
            // the bindings' data links and replica clusters leave the region with the copy
            try {
                app(RegionalData::class)->syncProject($project);
            } catch (Throwable $e) {
                Log::warning('Regional data of a removed region not synced', ['project' => $project->slug, 'error' => $e->getMessage()]);
            }
        } else {
            $row->update(['status_message' => __('api.global.remove_incomplete')]);
        }

        SyncGlobalZoneJob::dispatch();

        return $ok;
    }

    /**
     * The project's builds in a region it leaves: every one when no environment of the project
     * runs there (its images and their rows in the store), else those no deployment runs.
     */
    private function forgetRegionImages(Project $project, KnativeRegion $region): void
    {
        $regionId = (string) $region->getKey();
        $environmentThere = ProjectEnvironment::tablesReady() && ProjectEnvironment::query()
            ->where('project_id', $project->getKey())->where('is_primary', false)->get()
            ->contains(fn (ProjectEnvironment $e) => $e->setRelation('project', $project)->regionId() === $regionId);

        if ($environmentThere) {
            $this->images->forgetProject($project, $regionId);

            return;
        }

        app(RegistryImageLifecycleService::class)->deleteAllForProject($region, $project);
        if (RegionImages::tableReady()) {
            VersionRegionImage::query()->where('project_id', $project->getKey())->where('knative_region_id', $regionId)->delete();
        }
    }

    /** The version has a build image of its own: every region builds it into its registry. */
    public function needsBuild(Project $project, Version $version): bool
    {
        return $project->service_type !== 'caas' && $version->build_image !== null && $version->build_image !== '';
    }

    /** The project runs an uploaded image of its primary's registry: the region gets a copy. */
    public function needsImageCopy(Project $project, ProjectRegion $row): bool
    {
        $region = $row->knativeRegion;

        return $project->service_type === 'caas' && $region !== null && app(GlobalImageCopy::class)->needed($project, $region);
    }

    /**
     * The Knative Service's latest revision is ready, created from the current spec, and runs
     * $versionNumber.
     */
    public static function revisionReady(?array $service, string $versionNumber): bool
    {
        if (! $service) {
            return false;
        }

        $ready = false;
        foreach ($service['status']['conditions'] ?? [] as $condition) {
            if (($condition['type'] ?? '') === 'Ready') {
                $ready = ($condition['status'] ?? '') === 'True';
            }
        }

        $generation = (int) ($service['metadata']['generation'] ?? 0);
        $observed = (int) ($service['status']['observedGeneration'] ?? -1);
        $created = $service['status']['latestCreatedRevisionName'] ?? null;
        $readyRevision = $service['status']['latestReadyRevisionName'] ?? null;
        $templateVersion = (string) ($service['spec']['template']['metadata']['labels']['runr/version'] ?? '');

        return $ready && $observed >= $generation && $created !== null && $created === $readyRevision && $templateVersion === $versionNumber;
    }

    private function readyMessage(?array $service): string
    {
        foreach ($service['status']['conditions'] ?? [] as $condition) {
            if (($condition['type'] ?? '') === 'Ready') {
                return (string) ($condition['message'] ?? $condition['reason'] ?? 'not ready');
            }
        }

        return $service ? 'not ready' : 'Knative Service not found';
    }

    /** Code ConfigMaps of the project's other versions in the region (its own build ConfigMaps stay). */
    private function removeOtherVersions(Project $project, Version $version, string $namespace, KubernetesClient $k8s): void
    {
        try {
            foreach ($k8s->listResources('v1', 'ConfigMap', $namespace, ['runr/project-id' => (string) $project->getKey()])['items'] ?? [] as $configMap) {
                $labels = (array) ($configMap['metadata']['labels'] ?? []);
                if (isset($labels['runr/build']) || ! isset($labels['runr/version']) || (string) $labels['runr/version'] === (string) $version->number) {
                    continue;
                }
                $k8s->deleteResource('v1', 'ConfigMap', (string) $configMap['metadata']['name'], $namespace);
            }
        } catch (Throwable $e) {
            Log::info('Old code ConfigMaps of a global project region not removed', ['project' => $project->slug, 'error' => $e->getMessage()]);
        }
    }

    private function fail(ProjectRegion $row, string $message): null
    {
        $row->update([
            'status' => ProjectRegion::STATUS_FAILED,
            'status_message' => mb_substr($message, 0, 2000),
            'rollout_token' => null,
        ]);

        return null;
    }
}
