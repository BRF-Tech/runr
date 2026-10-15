<?php

namespace App\Http\Controllers\Api;

use App\Jobs\GitDeploy\DeployPreviewJob;
use App\Models\Git\PreviewEnvironment;
use App\Models\Project;
use App\Services\GitDeploy\GitAudit;
use App\Services\GitDeploy\PreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Preview environments of a project:
 *   GET    /projects/{project}/previews                     live and recent previews (?all=1: deleted ones too)
 *   POST   /projects/{project}/previews/{preview}/redeploy  apply the preview's version again
 *   DELETE /projects/{project}/previews/{preview}           delete the preview now
 * Reads need git.pull, the rest previews.manage.
 */
class GitPreviewController extends Controller
{
    public function index(Request $request, Project $project): JsonResponse
    {
        abort_unless(Gate::allows('git.pull', $project), 403, __('git.forbidden', ['permission' => 'git.pull']));

        $previews = PreviewEnvironment::where('project_id', $project->id)
            ->when(! $request->boolean('all'), fn ($q) => $q->where('status', '!=', PreviewEnvironment::DELETED))
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get();

        return response()->json(['data' => $previews->map(fn (PreviewEnvironment $p) => $p->toPublicArray())->values()]);
    }

    public function redeploy(Project $project, string $preview): JsonResponse
    {
        abort_unless(Gate::allows('previews.manage', $project), 403, __('git.forbidden', ['permission' => 'previews.manage']));

        $row = $this->find($project, $preview);
        if (! $row->version_id || ! in_array($row->status, [PreviewEnvironment::READY, PreviewEnvironment::FAILED], true)) {
            throw ValidationException::withMessages(['preview' => __('git.preview_not_redeployable')]);
        }

        $row->mark(PreviewEnvironment::DEPLOYING);
        DeployPreviewJob::dispatch($row->id);
        GitAudit::record('git.preview.redeploy', $project, ['preview' => $row->number]);

        return response()->json(['data' => $row->fresh()->toPublicArray()], 202);
    }

    public function destroy(Project $project, string $preview, PreviewService $previews): JsonResponse
    {
        abort_unless(Gate::allows('previews.manage', $project), 403, __('git.forbidden', ['permission' => 'previews.manage']));

        $row = $this->find($project, $preview);
        if ($row->status !== PreviewEnvironment::DELETED) {
            $previews->teardown($row, 'Deleted in RunR');
            GitAudit::record('git.preview.delete', $project, ['preview' => $row->number]);
        }

        return response()->json(['data' => $row->fresh()->toPublicArray()]);
    }

    private function find(Project $project, string $id): PreviewEnvironment
    {
        return PreviewEnvironment::where('project_id', $project->id)->findOrFail($id);
    }
}
