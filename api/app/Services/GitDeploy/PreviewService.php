<?php

namespace App\Services\GitDeploy;

use App\Jobs\GitDeploy\BuildPreviewJob;
use App\Jobs\GitDeploy\CheckPreviewReadyJob;
use App\Jobs\GitDeploy\DeployPreviewJob;
use App\Jobs\GitDeploy\ReportGitStatusJob;
use App\Models\Git\GitDeploy;
use App\Models\Git\GitRepoLink;
use App\Models\Git\PreviewEnvironment;
use App\Models\Git\ProjectGitSettings;
use App\Models\Git\VersionGitSource;
use App\Models\Project;
use App\Models\Version;
use App\Services\Concerns\RuntimeConfigTrait;
use App\Services\GitDeploy\Providers\GitEvent;
use App\Services\Helpers\ResourceReservation;
use App\Services\KnativeDeployService;
use App\Services\KubernetesClient;
use App\Services\Manifests\RegistryImageManifests;
use App\Services\RegistryImageLifecycleService;
use App\Services\Residency\RegionPolicy;
use App\Services\Runtimes\RuntimeImagePendingException;
use App\Support\DeployGate;
use App\Support\Projects\DeployIntent;
use App\Support\Projects\ProjectAdmission;
use App\Support\TenantAccess;
use App\Triggers\ProjectTriggers;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\Tenancy\Services\TenantQuotaService;

/**
 * Preview environments of pull/merge requests.
 *
 * Open / new commits → the head commit's source (provider archive, root directory) is stored as
 * a commit under refs/runr/previews/<number> of the project remote and becomes a preview version
 * (numbered from PreviewVersionScope::NUMBER_BASE, hidden from the project's own versions); it is
 * built like any version when the runtime needs a build, then deployed as its own Knative
 * Service <slug>-pr-<number> (PreviewManifests: scale to zero, preview env overrides, no secrets
 * for forks unless allowed). URL: https://<slug>-pr-<number>.<function domain>.
 * Close / merge, the TTL (preview_ttl_hours since the last commit) or a delete by hand → the
 * Service, ConfigMaps, build artifacts, images and versions of the preview are removed.
 *
 * Rules: previews must be on for the project; requests from forks need fork previews on; at
 * most preview_limit live previews per project; HTTP-triggered projects only; in a multi-tenant
 * install the preview's reservation must fit the organization quota (previews count: see
 * GitDeployServiceProvider's quota contributor). ProjectAdmission is asked on every path (a new
 * request, its deploy, a redeploy by hand): a stopped project has no previews — the stop removes
 * them — and in a fixed-price region the project's allocation must hold the preview at its maximum
 * next to what the project runs (Billing module).
 */
class PreviewService
{
    use RuntimeConfigTrait;

    /** A runtime image still building in the region: the deploy is tried again every DEPLOY_WAIT_SECONDS, DEPLOY_WAIT_ATTEMPTS times (15 minutes) */
    public const DEPLOY_WAIT_SECONDS = 10;

    public const DEPLOY_WAIT_ATTEMPTS = 90;

    public function __construct(
        private ProjectRemote $remote,
        private VersionImporter $importer,
        private GitDeployService $deploys,
    ) {}

