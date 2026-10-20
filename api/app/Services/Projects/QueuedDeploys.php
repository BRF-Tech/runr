<?php

namespace App\Services\Projects;

use App\Events\NotificationCreated;
use App\Jobs\ProcessQueuedDeployJob;
use App\Models\Deployment;
use App\Models\Notification;
use App\Models\Project;
use App\Models\QueuedDeploy;
use App\Models\Version;
use App\Services\GitDeploy\DeployCancelledException;
use App\Services\GitDeploy\DeploymentStarter;
use App\Services\GitDeploy\DeployPendingException;
use App\Services\GitDeploy\ImportException;
use App\Services\RegionMigrateService;
use App\Services\Runtimes\RuntimeImagePendingException;
use App\Services\Runtimes\RuntimeRegistry;
use App\Support\SchemaColumns;
use Illuminate\Support\Facades\Log;

/**
 * Deploy after build: a production deploy of a version that is queued until the version is built
 * (QueuedDeploy) and then started on the normal guarded deploy path — DeploymentStarter: the
 * version, region, data residency and supply-chain checks, ProjectAdmission (a stopped project, a
 * module's guard such as a fixed allocation), every live deployment of the project replaced.
 *
 *  - The version's build ends (Version hook): ready → the deploy starts; failed, cancelled or
 *    pruned → the queued deploy fails with the build's error.
 *  - A version without a build of its own runs on its runtime version's image; one RunR builds in
 *    the region from source may still be building — the deploy is checked again every
 *    WAIT_SECONDS, up to WAIT_ATTEMPTS times.
 *  - The version's supply-chain checks are still running (the policy gate waits for them): the
 *    deploy continues when they are final (ImageSecurityReport hook).
 *  - A deploy of the project after it was queued (or a newer queued one) replaces it.
 *  - One step claims it (status starting) and, once the deploy checks passed, the deployment it
 *    creates (deployment_id, set before the deployment is created): until then it can still be
 *    cancelled (cancelUnstarted(), a region migration rollback), and the step then creates
 *    nothing.
 *
 * The live deployment it takes over from (replaces_deployment_id) stays live until the deploy
 * starts. When the queued deploy fails, that deployment keeps running — unless its reason ends it:
 * a region migration's (REASON_REGION_MIGRATION) runs on the cluster the region left, so it ends
 * with "Not moved to the new cluster of the region: …" (RegionMigrateService::NOT_MOVED), as a
 * project the move refused. A newer queued deploy that replaces such a deploy while it waits and
 * that deployment is still live — a deploy of an older version during the move (REASON_REBUILD)
 * — takes its place: it takes over from the same deployment and carries the reason on
 * (replaces_reason), so its failure ends that deployment the same way, and the move's report
 * follows it (RegionMigrateService::migrationReport()). A deploy of a version built again for
 * its region's registry (REASON_REBUILD, VersionRebuilds) that the deploy checks refuse tells the
 * user who asked for it (a notification), since no deployment shows it.
 */
final class QueuedDeploys
{
    /** Queued by a region migration for a version built again on the new cluster. */
    public const REASON_REGION_MIGRATION = 'region-migration';

    /** Queued by a deploy of a version built again for its region's registry (VersionRebuilds). */
    public const REASON_REBUILD = 'registry-rebuild';

    /** A runtime image still building in the region: checked again every WAIT_SECONDS, WAIT_ATTEMPTS times (about 17 minutes) */
    public const WAIT_SECONDS = 10;

    public const WAIT_ATTEMPTS = 100;

    /** reason => what the deployment it replaces ends with when the queued deploy fails */
    private const ENDS_REPLACED = [
        self::REASON_REGION_MIGRATION => RegionMigrateService::NOT_MOVED,
    ];

    public function __construct(
        private DeploymentStarter $starter,
        private RuntimeRegistry $runtimes,
    ) {}

