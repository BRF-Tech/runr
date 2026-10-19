<?php

namespace App\Services\Projects;

use App\Events\DeploymentStatusChanged;
use App\Models\Deployment;
use App\Models\Project;

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