    /** A pull/merge request event of the connected repository (ProcessGitEventJob). */
    public function handle(Project $project, GitRepoLink $link, GitEvent $event): ?PreviewEnvironment
    {
        if (! $event->isPullRequest() || ! $event->number || $event->action === null) {
            return null;
        }

        if ($event->action === GitEvent::PR_CLOSE) {
            $preview = PreviewEnvironment::where('project_id', $project->id)->where('number', $event->number)->first();
            if ($preview && $preview->status !== PreviewEnvironment::DELETED) {
                $this->teardown($preview, $event->merged ? 'Merged' : 'Closed');
            }

            return $preview;
        }

        $settings = ProjectGitSettings::for($project);
        if (! $settings->previews_enabled || ! $event->headSha) {
            return null;
        }

        $preview = PreviewEnvironment::firstOrNew(['project_id' => $project->id, 'number' => $event->number]);
        $wasLive = $preview->exists && $preview->isLive();
        $preview->fill([
            'provider' => $link->provider_type,
            'git_repo_link_id' => $link->id,
            'title' => $event->title ? mb_substr($event->title, 0, 250) : null,
            'branch' => $event->headRef,
            'base_branch' => $event->baseRef,
            'head_sha' => $event->headSha,
            'author' => $event->prAuthor,
            'pr_url' => $event->prUrl,
            'is_fork' => $event->isFork,
            // the name it was created with stays (its Knative Service); a new one keeps the
            // organization's end of a long slug
            'service_name' => $preview->service_name ?: PreviewManifests::serviceName($project->slug, $event->number, TenantAccess::current()['slug'] ?? null),
            'closed_at' => null,
            'expires_at' => now()->addHours(max(1, $settings->preview_ttl_hours)),
        ]);

        $deploy = GitDeploy::create([
            'project_id' => $project->id,
            'kind' => GitDeploy::KIND_PREVIEW,
            'trigger' => 'pull_request',
            'provider' => $link->provider_type,
            'sha' => $event->headSha,
            'branch' => $event->headRef,
            'author_name' => $event->prAuthor ?? $event->authorName,
            'message' => $event->message ?? $event->title,
            'url' => $event->prUrl,
            'pr_number' => $event->number,
            'status' => GitDeploy::QUEUED,
        ]);

        if ($reason = $this->refusal($project, $settings, $preview)) {
            $preview->fill(['status' => PreviewEnvironment::SKIPPED, 'status_message' => $reason])->save();
            $deploy->forceFill(['preview_environment_id' => $preview->id])->save();
            $deploy->mark(GitDeploy::SKIPPED, $reason);
            // A preview that was live under a rule that no longer allows it is removed
            if ($wasLive) {
                $this->removeResources($project, $preview);
            }
            $this->reportPreview($preview, $deploy);

            return $preview;
        }

        $preview->fill(['status' => PreviewEnvironment::BUILDING, 'status_message' => null])->save();
        $deploy->forceFill(['preview_environment_id' => $preview->id, 'status' => GitDeploy::IMPORTING])->save();
        $this->reportPreview($preview, $deploy);

        try {
            $version = $this->createVersion($project, $settings, $link, $preview, $event);
        } catch (\Throwable $e) {
            $message = $e instanceof ImportException || $e instanceof GitCommandException ? $e->getMessage() : 'The source could not be imported: '.$e->getMessage();
            $this->fail($preview, $deploy, $message);

            return $preview;
        }

        $preview->update(['version_id' => $version->id]);
        $deploy->update(['version_id' => $version->id]);

        if ($version->build_status === 'pending') {
            $deploy->mark(GitDeploy::BUILDING);
            $this->reportPreview($preview, $deploy);
            BuildPreviewJob::dispatch($preview->id, $version->id);
        } else {
            DeployPreviewJob::dispatch($preview->id);
        }

        return $preview;
    }