    /**
     * Queue a deploy of $version. A deploy of the project that still waits is replaced by it — and
     * when that one ends the deployment it replaces on a failure (a region migration's) and the
     * deployment is still live, the new deploy takes its place (placeTakenOver()): it replaces that
     * deployment and ends it the same way when it fails.
     *
     * @param  array{deploy_config?: array<string, mixed>|null, initiator_id?: string|null, reason?: string|null, replaces?: Deployment|null, previous_build_image?: string|null, message?: string|null}  $options
     */
    public function queue(Project $project, Version $version, string $deployedBy, array $options = []): QueuedDeploy
    {
        $place = isset($options['replaces']) ? null : $this->placeTakenOver($project);
        $this->cancelWaiting($project, 'Replaced by a newer queued deploy of the project.');

        /** @var QueuedDeploy $queued */
        $queued = QueuedDeploy::query()->create([
            'project_id' => $project->id,
            'version_id' => $version->id,
            'status' => QueuedDeploy::WAITING,
            'reason' => $options['reason'] ?? null,
            'deployed_by' => mb_substr($deployedBy, 0, 250),
            'initiator_id' => $options['initiator_id'] ?? null,
            'deploy_config' => ($options['deploy_config'] ?? null) ?: null,
            'replaces_deployment_id' => isset($options['replaces']) ? $options['replaces']->id : $place?->replaces_deployment_id,
            'previous_build_image' => $options['previous_build_image'] ?? null,
            'message' => $options['message'] ?? "Waits for the build of v{$version->number}.",
        ] + ($place !== null ? ['replaces_reason' => self::endingReason($place)] : []));

        return $queued;
    }

    /** Version hook: the build of $version ended — its waiting deploys go on. */
    public function versionBuilt(Version $version): void
    {
        if (in_array($version->build_status, ['pending', 'building'], true)) {
            return;
        }

        $this->dispatchWaiting($version->id);
    }

    /** Supply-chain hook: the scan / signature of the version is final — its waiting deploys go on. */
    public function scanFinished(string $versionId): void
    {
        $this->dispatchWaiting($versionId);
    }

    /**
     * Take a waiting deploy one step further: wait, start the deployment, or end it.
     */
    public function process(QueuedDeploy $queued): void
    {
        // Deleted with its project or version, or ended meanwhile
        $stored = QueuedDeploy::query()->find($queued->getKey());
        if (! $stored || $stored->status !== QueuedDeploy::WAITING) {
            return;
        }
        $queued->setRawAttributes($stored->getAttributes(), true);

        $project = Project::query()->find($queued->project_id);
        $version = Version::query()->find($queued->version_id);
        if (! $project || ! $version) {
            $this->finish($queued, QueuedDeploy::CANCELLED, 'The project or its version was deleted.');

            return;
        }

        if ($this->replacedByNewerDeploy($queued)) {
            $this->finish($queued, QueuedDeploy::CANCELLED, 'Replaced by a newer deploy of the project.');

            return;
        }

        $label = "v{$version->number}";
        if ($version->build_status === Version::STATUS_CANCELLED) {
            // Stopped by RunR (a region migration rollback): nothing failed
            $this->finish($queued, QueuedDeploy::CANCELLED, "The build of {$label} was cancelled".($version->build_error ? ": {$version->build_error}" : '.'));

            return;
        }

        $built = match ($version->build_status) {
            'pending', 'building' => null,
            'ready' => true,
            Version::STATUS_PRUNED => "the build image of {$label} was removed by registry retention",
            default => "{$label} could not be built: ".($version->build_error ?: 'no details'),
        };
        if ($built === null) {
            return; // the Version hook comes back when the build ends
        }
        if ($built !== true) {
            $this->fail($queued, $built);

            return;
        }

        if ($this->runtimeImagePending($project, $version)) {
            $this->waitForRuntimeImage($queued, $project);

            return;
        }

        // Two steps at once (the build's hook and a scan's, say) start one deployment: the one that
        // claims the queued deploy
        if (QueuedDeploy::query()->whereKey($queued->getKey())->where('status', QueuedDeploy::WAITING)->update(['status' => QueuedDeploy::STARTING]) !== 1) {
            return;
        }
        $queued->setAttribute('status', QueuedDeploy::STARTING)->syncOriginalAttribute('status');

        try {
            $deployment = $this->starter->start(
                $project->loadMissing('knativeRegion'),
                $version,
                $queued->deployed_by,
                $queued->initiator_id,
                $queued->deploy_config,
                // The checks passed: the deployment is claimed for this queued deploy, unless it
                // was cancelled meanwhile (cancelUnstarted())
                fn (string $deploymentId) => QueuedDeploy::query()->whereKey($queued->getKey())
                    ->where('status', QueuedDeploy::STARTING)
                    ->whereNull('deployment_id')
                    ->update(['deployment_id' => $deploymentId, 'updated_at' => now()]) === 1,
            );
        } catch (DeployCancelledException) {
            Log::info("Queued deploy of {$project->slug} {$label} was cancelled before its deployment was created", ['queued_deploy' => $queued->id]);

            return;
        } catch (DeployPendingException $e) {
            // The supply-chain hook comes back once the checks are final
            $queued->forceFill(['status' => QueuedDeploy::WAITING, 'message' => mb_substr($e->getMessage(), 0, 1000)])->save();

            return;
        } catch (ImportException $e) {
            $this->fail($queued, $e->getMessage(), notify: true);

            return;
        } catch (\Throwable $e) {
            $this->fail($queued, 'the deploy could not start: '.$e->getMessage(), notify: true);

            throw $e;
        }

        $queued->forceFill([
            'status' => QueuedDeploy::DEPLOYED,
            'deployment_id' => $deployment->id,
            'message' => "{$label} was built and its deploy started.",
        ])->save();
        Log::info("Queued deploy of {$project->slug} {$label} started", ['queued_deploy' => $queued->id, 'deployment' => $deployment->id]);
    }

