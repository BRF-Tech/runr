<?php

namespace App\Services\Runtimes\Patches;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\QueuedDeploy;
use App\Models\RuntimePatch;
use App\Models\RuntimePatchFeed;
use App\Models\RuntimePatchMigration;
use App\Models\Version;
use App\Services\Audit\AuditLogger;
use App\Services\GitDeploy\DeploymentStarter;
use App\Services\GitDeploy\DeployPendingException;
use App\Services\GitDeploy\ImportException;
use App\Services\Observability\TenantScope;
use App\Services\Projects\QueuedDeploys;
use App\Services\Projects\VersionRebuilds;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Moving projects onto runtime patches (docs/runtimes.md#runtime-patches):
 *
 *  - start()      redeploy the live version onto a patch — "apply patch", a pin, the automatic
 *                 migration at a deadline, a platform administrator's "force now". A version that
 *                 runs on the runtime image (code mounted) gets a new deployment pinned to the
 *                 patch's digest; a prebuilt version (Kaniko) is built again FROM the patch's image
 *                 and deployed when the build is ready (VersionRebuilds, REASON_RUNTIME_PATCH).
 *  - watch()      started migrations: the deployment became active → succeeded; it failed (the
 *                 new revision did not become ready, the build failed) → an automatic migration is
 *                 rolled back to the digest the project ran before (and pinned there, so later
 *                 deploys stay on what worked until someone unpins) and a critical notification
 *                 goes out; a migration a user started just fails (Knative keeps serving the last
 *                 ready revision).
 *  - enforceDeadlines()  critical and zero-day patches past their (possibly extended) deadline:
 *                 every project still behind is migrated once per patch, while the platform policy
 *                 allows it (RuntimePatchFeed::autoMigrates()). A pin gives way at the deadline.
 */
class RuntimePatchMigrations
{
    /** A started migration that has not finished after this long failed */
    public const TIMEOUT_MINUTES = 60;

    /** Who deploys when RunR does (deployments.deployed_by, pinned_by) */
    public const SYSTEM = 'runr';

    public function __construct(
        private readonly RuntimePatches $patches,
        private readonly RuntimePatchNotifier $notifier,
        private readonly DeploymentStarter $starter,
        private readonly VersionRebuilds $rebuilds,
    ) {}

    /**
     * Redeploy the project's live version onto $patch (null: the newest patch of its version).
     *
     * @throws RuntimePatchException
     */
    public function start(Project $project, ?RuntimePatch $patch, string $trigger, string $deployedBy, ?string $initiatorId = null): RuntimePatchMigration
    {
        if (! $this->patches->applies($project)) {
            throw new RuntimePatchException(__('runtime_patches.errors.not_applicable'), 'not_applicable');
        }
        if ($project->isStopped()) {
            throw new RuntimePatchException(__('runtime_patches.errors.project_stopped'), 'project_stopped', 409);
        }

        $patch ??= $this->patches->newest($project->runtime, $this->patches->versionOf($project));
        if (! $patch || ! $patch->in_feed || $patch->runtime !== $project->runtime || $patch->version !== $this->patches->versionOf($project)) {
            throw new RuntimePatchException(__('runtime_patches.errors.no_patch'), 'no_patch');
        }

        // A migration that runs has replaced the live deployment with its own (pending) one
        if (RuntimePatchMigration::query()->where('project_id', $project->id)->where('status', RuntimePatchMigration::STARTED)->exists()) {
            throw new RuntimePatchException(__('runtime_patches.errors.migration_running'), 'migration_running', 409);
        }

        /** @var Deployment|null $live the project's own workload (its environments keep theirs) */
        $live = Deployment::forEnvironment(Deployment::query()->where('project_id', $project->id), null)->where('status', 'active')->latest('created_at')->first();
        $version = $live ? Version::query()->find($live->version_id) : null;
        if (! $live || ! $version) {
            throw new RuntimePatchException(__('runtime_patches.errors.not_deployed'), 'not_deployed', 409);
        }
        if ($live->runtime_digest === $patch->digest && $trigger !== RuntimePatchMigration::PIN) {
            throw new RuntimePatchException(__('runtime_patches.errors.already_running', ['tag' => $patch->tag]), 'already_running', 409);
        }

        /** @var RuntimePatchMigration $migration */
        $migration = RuntimePatchMigration::query()->create([
            'project_id' => $project->id,
            'patch_id' => $patch->id,
            'trigger' => $trigger,
            'status' => RuntimePatchMigration::STARTED,
            'version_id' => $version->id,
            'previous_digest' => $live->runtime_digest ?? $project->runtime_digest,
            'previous_patch_id' => $live->runtime_patch_id ?? $project->runtime_patch_id,
            'initiated_by' => $initiatorId ?? $deployedBy,
        ]);

        try {
            [$deployment, $queued] = $this->deployOnto($project, $version, $live->deploy_config, $patch->digest, $patch->id, $deployedBy, $initiatorId,
                __('runtime_patches.queued', ['version' => $version->number, 'tag' => $patch->tag]));
            $migration->forceFill(['deployment_id' => $deployment?->id, 'queued_deploy_id' => $queued?->id])->save();
        } catch (ImportException|DeployPendingException|\RuntimeException $e) {
            $migration->forceFill(['status' => RuntimePatchMigration::FAILED, 'message' => mb_substr($e->getMessage(), 0, 1000), 'finished_at' => now()])->save();
            if ($e instanceof RuntimePatchException) {
                throw $e;
            }

            throw new RuntimePatchException($e->getMessage(), 'deploy_refused');
        }

        return $migration->refresh();
    }

    /**
     * A new deployment of $version on $digest: a code-mounted version right away, a prebuilt one
     * after it is built again FROM that digest (the queued deploy).
     *
     * @param  array<string, mixed>|null  $deployConfig
     * @return array{0: Deployment|null, 1: QueuedDeploy|null}
     */
    private function deployOnto(Project $project, Version $version, ?array $deployConfig, string $digest, ?string $patchId, string $deployedBy, ?string $initiatorId, string $message): array
    {
        if ($version->build_image && $project->service_type !== 'caas') {
            Version::query()->whereKey($version->id)->update(['runtime_base_pin' => $digest]);
            $version->setAttribute('runtime_base_pin', $digest);

            return [null, $this->rebuilds->queue($project, $version, $deployedBy, [
                'deploy_config' => $deployConfig,
                'initiator_id' => $initiatorId,
                'reason' => QueuedDeploys::REASON_RUNTIME_PATCH,
                'message' => $message,
            ])];
        }

        return [$this->starter->start($project->loadMissing('knativeRegion'), $version, $deployedBy, $initiatorId, $deployConfig, null, [
            'runtime_digest' => $digest,
            'runtime_patch_id' => $patchId,
        ]), null];
    }

    /**
     * Pin the project to one of its version's pinnable patches and, when $deploy and it does not
     * run it, redeploy onto it.
     *
     * @throws RuntimePatchException
     */
    public function pin(Project $project, string $patchId, string $by, bool $deploy, string $deployedBy, ?string $initiatorId = null): ?RuntimePatchMigration
    {
        if (! $this->patches->applies($project)) {
            throw new RuntimePatchException(__('runtime_patches.errors.not_applicable'), 'not_applicable');
        }

        $patch = $this->patches->pinnable($project->runtime, $this->patches->versionOf($project))->firstWhere('id', $patchId);
        if (! $patch) {
            throw new RuntimePatchException(__('runtime_patches.errors.not_pinnable', ['keep' => (int) config('runtime_patches.keep', 5)]), 'not_pinnable');
        }

        $project->forceFill(['runtime_patch_pin' => $patch->id, 'runtime_patch_pinned_by' => $by])->save();

        // Nothing live, or it runs the pinned patch already: the next deploy uses the pin
        $running = Deployment::query()->where('project_id', $project->id)->where('status', 'active')->latest('created_at')->value('runtime_digest');
        if (! $deploy || $this->patches->liveSince($project) === null || $running === $patch->digest) {
            return null;
        }

        return $this->start($project, $patch, RuntimePatchMigration::PIN, $deployedBy, $initiatorId);
    }

    /** The project follows the newest patch again on its next deploy. */
    public function unpin(Project $project): void
    {
        $project->forceFill(['runtime_patch_pin' => null, 'runtime_patch_pinned_by' => null])->save();
    }

    // ── Following migrations ────────────────────────────────────────────────

    /** Every started migration of the current organization, one step further. */
    public function watch(): int
    {
        if (! $this->tracked()) {
            return 0;
        }

        $checked = 0;
        foreach (RuntimePatchMigration::query()->where('status', RuntimePatchMigration::STARTED)->get() as $migration) {
            try {
                $this->check($migration);
            } catch (\Throwable $e) {
                Log::warning('Runtime patch migration not checked', ['migration' => $migration->id, 'error' => $e->getMessage()]);
            }
            $checked++;
        }

        return $checked;
    }

    /** A deployment changed its status (model hook): its migration, if any, is checked now. */
    public function deploymentChanged(Deployment $deployment): void
    {
        if (! $this->tracked()) {
            return;
        }

        $queuedIds = QueuedDeploy::query()->where('deployment_id', $deployment->id)->pluck('id')->all();
        $migrations = RuntimePatchMigration::query()
            ->where('status', RuntimePatchMigration::STARTED)
            ->where(fn ($q) => $q->where('deployment_id', $deployment->id)->orWhereIn('queued_deploy_id', $queuedIds ?: ['']))
            ->get();

        foreach ($migrations as $migration) {
            $this->check($migration);
        }
    }

    public function check(RuntimePatchMigration $migration): void
    {
        $migration->refresh();
        if ($migration->status !== RuntimePatchMigration::STARTED) {
            return;
        }

        // A prebuilt version is built first: the queued deploy names the deployment once it started
        if ($migration->queued_deploy_id && ! $migration->deployment_id) {
            $queued = QueuedDeploy::query()->find($migration->queued_deploy_id);
            if (! $queued) {
                $this->failed($migration, 'The queued deploy of the migration is gone.');

                return;
            }
            if ($queued->deployment_id) {
                $migration->forceFill(['deployment_id' => $queued->deployment_id])->save();
            } elseif (in_array($queued->status, [QueuedDeploy::FAILED, QueuedDeploy::CANCELLED], true)) {
                $this->failed($migration, (string) ($queued->message ?: 'The build onto the patch failed.'));

                return;
            } else {
                $this->timeout($migration);

                return;
            }
        }

        $deployment = $migration->deployment_id ? Deployment::query()->find($migration->deployment_id) : null;
        if (! $deployment) {
            $this->failed($migration, 'The deployment of the migration was deleted.');

            return;
        }

        match (true) {
            $deployment->status === 'active' => $this->succeeded($migration),
            $deployment->status === 'failed' => $this->failed($migration, (string) ($deployment->knative_message ?: 'The new revision did not become ready.')),
            // Replaced after it went live: it worked; replaced before: a newer deploy took over
            in_array($deployment->status, ['shutting_down', 'shut_down'], true) => $deployment->ready_at !== null
                ? $this->succeeded($migration)
                : $this->finish($migration, RuntimePatchMigration::FAILED, 'Replaced by a newer deployment before it became ready.'),
            default => $this->timeout($migration),
        };
    }

    private function succeeded(RuntimePatchMigration $migration): void
    {
        $this->finish($migration, RuntimePatchMigration::SUCCEEDED, null);

        if ($migration->rollsBack()) {
            $project = Project::query()->find($migration->project_id);
            if ($project) {
                $this->notifier->migrated($project, $this->patches->find($migration->patch_id));
            }
        }
    }

    private function timeout(RuntimePatchMigration $migration): void
    {
        if ($migration->created_at && $migration->created_at->copy()->addMinutes(self::TIMEOUT_MINUTES)->isPast()) {
            $this->failed($migration, 'The migration did not finish within '.self::TIMEOUT_MINUTES.' minutes.');
        }
    }

    /** A migration RunR started is rolled back; one a user started just fails. */
    private function failed(RuntimePatchMigration $migration, string $reason): void
    {
        if (! $migration->rollsBack()) {
            $this->finish($migration, RuntimePatchMigration::FAILED, $reason);

            return;
        }

        $this->rollback($migration, $reason);
    }

    /**
     * Back to the digest the project ran before: a deployment of the version pinned to it (a
     * prebuilt version is built again FROM it), the project pinned there until someone unpins,
     * and a critical notification. A project deployed before patches were known goes back to
     * the newest patch older than the failed one — or stays on Knative's last ready revision
     * when there is none.
     */
    public function rollback(RuntimePatchMigration $migration, string $reason): void
    {
        $project = Project::query()->find($migration->project_id);
        $patch = $this->patches->find($migration->patch_id);
        if (! $project) {
            $this->finish($migration, RuntimePatchMigration::FAILED, $reason);

            return;
        }

        $digest = $migration->previous_digest;
        $previousPatch = $migration->previous_patch_id;
        if ($digest === null && $patch) {
            $older = $this->patches->of($project->runtime, $this->patches->versionOf($project))
                ->first(fn (RuntimePatch $p) => $p->published_at->lessThan($patch->published_at));
            [$digest, $previousPatch] = [$older?->digest, $older?->id];
        }

        $rolledBack = false;
        if ($digest !== null) {
            $pinnable = $previousPatch !== null && $this->patches->pinnable($project->runtime, $this->patches->versionOf($project))->contains('id', $previousPatch);
            $project->forceFill(['runtime_patch_pin' => $pinnable ? $previousPatch : $digest, 'runtime_patch_pinned_by' => self::SYSTEM])->save();

            $version = Version::query()->find($migration->version_id);
            $failed = $migration->deployment_id ? Deployment::query()->find($migration->deployment_id) : null;
            try {
                if ($version && ! $project->isStopped()) {
                    [$deployment, $queued] = $this->deployOnto($project, $version, $failed?->deploy_config, $digest, $previousPatch, self::SYSTEM, null,
                        __('runtime_patches.queued_rollback', ['version' => $version->number]));
                    // The rollback's deployment (a prebuilt version: once its build is ready, through the queued deploy)
                    $migration->forceFill(['rollback_deployment_id' => $deployment->id ?? $queued?->id])->save();
                    $rolledBack = true;
                }
            } catch (\Throwable $e) {
                $reason .= ' '.__('runtime_patches.notify.rollback_failed', ['error' => $e->getMessage()]);
                Log::error('Runtime patch rollback failed', ['project' => $project->slug, 'migration' => $migration->id, 'error' => $e->getMessage()]);
            }
        }

        $this->finish($migration, $rolledBack ? RuntimePatchMigration::ROLLED_BACK : RuntimePatchMigration::FAILED, $reason);
        $this->notifier->migrationFailed($project, $patch, $reason, $rolledBack);

        app(AuditLogger::class)->record('runtime_patch.rollback', [
            'actor' => 'system',
            'target' => ['type' => 'project', 'id' => $project->id, 'name' => $project->name],
            'result' => $rolledBack ? 'success' : 'failure',
            'metadata' => ['patch_id' => $migration->patch_id, 'digest' => $digest, 'reason' => mb_substr($reason, 0, 500)],
        ]);
    }

    private function finish(RuntimePatchMigration $migration, string $status, ?string $message): void
    {
        $migration->forceFill([
            'status' => $status,
            'message' => $message === null ? $migration->message : mb_substr($message, 0, 1000),
            'finished_at' => now(),
        ])->save();
    }

    // ── Deadlines ───────────────────────────────────────────────────────────

    /**
     * Critical and zero-day patches past their deadline: the projects still behind are moved onto
     * the newest patch in every organization — when the platform policy allows it.
     *
     * @return int migrations started
     */
    public function enforceDeadlines(): int
    {
        if (! RuntimePatchFeed::current()->autoMigrates()) {
            return 0;
        }

        $overdue = RuntimePatch::query()->where('in_feed', true)->whereIn('level', RuntimePatch::DEADLINE_LEVELS)
            ->orderBy('published_at')->get()->filter(fn (RuntimePatch $p) => $p->isOverdue());

        $started = 0;
        foreach ($overdue as $patch) {
            TenantScope::each(function () use ($patch, &$started) {
                $started += $this->migrateAffected($patch, RuntimePatchMigration::AUTO);
            });
        }

        return $started;
    }

    /**
     * Every project of the current organization that runs behind $patch, onto the newest patch.
     * At a deadline (AUTO) a project RunR tried this patch (or a newer one) on already is left
     * alone — after a rollback it stays where it works; FORCE (a platform administrator) tries again.
     *
     * @return int migrations started
     */
    public function migrateAffected(RuntimePatch $patch, string $trigger, ?string $initiatorId = null): int
    {
        if (! $this->tracked()) {
            return 0;
        }

        $sinceThis = $this->patches->of($patch->runtime, $patch->version)
            ->filter(fn (RuntimePatch $p) => ! $p->published_at->lessThan($patch->published_at))
            ->pluck('id')->push($patch->id)->unique()->values()->all();

        $started = 0;
        foreach ($this->patches->affected($patch) as $project) {
            $tried = RuntimePatchMigration::query()->where('project_id', $project->id)
                ->whereIn('patch_id', $sinceThis)
                ->whereIn('trigger', [RuntimePatchMigration::AUTO, RuntimePatchMigration::FORCE]);
            if ($trigger === RuntimePatchMigration::AUTO && $tried->exists()) {
                continue;
            }

            // The deadline is the end of waiting: a pin gives way
            if ($project->runtime_patch_pin) {
                $this->unpin($project);
            }

            try {
                $migration = $this->start($project, null, $trigger, self::SYSTEM, $initiatorId);
                $started++;
                app(AuditLogger::class)->record('runtime_patch.migrate', [
                    'actor' => $initiatorId ? null : 'system',
                    'target' => ['type' => 'project', 'id' => $project->id, 'name' => $project->name],
                    'metadata' => ['patch_id' => $migration->patch_id, 'deadline_patch_id' => $patch->id, 'trigger' => $trigger],
                ]);
            } catch (RuntimePatchException $e) {
                Log::warning('Runtime patch migration not started', ['project' => $project->slug, 'patch' => $patch->id, 'error' => $e->getMessage()]);
                if ($e->errorCode === 'deploy_refused') {
                    $this->notifier->migrationFailed($project, $patch, $e->getMessage(), false);
                }
            }
        }

        return $started;
    }

    /** Whether the current schema has the migrations table (a tenant migrated later skips). */
    private function tracked(): bool
    {
        static $memo = [];
        $key = (string) (TenantScope::current() ?? 'central');
        if (isset($memo[$key])) {
            return true;
        }

        // Only a table that exists is remembered: a long-lived worker asks again until the tenant's migration ran
        return Schema::hasTable('runtime_patch_migrations') ? $memo[$key] = true : false;
    }
}
