<?php

namespace App\Services\Projects;

use App\Events\DeploymentStatusChanged;
use App\Events\NotificationCreated;
use App\Events\ProjectResumed;
use App\Events\ProjectStopped;
use App\Models\Deployment;
use App\Models\Notification;
use App\Models\Project;
use App\Models\User;
use App\Models\Version;
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
 *  resume  needs the level of the stop or a higher one (an organization cannot lift the
 *          platform's stop), clears the stop and redeploys the version that ran when the project
 *          was stopped, with that deployment's overrides (deploy_config), through the guarded
 *          deploy path (DeploymentStarter: version, region, data residency and supply-chain checks,
 *          ProjectAdmission). When the redeploy is refused the project stays resumed without a
 *          deployment and the answer says why.
 */
class ProjectStopService
{
    public const LEVEL_PLATFORM = 'platform';

    public const LEVEL_ORGANIZATION = 'organization';

    /** Deployment states that hold cluster resources */
    private const LIVE = Deployment::LIVE;

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

        /** @var list<array{0: Deployment, 1: string}> $changed */
        $changed = [];
        $claimed = false;

        DB::connection($project->getConnectionName())->transaction(function () use ($project, $reason, $actorId, $level, $withLevel, &$changed, &$claimed) {
            $live = Deployment::query()
                ->where('project_id', $project->id)
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
        });

        if (! $claimed) {
            return $this->takeOver($project->refresh(), $actorId, $level, $reason, $withLevel);
        }

        // After the commit: ProcessTeardown (queued) reads the deployment back as shutting_down
        foreach ($changed as [$deployment, $previous]) {
            event(new DeploymentStatusChanged($deployment, $previous, 'shutting_down'));
        }

        $project->refresh();
        $this->removePreviews($project);
        $this->announceStop($project, $reason, $actorId);

        return $project;
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

        $attributes = ['stopped_at' => null, 'stopped_by' => null, 'stop_reason' => null, 'stopped_version_id' => null, 'updated_at' => now()];
        if (SchemaColumns::has($project, 'stop_level')) {
            $attributes['stop_level'] = null;
        }
        if (Project::query()->whereKey($project->getKey())->whereNotNull('stopped_at')->update($attributes) === 0) {
            throw new ProjectStopConflict('project_not_stopped', __('api.project.not_stopped'));
        }
        $project->refresh();

        $deployment = $versionId ? $this->redeploy($project, (string) $versionId, $stoppedAt, $actor, $refusal, $refusalCode) : null;

        $this->notifyUser($project->payerId(), $project, __('api.project.resumed_title', ['name' => $project->name]), null, 'info', 'project.resumed');

        event(new ProjectResumed($project, $deployment?->id, $actorId));

        return $deployment;
    }

    /**
     * The version that ran before the stop, with the overrides of the deployment that ran it,
     * deployed the way a git push deploys (DeploymentStarter: every deploy check and
     * ProjectAdmission's guards). Refused: null, $refusal says why ($refusalCode: a guard's code).
     */
    private function redeploy(Project $project, string $versionId, mixed $stoppedAt, Authenticatable $actor, ?string &$refusal, ?string &$refusalCode): ?Deployment
    {
        $version = Version::query()->where('project_id', $project->id)->find($versionId);
        if (! $version) {
            $message = __('api.project.resume_version_gone');
            $refusal = is_string($message) ? $message : 'resume_version_gone';
            Log::info('Resumed project not redeployed: its last version is gone', ['project' => $project->slug, 'version_id' => $versionId]);

            return null;
        }

        $ran = Deployment::query()->where('project_id', $project->id)->where('version_id', $version->id)
            ->when($stoppedAt !== null, fn ($q) => $q->where('created_at', '<=', $stoppedAt))
            ->latest('created_at')->first();
        $overrides = is_array($ran?->deploy_config) && $ran->deploy_config !== [] ? $ran->deploy_config : null;

        $deployedBy = $actor instanceof User && $actor->username ? (string) $actor->username : (string) $actor->getAuthIdentifier();

        try {
            return app(DeploymentStarter::class)->start($project, $version, $deployedBy, (string) $actor->getAuthIdentifier(), $overrides);
        } catch (ImportException|DeployPendingException $e) {
            $refusal = $e->getMessage();
            $refusalCode = $e->errorCode;
            Log::info('Resumed project not redeployed', ['project' => $project->slug, 'version' => $version->number, 'reason' => $refusal]);

            return null;
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
