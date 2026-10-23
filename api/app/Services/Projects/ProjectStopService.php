<?php

namespace App\Services\Projects;

use App\Events\DeploymentStatusChanged;
use App\Events\NotificationCreated;
use App\Events\ProjectResumed;
use App\Events\ProjectStopped;
use App\Models\Deployment;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\User;
use App\Models\Version;
use App\Services\Audit\AuditLogger;
use App\Services\Environments\EnvironmentManager;
use App\Services\GitDeploy\DeploymentStarter;
use App\Services\GitDeploy\DeployPendingException;
use App\Services\GitDeploy\ImportException;
use App\Services\GitDeploy\PreviewService;
use App\Support\Rbac\Rbac;
use App\Support\SchemaColumns;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stopping and resuming a project (docs/billing.md#project-stop-and-resume) — core, it works with
 * billing off too; billing operators use it, guarded by the projects.stop permission.
 *
 *  stop    the project's live deployments go to shutting_down, which tears their Knative Service,
 *          CronJob and queue consumer down (ProcessTeardown), and its preview environments are
 *          removed: nothing serves any more. The project keeps its versions and configuration;
 *          ProjectAdmission refuses every deploy while it is stopped, and a deploy that was queued
 *          before the stop does nothing (KnativeDeployService). The stop is claimed with a
 *          conditional update: of two concurrent stops one wins, the other changes nothing
 *          (ProjectStopConflict). The reason and the level are stored — "platform" when a platform
 *          administrator stopped it, else "organization" — shown to the members (ProjectResource)
 *          and sent to the owner as a notification. A platform administrator stopping a project
 *          an organization stopped takes the stop over (level platform, the new reason).
 *          stopBySystem() is the same stop made by RunR itself at the organization's level — the
 *          monthly cost limit of the Billing module — with stopped_by "system:<what>" (no user).
 *  resume  needs the level of the stop or a higher one (an organization cannot lift the
 *          platform's stop), clears the stop and redeploys the version that ran when the project
 *          was stopped, with that deployment's overrides (deploy_config), through the guarded
 *          deploy path (DeploymentStarter: version, region, data residency and supply-chain checks,
 *          ProjectAdmission). When the redeploy is refused the project stays resumed without a
 *          deployment and the answer says why. A version built into a registry the region's
 *          cluster does not pull from (the region moved while the project was stopped) is built
 *          again and deployed when ready (VersionRebuilds; code VERSION_REBUILDING).
 */
class ProjectStopService
{
    public const LEVEL_PLATFORM = 'platform';

    public const LEVEL_ORGANIZATION = 'organization';

    /**
     * redeploy_code of a resume whose version is built again for the region's registry first
     * (VersionRebuilds): not a refusal — it deploys when the build is ready.
     */
    public const VERSION_REBUILDING = 'version_rebuilding';

    /**
     * stopped_by of a stop RunR made by itself (stopBySystem): "system:<what>", e.g.
     * "system:cost_limit". No user stopped it, so none is named.
     */
    public const SYSTEM_ACTOR = 'system:';

    /** Deployment states that hold cluster resources */
    private const LIVE = Deployment::LIVE;

    public static function systemActor(string $system): string
    {
        return self::SYSTEM_ACTOR.$system;
    }

    /** What stopped the project when RunR stopped it by itself (stopBySystem), else null. */
    public static function systemOf(Project $project): ?string
    {
        $by = (string) $project->stopped_by;

        return $project->isStopped() && str_starts_with($by, self::SYSTEM_ACTOR) ? substr($by, strlen(self::SYSTEM_ACTOR)) : null;
    }

    /** The level of a stop by $actor: a platform administrator stops for the platform. */
    public function levelOf(Authenticatable $actor): string
    {
        return app(Rbac::class)->isPlatformAdmin($actor) ? self::LEVEL_PLATFORM : self::LEVEL_ORGANIZATION;
    }

    /** The level a project was stopped at (stops of before 0.5.8 count as the organization's). */
    public function levelOfStop(Project $project): string
    {
        return $project->getAttribute('stop_level') === self::LEVEL_PLATFORM ? self::LEVEL_PLATFORM : self::LEVEL_ORGANIZATION;
    }

    /** Whether $actor may resume the project: the level of the stop or a higher one. */
    public function canResume(Project $project, Authenticatable $actor): bool
    {
        return $this->levelOfStop($project) !== self::LEVEL_PLATFORM || $this->levelOf($actor) === self::LEVEL_PLATFORM;
    }

    /**
     * @throws ProjectStopConflict the project is already stopped (also by a concurrent stop)
     */
    public function stop(Project $project, Authenticatable $actor, string $reason): Project
    {
        $reason = mb_substr(trim($reason), 0, 1000);
        $actorId = (string) $actor->getAuthIdentifier();
        $level = $this->levelOf($actor);
        $withLevel = SchemaColumns::has($project, 'stop_level');

        if (! $this->claim($project, $actorId, $level, $reason, $withLevel)) {
            return $this->takeOver($project->refresh(), $actorId, $level, $reason, $withLevel);
        }

        return $project;
    }

    /**
     * A stop RunR makes by itself ($system, e.g. "cost_limit": the project's monthly cost limit,
     * Billing module) — at the organization's level, so the organization resumes it. Audited as
     * project.stop by the system.
     *
     * @throws ProjectStopConflict the project is already stopped (also by a concurrent stop)
     */
    public function stopBySystem(Project $project, string $system, string $reason): Project
    {
        $reason = mb_substr(trim($reason), 0, 1000);

        if (! $this->claim($project, self::systemActor($system), self::LEVEL_ORGANIZATION, $reason, SchemaColumns::has($project, 'stop_level'))) {
            $project->refresh();

            throw new ProjectStopConflict('project_already_stopped', __('api.project.already_stopped'), 409, [
                'level' => $project->isStopped() ? $this->levelOfStop($project) : null,
                'reason' => $project->stop_reason,
            ]);
        }

        app(AuditLogger::class)->record('project.stop', [
            'actor' => 'system',
            'target' => $project,
            'metadata' => ['reason' => $reason, 'system' => $system],
        ]);

        return $project;
    }

    /**
     * Stop the project as $actorId: its live deployments shut down, its previews removed, the
     * owner told. False when it was stopped already (also by a concurrent stop): nothing changed.
     */
    private function claim(Project $project, string $actorId, string $level, string $reason, bool $withLevel): bool
    {
        /** @var list<array{0: Deployment, 1: string}> $changed */
        $changed = [];
        $claimed = false;

        DB::connection($project->getConnectionName())->transaction(function () use ($project, $reason, $actorId, $level, $withLevel, &$changed, &$claimed) {
            // The project's own workload; its environments stop below (EnvironmentManager::stopAll)
            $live = Deployment::forEnvironment(Deployment::query()->where('project_id', $project->id), null)
                ->whereIn('status', self::LIVE)
                ->orderByDesc('created_at')
                ->get();
            /** @var Deployment|null $running */
            $running = $live->firstWhere('status', 'active') ?? $live->first();

            $attributes = [
                'stopped_at' => now(),
                'stopped_by' => $actorId,
                'stop_reason' => $reason,
                'stopped_version_id' => $running?->version_id,
                'updated_at' => now(),
            ];
            if ($withLevel) {
                $attributes['stop_level'] = $level;
            }

            // Only one stop wins: a concurrent one finds stopped_at set and changes nothing
            $claimed = Project::query()->whereKey($project->getKey())->whereNull('stopped_at')->update($attributes) > 0;
            if (! $claimed) {
                return;
            }

            foreach ($live as $deployment) {
                $previous = (string) $deployment->status;
                if ($deployment->transition(self::LIVE, ['status' => 'shutting_down'])) {
                    $changed[] = [$deployment, $previous];
                }
            }

            // Every environment of the project stops too, remembering the version it ran (docs/environments.md)
            array_push($changed, ...app(EnvironmentManager::class)->stopAll($project));
        });

        if (! $claimed) {
            return false;
        }

        // After the commit: ProcessTeardown (queued) reads the deployment back as shutting_down
        foreach ($changed as [$deployment, $previous]) {
            event(new DeploymentStatusChanged($deployment, $previous, 'shutting_down'));
        }

        $project->refresh();
        $this->removePreviews($project);
        $this->announceStop($project, $reason, $actorId);

        return true;
    }

    /**
     * @param  string|null  $refusal  set to the reason when the project is resumed but the version
     *                                that ran cannot be deployed again
     * @param  string|null  $refusalCode  the refusal's code when a deploy guard refused it
     *                                    (fixed_allocation_exceeded, …)
     * @return Deployment|null the redeploy of the version that ran when the project was stopped
     *
     * @throws ProjectStopConflict the project is not stopped (any more), or $actor's level is lower than the stop's
     */
    public function resume(Project $project, Authenticatable $actor, ?string &$refusal = null, ?string &$refusalCode = null): ?Deployment
    {
        $refusal = null;
        $refusalCode = null;
        if (! $this->canResume($project, $actor)) {
            throw new ProjectStopConflict('project_stopped_by_platform', __('api.project.stopped_by_platform'), 403);
        }

        $actorId = (string) $actor->getAuthIdentifier();
        $versionId = $project->stopped_version_id;
        $stoppedAt = $project->stopped_at;

        if (Project::query()->whereKey($project->getKey())->whereNotNull('stopped_at')->update($this->liftAttributes($project)) === 0) {
            throw new ProjectStopConflict('project_not_stopped', __('api.project.not_stopped'));
        }
        $project->refresh();

        $deployedBy = $actor instanceof User && $actor->username ? (string) $actor->username : $actorId;
        $deployment = $versionId ? $this->redeploy($project, (string) $versionId, $stoppedAt, $deployedBy, $actorId, $refusal, $refusalCode) : null;
        $this->redeployEnvironments($project, $deployedBy, $actorId);

        $this->notifyUser($project->payerId(), $project, __('api.project.resumed_title', ['name' => $project->name]), null, 'info', 'project.resumed');

        event(new ProjectResumed($project, $deployment?->id, $actorId));

        return $deployment;
    }

    /**
     * Resume a project RunR stopped by itself ($system, stopBySystem) — the monthly cost limit once
     * a new month begins. Only while that stop holds: one a platform administrator took over stays.
     * The version that ran is deployed again as by resume(), on behalf of no user (deployed by
     * "cost-limit"). Audited as project.resume by the system.
     *
     * @return Deployment|null the redeploy of the version that ran when the project was stopped
     *
     * @throws ProjectStopConflict project_not_stopped: not (any more) stopped by $system
     */
    public function resumeBySystem(Project $project, string $system, ?string &$refusal = null, ?string &$refusalCode = null): ?Deployment
    {
        $refusal = null;
        $refusalCode = null;
        $actorId = self::systemActor($system);
        $project->refresh();
        $versionId = $project->stopped_version_id;
        $stoppedAt = $project->stopped_at;

        if (Project::query()->whereKey($project->getKey())->whereNotNull('stopped_at')->where('stopped_by', $actorId)->update($this->liftAttributes($project)) === 0) {
            throw new ProjectStopConflict('project_not_stopped', __('api.project.not_stopped'));
        }
        $project->refresh();

        $deployment = $versionId ? $this->redeploy($project, (string) $versionId, $stoppedAt, str_replace('_', '-', $system), null, $refusal, $refusalCode) : null;
        // the environments it stopped with the project (EnvironmentManager::stopAll) come back too
        $this->redeployEnvironments($project, str_replace('_', '-', $system), null);

        app(AuditLogger::class)->record('project.resume', [
            'actor' => 'system',
            'target' => $project,
            'metadata' => ['system' => $system, 'deployment_id' => $deployment?->id, 'refusal' => $refusal],
        ]);

        $this->notifyUser($project->payerId(), $project, __('api.project.resumed_title', ['name' => $project->name]), null, 'info', 'project.resumed');

        event(new ProjectResumed($project, $deployment?->id, $actorId));

        return $deployment;
    }

    /** @return array<string, mixed> the columns a resume clears */
    private function liftAttributes(Project $project): array
    {
        $attributes = ['stopped_at' => null, 'stopped_by' => null, 'stop_reason' => null, 'stopped_version_id' => null, 'updated_at' => now()];
        if (SchemaColumns::has($project, 'stop_level')) {
            $attributes['stop_level'] = null;
        }

        return $attributes;
    }

    /**
     * The version that ran before the stop, with the overrides of the deployment that ran it,
     * deployed the way a git push deploys (DeploymentStarter: every deploy check and
     * ProjectAdmission's guards). Refused: null, $refusal says why ($refusalCode: a guard's code).
     */
    private function redeploy(Project $project, string $versionId, mixed $stoppedAt, string $deployedBy, ?string $initiatorId, ?string &$refusal, ?string &$refusalCode): ?Deployment
    {
        $version = Version::query()->where('project_id', $project->id)->find($versionId);
        if (! $version) {
            $message = __('api.project.resume_version_gone');
            $refusal = is_string($message) ? $message : 'resume_version_gone';
            Log::info('Resumed project not redeployed: its last version is gone', ['project' => $project->slug, 'version_id' => $versionId]);

            return null;
        }

        // the project's own workload: an environment's deployment of the version has its own overrides
        $ran = Deployment::forEnvironment(Deployment::query()->where('project_id', $project->id), null)->where('version_id', $version->id)
            ->when($stoppedAt !== null, fn ($q) => $q->where('created_at', '<=', $stoppedAt))
            ->latest('created_at')->first();
        $overrides = is_array($ran?->deploy_config) && $ran->deploy_config !== [] ? $ran->deploy_config : null;

        // Built into a registry the region's cluster does not pull from (the region moved to
        // another cluster while the project was stopped): built again, deployed when ready
        if (VersionRebuilds::needed($project->loadMissing('knativeRegion'), $version)) {
            try {
                app(DeploymentStarter::class)->assertBeforeBuild($project, $overrides);
            } catch (ImportException $e) {
                $refusal = $e->getMessage();
                $refusalCode = $e->errorCode;
                Log::info('Resumed project not redeployed', ['project' => $project->slug, 'version' => $version->number, 'reason' => $refusal]);

                return null;
            }
            app(VersionRebuilds::class)->queue($project, $version, $deployedBy, [
                'deploy_config' => $overrides,
                'initiator_id' => $initiatorId,
            ]);
            $refusal = VersionRebuilds::message($version);
            $refusalCode = self::VERSION_REBUILDING;
            Log::info('Resumed project deploys when its version is built again for the region\'s registry', ['project' => $project->slug, 'version' => $version->number]);

            return null;
        }

        try {
            return app(DeploymentStarter::class)->start($project, $version, $deployedBy, $initiatorId, $overrides);
        } catch (ImportException|DeployPendingException $e) {
            $refusal = $e->getMessage();
            $refusalCode = $e->errorCode;
            Log::info('Resumed project not redeployed', ['project' => $project->slug, 'version' => $version->number, 'reason' => $refusal]);

            return null;
        }
    }

    /**
     * The environments that ran when the project was stopped get the version each one ran
     * (best effort: one that cannot be deployed again stays down, the reason is logged).
     */
    private function redeployEnvironments(Project $project, string $deployedBy, ?string $initiatorId): void
    {
        if (! ProjectEnvironment::tablesReady()) {
            return;
        }

        $environments = ProjectEnvironment::query()->where('project_id', $project->id)->where('is_primary', false)->whereNotNull('stopped_version_id')->get();
        foreach ($environments as $environment) {
            $version = Version::query()->where('project_id', $project->id)->find($environment->stopped_version_id);
            $environment->forceFill(['stopped_version_id' => null])->save();
            if (! $version) {
                continue;
            }

            try {
                app(DeploymentStarter::class)->start($project, $version, $deployedBy, $initiatorId, environment: $environment);
            } catch (ImportException|DeployPendingException $e) {
                Log::info('Environment of the resumed project not redeployed', ['project' => $project->slug, 'environment' => $environment->name, 'reason' => $e->getMessage()]);
            }
        }
    }

    /**
     * A platform administrator stops a project its organization stopped: the stop becomes the
     * platform's (level, actor and reason); anything else on a stopped project is a conflict.
     *
     * @throws ProjectStopConflict
     */
    private function takeOver(Project $project, string $actorId, string $level, string $reason, bool $withLevel): Project
    {
        if (! $withLevel || $level !== self::LEVEL_PLATFORM || $this->levelOfStop($project) === self::LEVEL_PLATFORM || ! $project->isStopped()) {
            throw new ProjectStopConflict('project_already_stopped', __('api.project.already_stopped'), 409, [
                'level' => $project->isStopped() ? $this->levelOfStop($project) : null,
                'reason' => $project->stop_reason,
            ]);
        }

        $updated = Project::query()->whereKey($project->getKey())->whereNotNull('stopped_at')
            ->where(fn ($q) => $q->whereNull('stop_level')->orWhere('stop_level', '!=', self::LEVEL_PLATFORM))
            ->update(['stopped_by' => $actorId, 'stop_reason' => $reason, 'stop_level' => self::LEVEL_PLATFORM, 'updated_at' => now()]);
        if ($updated === 0) {
            throw new ProjectStopConflict('project_already_stopped', __('api.project.already_stopped'), 409, ['level' => self::LEVEL_PLATFORM]);
        }

        $project->refresh();
        $this->announceStop($project, $reason, $actorId);

        return $project;
    }

    /** Previews run the project's code too: a stopped project has none (a new commit opens them again after the resume). */
    private function removePreviews(Project $project): void
    {
        try {
            app(PreviewService::class)->teardownAll($project, 'Project stopped');
        } catch (\Throwable $e) {
            Log::warning('Previews of a stopped project not removed', ['project' => $project->slug, 'error' => $e->getMessage()]);
        }
    }

    private function announceStop(Project $project, string $reason, string $actorId): void
    {
        $this->notifyUser($project->payerId(), $project, __('api.project.stopped_title', ['name' => $project->name]), $reason, 'warning', 'project.stopped');

        event(new ProjectStopped($project, $reason, $actorId));
    }

    /** In-app notice for a user (the owner), best effort. */
    public function notifyUser(?string $userId, Project $project, string $title, ?string $body, string $level, string $type): void
    {
        if ($userId === null || $userId === '') {
            return;
        }

        try {
            $notification = Notification::create([
                'user_id' => $userId,
                'type' => $type,
                'level' => $level,
                'title' => mb_substr($title, 0, 255),
                'body' => $body,
                'data' => ['project_id' => $project->id, 'project_name' => $project->name, 'reason' => $body, 'level' => $project->getAttribute('stop_level')],
            ]);
            NotificationCreated::dispatch($notification);
        } catch (\Throwable $e) {
            Log::warning('Project notification not sent', ['project' => $project->slug, 'type' => $type, 'error' => $e->getMessage()]);
        }
    }
}
