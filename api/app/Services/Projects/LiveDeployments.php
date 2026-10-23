<?php

namespace App\Services\Projects;

use App\Events\DeploymentStatusChanged;
use App\Models\Deployment;
use App\Models\Project;
use App\Services\Environments\EnvironmentScope;
use App\Services\Runtimes\Patches\RuntimePatches;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The live deployments of a project (pending, running, active), per environment: the project's
 * own workload (deployments with environment_id null) and each of its environments
 * (docs/environments.md) is one workload with at most one active deployment — a deploy to staging
 * never retires production's. Preview environments are PreviewEnvironment rows with a lifecycle of
 * their own and are never touched here.
 *
 *  - supersede()  a deploy replaces every live deployment, not only the active one: a pending one
 *                 whose readiness check polls the project's shared Knative Service by name would
 *                 otherwise go live next to the new one (and both would be metered)
 *  - hasNewer()   whether a newer deployment of the project is live — a readiness check of an older
 *                 one stops without activating it (CheckDeploymentReadyJob)
 *  - activate()   a starting deployment goes live only while no newer one of the project is live,
 *                 and replaces the older live ones in the same transaction: at most one deployment
 *                 of a project is active, also when two deploys were created at the same instant
 *                 and the older one went live before the newer one existed
 *  - swap()       a blue-green / canary rollout's switch (App\Services\Rollouts\RolloutService):
 *                 one deployment becomes the active one, the other stays running (kept warm) or
 *                 is retired — during a rollout the new deployment runs next to the active one
 *                 (status running: live, not the primary), and supersede() keeps the one that
 *                 serves while the new one rolls out
 */
final class LiveDeployments
{
    /**
     * Every live deployment of the project in one environment goes to shutting_down — the
     * environment $project stands for (EnvironmentProject), else $environmentId, else the project's
     * own workload; ProcessTeardown removes what the new deployment does not apply again under the
     * same names. $keep (a rollout's deployment that
     * serves while the new one rolls out) stays.
     *
     * @return int the deployments replaced
     */
    public function supersede(Project $project, ?Deployment $keep = null, ?string $environmentId = null): int
    {
        $replaced = 0;
        $environmentId ??= EnvironmentScope::idOf($project);
        $live = Deployment::forEnvironment(Deployment::query()->where('project_id', $project->id), $environmentId)
            ->whereIn('status', Deployment::LIVE)
            ->when($keep !== null, fn ($q) => $q->whereKeyNot($keep->getKey()))
            ->get();
        foreach ($live as $deployment) {
            $replaced += $this->retire($deployment) ? 1 : 0;
        }

        return $replaced;
    }

    /**
     * One live deployment goes to shutting_down (only while it is still live: a stop or teardown
     * that changed it first wins) and its teardown is queued.
     */
    public function retire(Deployment $deployment): bool
    {
        $previous = (string) $deployment->status;
        if (! $deployment->transition(Deployment::LIVE, ['status' => 'shutting_down'])) {
            return false;
        }

        event(new DeploymentStatusChanged($deployment, $previous, 'shutting_down'));

        return true;
    }