    /**
     * Apply the preview's version to the region (DeployPreviewJob). A version without a build of
     * its own whose runtime image RunR is still building in the region (after a region migration,
     * say) waits for it: the job comes back every DEPLOY_WAIT_SECONDS, DEPLOY_WAIT_ATTEMPTS times.
     *
     * @param  int  $waited  checks so far while the runtime image builds
     */
    public function deploy(PreviewEnvironment $preview, int $waited = 0): void
    {
        $project = $preview->project?->load(['knativeRegion', 'xaasConfig', 'faasConfig', 'containerConfig', 'privateDatabaseBindings']);
        $version = $preview->version;
        $deploy = $this->currentDeploy($preview);

        if (! $project || ! $version || ! $preview->isLive()) {
            return;
        }

        $region = $project->knativeRegion;
        if (! $region || $region->is_demo) {
            if ($reason = ProjectAdmission::refusal($project, DeployIntent::preview((string) $preview->id))) {
                $this->fail($preview, $deploy, $reason);

                return;
            }
            $preview->update(['status' => PreviewEnvironment::READY, 'url' => GitUrls::function($project, $preview->service_name), 'last_deployed_at' => now()]);
            $deploy?->mark(GitDeploy::ACTIVE);
            $this->reportPreview($preview, $deploy);

            return;
        }

        if ($blocked = DeployGate::blockedReason()) {
            $this->fail($preview, $deploy, $blocked);

            return;
        }

        if (! $region->kubeconfig || ! $region->is_active) {
            $this->fail($preview, $deploy, 'The region is not active');

            return;
        }

        // A stopped project runs no previews; module guards (a fixed region: the allocation must
        // hold the preview next to what the project runs)
        if ($reason = ProjectAdmission::refusal($project, DeployIntent::preview((string) $preview->id))) {
            $this->fail($preview, $deploy, $reason);

            return;
        }

        // Data residency (RegionPolicy::PREVIEW) and the supply-chain gate, as for production
        try {
            DeployChecks::assert($project, $version, RegionPolicy::PREVIEW);
        } catch (DeployPendingException $e) {
            $deploy?->mark(GitDeploy::BUILDING, $e->getMessage()); // GitDeployService::scanFinished
            $this->reportPreview($preview, $deploy);

            return;
        } catch (ImportException $e) {
            $this->fail($preview, $deploy, $e->getMessage());

            return;
        }

        $settings = ProjectGitSettings::for($project);
        $preview->mark(PreviewEnvironment::DEPLOYING);
        $deploy?->mark(GitDeploy::DEPLOYING);
        $this->reportPreview($preview, $deploy);

        try {
            $builder = new KnativeDeployService(new RemoteRepoFiles($this->remote));
            // A preview scales to zero: no min_scale, no timed warmer
            $manifests = $builder->buildManifests($project, $version, ['scale_to_zero' => true, 'min_scale' => 0, 'scale_schedule' => null]);
            $manifests = PreviewManifests::transform(
                $manifests,
                $project->id,
                $project->slug,
                $preview->service_name,
                $preview->number,
                $this->stringEnv($settings->preview_env ?? []),
                $preview->is_fork && ! $settings->fork_secrets,
            );

            $k8s = new KubernetesClient($region->kubeconfig);
            $namespace = $region->project_namespace ?? $region->name;

            // The preview reads the project's secret Secrets (PreviewManifests): they hold what the
            // project's variables need, even before the project's first deploy
            if (PreviewManifests::referencesSecrets($manifests)) {
                $builder->prepareSecrets($project);
            }

            foreach ($manifests as $manifest) {
                if (! $k8s->applyResource($manifest, $namespace)) {
                    throw new \RuntimeException("Failed to apply {$manifest['kind']}: {$manifest['metadata']['name']}");
                }
            }

            // The preview's own copy of the secret env vars that earlier releases made goes
            $k8s->deleteResource('v1', 'Secret', PreviewManifests::secretEnvName($preview->service_name), $namespace);
        } catch (RuntimeImagePendingException $e) {
            if ($waited >= self::DEPLOY_WAIT_ATTEMPTS) {
                $this->fail($preview, $deploy, $e->getMessage());

                return;
            }
            $preview->mark(PreviewEnvironment::DEPLOYING, $e->getMessage());
            DeployPreviewJob::dispatch($preview->id, $waited + 1)->delay(now()->addSeconds(self::DEPLOY_WAIT_SECONDS));

            return;
        } catch (\Throwable $e) {
            Log::warning('Preview deploy failed', ['preview' => $preview->id, 'error' => $e->getMessage()]);
            $this->fail($preview, $deploy, $e->getMessage());

            return;
        }

        // Stopped or deleted while it was applied: what the apply created goes again
        $current = $preview->fresh();
        $stopped = Project::query()->whereKey($project->getKey())->whereNotNull('stopped_at')->exists();
        if (! $current || ! $current->isLive() || $stopped) {
            $this->removeResources($project, $preview);
            if ($current && $current->isLive()) {
                $this->fail($current, $deploy, __('api.project.stopped', ['reason' => (string) $project->fresh()?->stop_reason]));
            }

            return;
        }

        $preview->update(['url' => GitUrls::function($project, $preview->service_name), 'last_deployed_at' => now()]);
        CheckPreviewReadyJob::start($preview->id);
    }

