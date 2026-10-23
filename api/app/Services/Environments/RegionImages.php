<?php

namespace App\Services\Environments;

use App\Jobs\Environments\CheckRegionImageBuildJob;
use App\Jobs\Environments\ResumeEnvironmentDeployJob;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\Version;
use App\Models\VersionRegionImage;
use App\Services\FunctionBuildService;
use App\Services\GitDeploy\GitTables;
use App\Services\KubernetesClient;
use App\Services\Manifests\BuildManifests;
use App\Services\Manifests\RegistryImageManifests;
use App\Services\RegistryImageLifecycleService;
use App\Services\Runtimes\RuntimeImagePendingException;
use App\Services\Runtimes\RuntimeRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The image an environment runs a built version from (docs/environments.md#another-region).
 *
 * A version built into a registry (dependencies, a compile step, WebAssembly) runs from its build
 * image, which only the nodes of the regions that pull from that registry can start. An
 * environment in another region — or in a region that moved to a cluster with a registry of its
 * own — runs the same version built into its region's registry: the version's code, built again
 * there FROM the same runtime base (FunctionBuildService::submitReplicaBuild, the build global
 * projects run in their other regions), kept in version_region_images per version, region and
 * registry so that every later deploy and promotion of the version into that region runs it
 * without building again.
 *
 * The deployment waits for it, pending (KnativeDeployService::deploy → prepare()): its message
 * says what it waits for (WAITING), the environment's running deployment keeps serving
 * (DeploymentStarter keeps it live), and the deploy goes on when the build ends
 * (CheckRegionImageBuildJob → ResumeEnvironmentDeployJob) — or the deployment fails with the
 * build's error. A version that is being built again into the region's registry itself (a
 * region move, a rebuild for the project's own workload) is waited for the same way
 * (versionBuilt()). A version without a build of its own whose runtime image RunR still builds in
 * the environment's region waits for that image (WAIT_SECONDS × WAIT_ATTEMPTS).
 *
 * The project's own workload (production) is never served from here: a version is built again
 * into its own region's registry (VersionRebuilds).
 */
class RegionImages
{
    /** knative_message prefix of a deployment that waits for its image (resumed by its build's end) */
    public const WAITING = 'Waits for ';

    /** Seconds between two checks of a build Job */
    public const POLL_SECONDS = 5;

    /** A runtime image still building in the region: checked again every WAIT_SECONDS, WAIT_ATTEMPTS times */
    public const WAIT_SECONDS = 10;

    public const WAIT_ATTEMPTS = 100;

    public function __construct(
        private FunctionBuildService $builder,
        private RuntimeRegistry $runtimes,
    ) {}

    public static function tableReady(): bool
    {
        return GitTables::present(RegionImageSchema::TABLE);
    }

    /**
     * Whether a deployment of $version to the environment $target has to wait for an image built
     * into its region's registry (none is ready there yet). False for the project's own workload,
     * CaaS projects and versions without a build of their own.
     */
    public function waits(Project $target, Version $version): bool
    {
        $registry = $this->registryOf($target);
        if ($registry === null || ! $version->build_image) {
            return false;
        }
        if ($version->build_status === 'ready' && str_starts_with((string) $version->build_image, $registry.'/')) {
            return false;
        }

        return $this->readyRow($version, (string) $target->knative_region_id, $registry) === null;
    }

    /**
     * What a deployment of $version to $target runs (KnativeDeployService::deploy):
     *
     *  - ['as_is', null]       the version's own image (or no build image: the runtime's image)
     *  - ['ready', $image]     the version's image in the environment region's registry
     *  - ['waiting', $message] the deployment waits (its build was submitted or runs); resumed later
     *  - ['retry', $message]   a runtime image builds in the region: deploy again in WAIT_SECONDS
     *  - ['failed', $message]  the version cannot run there (its build failed)
     *
     * @return array{0: string, 1: string|null}
     */
    public function prepare(Project $target, Version $version, Deployment $deployment, int $attempt = 0): array
    {
        $registry = $this->registryOf($target);
        if ($registry === null) {
            return ['as_is', null];
        }
        $region = $target->knativeRegion;
        $label = "v{$version->number}";

        // Built again right now — into the environment region's own registry (a region move, a
        // rebuild for the project's own workload in the same region): the deploy waits for that
        // build (versionBuilt()); into another one: the region gets a build of its own
        if (in_array($version->build_status, ['pending', 'building'], true)) {
            if ($this->rebuiltInto($version, $registry)) {
                return ['waiting', self::WAITING."the build of {$label}, which is built again into the registry of {$region->name}; deployed when it is ready."];
            }
        } elseif ($version->build_status !== 'ready') {
            return ['failed', "{$label} cannot run in {$region->name}: its build is {$version->build_status}".($version->build_error ? " ({$version->build_error})" : '')];
        }

        if (! $version->build_image) {
            return $this->runtimeImage($target, $version, $attempt);
        }
        if ($version->build_status === 'ready' && str_starts_with((string) $version->build_image, $registry.'/')) {
            return ['as_is', null];
        }
        if (! self::tableReady()) {
            return ['failed', "{$label} was built into a registry the region {$region->name} does not pull from; this organization's schema has no region builds yet (run the tenant migrations)."];
        }

        $row = VersionRegionImage::query()->where('version_id', $version->id)
            ->where('knative_region_id', (string) $region->getKey())->where('registry', $registry)->first();

        if ($row?->status === VersionRegionImage::READY && $row->image) {
            return ['ready', $row->image];
        }
        if ($row?->status === VersionRegionImage::BUILDING && ! $this->stale($row)) {
            return ['waiting', self::waitingMessage($version, $region)];
        }
        // A build that failed after this deployment was created is its own: it fails too. A
        // newer deploy of the version tries again.
        if ($row?->status === VersionRegionImage::FAILED && $deployment->created_at !== null
            && $row->updated_at !== null && $row->updated_at->greaterThanOrEqualTo($deployment->created_at)) {
            return ['failed', self::failedMessage($version, $region, (string) $row->error)];
        }

        return $this->submit($target, $version, $region, $registry, $row);
    }

    /**
     * The image a global project's copy in $region runs $version from (GlobalReplicaDeployer,
     * docs/global-projects.md#deploys): the same store as the environments' — one build per
     * version, region and registry, whichever of them asked first.
     *
     *  - ['ready', $image]    built into the region's registry (or the version's own image is there)
     *  - ['building', null]   a build runs (this copy's or an environment's); asked again later
     *  - ['failed', $message] the build failed after $since (the rollout began)
     *
     * @return array{0: string, 1: string|null}
     */
    public function forGlobalCopy(Project $project, Version $version, KnativeRegion $region, ?\DateTimeInterface $since = null): array
    {
        $registry = rtrim((string) $region->registry_address, '/');
        if ($registry === '' || $region->is_demo) {
            return ['failed', "{$region->name} has no registry to build v{$version->number} into"];
        }
        if ($version->build_status === 'ready' && str_starts_with((string) $version->build_image, $registry.'/')) {
            return ['ready', (string) $version->build_image];
        }
        if (! self::tableReady()) {
            return ['failed', "this organization's schema has no region builds yet (run the tenant migrations)"];
        }

        $row = VersionRegionImage::query()->where('version_id', $version->id)
            ->where('knative_region_id', (string) $region->getKey())->where('registry', $registry)->first();

        if ($row?->status === VersionRegionImage::READY && $row->image) {
            return ['ready', (string) $row->image];
        }
        if ($row?->status === VersionRegionImage::BUILDING && ! $this->stale($row)) {
            return ['building', null];
        }
        if ($row?->status === VersionRegionImage::FAILED && $since !== null && $row->updated_at !== null
            && $row->updated_at->greaterThanOrEqualTo($since)) {
            return ['failed', self::failedMessage($version, $region, (string) $row->error)];
        }

        [$state, $message] = $this->submit($project, $version, $region, $registry, $row);

        return $state === 'failed' ? ['failed', $message] : ['building', null];
    }

    /** What a deployment says while it waits for the build of $version into $region's registry. */
    public static function waitingMessage(Version $version, KnativeRegion $region): string
    {
        return self::WAITING."v{$version->number} to be built into the registry of {$region->name} (the version's code, the same runtime base); deployed when it is ready.";
    }

    public static function failedMessage(Version $version, KnativeRegion $region, string $error): string
    {
        return "The build of v{$version->number} into the registry of {$region->name} failed: ".($error !== '' ? $error : 'no details');
    }

    /**
     * One check of a region build (CheckRegionImageBuildJob): submitted again while the runtime
     * image builds in the region, then followed until its Job ends.
     *
     * @return int|null seconds until the next check; null: the build ended (or another took over)
     */
    public function check(string $rowId, string $token): ?int
    {
        $row = VersionRegionImage::query()->find($rowId);
        if (! $row || $row->status !== VersionRegionImage::BUILDING || $row->token !== $token) {
            return null;
        }

        $version = Version::query()->find($row->version_id);
        $region = KnativeRegion::query()->find($row->knative_region_id);
        if (! $version || ! $region || ! $region->kubeconfig || $region->is_demo) {
            $this->failed($row, 'the region or the version is gone');

            return null;
        }
        // The region moved to another registry meanwhile: the waiting deploys build there instead
        if (rtrim((string) $region->registry_address, '/') !== $row->registry) {
            $row->forceFill(['status' => VersionRegionImage::FAILED, 'error' => 'The region moved to another registry before the build ended.', 'token' => null])->save();
            $this->resume($row);

            return null;
        }

        $row->forceFill(['attempts' => $row->attempts + 1])->save();

        if ($row->image === null) {
            try {
                $row->forceFill(['image' => $this->builder->submitReplicaBuild($version->loadMissing('project'), $region, $row->job_name)])->save();
            } catch (RuntimeImagePendingException) {
                if ($row->attempts >= self::WAIT_ATTEMPTS) {
                    $this->failed($row, 'the runtime image was not built in the region within '.intdiv(self::WAIT_SECONDS * self::WAIT_ATTEMPTS, 60).' minutes');

                    return null;
                }

                return self::WAIT_SECONDS;
            } catch (\Throwable $e) {
                $this->failed($row, $e->getMessage());

                return null;
            }
            $row->forceFill(['attempts' => 1])->save();

            return self::POLL_SECONDS;
        }

        $k8s = new KubernetesClient((string) $region->kubeconfig);
        $namespace = (string) ($region->project_namespace ?? $region->name);
        try {
            $job = $k8s->getResourceStatus('batch/v1', 'Job', (string) $row->job_name, $namespace);
        } catch (\Throwable $e) {
            Log::info('Region build not checked', ['row' => $row->id, 'error' => $e->getMessage()]);
            $job = null;
        }

        if ((int) ($job['status']['succeeded'] ?? 0) > 0) {
            $row->forceFill(['status' => VersionRegionImage::READY, 'error' => null, 'token' => null])->save();
            $this->removeSource($region, (string) $row->job_name);
            Log::info("v{$version->number} built into the registry of {$region->name}", ['image' => $row->image]);
            $this->resume($row);
            $this->retain((string) $row->project_id, $region, $row->registry);

            return null;
        }
        if ((int) ($job['status']['failed'] ?? 0) > 0) {
            $this->failed($row, $this->jobError($k8s, $job, $namespace));
            $this->removeSource($region, (string) $row->job_name);

            return null;
        }
        if (! $job && $row->attempts > 3) {
            $this->failed($row, 'its build Job is gone from the region');

            return null;
        }
        if ($row->attempts * self::POLL_SECONDS > BuildManifests::deadlineSeconds() + 60) {
            $this->failed($row, 'it did not end within '.BuildManifests::deadlineSeconds().' seconds');

            return null;
        }

        return self::POLL_SECONDS;
    }

    /**
     * Version hook: the version's own build ended — the environment deployments that waited for
     * it (built again into their region's registry) go on, or fail with it.
     */
    public function versionBuilt(Version $version): void
    {
        if (in_array($version->build_status, ['pending', 'building'], true) || ! ProjectEnvironment::tablesReady()) {
            return;
        }

        foreach ($this->waitingDeployments((string) $version->project_id, (string) $version->id) as $deployment) {
            ResumeEnvironmentDeployJob::dispatch((string) $deployment->id)->afterCommit();
        }
    }

    /**
     * Version hook: registry retention removed the version's image (pruned): its builds in other
     * regions go too — it can no longer be deployed anywhere.
     */
    public function versionPruned(Version $version): void
    {
        if (! self::tableReady()) {
            return;
        }

        foreach (VersionRegionImage::query()->where('version_id', $version->id)->get() as $row) {
            $this->forget($row, $version);
        }
    }

    /**
     * Remove the region builds of a project — every one (the project is deleted), or those in one
     * region that no environment of the project runs any more (an environment was deleted or left
     * the region).
     */
    public function forgetProject(Project $project, ?string $regionId = null): void
    {
        if (! self::tableReady()) {
            return;
        }

        $rows = VersionRegionImage::query()->where('project_id', $project->getKey())
            ->when($regionId !== null, fn ($q) => $q->where('knative_region_id', $regionId))
            ->get();
        $inUse = $regionId !== null ? $this->versionsInUse((string) $project->getKey()) : [];
        foreach ($rows as $row) {
            if (! in_array((string) $row->version_id, $inUse, true)) {
                $this->forget($row);
            }
        }
    }

    /**
     * The organization lost the region (it was detached from it): every build there is forgotten,
     * whatever ran it — nothing of the organization runs there any more.
     */
    public function forgetRegion(string $regionId): void
    {
        if (! self::tableReady()) {
            return;
        }

        foreach (VersionRegionImage::query()->where('knative_region_id', $regionId)->get() as $row) {
            $this->forget($row);
        }
    }

    /**
     * Keep the newest region builds of the project in the region (registry.retain_versions) and
     * every one a deployment still runs; the others leave the registry.
     */
    public function retain(string $projectId, KnativeRegion $region, string $registry): void
    {
        try {
            $rows = VersionRegionImage::query()->where('project_id', $projectId)
                ->where('knative_region_id', (string) $region->getKey())->where('registry', $registry)
                ->where('status', VersionRegionImage::READY)
                ->with('version:id,number,build_image')
                ->get()
                ->sortByDesc(fn (VersionRegionImage $row) => (int) ($row->version->number ?? 0))
                ->values();
            $keep = RegistryImageManifests::clampRetain(config('registry.retain_versions'));
            $inUse = $this->versionsInUse($projectId);
            foreach ($rows->slice($keep) as $row) {
                if (! in_array((string) $row->version_id, $inUse, true)) {
                    $this->forget($row, $row->version, $region);
                }
            }
        } catch (\Throwable $e) {
            Log::info('Region builds not trimmed', ['project' => $projectId, 'region' => $region->name, 'error' => $e->getMessage()]);
        }
    }

    /** The environment region's registry address of $target; null when nothing is built for it. */
    private function registryOf(Project $target): ?string
    {
        if (EnvironmentScope::idOf($target) === null || $target->service_type === 'caas') {
            return null;
        }
        $region = $target->knativeRegion;
        $registry = rtrim((string) $region?->registry_address, '/');

        return $region === null || $region->is_demo || $registry === '' ? null : $registry;
    }

    private function readyRow(Version $version, string $regionId, string $registry): ?VersionRegionImage
    {
        if (! self::tableReady()) {
            return null;
        }

        return VersionRegionImage::query()->where('version_id', $version->id)->where('knative_region_id', $regionId)
            ->where('registry', $registry)->where('status', VersionRegionImage::READY)->whereNotNull('image')->first();
    }

    /** Whether the version's running build pushes into $registry (the registry of its project's region). */
    private function rebuiltInto(Version $version, string $registry): bool
    {
        $image = (string) $version->build_image;
        if ($image !== '' && $version->build_status === 'building') {
            return str_starts_with($image, $registry.'/');
        }
        $own = Project::query()->whereKey($version->project_id)->first()?->knativeRegion;

        return rtrim((string) $own?->registry_address, '/') === $registry;
    }

    /**
     * A version without a build of its own runs its runtime's image: one RunR builds from source
     * in the environment's region may still be building there.
     *
     * @return array{0: string, 1: string|null}
     */
    private function runtimeImage(Project $target, Version $version, int $attempt): array
    {
        try {
            $this->runtimes->versionImage($this->runtimes->forProject($target), $this->runtimes->executorFor($target), $target->knativeRegion);
        } catch (RuntimeImagePendingException) {
            if ($attempt >= self::WAIT_ATTEMPTS) {
                return ['failed', "The runtime image of {$target->runtime} was not built in {$target->knativeRegion->name} within ".intdiv(self::WAIT_SECONDS * self::WAIT_ATTEMPTS, 60).' minutes'];
            }

            return ['retry', self::WAITING."the runtime image of {$target->runtime}, which is built in {$target->knativeRegion->name}; deployed when it is ready."];
        } catch (\Throwable) {
            // any other problem with the runtime image is the deploy's to report
        }

        return ['as_is', null];
    }

    /** A build that has not been checked for longer than a build may take: its checks were lost. */
    private function stale(VersionRegionImage $row): bool
    {
        return $row->updated_at !== null
            && $row->updated_at->lt(now()->subSeconds(BuildManifests::deadlineSeconds() + 300));
    }

    /**
     * Submit the build of $version into $region's registry (a new row, or one whose build failed
     * or was lost), followed by CheckRegionImageBuildJob.
     *
     * @return array{0: string, 1: string|null}
     */
    private function submit(Project $target, Version $version, KnativeRegion $region, string $registry, ?VersionRegionImage $row): array
    {
        $base = EnvironmentScope::base($target);
        $token = Str::random(32);
        $attributes = [
            'status' => VersionRegionImage::BUILDING,
            'image' => null,
            'job_name' => FunctionBuildService::regionBuildJobName($base, $version),
            'error' => null,
            'attempts' => 0,
            'token' => $token,
        ];

        try {
            if ($row === null) {
                $row = VersionRegionImage::query()->create([
                    'project_id' => (string) $base->getKey(),
                    'version_id' => (string) $version->getKey(),
                    'knative_region_id' => (string) $region->getKey(),
                    'registry' => $registry,
                ] + $attributes);
            } else {
                // Of two deploys at once one claims the build
                $claimed = VersionRegionImage::query()->whereKey($row->getKey())->where('status', $row->status)
                    ->where(fn ($q) => $row->token === null ? $q->whereNull('token') : $q->where('token', $row->token))
                    ->update($attributes + ['updated_at' => now()]);
                if ($claimed !== 1) {
                    return ['waiting', self::waitingMessage($version, $region)];
                }
                $row->refresh();
            }
        } catch (QueryException) {
            // Another deploy of the version created the row first: its build is followed
            return ['waiting', self::waitingMessage($version, $region)];
        }

        try {
            $version->setRelation('project', $base);
            $row->forceFill(['image' => $this->builder->submitReplicaBuild($version, $region, $row->job_name)])->save();
        } catch (RuntimeImagePendingException) {
            // the runtime image is built in the region first: the checks submit the build then
        } catch (\Throwable $e) {
            $row->forceFill(['status' => VersionRegionImage::FAILED, 'error' => mb_substr($e->getMessage(), 0, 2000), 'token' => null])->save();
            Log::warning("v{$version->number} of {$base->slug} not built into the registry of {$region->name}", ['error' => $e->getMessage()]);

            return ['failed', self::failedMessage($version, $region, $e->getMessage())];
        }

        CheckRegionImageBuildJob::dispatch((string) $row->getKey(), $token)->delay(now()->addSeconds(self::POLL_SECONDS));
        Log::info("v{$version->number} of {$base->slug} is built into the registry of {$region->name} for an environment there", ['job' => $row->job_name]);

        return ['waiting', self::waitingMessage($version, $region)];
    }

    /** The build failed: the deployments that waited for it fail with its error. */
    private function failed(VersionRegionImage $row, string $error): void
    {
        $row->forceFill(['status' => VersionRegionImage::FAILED, 'error' => mb_substr($error, 0, 2000), 'token' => null])->save();
        $version = Version::query()->find($row->version_id);
        $region = KnativeRegion::query()->find($row->knative_region_id);
        Log::warning('Region build failed', ['row' => $row->id, 'error' => $error]);
        if (! $version || ! $region) {
            return;
        }

        $message = mb_substr(self::failedMessage($version, $region, $error), 0, 255);
        foreach ($this->waitingDeployments((string) $row->project_id, (string) $row->version_id, (string) $row->knative_region_id) as $deployment) {
            $deployment->transition(['pending'], ['status' => 'failed', 'knative_ready' => false, 'knative_message' => $message]);
        }
    }

    /** The build is ready (or another registry takes over): the deployments that waited for it go on. */
    private function resume(VersionRegionImage $row): void
    {
        foreach ($this->waitingDeployments((string) $row->project_id, (string) $row->version_id, (string) $row->knative_region_id) as $deployment) {
            ResumeEnvironmentDeployJob::dispatch((string) $deployment->id);
        }
    }

    /**
     * The environment deployments of a version that wait for an image (WAITING), optionally of
     * the environments that run in one region.
     *
     * @return Collection<int, Deployment>
     */
    private function waitingDeployments(string $projectId, string $versionId, ?string $regionId = null): Collection
    {
        $deployments = Deployment::query()->where('project_id', $projectId)->where('version_id', $versionId)
            ->whereNotNull('environment_id')->where('status', 'pending')
            ->where('knative_message', 'like', self::WAITING.'%')
            ->get();
        if ($regionId === null || $deployments->isEmpty()) {
            return $deployments->values();
        }

        $project = Project::query()->find($projectId);
        $environments = ProjectEnvironment::query()->whereIn('id', $deployments->pluck('environment_id')->unique()->all())->get()->keyBy('id');

        return $deployments->filter(function (Deployment $deployment) use ($environments, $project, $regionId) {
            $environment = $environments->get($deployment->environment_id);
            if (! $environment || ! $project) {
                return false;
            }
            $environment->setRelation('project', $project);

            return $environment->regionId() === $regionId;
        })->values();
    }

    /**
     * Versions a deployment of the project still runs or starts (any environment).
     *
     * @return list<string>
     */
    private function versionsInUse(string $projectId): array
    {
        return Deployment::query()->where('project_id', $projectId)
            ->whereNotIn('status', ['shut_down', 'failed'])
            ->pluck('version_id')->filter()->map(fn ($id) => (string) $id)->unique()->values()->all();
    }

    /**
     * A region build leaves its registry — unless the version's own build image is that very
     * image (the region's registry holds the version's own build) — and its row goes.
     */
    private function forget(VersionRegionImage $row, ?Version $version = null, ?KnativeRegion $region = null): void
    {
        $version ??= Version::query()->find($row->version_id);
        $region ??= KnativeRegion::query()->find($row->knative_region_id);
        $ref = RegistryImageManifests::parseImageRef($row->image);
        if ($ref !== null && $region !== null && (string) $version?->build_image !== (string) $row->image) {
            app(RegistryImageLifecycleService::class)->deleteImage($region, $ref['repo'], $ref['tag']);
        }
        $row->delete();
    }

    /** The source ConfigMap of a build Job (the Job itself goes by its TTL). */
    private function removeSource(KnativeRegion $region, string $name): void
    {
        try {
            (new KubernetesClient((string) $region->kubeconfig))->deleteResource('v1', 'ConfigMap', $name, (string) ($region->project_namespace ?? $region->name));
        } catch (\Throwable) {
            // left for the namespace's next cleanup
        }
    }

    /**
     * The error of a failed build Job: the last lines of its build container's log, else the
     * Job's Failed condition.
     *
     * @param  array<string, mixed>  $job
     */
    private function jobError(KubernetesClient $k8s, array $job, string $namespace): string
    {
        try {
            $selector = ['job-name' => (string) ($job['metadata']['name'] ?? '')];
            $pod = $k8s->listResources('v1', 'Pod', $namespace, $selector)['items'][0]['metadata']['name'] ?? null;
            if (is_string($pod) && $pod !== '') {
                $logs = $k8s->getPodLogs($pod, $namespace, 'kaniko', 20) ?: $k8s->getPodLogs($pod, $namespace, 'build', 20);
                if ($logs) {
                    $lines = array_values(array_filter(explode("\n", trim((string) $logs))));
                    $errors = array_filter($lines, fn ($l) => str_contains($l, 'error') || str_contains($l, 'fatal'));
                    $message = implode("\n", array_slice($errors ?: $lines, -3));
                    if ($message !== '') {
                        return mb_substr((string) preg_replace('/\x1b\[[0-9;]*m/', '', $message), 0, 1000);
                    }
                }
            }
        } catch (\Throwable) {
            // the condition below
        }

        foreach ($job['status']['conditions'] ?? [] as $condition) {
            if (($condition['type'] ?? '') === 'Failed' && ! empty($condition['message'])) {
                return mb_substr((string) $condition['message'], 0, 1000);
            }
        }

        return 'the build failed (no details available)';
    }
}