    /**
     * A starting (pending / running) deployment becomes active with $attributes (ready_at,
     * knative_ready, …) — under the project's row lock, which serializes the activations of a
     * project, and only while no deployment of the project created after it is live: a newer one
     * owns what is ready now (the Knative Service is the project's, by name), so this one is
     * retired instead. Activating it retires every older live deployment of the project in the
     * same transaction, so an older one that went live in the meantime (a deploy that superseded
     * before it was created) does not stay active next to it. The teardowns of the retired ones
     * are queued after the commit (ProcessTeardown reads them back as shutting_down).
     *
     * @param  array<string, mixed>  $attributes
     * @return bool whether it became active (false: replaced, stopped or shut down meanwhile)
     */
    public function activate(Deployment $deployment, array $attributes = []): bool
    {
        /** @var list<array{0: Deployment, 1: string}> $retired */
        $retired = [];

        $activated = DB::connection($deployment->getConnectionName())->transaction(function () use ($deployment, $attributes, &$retired) {
            Project::query()->whereKey($deployment->project_id)->lockForUpdate()->first();

            if ($this->hasNewer($deployment)) {
                $previous = (string) $deployment->status;
                if ($deployment->transition(Deployment::LIVE, ['status' => 'shutting_down'])) {
                    $retired[] = [$deployment, $previous];
                }

                return false;
            }

            if (! $deployment->transition(['pending', 'running'], ['status' => 'active'] + $attributes)) {
                return false;
            }

            // The project now runs the runtime patch of this deployment (docs/runtimes.md#runtime-patches)
            app(RuntimePatches::class)->recordRunning($deployment);

            $older = $this->siblings($deployment)
                ->whereKeyNot($deployment->getKey())
                ->whereIn('status', Deployment::LIVE)
                ->where(fn ($q) => $q->where('created_at', '<', $deployment->created_at)
                    ->orWhere(fn ($same) => $same->where('created_at', $deployment->created_at)->where('id', '<', $deployment->getKey())))
                ->get();
            foreach ($older as $old) {
                $previous = (string) $old->status;
                if ($old->transition(Deployment::LIVE, ['status' => 'shutting_down'])) {
                    $retired[] = [$old, $previous];
                }
            }

            return true;
        });

        foreach ($retired as [$ended, $previous]) {
            event(new DeploymentStatusChanged($ended, $previous, 'shutting_down'));
        }

        return $activated;
    }

    /**
     * A rollout's switch: $incoming (running — ready next to the active one — or already active)
     * becomes the active deployment; $outgoing goes on running when $keepOutgoing (the previous
     * revision kept warm for an instant rollback) and is retired otherwise; any other live
     * deployment of the same workload (the project's own, or one environment's) is retired. Under
     * the project's row lock, like activate(); the
     * teardowns are queued after the commit.
     *
     * @param  array<string, mixed>  $attributes  written with $incoming's status
     * @return bool whether $incoming is active (false: it was stopped or retired meanwhile)
     */
    public function swap(Deployment $incoming, ?Deployment $outgoing, bool $keepOutgoing, array $attributes = []): bool
    {
        /** @var list<array{0: Deployment, 1: string}> $retired */
        $retired = [];

        $swapped = DB::connection($incoming->getConnectionName())->transaction(function () use ($incoming, $outgoing, $keepOutgoing, $attributes, &$retired) {
            Project::query()->whereKey($incoming->project_id)->lockForUpdate()->first();

            if (! $incoming->transition(['running', 'active'], ['status' => 'active'] + $attributes)) {
                return false;
            }

            if ($outgoing !== null) {
                $previous = (string) $outgoing->status;
                if ($keepOutgoing) {
                    $outgoing->transition(['active', 'running'], ['status' => 'running']);
                } elseif ($outgoing->transition(Deployment::LIVE, ['status' => 'shutting_down'])) {
                    $retired[] = [$outgoing, $previous];
                }
            }

            // the same workload's: a rollout of production never ends an environment's deployment
            $others = $this->siblings($incoming)
                ->whereKeyNot(array_filter([$incoming->getKey(), $outgoing?->getKey()]))
                ->whereIn('status', Deployment::LIVE)
                ->get();
            foreach ($others as $other) {
                $previous = (string) $other->status;
                if ($other->transition(Deployment::LIVE, ['status' => 'shutting_down'])) {
                    $retired[] = [$other, $previous];
                }
            }

            return true;
        });

        foreach ($retired as [$ended, $previous]) {
            event(new DeploymentStatusChanged($ended, $previous, 'shutting_down'));
        }

        return $swapped;
    }

    /** Whether a deployment of the project (in the same environment) created after this one is live. */
    public function hasNewer(Deployment $deployment): bool
    {
        return $this->siblings($deployment)
            ->whereKeyNot($deployment->getKey())
            ->whereIn('status', Deployment::LIVE)
            ->where(fn ($q) => $q->where('created_at', '>', $deployment->created_at)
                ->orWhere(fn ($same) => $same->where('created_at', $deployment->created_at)->where('id', '>', $deployment->getKey())))
            ->exists();
    }

    /**
     * Deployments of the same project and environment as $deployment.
     *
     * @return Builder<Deployment>
     */
    private function siblings(Deployment $deployment): Builder
    {
        return $deployment->siblings();
    }
}