    /** The preview's Knative Service became ready (CheckPreviewReadyJob). */
    public function ready(PreviewEnvironment $preview): void
    {
        $preview->mark(PreviewEnvironment::READY);
        $deploy = $this->currentDeploy($preview);
        $deploy?->mark(GitDeploy::ACTIVE);
        $this->reportPreview($preview, $deploy);
        $this->pruneOldVersions($preview);
    }

    public function fail(PreviewEnvironment $preview, ?GitDeploy $deploy, string $message): void
    {
        $preview->mark(PreviewEnvironment::FAILED, $message);
        $deploy?->mark(GitDeploy::FAILED, $message);
        $this->reportPreview($preview, $deploy);
    }

    /**
     * Remove a preview: cluster resources, build images, the preview ref and versions. The row
     * stays (status deleted) for the history and the PR comment.
     */
    public function teardown(PreviewEnvironment $preview, string $reason = 'Deleted'): void
    {
        $project = $preview->project;
        $preview->update(['status' => PreviewEnvironment::DELETING, 'status_message' => $reason, 'closed_at' => now()]);

        if ($project) {
            $this->removeResources($project, $preview);
            $this->remote->deleteRef($project, self::ref($preview->number));

            $versions = $this->previewVersions($project, $preview->number);
            VersionGitSource::whereIn('version_id', $versions->pluck('id'))->delete();
            PreviewVersionScope::query()->whereIn('id', $versions->pluck('id'))->delete();
        }

        $preview->update(['status' => PreviewEnvironment::DELETED, 'version_id' => null, 'url' => null]);
        $this->reportPreview($preview, null);
    }

    /** Previews past their TTL, and live previews of a project whose previews were turned off. */
    public function cleanupExpired(): int
    {
        $count = 0;

        PreviewEnvironment::query()
            ->whereIn('status', [...PreviewEnvironment::LIVE, PreviewEnvironment::SKIPPED])
            ->where('expires_at', '<', now())
            ->with('project')
            ->get()
            ->each(function (PreviewEnvironment $preview) use (&$count) {
                $this->teardown($preview, 'Expired');
                $count++;
            });

        return $count;
    }

    /** Every preview of a project (project deleted / repository disconnected / previews off). */
    public function teardownAll(Project $project, string $reason): void
    {
        PreviewEnvironment::where('project_id', $project->id)
            ->where('status', '!=', PreviewEnvironment::DELETED)
            ->get()
            ->each(fn (PreviewEnvironment $p) => $this->teardown($p, $reason));
    }

    /** Admitted reservation of one preview of the project, both budgets together. */
    public static function reservation(Project $project): array
    {
        return ResourceReservation::total(self::demand($project));
    }

    /**
     * Demand of one preview of the project (quota contributor, admission): one pod of the
     * project's size that scales to zero (http, min_scale 0) — the organization quota's FaaS
     * overcommit budget, its Kata pod overhead the always-on budget.
     *
     * @return array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}
     */
    public static function demand(Project $project): array
    {
        $config = ResourceReservation::configOf($project);
        if (! $config) {
            return ResourceReservation::noDemand();
        }

        return ResourceReservation::demandForConfig([
            'cpu' => $config->cpu ?? null,
            'memory_mb' => $config->memory_mb ?? null,
            'trigger_type' => 'http',
            'min_scale' => 0,
            'scale_to_zero' => true,
        ], $project->service_type, $project->knativeRegion);
    }

    public static function ref(int $number): string
    {
        return "refs/runr/previews/{$number}";
    }

    public function reportPreview(PreviewEnvironment $preview, ?GitDeploy $deploy): void
    {
        ReportGitStatusJob::dispatch($deploy?->id, $preview->id)->afterCommit();
    }

