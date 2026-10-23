<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\DeploymentRolloutResource;
use App\Models\Deployment;
use App\Models\DeploymentRollout;
use App\Models\Project;
use App\Services\Environments\EnvironmentResolver;
use App\Services\Rollouts\RolloutActionException;
use App\Services\Rollouts\RolloutService;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Blue-green / canary rollouts (docs/deploy-strategies.md): the project's current
 * one, a deployment's, and what a user does with it — promote now, abort (the new revision goes,
 * the traffic stays), roll back to the previous revision while it is kept warm.
 */
class DeploymentRolloutController extends Controller
{
    /**
     * The newest rollout (open, kept or ended) of the project's own workload, or with ?environment=
     * (a name or id) of one of its environments (docs/deploy-strategies.md#environments); data null
     * when it never rolled out.
     */
    public function current(Request $request, Project $project): JsonResponse
    {
        $environment = EnvironmentResolver::resolve($project, $request->query('environment'));
        $rollout = DeploymentRollout::available()
            ? DeploymentRollout::query()->ofEnvironment((string) $project->id, $environment?->scopeKey())->latest('id')->first()
            : null;

        return response()->json(['data' => $rollout ? new DeploymentRolloutResource($rollout) : null]);
    }

    public function show(Project $project, Deployment $deployment): DeploymentRolloutResource
    {
        return new DeploymentRolloutResource($this->rolloutOf($project, $deployment));
    }

    #[OpenApiResponse(409, 'The rollout cannot be promoted now', type: 'array{message: string, code: "rollout_not_promotable"|"rollout_busy"}')]
    #[OpenApiResponse(502, 'The cluster did not take the new traffic split', type: 'array{message: string, code: "rollout_traffic_failed"}')]
    #[OpenApiResponse(200, 'The rollout after the action', type: 'array{data: DeploymentRolloutResource}')]
    public function promote(Request $request, Project $project, Deployment $deployment, RolloutService $rollouts): JsonResponse
    {
        return $this->act(fn () => $rollouts->promote($this->rolloutOf($project, $deployment), $request->user()?->username));
    }

    #[OpenApiResponse(409, 'The rollout is not under way', type: 'array{message: string, code: "rollout_not_abortable"|"rollout_busy"}')]
    #[OpenApiResponse(502, 'The cluster did not take the new traffic split', type: 'array{message: string, code: "rollout_traffic_failed"}')]
    #[OpenApiResponse(200, 'The rollout after the action', type: 'array{data: DeploymentRolloutResource}')]
    public function abort(Request $request, Project $project, Deployment $deployment, RolloutService $rollouts): JsonResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        return $this->act(fn () => $rollouts->abort($this->rolloutOf($project, $deployment), $request->user()?->username, $data['reason'] ?? null));
    }

    #[OpenApiResponse(409, 'The previous revision is no longer kept', type: 'array{message: string, code: "rollout_not_rollbackable"|"rollout_busy"}')]
    #[OpenApiResponse(502, 'The cluster did not take the new traffic split', type: 'array{message: string, code: "rollout_traffic_failed"}')]
    #[OpenApiResponse(200, 'The rollout after the action', type: 'array{data: DeploymentRolloutResource}')]
    public function rollback(Request $request, Project $project, Deployment $deployment, RolloutService $rollouts): JsonResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        return $this->act(fn () => $rollouts->rollback($this->rolloutOf($project, $deployment), $request->user()?->username, $data['reason'] ?? null));
    }

    /** The rollout whose new deployment $deployment is; 404 for another project's deployment or none. */
    private function rolloutOf(Project $project, Deployment $deployment): DeploymentRollout
    {
        abort_if($deployment->project_id !== $project->id || ! DeploymentRollout::available(), 404);

        return DeploymentRollout::query()->where('deployment_id', $deployment->id)->latest('id')->firstOrFail();
    }

    /** @param \Closure(): DeploymentRollout $action */
    private function act(\Closure $action): JsonResponse
    {
        try {
            $rollout = $action();
        } catch (RolloutActionException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        return (new DeploymentRolloutResource($rollout->fresh() ?? $rollout))->response()->setStatusCode(200);
    }
}
