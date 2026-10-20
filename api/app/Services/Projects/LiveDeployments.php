<?php

namespace App\Services\Projects;

use App\Events\DeploymentStatusChanged;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * The live deployments of a project (pending, running, active): its production workload in its
 * region. Every deployment row is a production deployment of the project's one region — preview
 * environments are PreviewEnvironment rows with a lifecycle of their own and are never touched
 * here.
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
 */
final class LiveDeployments
{
    /**
     * Every live deployment of the project goes to shutting_down; ProcessTeardown removes what the
     * new deployment does not apply again under the same names.
     *
     * @return int the deployments replaced
     */
    public function supersede(Project $project): int
    {
        $replaced = 0;
        $live = Deployment::query()->where('project_id', $project->id)->whereIn('status', Deployment::LIVE)->get();
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

            $older = Deployment::query()
                ->where('project_id', $deployment->project_id)
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

    /** Whether a deployment of the project created after this one is live. */
    public function hasNewer(Deployment $deployment): bool
    {
        return Deployment::query()
            ->where('project_id', $deployment->project_id)
            ->whereKeyNot($deployment->getKey())
            ->whereIn('status', Deployment::LIVE)
            ->where(fn ($q) => $q->where('created_at', '>', $deployment->created_at)
                ->orWhere(fn ($same) => $same->where('created_at', $deployment->created_at)->where('id', '>', $deployment->getKey())))
            ->exists();
    }
}