    public function currentDeploy(PreviewEnvironment $preview): ?GitDeploy
    {
        return GitDeploy::where('preview_environment_id', $preview->id)
            ->where('sha', $preview->head_sha)
            ->latest()
            ->first();
    }

    /** Why a preview of this request must not be deployed (null: it may). */
    private function refusal(Project $project, ProjectGitSettings $settings, PreviewEnvironment $preview): ?string
    {
        if (! $project->isSupportedType() || $project->service_type === 'caas') {
            return 'Previews are built from code: container projects have none';
        }

        // A stopped project (ProjectAdmission) runs no previews; module guards, e.g. a fixed
        // region whose allocation must hold the preview next to what the project runs
        if ($reason = ProjectAdmission::refusal($project, DeployIntent::preview((string) ($preview->getKey() ?? '')))) {
            return $reason;
        }

        // A preview is the project's HTTP service on its own URL (its other triggers do not run)
        if (! app(ProjectTriggers::class)->plan($project)->has('http')) {
            $trigger = ResourceReservation::configOf($project)?->trigger_type ?? 'http';

            return "Previews need an HTTP-triggered project (this one is triggered by {$trigger})";
        }

        if ($preview->is_fork && ! $settings->fork_previews) {
            return 'Previews of requests from forks are turned off for this project';
        }

        $live = PreviewEnvironment::where('project_id', $project->id)
            ->whereIn('status', PreviewEnvironment::LIVE)
            ->when($preview->exists, fn ($q) => $q->whereKeyNot($preview->getKey()))
            ->count();
        $limit = max(0, (int) $settings->preview_limit);
        if ($live >= $limit) {
            return "The project already has {$live} live preview(s), its limit is {$limit}";
        }

        return $this->quotaRefusal($project, $preview);
    }

    private function quotaRefusal(Project $project, PreviewEnvironment $preview): ?string
    {
        if (! TenantContext::enabled() || ! ($tenant = TenantContext::find(TenantContext::currentId()))) {
            return null;
        }

        $region = $project->knativeRegion;
        if (! $region || $region->region_mode === 'infinite') {
            return null;
        }

        // A preview that is already live keeps its place
        if ($preview->exists && $preview->isLive()) {
            return null;
        }

        $result = app(TenantQuotaService::class)
            ->checkReservation($tenant, $region, self::demand($project));

        return $result['allowed'] ? null : 'The organization quota leaves no room for a preview: '.($result['message'] ?? '');
    }

    private function createVersion(Project $project, ProjectGitSettings $settings, GitRepoLink $link, PreviewEnvironment $preview, GitEvent $event): Version
    {
        $project->loadMissing(['xaasConfig', 'faasConfig']);
        $tree = $this->deploys->providerTree($link, (string) $event->headSha, $settings->rootDir());

        // The request's runr.yaml is not applied: a preview runs with the project's settings
        $files = $this->importer->functionFiles($project, $tree);

        $this->remote->ensure($project, $settings);
        $sha = $this->remote->commitFiles(
            $project,
            self::ref($preview->number),
            $files,
            "Preview #{$preview->number} at {$event->headSha}",
            ['name' => mb_substr((string) ($event->authorName ?? $event->prAuthor ?? 'RunR'), 0, 100), 'email' => (string) ($event->authorEmail ?? 'runr@localhost')],
        ) ?? $this->remote->resolve($project, self::ref($preview->number));

        $build = $project->service_type === 'xaas'
            ? ($this->isCompiledRuntime($project->runtime) ? 'pending' : 'ready')
            : ($this->needsBuild($project->runtime, $files) ? 'pending' : 'ready');

        $version = Version::create([
            'project_id' => $project->id,
            'number' => PreviewVersionScope::nextNumber($project),
            'commit_id' => $sha,
            'commit_message' => mb_substr("PR #{$preview->number}: ".($event->title ?? $event->headRef ?? ''), 0, 240),
            'created_by' => 'git',
            'changed_files' => array_keys($files),
            'build_status' => $build,
        ]);

        VersionGitSource::create([
            'version_id' => $version->id,
            'project_id' => $project->id,
            'provider' => $link->provider_type,
            'sha' => (string) $event->headSha,
            'ref' => $event->headRef,
            'author_name' => $event->authorName ?? $event->prAuthor,
            'author_email' => $event->authorEmail,
            'message' => $event->message ?? $event->title,
            'url' => $event->commitUrl ?? $event->prUrl,
            'pr_number' => $preview->number,
        ]);

        return $version;
    }