    /**
     * End $queued unless its deployment was created already: a waiting deploy, or one a step
     * claimed (starting) that has not reached its deployment yet — that step then creates none
     * (DeploymentStarter's claim). Of the cancel and the step's claim exactly one wins.
     *
     * @return bool whether it was cancelled
     */
    public function cancelUnstarted(QueuedDeploy $queued, string $message): bool
    {
        $cancelled = QueuedDeploy::query()->whereKey($queued->getKey())
            ->whereIn('status', [QueuedDeploy::WAITING, QueuedDeploy::STARTING])
            ->whereNull('deployment_id')
            ->update(['status' => QueuedDeploy::CANCELLED, 'message' => mb_substr($message, 0, 1000), 'updated_at' => now()]) === 1;

        $queued->refresh();

        return $cancelled;
    }

    /**
     * End the waiting deploys of the project (a deploy, a stop or a rollback took over).
     *
     * @return int how many
     */
    public function cancelWaiting(Project $project, string $message): int
    {
        $cancelled = 0;
        foreach (QueuedDeploy::query()->waiting()->where('project_id', $project->id)->get() as $queued) {
            $this->finish($queued, QueuedDeploy::CANCELLED, $message);
            $cancelled++;
        }

        return $cancelled;
    }

    /**
     * The waiting deploy of the project whose place a newer queued deploy takes: the latest one
     * that ends the deployment it replaces on a failure (endingReason()) while that deployment is
     * still live. Null when there is none — or when that deployment was replaced or stopped
     * meanwhile (a deploy that did not wait for a build, a stop): nothing runs on the cluster the
     * region left any more, and the new deploy is one like any other.
     */
    private function placeTakenOver(Project $project): ?QueuedDeploy
    {
        // A tenant schema whose migration has not run yet keeps the deploy as it was
        if (! SchemaColumns::has(new QueuedDeploy, 'replaces_reason')) {
            return null;
        }

        $waiting = QueuedDeploy::query()->waiting()->where('project_id', $project->id)
            ->whereNotNull('replaces_deployment_id')
            ->latest('created_at')->latest('id')
            ->get();
        foreach ($waiting as $queued) {
            if (self::endingReason($queued) !== null
                && Deployment::query()->whereKey($queued->replaces_deployment_id)->whereIn('status', Deployment::LIVE)->exists()) {
                return $queued;
            }
        }

        return null;
    }

    /**
     * The reason whose end (ENDS_REPLACED) the deployment $queued replaces gets when it fails: its
     * own, or that of the deploy whose place it took (replaces_reason). Null: that deployment
     * keeps running.
     */
    private static function endingReason(QueuedDeploy $queued): ?string
    {
        foreach ([$queued->reason, $queued->replaces_reason] as $reason) {
            if ($reason !== null && array_key_exists($reason, self::ENDS_REPLACED)) {
                return $reason;
            }
        }

        return null;
    }

    private function dispatchWaiting(string $versionId): void
    {
        $ids = QueuedDeploy::query()->waiting()->where('version_id', $versionId)->pluck('id');
        foreach ($ids as $id) {
            ProcessQueuedDeployJob::dispatch((string) $id)->afterCommit();
        }
    }

