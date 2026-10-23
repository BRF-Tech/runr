<?php

namespace App\Http\Controllers\Api;

use App\Models\Project;
use App\Models\RuntimePatch;
use App\Models\RuntimePatchFeed;
use App\Models\RuntimePatchMigration;
use App\Services\Runtimes\Patches\RuntimePatches;
use App\Services\Runtimes\Patches\RuntimePatchException;
use App\Services\Runtimes\Patches\RuntimePatchMigrations;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Runtime patches of a project (docs/runtimes.md#runtime-patches):
 *
 *  GET    /api/projects/{project}/runtime-patches        state, the version's pinnable patches
 *                                                         (level, reason, CVEs, link, deadline), migrations
 *  POST   /api/projects/{project}/runtime-patches/apply  redeploy the live version onto the newest
 *                                                         patch (a pin goes) → 202 {data: migration}
 *  PUT    /api/projects/{project}/runtime-patches/pin    {patch_id, deploy?} pin to one of the last
 *                                                         five patches (and redeploy onto it, default)
 *  DELETE /api/projects/{project}/runtime-patches/pin    follow the newest patch again (next deploy)
 *
 * Refusals: 422 / 409 {message, code}: not_applicable, no_patch, not_pinnable, not_deployed,
 * project_stopped, already_running, migration_running, deploy_refused.
 */
class ProjectRuntimePatchController extends Controller
{
    public function __construct(
        private readonly RuntimePatches $patches,
        private readonly RuntimePatchMigrations $migrations,
    ) {}

    #[OpenApiResponse(200, 'Patch state of the project, the patches it can pin and its last migrations', type: 'array{data: array{state: '.RuntimePatches::STATE_OPENAPI_TYPE.'|null, patches: list<array{'.RuntimePatch::OPENAPI_FIELDS.'}>, keep: int, auto_migrate: bool, migrations: list<'.RuntimePatchMigration::OPENAPI_TYPE.'>}}')]
    public function show(Request $request, Project $project): JsonResponse
    {
        $migrations = $this->patches->applies($project)
            ? $project->runtimePatchMigrations()->limit(5)->get()->map(fn (RuntimePatchMigration $m) => $m->toApi())->values()->all()
            : [];

        return response()->json(['data' => [
            'state' => $this->patches->state($project),
            'patches' => $this->patches->applies($project)
                ? $this->patches->pinnable($project->runtime, $this->patches->versionOf($project))->map(fn (RuntimePatch $p) => $p->toApi())->values()->all()
                : [],
            'keep' => (int) config('runtime_patches.keep', 5),
            'auto_migrate' => RuntimePatchFeed::current()->autoMigrates(),
            'migrations' => $migrations,
        ]])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Redeploys the live version onto the newest patch (a pin ends).
     *
     * @response JsonResponse<array{data: array<string, mixed>, message: string}, 202>
     */
    #[OpenApiResponse(202, 'The redeploy onto the newest patch started', type: 'array{data: '.RuntimePatchMigration::OPENAPI_TYPE.', message: string}')]
    #[OpenApiResponse(409, 'Nothing is deployed, the project is stopped, already on the patch or migrating', type: 'array{message: string, code: string}')]
    #[OpenApiResponse(422, 'No patch for the project\'s runtime, or a deploy check refused the redeploy', type: 'array{message: string, code: string}')]
    public function apply(Request $request, Project $project): JsonResponse
    {
        try {
            $migration = $this->migrations->start($project, null, RuntimePatchMigration::APPLY, (string) $request->user()->username, (string) $request->user()->getAuthIdentifier());
        } catch (RuntimePatchException $e) {
            return $this->refusal($e);
        }

        // Applying the newest patch ends a pin
        if ($project->runtime_patch_pin) {
            $this->migrations->unpin($project);
        }

        return response()->json([
            'data' => $migration->toApi(),
            'message' => __('runtime_patches.applying', ['tag' => $this->patches->find($migration->patch_id)->tag ?? '-']),
        ], 202);
    }

    /**
     * Pins the project on one of the last patches; with deploy (default) it redeploys onto it (202).
     *
     * @response JsonResponse<array{data: array<string, mixed>, migration: null}, 200>
     */
    #[OpenApiResponse(200, 'Pinned; nothing redeployed', type: 'array{data: '.RuntimePatches::STATE_OPENAPI_TYPE.'|null, migration: null}')]
    #[OpenApiResponse(202, 'Pinned; the redeploy onto the pin started', type: 'array{data: '.RuntimePatches::STATE_OPENAPI_TYPE.'|null, migration: '.RuntimePatchMigration::OPENAPI_TYPE.'}')]
    #[OpenApiResponse(409, 'Nothing is deployed, the project is stopped or a migration runs', type: 'array{message: string, code: string}')]
    #[OpenApiResponse(422, 'Not a patch the project can pin, or a deploy check refused the redeploy', type: 'array{message: string, code: string}')]
    public function pin(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'patch_id' => ['required', 'string', 'max:128'],
            'deploy' => ['sometimes', 'boolean'],
        ]);

        try {
            $migration = $this->migrations->pin(
                $project, $data['patch_id'], (string) $request->user()->getAuthIdentifier(), (bool) ($data['deploy'] ?? true),
                (string) $request->user()->username, (string) $request->user()->getAuthIdentifier(),
            );
        } catch (RuntimePatchException $e) {
            return $this->refusal($e);
        }

        return response()->json([
            'data' => $this->patches->state($project->refresh()),
            'migration' => $migration?->toApi(),
        ], $migration ? 202 : 200);
    }

    #[OpenApiResponse(200, 'The patch state without the pin', type: 'array{data: '.RuntimePatches::STATE_OPENAPI_TYPE.'|null}')]
    public function unpin(Project $project): JsonResponse
    {
        $this->migrations->unpin($project);

        return response()->json(['data' => $this->patches->state($project->refresh())]);
    }

    private function refusal(RuntimePatchException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
    }
}