    /** Knative Service, ConfigMaps, build Jobs and images of a preview (404 = already gone). */
    private function removeResources(Project $project, PreviewEnvironment $preview): void
    {
        $region = $project->knativeRegion;
        if (! $region || $region->is_demo || ! $region->kubeconfig) {
            return;
        }

        $namespace = $region->project_namespace ?? $region->name;

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $k8s->deleteResource('serving.knative.dev/v1', 'Service', $preview->service_name, $namespace);

            foreach (['ConfigMap', 'Secret'] as $kind) {
                foreach ($k8s->listResources('v1', $kind, $namespace, [PreviewManifests::LABEL_PREVIEW => $preview->service_name])['items'] ?? [] as $item) {
                    if (! empty($item['metadata']['name'])) {
                        $k8s->deleteResource('v1', $kind, $item['metadata']['name'], $namespace);
                    }
                }
            }

            $registry = app(RegistryImageLifecycleService::class);
            foreach ($this->previewVersions($project, $preview->number) as $version) {
                $build = "build-{$project->slug}-v{$version->number}";
                $k8s->deleteResource('batch/v1', 'Job', $build, $namespace, 'Background');
                $k8s->deleteResource('v1', 'ConfigMap', $build, $namespace);

                if ($ref = RegistryImageManifests::parseImageRef($version->build_image)) {
                    $registry->deleteImage($region, $ref['repo'], $ref['tag']);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Preview teardown incomplete', ['preview' => $preview->id, 'error' => $e->getMessage()]);
        }
    }

    /** Keep the preview's live version (and its image) only. */
    private function pruneOldVersions(PreviewEnvironment $preview): void
    {
        $project = $preview->project;
        if (! $project || ! $preview->version_id) {
            return;
        }

        $old = $this->previewVersions($project, $preview->number)->where('id', '!=', $preview->version_id);
        if ($old->isEmpty()) {
            return;
        }

        $region = $project->knativeRegion;
        if ($region && ! $region->is_demo && $region->kubeconfig) {
            try {
                $k8s = new KubernetesClient($region->kubeconfig);
                $namespace = $region->project_namespace ?? $region->name;
                $registry = app(RegistryImageLifecycleService::class);
                foreach ($old as $version) {
                    $k8s->deleteResource('v1', 'ConfigMap', "{$preview->service_name}-v{$version->number}", $namespace);
                    if ($ref = RegistryImageManifests::parseImageRef($version->build_image)) {
                        $registry->deleteImage($region, $ref['repo'], $ref['tag']);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Old preview versions not cleaned up', ['preview' => $preview->id, 'error' => $e->getMessage()]);
            }
        }

        VersionGitSource::whereIn('version_id', $old->pluck('id'))->delete();
        PreviewVersionScope::query()->whereIn('id', $old->pluck('id'))->delete();
    }

    /** @return Collection<int, Version> */
    private function previewVersions(Project $project, int $number)
    {
        $ids = VersionGitSource::where('project_id', $project->id)->where('pr_number', $number)->pluck('version_id');

        return PreviewVersionScope::query()
            ->where('project_id', $project->id)
            ->where('number', '>=', PreviewVersionScope::NUMBER_BASE)
            ->whereIn('id', $ids)
            ->get();
    }

    /** @return array<string, string> */
    private function stringEnv(array $env): array
    {
        $out = [];
        foreach ($env as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $out[(string) $key] = (string) $value;
            }
        }

        return $out;
    }
}