    /**
     * A deployment of the project created after the deploy was queued: the newer deploy wins (a
     * deploy from the console, a git push, a resume).
     */
    private function replacedByNewerDeploy(QueuedDeploy $queued): bool
    {
        return Deployment::query()
            ->where('project_id', $queued->project_id)
            ->where(fn ($q) => $q->where('created_at', '>', $queued->created_at)
                ->orWhere(fn ($same) => $same->where('created_at', $queued->created_at)->where('id', '>', $queued->getKey())))
            ->exists();
    }

    /**
     * Whether the version runs on a runtime version image RunR builds in the project's region and
     * that build is not ready yet (RuntimeRegistry starts it then). A version with a build of its
     * own, a CaaS project and a region without builds are never waiting; any other problem with
     * the image is the deploy's to report.
     */
    private function runtimeImagePending(Project $project, Version $version): bool
    {
        $region = $project->knativeRegion;
        if ($project->service_type === 'caas' || $version->build_image || ! $region || $region->is_demo) {
            return false;
        }

        try {
            $this->runtimes->versionImage($this->runtimes->forProject($project), $this->runtimes->executorFor($project), $region);
        } catch (RuntimeImagePendingException) {
            return true;
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    private function waitForRuntimeImage(QueuedDeploy $queued, Project $project): void
    {
        if ($queued->attempts >= self::WAIT_ATTEMPTS) {
            $this->fail($queued, 'the runtime image of '.($project->runtime ?? 'its runtime').' was not built in the region within '
                .intdiv(self::WAIT_SECONDS * self::WAIT_ATTEMPTS, 60).' minutes (Settings → Runtimes shows the build)');

            return;
        }

        $queued->forceFill([
            'attempts' => $queued->attempts + 1,
            'message' => 'Waits for the runtime image of '.($project->runtime ?? 'its runtime').', which is built in the region.',
        ])->save();
        ProcessQueuedDeployJob::dispatch($queued->id)->delay(now()->addSeconds(self::WAIT_SECONDS));
    }

    /**
     * The deploy cannot happen. The deployment it replaces ends when its reason — or that of the
     * deploy whose place it took — says so (a migration's: the project is not moved), with the
     * reason as its message. $notify: the deploy itself was refused or failed to start (a failed
     * build has its own notification).
     */
    private function fail(QueuedDeploy $queued, string $reason, bool $notify = false): void
    {
        $this->finish($queued, QueuedDeploy::FAILED, ucfirst($reason));
        Log::warning("Queued deploy of project {$queued->project_id} failed", ['queued_deploy' => $queued->id, 'reason' => $reason]);

        $ending = self::endingReason($queued);
        $replaced = $ending !== null && $queued->replaces_deployment_id
            ? Deployment::query()->find($queued->replaces_deployment_id)
            : null;
        if ($ending !== null && $replaced !== null) {
            // Nothing of it runs where the project's region is now: neither metered nor counted as running
            $replaced->transition(Deployment::LIVE, [
                'status' => 'shut_down',
                'knative_ready' => false,
                'knative_message' => mb_substr(self::ENDS_REPLACED[$ending].$reason, 0, 255),
            ]);
        }

        if ($notify) {
            $this->notifyInitiator($queued, $reason);
        }
    }

    /**
     * The user whose deploy waited (a version built again for the region's registry) hears that
     * it did not start — no deployment shows it. Best effort.
     */
    private function notifyInitiator(QueuedDeploy $queued, string $reason): void
    {
        if ($queued->reason !== self::REASON_REBUILD || ! $queued->initiator_id) {
            return;
        }

        try {
            $project = Project::query()->find($queued->project_id);
            $number = Version::query()->whereKey($queued->version_id)->value('number');
            $notification = Notification::create([
                'user_id' => $queued->initiator_id,
                'type' => 'deployment.queued_failed',
                'level' => 'critical',
                'title' => mb_substr('Deploy of '.($project->name ?? 'a project').' v'.($number ?? '?').' did not start', 0, 255),
                'body' => ucfirst($reason),
                'data' => ['project_id' => $queued->project_id, 'project_name' => $project?->name, 'version' => $number, 'queued_deploy_id' => $queued->id, 'error' => ucfirst($reason)],
            ]);
            NotificationCreated::dispatch($notification);
        } catch (\Throwable $e) {
            Log::warning('Queued deploy failure not notified', ['queued_deploy' => $queued->id, 'error' => $e->getMessage()]);
        }
    }

    private function finish(QueuedDeploy $queued, string $status, string $message): void
    {
        $queued->forceFill(['status' => $status, 'message' => mb_substr($message, 0, 1000)])->save();
    }
}
