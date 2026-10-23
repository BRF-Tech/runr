<?php

namespace App\Services\Environments;

use App\Events\DeploymentStatusChanged;
use App\Models\BindingAttachment;
use App\Models\Deployment;
use App\Models\DeploymentRollout;
use App\Models\EnvironmentPromotion;
use App\Models\EnvironmentSecret;
use App\Models\GatewayRoute;
use App\Models\Git\ProjectGitSettings;
use App\Models\KnativeRegion;
use App\Models\PrivateDatabaseBinding;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\ProjectEnvironment;
use App\Services\Bindings\BindingManager;
use App\Services\Bindings\ProjectBindingSync;
use App\Services\GatewayService;
use App\Services\KnativeDeployService;
use App\Services\ProjectDomainService;
use App\Services\Residency\RegionPolicy;
use App\Services\Secrets\SecretBackendException;
use App\Services\Skupper\SkupperService;
use App\Support\Projects\ScaleSchedule;
use App\Support\SchemaColumns;
use App\Triggers\Cleanup\CdcSlotCleanup;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lifecycle of a project's environments (docs/environments.md):
 *
 *  - create   name (DNS label, not reserved, unique in the project; its workload name
 *             <slug>-<name> must not be another project's or environment's), region (the
 *             project's, or another one the organization may use — data residency), overrides,
 *             branch, approval; it must fit the region capacity and the organization quota with
 *             its size (EnvironmentReservations) — that is how many environments a project may
 *             have. Nothing is deployed until a version is deployed or promoted to it.
 *  - update   overrides, branch, approval, secrets inheritance, position; the region only while
 *             nothing runs there and it has no gateway route or private database connection.
 *             Overrides reach the workload with the next deploy.
 *  - delete   its workloads leave the region, its custom domains and bindings are detached, its
 *             gateway route and private database connections are removed, its deployments,
 *             secrets and open promotions go; usage records stay.
 *
 * The primary environment (the project's own workload) is never created or deleted here: it is
 * the project.
 */
class EnvironmentManager
{
    public function __construct(
        private KnativeDeployService $deployer,
        private RegionPolicy $residency,
        private EnvironmentSecrets $secrets,
    ) {}

    /**
     * @param  array{name: string, knative_region_id?: string|null, position?: int|null, requires_approval?: bool|null, git_branch?: string|null, auto_deploy?: bool|null, inherit_secrets?: bool|null, overrides?: array<string, mixed>|null}  $data
     *
     * @throws HttpResponseException 422 with the field's error
     */
    public function create(Project $project, array $data, ?string $userId = null): ProjectEnvironment
    {
        $name = strtolower(trim($data['name']));
        $this->assertName($project, $name);
        $workload = ProjectEnvironment::workloadNameFor($project, $name);
        $this->assertWorkloadName($workload);

        $regionId = $this->regionIdOf($project, $data['knative_region_id'] ?? null);
        $this->assertBranch($project, $data['git_branch'] ?? null);

        // The project's own workload joins the pipeline as its last environment
        ProjectEnvironment::primaryFor($project);
        $position = isset($data['position'])
            ? (int) $data['position']
            : min(ProjectEnvironment::PRIMARY_POSITION - 1, (int) ProjectEnvironment::query()->where('project_id', $project->id)->where('is_primary', false)->max('position') + 1);

        $environment = new ProjectEnvironment([
            'project_id' => $project->id,
            'name' => $name,
            'is_primary' => false,
            'workload_name' => $workload,
            'knative_region_id' => $regionId !== (string) $project->knative_region_id ? $regionId : null,
            'position' => $position,
            'requires_approval' => (bool) ($data['requires_approval'] ?? false),
            'git_branch' => $this->branchOf($data['git_branch'] ?? null),
            'auto_deploy' => (bool) ($data['auto_deploy'] ?? true),
            'inherit_secrets' => (bool) ($data['inherit_secrets'] ?? true),
            'overrides' => $this->cleanOverrides((array) ($data['overrides'] ?? [])),
            'created_by' => $userId,
        ]);
        $environment->setRelation('project', $project);

        $this->assertFits($project, $environment);

        $environment->save();
        EnvironmentAudit::record('environment.created', $project, $environment, [
            'region_id' => $environment->regionId(),
            'overrides' => array_keys($environment->overrideValues()),
            'requires_approval' => $environment->requires_approval,
        ]);

        return $environment;
    }

    /**
     * @param  array<string, mixed>  $data  the fields to change (create()'s, without the name)
     *
     * @throws HttpResponseException
     */
    public function update(ProjectEnvironment $environment, array $data): ProjectEnvironment
    {
        $project = $environment->project;
        $changes = [];
        $oldRegionId = $environment->regionId();

        if (array_key_exists('knative_region_id', $data) && ! $environment->is_primary) {
            $regionId = $this->regionIdOf($project, $data['knative_region_id']);
            if ($regionId !== $environment->regionId()) {
                if ($environment->deployments()->whereIn('status', Deployment::LIVE)->exists()) {
                    $this->invalid('knative_region_id', __('environments.region_live'));
                }
                // A gateway route and private connections live in the region (its gateway, its
                // namespace's Skupper site): removed first, added again in the new region
                if ($this->hasRegionalEndpoints($environment)) {
                    $this->invalid('knative_region_id', __('environments.region_endpoints'));
                }
                $changes['knative_region_id'] = $regionId !== (string) $project->knative_region_id ? $regionId : null;
            }
        }
        if (array_key_exists('git_branch', $data) && ! $environment->is_primary) {
            $this->assertBranch($project, $data['git_branch'], $environment);
            $changes['git_branch'] = $this->branchOf($data['git_branch']);
        }
        foreach (['requires_approval', 'auto_deploy', 'inherit_secrets'] as $flag) {
            if (array_key_exists($flag, $data) && $data[$flag] !== null) {
                $changes[$flag] = (bool) $data[$flag];
            }
        }
        if (array_key_exists('position', $data) && $data['position'] !== null) {
            $changes['position'] = (int) $data['position'];
        }
        if (array_key_exists('overrides', $data) && ! $environment->is_primary) {
            $overrides = (array) ($data['overrides'] ?? []);
            // The deploy strategy is set on its own (PUT …/deploy-strategy with environment):
            // overrides sent without it keep the one the environment has
            $current = $environment->overrideValues();
            if (! array_key_exists('deploy_strategy', $overrides) && isset($current['deploy_strategy'])) {
                $overrides['deploy_strategy'] = $current['deploy_strategy'];
            }
            $changes['overrides'] = $this->cleanOverrides($overrides);
        }

        $from = $environment->region();
        $environment->fill($changes);
        if (! $environment->is_primary && ($environment->isDirty('overrides') || $environment->isDirty('knative_region_id'))) {
            $environment->unsetRelation('knativeRegion');
            $this->assertFits($project, $environment);
        }

        // Its own secret values live in its region only: they go with it (nothing runs in it here)
        if (! $environment->is_primary && $environment->isDirty('knative_region_id') && $from && ($to = $environment->region())
            && (string) $from->getKey() !== (string) $to->getKey()) {
            try {
                $this->secrets->copy($project, $environment, $from, $to, true);
            } catch (SecretBackendException $e) {
                throw new HttpResponseException(response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $e->status()));
            }
        }

        $dirty = array_keys($environment->getDirty());
        $environment->save();

        // Another region (nothing runs in it): its custom domains leave the old region and wait for
        // a new verification, as the project's do when it moves
        if (in_array('knative_region_id', $dirty, true) && $oldRegionId !== null) {
            $environment->unsetRelation('knativeRegion');
            $this->regionLeft($project, $environment, $oldRegionId);
        }

        if ($dirty) {
            EnvironmentAudit::record('environment.updated', $project, $environment, ['fields' => array_values(array_diff($dirty, ['updated_at']))]);
        }

        return $environment;
    }

    /**
     * An environment no longer runs in a region (it moved while nothing ran in it, or followed its
     * project to another one): its custom domains leave the region and wait for a new
     * verification (their DNS target changed; an uploaded certificate lived in the region's
     * Secret only, ProjectDomainService::regionChanged), and its versions built into that region's
     * registry are forgotten unless another environment runs them there.
     */
    private function regionLeft(Project $project, ProjectEnvironment $environment, string $oldRegionId): void
    {
        try {
            app(ProjectDomainService::class)->regionChanged(DeployTarget::environment($project, $environment), $oldRegionId);
        } catch (\Throwable $e) {
            Log::warning('Custom domains of the environment not moved', ['environment' => $environment->id, 'error' => $e->getMessage()]);
        }
        try {
            app(RegionImages::class)->forgetProject($project, $oldRegionId);
        } catch (\Throwable $e) {
            Log::info('Region builds of the environment not removed', ['environment' => $environment->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The organization lost a region (Tenancy: the region was detached from it, docs/tenancy.md):
     * every environment that runs there leaves it the way its project does — its deployments end,
     * its workloads, Secrets and code ConfigMaps leave the region, its custom domains leave it and
     * wait for a new verification, its gateway route and private database connections there are
     * removed, its bindings' Secret goes (the attachments stay), its versions built into the
     * region's registry are forgotten — and an environment with the region as its own follows its
     * project's region from then on. Run before the projects leave the region: an environment that
     * still ran would keep the region (projectMoved()).
     *
     * @return list<string> what could not be removed from the region (logged)
     */
    public function leaveRegion(KnativeRegion $region): array
    {
        if (! ProjectEnvironment::tablesReady()) {
            return [];
        }

        $regionId = (string) $region->getKey();
        $environments = ProjectEnvironment::query()->where('is_primary', false)
            ->where(fn ($q) => $q->where('knative_region_id', $regionId)
                ->orWhere(fn ($following) => $following->where(fn ($n) => $n->whereNull('knative_region_id')->orWhere('knative_region_id', ''))
                    ->whereIn('project_id', Project::query()->where('knative_region_id', $regionId)->select('id'))))
            ->get();

        $failures = [];
        foreach ($environments as $environment) {
            $project = Project::query()->find($environment->project_id);
            if (! $project) {
                continue;
            }
            $environment->setRelation('project', $project);
            $view = DeployTarget::environment($project, $environment);

            // Each through the model: a deployment that stops running records when (metering)
            foreach (Deployment::query()->where('environment_id', $environment->id)->where('status', '!=', 'shut_down')->get() as $deployment) {
                $deployment->update(['status' => 'shut_down']);
            }

            try {
                if (! $this->deployer->teardownEnvironment($view)) {
                    $failures[] = "{$environment->workload_name}: Kubernetes teardown incomplete (see log)";
                }
            } catch (\Throwable $e) {
                $failures[] = "{$environment->workload_name}: {$e->getMessage()}";
            }

            try {
                foreach (app(GatewayService::class)->removeRoutesOf($project, $environment) as $failure) {
                    $failures[] = "{$environment->workload_name}: {$failure}";
                }
            } catch (\Throwable $e) {
                $failures[] = "{$environment->workload_name}: gateway route: {$e->getMessage()}";
            }
            array_push($failures, ...$this->removePrivateConnections($view, $environment));

            if (SchemaColumns::has(new BindingAttachment, 'environment_id')) {
                try {
                    app(ProjectBindingSync::class)->forget($view);
                } catch (\Throwable $e) {
                    $failures[] = "{$environment->workload_name}: bindings Secret: {$e->getMessage()}";
                }
            }

            $this->regionLeft($project, $environment, $regionId);
            if ((string) $environment->knative_region_id === $regionId) {
                $environment->forceFill(['knative_region_id' => null])->save();
            }
            EnvironmentAudit::record('environment.region_detached', $project, $environment, ['region_id' => $regionId]);
        }

        // The organization's builds into the region's registry: nothing of it runs there any more
        try {
            app(RegionImages::class)->forgetRegion($regionId);
        } catch (\Throwable $e) {
            $failures[] = "region builds: {$e->getMessage()}";
        }

        if ($failures) {
            Log::warning('Environments not fully removed from the detached region', ['region' => $regionId, 'failures' => $failures]);
        }

        return $failures;
    }

    /**
     * Delete an environment with everything it runs.
     *
     * @return list<string> what could not be removed from its region (logged; the rows go anyway)
     */
    public function delete(ProjectEnvironment $environment): array
    {
        if ($environment->is_primary) {
            $this->invalid('environment', __('environments.primary_not_deletable'));
        }

        $project = $environment->project;
        $failures = $this->removeFromRegion($project, $environment);

        DB::connection($environment->getConnectionName())->transaction(function () use ($environment) {
            EnvironmentPromotion::query()
                ->where(fn ($q) => $q->where('source_environment_id', $environment->id)->orWhere('target_environment_id', $environment->id))
                ->whereIn('status', EnvironmentPromotion::OPEN)
                ->update(['status' => EnvironmentPromotion::CANCELLED, 'status_message' => 'The environment was deleted', 'updated_at' => now()]);
            // Its rollouts (docs/deploy-strategies.md#environments) go with its deployments
            if (DeploymentRollout::available()) {
                DeploymentRollout::query()->ofEnvironment((string) $environment->project_id, (string) $environment->id)->delete();
                if (SchemaColumns::has(new DeploymentRollout, 'environment_id')) {
                    DeploymentRollout::query()->where('environment_id', $environment->id)->delete();
                }
            }
            Deployment::query()->where('environment_id', $environment->id)->delete();
            EnvironmentSecret::query()->where('environment_id', $environment->id)->delete();
            $environment->delete();
        });

        // Its versions built into its region's registry, unless another environment runs them there
        try {
            if (($regionId = $environment->regionId()) !== null) {
                app(RegionImages::class)->forgetProject($project, $regionId);
            }
        } catch (\Throwable $e) {
            Log::info('Region builds of the deleted environment not removed', ['environment' => $environment->id, 'error' => $e->getMessage()]);
        }

        EnvironmentAudit::record('environment.deleted', $project, $environment, ['failures' => count($failures)]);

        // the default replication slots its gateway read for the project's cdc triggers
        try {
            app(CdcSlotCleanup::class)->environmentRemoved($project, (string) $environment->workload_name, $environment->regionId());
        } catch (\Throwable $e) {
            Log::warning('CDC cleanup not scheduled', ['environment' => $environment->workload_name, 'error' => $e->getMessage()]);
        }

        return $failures;
    }

    /**
     * Every environment of a project leaves its region (the project is deleted).
     *
     * @return list<string> failures
     */
    public function teardownAll(Project $project): array
    {
        if (! ProjectEnvironment::tablesReady()) {
            return [];
        }

        $failures = [];
        foreach (ProjectEnvironment::query()->where('project_id', $project->id)->where('is_primary', false)->get() as $environment) {
            $environment->setRelation('project', $project);
            array_push($failures, ...$this->removeFromRegion($project, $environment));
        }

        return $failures;
    }

    /**
     * Set a secret env var of an environment (over the project's of the same name): the value goes
     * straight into the environment's region (EnvironmentSecrets), RunR keeps the metadata.
     *
     * @throws SecretBackendException the region did not take the value: nothing was changed
     */
    public function setSecret(ProjectEnvironment $environment, string $name, string $value, ?int $userId): EnvironmentSecret
    {
        if ($environment->is_primary) {
            $this->invalid('environment', __('environments.primary_secrets'));
        }

        $secret = $this->secrets->set($environment->project, $environment, $name, $value, $userId);

        EnvironmentAudit::record('environment.secret.set', $environment->project, $environment, ['name' => $name, 'version' => (int) $secret->version]);

        return $secret;
    }

    public function deleteSecret(ProjectEnvironment $environment, string $name): bool
    {
        $deleted = $this->secrets->remove($environment->project, $environment, $name);
        if ($deleted) {
            EnvironmentAudit::record('environment.secret.delete', $environment->project, $environment, ['name' => $name]);
        }

        return $deleted;
    }

    /**
     * The project is stopped: every environment's live deployment goes too (ProcessTeardown), and
     * the version each one ran is kept for the resume.
     *
     * @return list<array{0: Deployment, 1: string}> the deployments set to shutting_down (their events follow after the commit)
     */
    public function stopAll(Project $project): array
    {
        if (! ProjectEnvironment::tablesReady()) {
            return [];
        }

        $changed = [];
        foreach (ProjectEnvironment::query()->where('project_id', $project->id)->where('is_primary', false)->get() as $environment) {
            $live = Deployment::query()->where('environment_id', $environment->id)->whereIn('status', Deployment::LIVE)->orderByDesc('created_at')->get();
            $running = $live->firstWhere('status', 'active') ?? $live->first();
            if ($running) {
                $environment->forceFill(['stopped_version_id' => $running->version_id])->save();
            }
            foreach ($live as $deployment) {
                $previous = (string) $deployment->status;
                if ($deployment->transition(Deployment::LIVE, ['status' => 'shutting_down'])) {
                    $changed[] = [$deployment, $previous];
                }
            }
        }

        return $changed;
    }

    /** Events of the deployments stopAll() ended (after the stop's transaction). */
    public static function announce(array $changed): void
    {
        foreach ($changed as [$deployment, $previous]) {
            event(new DeploymentStatusChanged($deployment, $previous, 'shutting_down'));
        }
    }

    /**
     * The project moved to another region: an environment that followed the project's region and
     * runs there keeps that region (its own knative_region_id from now on) — it moves when it is
     * changed, while nothing runs in it.
     */
    public function projectMoved(Project $project, mixed $oldRegionId): void
    {
        if ($oldRegionId === null || $oldRegionId === '' || ! ProjectEnvironment::tablesReady()) {
            return;
        }

        $following = ProjectEnvironment::query()->where('project_id', $project->id)->where('is_primary', false)
            ->where(fn ($q) => $q->whereNull('knative_region_id')->orWhere('knative_region_id', ''))
            ->get();
        foreach ($following as $environment) {
            // its gateway route and private connections stay in that region too
            if (Deployment::query()->where('environment_id', $environment->id)->whereIn('status', Deployment::LIVE)->exists()
                || $this->hasRegionalEndpoints($environment)) {
                $environment->forceFill(['knative_region_id' => (string) $oldRegionId])->save();
                EnvironmentAudit::record('environment.region_kept', $project, $environment, ['region_id' => (string) $oldRegionId]);
            } else {
                // It follows the project: its custom domains move with it, as the project's do
                $environment->setRelation('project', $project);
                $this->regionLeft($project, $environment, (string) $oldRegionId);
            }
        }
    }

    /**
     * Name rules (ProjectEnvironment::NAME_PATTERN, RESERVED_NAMES, unique in the project).
     *
     * @throws HttpResponseException
     */
    public function assertName(Project $project, string $name): void
    {
        if (! preg_match(ProjectEnvironment::NAME_PATTERN, $name)) {
            $this->invalid('name', __('environments.name_invalid', ['max' => ProjectEnvironment::MAX_NAME_LENGTH]));
        }
        if (ProjectEnvironment::isReservedName($name)) {
            $this->invalid('name', __('environments.name_reserved', ['name' => $name]));
        }
        if (ProjectEnvironment::query()->where('project_id', $project->id)->where('name', $name)->exists()) {
            $this->invalid('name', __('environments.name_taken', ['name' => $name]));
        }
    }

    /** No project and no other environment may already use the workload name (one namespace). */
    private function assertWorkloadName(string $workload): void
    {
        if (Project::query()->where('slug', $workload)->exists()
            || ProjectEnvironment::query()->where('workload_name', $workload)->exists()) {
            $this->invalid('name', __('environments.workload_taken', ['workload' => $workload]));
        }
    }

    /**
     * The region an environment runs in: the given one (it must be usable and allowed by the
     * organization's data residency), else the project's.
     */
    private function regionIdOf(Project $project, mixed $regionId): string
    {
        if ($regionId === null || $regionId === '' || (string) $regionId === (string) $project->knative_region_id) {
            if (! $project->knative_region_id) {
                $this->invalid('knative_region_id', __('environments.project_without_region'));
            }

            return (string) $project->knative_region_id;
        }

        $region = KnativeRegion::query()->find($regionId);
        if (! $region || $region->is_demo) {
            $this->invalid('knative_region_id', __('environments.region_unavailable'));
        }

        // Data residency: the organization must be allowed to run workloads there
        $this->residency->authorize($region, RegionPolicy::ENVIRONMENT);

        return (string) $region->getKey();
    }

    /** A branch maps to one environment of the project, never the one production deploys from. */
    private function assertBranch(Project $project, mixed $branch, ?ProjectEnvironment $except = null): void
    {
        $branch = $this->branchOf($branch);
        if ($branch === null) {
            return;
        }

        if (! preg_match('#^[A-Za-z0-9._/-]{1,255}$#', $branch) || str_contains($branch, '..')) {
            $this->invalid('git_branch', __('environments.branch_invalid'));
        }
        if (ProjectGitSettings::query()->where('project_id', $project->id)->value('deploy_branch') === $branch) {
            $this->invalid('git_branch', __('environments.branch_is_production', ['branch' => $branch]));
        }
        $taken = ProjectEnvironment::query()->where('project_id', $project->id)->where('git_branch', $branch)
            ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))->value('name');
        if ($taken !== null) {
            $this->invalid('git_branch', __('environments.branch_taken', ['branch' => $branch, 'environment' => $taken]));
        }
    }

    private function branchOf(mixed $branch): ?string
    {
        $branch = is_string($branch) ? trim($branch) : null;
        if ($branch !== null && str_starts_with($branch, 'refs/heads/')) {
            $branch = substr($branch, strlen('refs/heads/'));
        }

        return $branch === null || $branch === '' ? null : $branch;
    }

    /**
     * Overrides with the known fields only; env var names as given (validated by the request).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>|null
     */
    private function cleanOverrides(array $overrides): ?array
    {
        $clean = array_intersect_key($overrides, array_flip(ProjectEnvironment::OVERRIDABLE));
        if (isset($clean['env_vars']) && is_array($clean['env_vars'])) {
            $clean['env_vars'] = array_map(fn ($v) => $v === null ? null : (string) $v, $clean['env_vars']);
        }
        // Its own timed warmer (docs/scaling.md#timed-warmer): rows, or [] for none; no value
        // follows the project's schedule
        if (array_key_exists('scale_schedule', $clean)) {
            if ($clean['scale_schedule'] === null) {
                unset($clean['scale_schedule']);
            } else {
                $clean['scale_schedule'] = ScaleSchedule::normalize($clean['scale_schedule']) ?? [];
            }
        }
        // The deploy strategy settings of its own (validated by the request): only what is set
        if (array_key_exists('deploy_strategy', $clean)) {
            $own = is_array($clean['deploy_strategy']) ? array_intersect_key($clean['deploy_strategy'], array_flip(['strategy', 'blue_green', 'canary'])) : [];
            if ($own === []) {
                unset($clean['deploy_strategy']);
            } else {
                $clean['deploy_strategy'] = $own;
            }
        }

        return $clean === [] ? null : $clean;
    }

    /** The environment's size must fit its region and the organization quota. */
    private function assertFits(Project $project, ProjectEnvironment $environment): void
    {
        $region = $environment->region();
        $original = $environment->exists ? (new ProjectEnvironment)->newQuery()->find($environment->getKey()) : null;
        $original?->setRelation('project', $project);

        if ($reason = EnvironmentReservations::refusal($region, EnvironmentReservations::demand($project, $environment), $original)) {
            $this->invalid('overrides', $reason);
        }
    }

    /**
     * Workloads, custom domains and bindings of the environment leave its region.
     *
     * @return list<string> failures
     */
    private function removeFromRegion(Project $project, ProjectEnvironment $environment): array
    {
        $failures = [];
        $view = DeployTarget::environment($project, $environment);

        foreach (Deployment::query()->where('environment_id', $environment->id)->whereIn('status', [...Deployment::LIVE, 'shutting_down'])->get() as $deployment) {
            $deployment->transition([...Deployment::LIVE, 'shutting_down'], ['status' => 'shut_down']);
        }

        try {
            if (! $this->deployer->teardownEnvironment($view)) {
                $failures[] = "{$environment->workload_name}: Kubernetes teardown incomplete (see log)";
            }
        } catch (\Throwable $e) {
            $failures[] = "{$environment->workload_name}: {$e->getMessage()}";
        }

        if (SchemaColumns::has(new ProjectDomain, 'environment_id')) {
            foreach (ProjectDomain::query()->where('environment_id', $environment->id)->get() as $domain) {
                try {
                    app(ProjectDomainService::class)->removeFromRegion($domain, $view->knativeRegion);
                    $domain->delete();
                } catch (\Throwable $e) {
                    $failures[] = "{$environment->workload_name}: custom domain {$domain->domain}: {$e->getMessage()}";
                }
            }
        }

        if (SchemaColumns::has(new BindingAttachment, 'environment_id')) {
            $attachments = BindingAttachment::query()->where('environment_id', $environment->id)->with('binding')->get();
            foreach ($attachments as $attachment) {
                try {
                    app(BindingManager::class)->detach($attachment);
                } catch (\Throwable $e) {
                    $failures[] = "{$environment->workload_name}: binding {$attachment->binding->name}: {$e->getMessage()}";
                }
            }
            try {
                app(ProjectBindingSync::class)->forget($view);
            } catch (\Throwable $e) {
                $failures[] = "{$environment->workload_name}: bindings Secret: {$e->getMessage()}";
            }
        }

        // Its gateway route leaves its gateway (synced without it)
        try {
            foreach (app(GatewayService::class)->removeRoutesOf($project, $environment) as $failure) {
                $failures[] = "{$environment->workload_name}: {$failure}";
            }
        } catch (\Throwable $e) {
            $failures[] = "{$environment->workload_name}: gateway route: {$e->getMessage()}";
        }

        // Its private database connections leave the region's namespace, their rows go
        array_push($failures, ...$this->removePrivateConnections($view, $environment));

        if ($failures) {
            Log::warning('Environment teardown incomplete', ['environment' => $environment->id, 'failures' => $failures]);
        }

        return $failures;
    }

    /**
     * The environment's private database connections (Skupper Listener, Link / AccessToken and
     * Secrets in its region's namespace), then their rows.
     *
     * @return list<string> failures
     */
    private function removePrivateConnections(Project $view, ProjectEnvironment $environment): array
    {
        if (! SchemaColumns::has(new PrivateDatabaseBinding, 'environment_id')) {
            return [];
        }

        $failures = [];
        $region = $view->knativeRegion;
        foreach (PrivateDatabaseBinding::query()->where('environment_id', $environment->id)->get() as $binding) {
            try {
                if ($region?->kubeconfig && ! $region->is_demo) {
                    app(SkupperService::class)->remove($binding, $region);
                }
            } catch (\Throwable $e) {
                $failures[] = "{$environment->workload_name}: private connection {$binding->resource_name}: {$e->getMessage()}";
            }
            $binding->delete();
        }

        return $failures;
    }

    /** Whether the environment has a gateway route or private database connections (they live in its region). */
    private function hasRegionalEndpoints(ProjectEnvironment $environment): bool
    {
        return (SchemaColumns::has(new GatewayRoute, 'environment_id') && GatewayRoute::query()->where('environment_id', $environment->id)->exists())
            || (SchemaColumns::has(new PrivateDatabaseBinding, 'environment_id') && PrivateDatabaseBinding::query()->where('environment_id', $environment->id)->exists());
    }

    /** @throws HttpResponseException */
    private function invalid(string $field, mixed $message): never
    {
        $message = (string) $message;

        throw new HttpResponseException(response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422));
    }
}
