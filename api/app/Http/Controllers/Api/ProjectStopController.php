<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\Projects\ProjectStopConflict;
use App\Services\Projects\ProjectStopService;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stop and resume a project (docs/billing.md#project-stop-and-resume), permission projects.stop:
 *
 *  POST /api/projects/{project}/stop    {reason}  → {data: project}; 409 project_already_stopped
 *       (a platform administrator takes over an organization's stop: 200, level platform)
 *  POST /api/projects/{project}/resume            → {data: project, deployment_id, redeploy_error, redeploy_code};
 *       409 project_not_stopped; 403 project_stopped_by_platform (an organization lifting the
 *       platform's stop)
 */
class ProjectStopController extends Controller
{
    public function __construct(private readonly ProjectStopService $stops) {}

    #[OpenApiResponse(409, 'The project is already stopped (a platform administrator takes an organization\'s stop over: 200)', type: 'array{message: string, code: "project_already_stopped", level: "platform"|"organization"|null, reason?: string|null}')]
    public function stop(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        try {
            $project = $this->stops->stop($project, $request->user(), $data['reason']);
        } catch (ProjectStopConflict $e) {
            return $e->render();
        }

        return response()->json(['data' => $this->present($request, $project)]);
    }

    #[OpenApiResponse(403, 'The platform stopped the project: only a platform administrator resumes it', type: 'array{message: string, code: "project_stopped_by_platform"}')]
    public function resume(Request $request, Project $project): JsonResponse
    {
        if (! $project->isStopped()) {
            return response()->json(['message' => __('api.project.not_stopped'), 'code' => 'project_not_stopped'], 409);
        }

        try {
            $deployment = $this->stops->resume($project, $request->user(), $refusal, $refusalCode);
        } catch (ProjectStopConflict $e) {
            return $e->render();
        }

        return response()->json([
            'data' => $this->present($request, $project->refresh()),
            'deployment_id' => $deployment?->id,
            // The project is running again but the version that ran could not be deployed: why,
            // and the code of a deploy guard's refusal (e.g. fixed_allocation_exceeded)
            'redeploy_error' => $refusal,
            'redeploy_code' => $refusalCode,
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Request $request, Project $project): array
    {
        $project->load(['knativeRegion', 'latestVersion', 'latestDeployment.version', 'creator:id,name', 'owner:id,name,username']);

        return (new ProjectResource($project))->resolve($request);
    }
}
