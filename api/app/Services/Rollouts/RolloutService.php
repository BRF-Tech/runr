<?php

namespace App\Services\Rollouts;

use App\Http\Resources\DeploymentRolloutResource;
use App\Jobs\AdvanceRolloutJob;
use App\Models\Deployment;
use App\Models\DeploymentRollout;
use App\Models\Project;
use App\Models\Version;
use App\Services\Audit\AuditLogger;
use App\Services\Concerns\BroadcastsModelEvents;
use App\Services\Environments\EnvironmentScope;
use App\Services\GlobalProjects\GlobalReplicaDeployer;
use App\Services\Helpers\ResourceReservation;
use App\Services\KubernetesClient;
use App\Services\Projects\LiveDeployments;
use App\Support\SchemaColumns;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Blue-green and canary rollouts (docs/deploy-strategies.md).
 *
 * A deploy of a project whose strategy is blue_green or canary — while a deployment serves it —
 * opens a rollout instead of replacing what serves:
 *
 *   deploy      plan() finds the deployment that serves ("stable", kept live by
 *               LiveDeployments::supersede), open() records the rollout; the deploy's apply names
 *               the new revision and pins the traffic: stable 100 %, new 0 % under its tag
 *               (manifestsFor, RolloutTraffic)
 *   ready       the readiness check hands the ready revision over (candidateReady): the new
 *               deployment is running next to the active one; blue-green waits for a promotion
 *               (waiting_promotion) or checks its health path and promotes itself, canary enters
 *               its first step
 *   steps       AdvanceRolloutJob looks at the rollout when next_check_at comes (advance): health
 *               checks, the canary's thresholds (RolloutMetrics) and the next traffic step
 *   promote     all traffic to the new revision; the deployments swap (LiveDeployments::swap);
 *               blue-green keeps the previous revision warm until keep_until (rollback() switches
 *               back at once), then releases it (Knative's default traffic again)
 *   abort       the new revision leaves the split, traffic stays where it was; a threshold breach
 *               of a canary step does the same automatically (rolled_back)
 *
 * Every change runs under a lock per rollout and is broadcast (deployment.rollout). Deployments
 * that end outside of the rollout (a stop, a failed readiness, a delete) end it too
 * (settleOrphan, via EndRolloutWithDeployment and the minute safety net advanceOverdue()).
 *
 * An environment of the project (docs/deploy-strategies.md#environments) rolls out the same way
 * with its own strategy (DeployStrategies: the project's unless it sets its own): $project is the
 * environment's view (EnvironmentProject), the rollout carries its environment_id, and the
 * traffic, the health checks, the canary's metrics and the warmer act on its Knative Service
 * <workload> in its region (DeploymentRollout::workload()). Rollouts of one workload never touch
 * another's deployments.
 */
class RolloutService
{
    use BroadcastsModelEvents;

    public function __construct(
        private RolloutTraffic $traffic,
        private RolloutHealthProbe $probe,
        private RolloutMetrics $metrics,
        private LiveDeployments $live,
        private DeployStrategies $strategies,
    ) {}

    // ── Deploy ──────────────────────────────────────────────────────────────

    /**
     * How a deploy of $project about to be created rolls out: its strategy, the options and the
     * deployment that serves; null for a direct deploy — strategy direct (or $override "direct"),
     * a queue / schedule function, a stopped project, a region without a cluster, or nothing
     * that serves yet (the first deploy).
     *
     * @return array{strategy: string, options: array<string, mixed>, stable: Deployment}|null
     */
    public function plan(Project $project, ?string $override = null): ?array
    {
        if (! DeploymentRollout::available()) {
            return null;
        }

        $settings = DeployStrategy::forProject($project);
        $name = $override !== null && $override !== '' ? (DeployStrategy::normalizeName($override) ?? DeployStrategy::DIRECT) : $settings->strategy;
        if ($name === DeployStrategy::DIRECT || ! DeployStrategy::eligibleTrigger(ResourceReservation::configOf($project)?->trigger_type)) {
            return null;
        }

        $region = $project->knativeRegion;
        if (! $region || $region->is_demo || ! $region->kubeconfig || $project->isStopped()) {
            return null;
        }

        $stable = Deployment::forEnvironment(Deployment::query()->where('project_id', $project->id), EnvironmentScope::idOf($project))
            ->where('status', 'active')
            ->where('knative_ready', true)
            ->orderByDesc('created_at')
            ->first();

        return $stable ? ['strategy' => $name, 'options' => $settings->options($name), 'stable' => $stable] : null;
    }

    /**
     * Before a new deploy of the project: the rollouts that pin its traffic end. An open one is
     * replaced (aborted) — its new revision's share goes back to the revision that served first,
     * so the replaced deployment's teardown never removes what takes traffic; a promoted one lets
     * its previous revision go (LiveDeployments::supersede retires it). The new deploy's apply
     * writes the traffic again.
     */
    public function supersede(Project $project, ?string $actor = null): void
    {
        if (! DeploymentRollout::available()) {
            return;
        }

        foreach (DeploymentRollout::query()->ofEnvironment((string) $project->id, EnvironmentScope::idOf($project))->holding()->get() as $rollout) {
            $this->locked($rollout, function (DeploymentRollout $rollout) use ($project, $actor) {
                if ($rollout->isOpen()) {
                    if ($rollout->traffic_percent > 0 && $rollout->stable_revision) {
                        $this->traffic->set($rollout, $project, RolloutTraffic::only($rollout->stable_revision));
                    }
                    $this->finish($rollout, DeploymentRollout::ABORTED, __('rollouts.reason.replaced'), $actor);
                } elseif ($rollout->keepsPrevious()) {
                    $rollout->forceFill(['released_at' => now(), 'next_check_at' => null]);
                    $rollout->addEvent('released', __('rollouts.event.released_replaced'), $actor);
                    $rollout->save();
                    $this->broadcast($rollout);
                }
            });
        }
    }

    /** Records the rollout of a deployment a deploy with $plan just created. */
    public function open(array $plan, Deployment $deployment, Project $project, Version $version, ?string $actor = null): DeploymentRollout
    {
        /** @var Deployment $stable */
        $stable = $plan['stable'];
        $strategy = (string) $plan['strategy'];

        $rollout = new DeploymentRollout([
            'project_id' => $project->id,
            'deployment_id' => $deployment->id,
            'stable_deployment_id' => $stable->id,
            'region_id' => $project->knative_region_id,
            'strategy' => $strategy,
            'status' => DeploymentRollout::PENDING,
            'options' => (array) $plan['options'],
            'revision' => RolloutTraffic::revisionName($project, $version, $deployment),
            'stable_revision' => $this->revisionOf($stable),
            'tag' => RolloutTraffic::TAGS[$strategy] ?? 'green',
            'traffic_percent' => 0,
            'created_by' => $actor !== null ? mb_substr($actor, 0, 250) : null,
        ]);
        if (($environmentId = EnvironmentScope::idOf($project)) !== null && SchemaColumns::has($rollout, 'environment_id')) {
            $rollout->setAttribute('environment_id', $environmentId);
        }
        $rollout->addEvent('created', __('rollouts.event.created', [
            'strategy' => __('rollouts.strategy.'.$strategy),
            'version' => $version->label,
        ]), $actor, 0);
        $rollout->save();

        $this->broadcast($rollout);

        return $rollout;
    }

    /**
     * The revision a deployment runs as the rollouts it took part in recorded it; null when none
     * did (its deploy's apply reads it from the cluster).
     */
    public function revisionOf(Deployment $deployment): ?string
    {
        if (! DeploymentRollout::available()) {
            return null;
        }

        $own = DeploymentRollout::query()->where('deployment_id', $deployment->id)->whereNotNull('revision')
            ->whereNotIn('status', [DeploymentRollout::PENDING, DeploymentRollout::FAILED])->latest('id')->value('revision');

        return $own ?: DeploymentRollout::query()->where('stable_deployment_id', $deployment->id)->whereNotNull('stable_revision')->latest('id')->value('stable_revision');
    }

    /**
     * The manifests a deploy applies (KnativeDeployService::deploy). A rollout's deployment names
     * its revision and pins the traffic to the revision that serves, read from the cluster; when
     * nothing serves there (it moved to another cluster, the service is gone) the rollout goes
     * away and the deploy is direct. A direct deploy of a project that rolled out before writes
     * Knative's default traffic back (a rollout may have left the traffic pinned).
     *
     * @param  list<array>  $manifests
     * @return list<array>
     */
    public function manifestsFor(Project $project, Version $version, Deployment $deployment, array $manifests, KubernetesClient $k8s, string $namespace): array
    {
        if (! DeploymentRollout::available()) {
            return $manifests;
        }

        $rollout = DeploymentRollout::query()->where('deployment_id', $deployment->id)->where('status', DeploymentRollout::PENDING)->first();
        if ($rollout) {
            $ksvc = $k8s->getResourceStatus('serving.knative.dev/v1', 'Service', $project->slug, $namespace);
            $stable = RolloutTraffic::servingRevision($ksvc, $rollout->stable_revision);
            if ($stable !== null) {
                $revision = $rollout->revision ?: RolloutTraffic::revisionName($project, $version, $deployment);
                $rollout->forceFill(['revision' => $revision, 'stable_revision' => $stable, 'region_id' => $project->knative_region_id])->save();

                return RolloutTraffic::pinCandidate($manifests, $revision, $stable, $rollout->tag);
            }

            $this->dissolve($rollout);
        }

        return DeploymentRollout::query()->ofEnvironment((string) $project->id, EnvironmentScope::idOf($project))->exists()
            ? RolloutTraffic::withLatestTraffic($manifests)
            : $manifests;
    }

    // ── Readiness ───────────────────────────────────────────────────────────

    /** The open rollout of a deployment (its new revision); null for a direct deploy. */
    public function pendingFor(Deployment $deployment): ?DeploymentRollout
    {
        if (! DeploymentRollout::available()) {
            return null;
        }

        return DeploymentRollout::query()->where('deployment_id', $deployment->id)->whereIn('status', DeploymentRollout::OPEN)->first();
    }

    /**
     * The rollout's new revision is ready (CheckDeploymentReadyJob): the deployment runs next to
     * the active one and the strategy takes over.
     */
    public function candidateReady(DeploymentRollout $rollout, Deployment $deployment, ?string $message = null): void
    {
        $this->locked($rollout, function (DeploymentRollout $rollout) use ($deployment, $message) {
            $project = $rollout->workload();
            if ($rollout->status !== DeploymentRollout::PENDING) {
                return;
            }

            if (! $rollout->stableDeployment?->isLive()) {
                // What served ended while the new revision started: it takes over directly
                $this->settleOrphan($rollout, $project);

                return;
            }

            if (! $deployment->transition(['pending', 'running'], [
                'status' => 'running',
                'knative_ready' => true,
                'knative_message' => $message !== null ? mb_substr($message, 0, 255) : null,
                'ready_at' => now(),
            ])) {
                // replaced or stopped meanwhile: the orphan check ends the rollout
                return;
            }

            $rollout->addEvent('ready', __('rollouts.event.ready', ['revision' => $rollout->revision]), 'system', 0);
            $options = (array) $rollout->options;

            if ($rollout->strategy === DeploymentRollout::CANARY) {
                $this->enterStep($rollout, $project, 0, 'system');

                return;
            }

            if (! ($options['auto_promote'] ?? false)) {
                $rollout->forceFill(['status' => DeploymentRollout::WAITING_PROMOTION, 'next_check_at' => null])->save();
                $this->broadcast($rollout);

                return;
            }

            // Promotes itself: the first check (or, without a health path, the promotion) runs in
            // AdvanceRolloutJob right away, not in the readiness check
            $path = $options['health_path'] ?? null;
            $rollout->forceFill([
                'status' => DeploymentRollout::PROGRESSING,
                'health' => [
                    'path' => is_string($path) && $path !== '' ? $path : null,
                    'passes' => 0,
                    'required' => (int) ($options['health_checks'] ?? 3),
                    'last_status' => null,
                    'last_error' => null,
                    'checked_at' => null,
                    'deadline_at' => now()->addSeconds(max(10, (int) config('knative.rollouts.health_deadline_seconds', 300)))->toIso8601String(),
                ],
                'next_check_at' => now(),
            ])->save();
            $this->broadcast($rollout);
        });
    }

    /**
     * The rollout's new deployment failed (the apply, the readiness check or the deploy job): the
     * rollout fails and the new revision leaves the split — the traffic stays where it was.
     */
    public function candidateFailed(Deployment $deployment, string $reason): void
    {
        $rollout = $this->pendingFor($deployment);
        if (! $rollout) {
            return;
        }

        $this->locked($rollout, function (DeploymentRollout $rollout) use ($reason) {
            $project = $rollout->workload();
            if (! $rollout->isOpen()) {
                return;
            }

            $this->restore($rollout, $project);
            $this->finish($rollout, DeploymentRollout::FAILED, __('rollouts.reason.candidate_failed', ['reason' => mb_substr($reason, 0, 200)]), 'system');
            $this->audit('deployment.rollout.failed', $rollout, ['reason' => $rollout->reason]);
        });
    }

    // ── Actions ─────────────────────────────────────────────────────────────

    /**
     * All traffic to the new revision now (a blue-green waiting for its promotion, a canary
     * before its last step).
     *
     * @throws RolloutActionException
     */
    public function promote(DeploymentRollout $rollout, ?string $actor): DeploymentRollout
    {
        return $this->locked($rollout, function (DeploymentRollout $rollout) use ($actor) {
            $project = $rollout->workload();
            if (! in_array($rollout->status, [DeploymentRollout::PROGRESSING, DeploymentRollout::WAITING_PROMOTION], true)) {
                throw new RolloutActionException(__('rollouts.not_promotable', ['status' => $rollout->status]), 'rollout_not_promotable');
            }

            $this->promoteNow($rollout, $project, $actor, false);

            return $rollout;
        }) ?? throw new RolloutActionException(__('rollouts.not_found'), 'rollout_not_promotable', 404);
    }

    /**
     * Ends an open rollout: the new revision leaves the split, the traffic stays with the
     * revision that served, the new deployment is torn down.
     *
     * @throws RolloutActionException
     */
    public function abort(DeploymentRollout $rollout, ?string $actor, ?string $reason = null): DeploymentRollout
    {
        return $this->locked($rollout, function (DeploymentRollout $rollout) use ($actor, $reason) {
            $project = $rollout->workload();
            if (! $rollout->isOpen()) {
                throw new RolloutActionException(__('rollouts.not_abortable', ['status' => $rollout->status]), 'rollout_not_abortable');
            }

            if (! $this->restore($rollout, $project)) {
                $this->trafficFailed($rollout, $actor);
            }

            if (($candidate = $rollout->deployment) && $candidate->isLive()) {
                $this->live->retire($candidate);
            }
            $this->finish($rollout, DeploymentRollout::ABORTED, $reason !== null && trim($reason) !== ''
                ? mb_substr(trim($reason), 0, 500)
                : __('rollouts.reason.aborted', ['actor' => $actor ?? 'system']), $actor);

            return $rollout;
        }) ?? throw new RolloutActionException(__('rollouts.not_found'), 'rollout_not_abortable', 404);
    }

    /**
     * Back to the previous revision after a promotion, while it is still kept warm: all traffic
     * to it at once, the deployments swap back.
     *
     * @throws RolloutActionException
     */
    public function rollback(DeploymentRollout $rollout, ?string $actor, ?string $reason = null): DeploymentRollout
    {
        return $this->locked($rollout, function (DeploymentRollout $rollout) use ($actor, $reason) {
            $previous = $rollout->stableDeployment;
            $project = $rollout->workload();
            if (! $rollout->keepsPrevious() || ! $rollout->stable_revision || ! $previous?->isLive()) {
                throw new RolloutActionException(__('rollouts.not_rollbackable', ['status' => $rollout->status]), 'rollout_not_rollbackable');
            }

            if (! $this->traffic->set($rollout, $project, RolloutTraffic::only($rollout->stable_revision))) {
                $this->trafficFailed($rollout, $actor);
            }

            if ($candidate = $rollout->deployment) {
                $this->live->swap($previous, $candidate, false);
            }
            $this->finish($rollout, DeploymentRollout::ROLLED_BACK, $reason !== null && trim($reason) !== ''
                ? mb_substr(trim($reason), 0, 500)
                : __('rollouts.reason.rolled_back', ['actor' => $actor ?? 'system']), $actor);

            return $rollout;
        }) ?? throw new RolloutActionException(__('rollouts.not_found'), 'rollout_not_rollbackable', 404);
    }

    // ── Time ────────────────────────────────────────────────────────────────

    /**
     * Looks at a rollout whose next check is due (AdvanceRolloutJob, advanceOverdue): ends it
     * when its deployments ended, checks the health of a blue-green revision, judges and moves a
     * canary step, releases a previous revision whose time is up. Never waits for the lock.
     */
    public function advance(DeploymentRollout $rollout): void
    {
        $this->locked($rollout, function (DeploymentRollout $rollout) {
            $project = $rollout->workload();
            if (! $rollout->holdsTraffic()) {
                return;
            }
            if ($this->settleOrphan($rollout, $project)) {
                return;
            }
            if ($rollout->next_check_at === null || $rollout->next_check_at->isFuture()) {
                return;
            }

            if ($rollout->status === DeploymentRollout::PROMOTED) {
                $this->release($rollout, $project);
            } elseif ($rollout->status === DeploymentRollout::PROGRESSING && $rollout->strategy === DeploymentRollout::CANARY) {
                $this->canaryStep($rollout, $project);
            } elseif ($rollout->status === DeploymentRollout::PROGRESSING) {
                $this->healthStep($rollout, $project);
            }
        }, false);
    }

    /**
     * The minute safety net (AdvanceRolloutsJob): rollouts whose check is overdue — its job was
     * lost (a worker restart) — and those without a timer (pending, waiting for a promotion),
     * whose deployments may have ended. In the current schema.
     */
    public function advanceOverdue(): int
    {
        if (! DeploymentRollout::available()) {
            return 0;
        }

        $rollouts = DeploymentRollout::query()->holding()
            ->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()->subSeconds(30)))
            ->orderBy('next_check_at')
            ->limit(500)
            ->get();

        foreach ($rollouts as $rollout) {
            try {
                $this->advance($rollout);
            } catch (\Throwable $e) {
                Log::warning('Rollout check failed', ['rollout' => $rollout->id, 'error' => $e->getMessage()]);
            }
        }

        return $rollouts->count();
    }

    /**
     * A deployment ended (EndRolloutWithDeployment): the rollouts it took part in look again. A
     * rollout someone holds is left alone — the holder is the one ending the deployment (an
     * abort, a promotion, a release), and the minute safety net looks at whatever is left.
     */
    public function deploymentEnded(Deployment $deployment): void
    {
        if (! DeploymentRollout::available()) {
            return;
        }

        $rollouts = DeploymentRollout::query()->holding()
            ->where(fn ($q) => $q->where('deployment_id', $deployment->id)->orWhere('stable_deployment_id', $deployment->id))
            ->get();
        foreach ($rollouts as $rollout) {
            $this->locked($rollout, function (DeploymentRollout $rollout) {
                $project = $rollout->workload();
                if ($rollout->holdsTraffic()) {
                    $this->settleOrphan($rollout, $project);
                }
            }, false);
        }
    }

    /**
     * Before a deployment is deleted (DELETE …/deployments/{deployment}): the new deployment of an
     * open rollout is aborted first, a kept previous revision (or the promoted one) lets go of the
     * other. The deployment that serves while a rollout is under way cannot go.
     *
     * @throws RolloutActionException
     */
    public function beforeDelete(Deployment $deployment, ?string $actor): void
    {
        if (! DeploymentRollout::available()) {
            return;
        }

        $rollouts = DeploymentRollout::query()->holding()
            ->where(fn ($q) => $q->where('deployment_id', $deployment->id)->orWhere('stable_deployment_id', $deployment->id))
            ->get();

        foreach ($rollouts as $rollout) {
            if ($rollout->isOpen() && $rollout->stable_deployment_id === $deployment->id) {
                throw new RolloutActionException(__('rollouts.stable_in_use'), 'rollout_in_progress');
            }
            if ($rollout->isOpen()) {
                $this->abort($rollout, $actor, __('rollouts.reason.deleted'));

                continue;
            }

            $this->locked($rollout, function (DeploymentRollout $rollout) use ($actor) {
                $project = $rollout->workload();
                if ($rollout->keepsPrevious()) {
                    $this->release($rollout, $project, $actor);
                }
            });
        }
    }

    // ── Steps ───────────────────────────────────────────────────────────────

    /** Canary: the step $index takes effect (its share to the new revision); at 100 % it is promoted. */
    private function enterStep(DeploymentRollout $rollout, Project $project, int $index, ?string $actor): void
    {
        $steps = $rollout->steps() ?: [100];
        $percent = $steps[$index] ?? 100;
        if ($percent >= 100) {
            try {
                $this->promoteNow($rollout, $project, $actor, true);
            } catch (RolloutActionException $e) {
                $this->retryLater($rollout, $e);
            }

            return;
        }

        if (! $rollout->stable_revision || ! $rollout->revision
            || ! $this->traffic->set($rollout, $project, RolloutTraffic::split($rollout->stable_revision, $rollout->revision, $percent, $rollout->tag))) {
            // tried again on the next check
            $rollout->forceFill(['status' => DeploymentRollout::PROGRESSING, 'next_check_at' => now()->addSeconds(30)]);
            $rollout->addEvent('traffic_failed', __('rollouts.event.traffic_failed', ['percent' => $percent]), $actor);
            $rollout->save();
            $this->broadcast($rollout);

            return;
        }

        $interval = max(1, (int) ($rollout->options['interval_minutes'] ?? 5));
        $rollout->forceFill([
            'status' => DeploymentRollout::PROGRESSING,
            'traffic_percent' => $percent,
            'step_index' => $index,
            'step_started_at' => now(),
            'metrics' => [
                'tracing' => $this->strategies->tracing($project), 'source' => null, 'revision' => $rollout->revision,
                'error_rate' => null, 'p95_ms' => null, 'volume' => 0, 'window_minutes' => 0, 'measured_at' => null,
            ],
            'next_check_at' => now()->addSeconds(min($interval * 60, max(10, (int) config('knative.rollouts.metrics_interval_seconds', 60)))),
        ]);
        $rollout->addEvent('step', __('rollouts.event.step', ['step' => $index + 1, 'count' => count($steps), 'percent' => $percent]), $actor, $percent);
        $rollout->save();
        $this->broadcast($rollout);
    }

    /** Canary: the step's thresholds, then the next step once its interval is over. */
    private function canaryStep(DeploymentRollout $rollout, Project $project): void
    {
        $index = $rollout->step_index;
        if ($index === null || $rollout->step_started_at === null) {
            $this->enterStep($rollout, $project, 0, 'system');

            return;
        }

        $options = (array) $rollout->options;
        $interval = max(1, (int) ($options['interval_minutes'] ?? 5));
        $elapsed = max(0, (int) $rollout->step_started_at->diffInSeconds(now(), true));
        $window = max(1, min($interval, (int) ceil($elapsed / 60)));

        // the new revision's own requests (RolloutMetrics: its span metrics, else Knative's)
        $metrics = $this->metrics->measure($project, $window, $rollout->revision);
        $rollout->metrics = $metrics;

        if ($breach = RolloutMetrics::breach($metrics, $options)) {
            $this->rollBackStep($rollout, $project, $breach);

            return;
        }

        $ends = $rollout->step_started_at->copy()->addMinutes($interval);
        if (! now()->lt($ends)) {
            $this->enterStep($rollout, $project, $index + 1, 'system');

            return;
        }

        $next = now()->addSeconds(max(10, (int) config('knative.rollouts.metrics_interval_seconds', 60)));
        $rollout->forceFill(['next_check_at' => $next->lt($ends) ? $next : $ends])->save();
        $this->broadcast($rollout);
    }

    /**
     * Blue-green promoting itself: without a health path at once, else one health check — enough
     * passes in a row promote, the deadline fails it.
     */
    private function healthStep(DeploymentRollout $rollout, Project $project): void
    {
        $health = (array) ($rollout->health ?? []);
        $options = (array) $rollout->options;
        $required = max(1, (int) ($health['required'] ?? $options['health_checks'] ?? 3));
        $path = $health['path'] ?? null;

        if (! is_string($path) || $path === '') {
            try {
                $this->promoteNow($rollout, $project, 'system', true);
            } catch (RolloutActionException $e) {
                $this->retryLater($rollout, $e);
            }

            return;
        }

        $result = $this->probe->check($project, $rollout, $path);
        $passes = $result['ok'] ? (int) ($health['passes'] ?? 0) + 1 : 0;
        $wasOk = ($health['last_status'] ?? null) !== null && ($health['last_error'] ?? null) === null;

        $rollout->health = array_merge($health, [
            'path' => $path,
            'passes' => $passes,
            'required' => $required,
            'last_status' => $result['status'],
            'last_error' => $result['error'],
            'checked_at' => now()->toIso8601String(),
        ]);

        if ($passes >= $required) {
            $rollout->addEvent('health', __('rollouts.event.health_passed', ['passes' => $passes, 'path' => $path]), 'system');
            try {
                $this->promoteNow($rollout, $project, 'system', true);
            } catch (RolloutActionException $e) {
                $this->retryLater($rollout, $e);
            }

            return;
        }

        // An event when the answer changes, not one per check
        if ($result['ok'] !== $wasOk || ($health['checked_at'] ?? null) === null) {
            $rollout->addEvent('health', $result['ok']
                ? __('rollouts.event.health_ok', ['path' => $path, 'status' => $result['status']])
                : __('rollouts.event.health_failed', ['path' => $path, 'status' => $result['status'] ?? '-', 'error' => (string) $result['error']]), 'system');
        }

        $deadline = isset($health['deadline_at']) ? Carbon::parse($health['deadline_at']) : null;
        if ($deadline !== null && ! now()->lt($deadline)) {
            $this->restore($rollout, $project);
            if (($candidate = $rollout->deployment) && $candidate->isLive()) {
                $this->live->retire($candidate);
            }
            $this->finish($rollout, DeploymentRollout::FAILED, __('rollouts.reason.health_deadline', [
                'path' => $path, 'passes' => $required, 'minutes' => (int) ceil(max(10, (int) config('knative.rollouts.health_deadline_seconds', 300)) / 60),
            ]), 'system');
            $this->audit('deployment.rollout.failed', $rollout, ['reason' => $rollout->reason]);

            return;
        }

        $rollout->forceFill(['next_check_at' => now()->addSeconds(max(1, (int) config('knative.rollouts.health_interval_seconds', 10)))])->save();
        $this->broadcast($rollout);
    }

    /** A canary step above its thresholds: traffic back to the revision that served, the new deployment goes. */
    private function rollBackStep(DeploymentRollout $rollout, Project $project, string $reason): void
    {
        if (! $this->restore($rollout, $project)) {
            // the next check tries again
            $rollout->forceFill(['next_check_at' => now()->addSeconds(30)]);
            $rollout->addEvent('traffic_failed', __('rollouts.event.traffic_failed', ['percent' => 0]), 'system');
            $rollout->save();
            $this->broadcast($rollout);

            return;
        }

        if (($candidate = $rollout->deployment) && $candidate->isLive()) {
            $this->live->retire($candidate);
        }
        $this->finish($rollout, DeploymentRollout::ROLLED_BACK, $reason, 'system');
        $this->audit('deployment.rollout.auto_rolled_back', $rollout, ['reason' => $reason, 'metrics' => $rollout->metrics]);
    }

    /**
     * All traffic to the new revision and the deployments swap; blue-green with
     * keep_previous_minutes keeps the previous revision routable (0 %) and warm until keep_until.
     * The lock is held by the caller.
     *
     * @throws RolloutActionException the cluster did not take the traffic, or the new deployment ended
     */
    private function promoteNow(DeploymentRollout $rollout, Project $project, ?string $actor, bool $auto): void
    {
        $candidate = $rollout->deployment;
        $stable = $rollout->stableDeployment;
        if (! $candidate || ! in_array($candidate->status, ['running', 'active'], true) || ! $rollout->revision) {
            throw new RolloutActionException(__('rollouts.not_ready'), 'rollout_not_promotable');
        }

        $keepMinutes = $rollout->strategy === DeploymentRollout::BLUE_GREEN ? (int) ($rollout->options['keep_previous_minutes'] ?? 0) : 0;
        $keep = $keepMinutes > 0 && $stable?->isLive() && $rollout->stable_revision;

        $targets = $keep
            ? RolloutTraffic::promoted($rollout->revision, $rollout->tag, $rollout->stable_revision)
            : RolloutTraffic::latest();
        if (! $this->traffic->set($rollout, $project, $targets)) {
            $this->trafficFailed($rollout, $actor);
        }

        if (! $this->live->swap($candidate, $stable, (bool) $keep, ['knative_ready' => true])) {
            // the new deployment was stopped or replaced meanwhile: the traffic goes back
            $this->restore($rollout, $project);
            $this->finish($rollout, DeploymentRollout::ABORTED, __('rollouts.reason.candidate_ended', ['status' => $candidate->fresh()->status ?? 'deleted']), 'system');

            throw new RolloutActionException(__('rollouts.not_ready'), 'rollout_not_promotable');
        }

        if ($keep) {
            $this->traffic->warm($rollout, $project, (string) $rollout->stable_revision);
        }

        $rollout->forceFill([
            'status' => DeploymentRollout::PROMOTED,
            'traffic_percent' => 100,
            'promoted_at' => now(),
            'keep_until' => $keep ? now()->addMinutes($keepMinutes) : null,
            'released_at' => $keep ? null : now(),
            'next_check_at' => $keep ? now()->addMinutes($keepMinutes) : null,
        ]);
        $rollout->addEvent('promoted', $keep
            ? __('rollouts.event.promoted_keep', ['minutes' => $keepMinutes])
            : __('rollouts.event.promoted'), $actor, 100);
        $rollout->save();
        $this->broadcast($rollout);

        // A global project's other regions get the version now that it serves in the primary
        // (docs/global-projects.md): the fan-out starts at the promotion, not when the new
        // revision was first ready next to the one that served
        try {
            if (EnvironmentScope::idOf($project) === null) {
                app(GlobalReplicaDeployer::class)->afterPrimaryReady($project, $candidate->fresh() ?? $candidate);
            }
        } catch (\Throwable $e) {
            Log::warning('Global project rollout not started after the promotion', ['project' => $project->slug, 'rollout' => $rollout->id, 'error' => $e->getMessage()]);
        }

        if ($auto) {
            $this->audit('deployment.rollout.auto_promoted', $rollout, ['strategy' => $rollout->strategy]);
        }
    }

    /** The previous revision's time is up (or it goes early): Knative's default traffic, the previous deployment is torn down. */
    private function release(DeploymentRollout $rollout, Project $project, ?string $actor = 'system'): void
    {
        if (! $this->traffic->set($rollout, $project, RolloutTraffic::latest())) {
            $rollout->forceFill(['next_check_at' => now()->addSeconds(30)])->save();

            return;
        }

        if (($previous = $rollout->stableDeployment) && $previous->isLive()) {
            $this->live->retire($previous);
        }

        $rollout->forceFill(['released_at' => now(), 'next_check_at' => null]);
        $rollout->addEvent('released', __('rollouts.event.released'), $actor);
        $rollout->save();
        $this->broadcast($rollout);
        $this->audit('deployment.rollout.released', $rollout, []);
    }

    /**
     * A rollout whose deployments ended outside of it (a stop, a delete, a failed start, a
     * newer deploy that did not go through supersede()): it ends, and the traffic goes to what
     * still runs. True when it ended (or let go of its previous revision).
     */
    private function settleOrphan(DeploymentRollout $rollout, Project $project): bool
    {
        $candidate = Deployment::query()->find($rollout->deployment_id);
        $stable = $rollout->stable_deployment_id ? Deployment::query()->find($rollout->stable_deployment_id) : null;
        $candidateLive = (bool) $candidate?->isLive();
        $stableLive = (bool) $stable?->isLive();

        if ($rollout->isOpen()) {
            if (! $candidateLive) {
                if ($stableLive) {
                    $this->restore($rollout, $project);
                }
                $this->finish($rollout, $candidate?->status === 'failed' ? DeploymentRollout::FAILED : DeploymentRollout::ABORTED,
                    __('rollouts.reason.candidate_ended', ['status' => $candidate->status ?? 'deleted']), 'system');

                return true;
            }

            if (! $stableLive) {
                // Nothing to fall back to: the new deployment deploys directly (Knative's default
                // traffic; a pending one is activated by its readiness check, no rollout any more)
                $this->traffic->set($rollout, $project, RolloutTraffic::latest());
                if ($candidate->status === 'running') {
                    $this->live->activate($candidate, ['knative_ready' => true]);
                }
                $this->finish($rollout, DeploymentRollout::ABORTED, __('rollouts.reason.stable_ended'), 'system');

                return true;
            }

            return false;
        }

        if ($rollout->keepsPrevious() && (! $candidateLive || ! $stableLive)) {
            if ($candidateLive) {
                $this->traffic->set($rollout, $project, RolloutTraffic::latest());
            }
            $rollout->forceFill(['released_at' => now(), 'next_check_at' => null]);
            $rollout->addEvent('released', __('rollouts.event.released'), 'system');
            $rollout->save();
            $this->broadcast($rollout);

            return true;
        }

        return false;
    }

    /** Nothing served when the deploy was applied: no rollout, the deploy goes on directly (the deployment that served goes). */
    private function dissolve(DeploymentRollout $rollout): void
    {
        Log::info('Rollout dropped: nothing serves the project, the deploy is direct', ['rollout' => $rollout->id, 'deployment' => $rollout->deployment_id]);

        $stable = $rollout->stableDeployment;
        $projectId = $rollout->project_id;
        $deploymentId = $rollout->deployment_id;
        $rollout->delete();

        if ($stable && $stable->isLive()) {
            $this->live->retire($stable);
        }

        $this->broadcastSafely('deployment.rollout', ['project_id' => $projectId, 'deployment_id' => $deploymentId, 'rollout' => null]);
    }

    /** An automatic step the cluster did not take (or whose deployment ended): looked at again in 30 s. */
    private function retryLater(DeploymentRollout $rollout, RolloutActionException $e): void
    {
        if (! $rollout->holdsTraffic() || $rollout->status === DeploymentRollout::PROMOTED) {
            return;
        }

        Log::warning('Rollout step not done, trying again', ['rollout' => $rollout->id, 'error' => $e->getMessage()]);
        $rollout->forceFill(['next_check_at' => now()->addSeconds(30)])->save();
        $this->broadcast($rollout);
    }

    /** All traffic back to the revision that served (nothing to do before the deploy pinned it). */
    private function restore(DeploymentRollout $rollout, Project $project): bool
    {
        if (! $rollout->stable_revision) {
            return true;
        }

        return $this->traffic->set($rollout, $project, RolloutTraffic::only($rollout->stable_revision));
    }

    /**
     * @throws RolloutActionException
     */
    private function trafficFailed(DeploymentRollout $rollout, ?string $actor): never
    {
        $rollout->addEvent('traffic_failed', __('rollouts.traffic_failed'), $actor);
        $rollout->save();
        $this->broadcast($rollout);

        throw new RolloutActionException(__('rollouts.traffic_failed'), 'rollout_traffic_failed', 502);
    }

    private function finish(DeploymentRollout $rollout, string $status, string $reason, ?string $actor): void
    {
        $rollout->forceFill([
            'status' => $status,
            'reason' => mb_substr($reason, 0, 1000),
            'traffic_percent' => $status === DeploymentRollout::PROMOTED ? 100 : 0,
            'finished_at' => now(),
            'next_check_at' => null,
        ]);
        $rollout->addEvent($status, $reason, $actor, $rollout->traffic_percent);
        $rollout->save();

        // The new deployment carries why (the deployment list, a git deploy's status)
        if (($candidate = Deployment::query()->find($rollout->deployment_id)) && $status !== DeploymentRollout::PROMOTED) {
            Deployment::query()->whereKey($candidate->getKey())->update(['knative_message' => mb_substr($reason, 0, 255)]);
        }

        $this->broadcast($rollout);
    }

    /**
     * Runs $fn with the rollout read again under its lock; after the lock, the next check is
     * queued when the change set one. $wait false: a rollout someone else holds is left alone
     * (null).
     *
     * @template T
     *
     * @param  \Closure(DeploymentRollout): T  $fn
     * @return T|null
     *
     * @throws RolloutActionException the lock was not free within 15 s
     */
    private function locked(DeploymentRollout $rollout, \Closure $fn, bool $wait = true): mixed
    {
        $lock = Cache::lock('rollout:'.$rollout->getKey(), 120);
        try {
            $acquired = $wait ? $lock->block(15) : $lock->get();
        } catch (LockTimeoutException) {
            throw new RolloutActionException(__('rollouts.busy'), 'rollout_busy');
        }
        if (! $acquired) {
            return null;
        }

        $before = null;
        try {
            $fresh = DeploymentRollout::query()->find($rollout->getKey());
            if (! $fresh) {
                return null;
            }
            $before = $fresh->next_check_at?->toIso8601String();
            $result = $fn($fresh);
        } finally {
            $lock->release();
        }

        // A changed timer: the job that looks at the rollout then (the old one finds it moved)
        if ($fresh->exists && $fresh->next_check_at !== null && $fresh->next_check_at->toIso8601String() !== $before && $fresh->holdsTraffic()) {
            AdvanceRolloutJob::schedule($fresh);
        }

        return $result;
    }

    private function broadcast(DeploymentRollout $rollout): void
    {
        $candidate = Deployment::query()->find($rollout->deployment_id);
        $stable = $rollout->stable_deployment_id ? Deployment::query()->find($rollout->stable_deployment_id) : null;

        $this->broadcastSafely('deployment.rollout', [
            'project_id' => $rollout->project_id,
            'environment_id' => $candidate !== null && ! $candidate->isPrimary() ? $candidate->environment_id : null,
            'deployment_id' => $rollout->deployment_id,
            'status' => $candidate?->status,
            'rollout' => (new DeploymentRolloutResource($rollout))->resolve(),
        ]);

        if ($stable) {
            $this->broadcastSafely('deployment.status', [
                'project_id' => $stable->project_id,
                'deployment_id' => $stable->id,
                'status' => $stable->status,
            ]);
        }
    }

    /** The change is saved: a broadcast that cannot reach the WebSocket server must not undo it. */
    private function broadcastSafely(string $topic, array $payload): void
    {
        try {
            $this->broadcastModel($topic, $payload);
        } catch (\Throwable $e) {
            Log::warning('Rollout change not broadcast', ['topic' => $topic, 'error' => $e->getMessage()]);
        }
    }

    /** What the rollout did by itself (the user's actions are audited by their API route). */
    private function audit(string $action, DeploymentRollout $rollout, array $metadata): void
    {
        app(AuditLogger::class)->record($action, [
            'actor' => 'system',
            'target' => ['type' => 'deployment', 'id' => $rollout->deployment_id, 'name' => $rollout->revision],
            'metadata' => $metadata + [
                'project_id' => $rollout->project_id,
                'rollout_id' => $rollout->id,
                'strategy' => $rollout->strategy,
                'stable_deployment_id' => $rollout->stable_deployment_id,
                'environment_id' => $rollout->environmentId(),
            ],
        ]);
    }
}
