<?php

namespace App\Http\Controllers\Api\Observability;

use App\Http\Controllers\Api\Controller;
use App\Models\Observability\ReplayCapture;
use App\Models\Project;
use App\Services\Observability\Replay\ReplayNotPossibleException;
use App\Services\Observability\Replay\ReplayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Captured failed requests of a project and their replay.
 *
 * @tags Observability
 */
class ReplayCaptureController extends Controller
{
    public function __construct(private ReplayService $replays) {}

    /** GET /api/projects/{project}/replays — newest first, ?status=, ?path= */
    public function index(Request $request, Project $project): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'integer', 'min:100', 'max:999'],
            'path' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = ReplayCapture::query()
            ->where('project_id', $project->getKey())
            ->when($request->filled('status'), fn ($q) => $q->where('status', (int) $request->input('status')))
            ->when($request->filled('path'), fn ($q) => $q->where('path', 'like', '%'.addcslashes((string) $request->input('path'), '%_\\').'%'))
            ->orderByDesc('captured_at')
            ->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (ReplayCapture $c) => $c->toSummary())->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** GET /api/projects/{project}/replays/{capture} */
    public function show(Project $project, string $capture): JsonResponse
    {
        return response()->json(['data' => $this->find($project, $capture)->toDetail()]);
    }

    /**
     * POST /api/projects/{project}/replays/{capture}/replay — re-send to the current revision.
     * Optional: headers {name: value} added for this replay only (never stored), query.
     */
    public function replay(Request $request, Project $project, string $capture): JsonResponse
    {
        $data = $request->validate([
            'headers' => ['nullable', 'array', 'max:30'],
            'headers.*' => ['nullable', 'string', 'max:8192'],
            'query' => ['nullable', 'string', 'max:8192'],
        ]);
        foreach (array_keys((array) ($data['headers'] ?? [])) as $name) {
            if (! preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]{1,100}$/', (string) $name)) {
                return response()->json(['message' => __('observability.replay.header_invalid'), 'errors' => ['headers' => [__('observability.replay.header_invalid')]]], 422);
            }
        }

        $model = $this->find($project, $capture);

        try {
            $result = $this->replays->replay($project, $model, array_filter([
                'headers' => $data['headers'] ?? null,
                'query' => $data['query'] ?? null,
            ], fn ($v) => $v !== null));
        } catch (ReplayNotPossibleException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['data' => $result]);
    }

    /** DELETE /api/projects/{project}/replays/{capture} */
    public function destroy(Request $request, Project $project, string $capture): Response
    {
        $model = $this->find($project, $capture);
        $model->delete();

        return response()->noContent();
    }

    /** DELETE /api/projects/{project}/replays — every capture of the project. */
    public function destroyAll(Request $request, Project $project): JsonResponse
    {
        $count = ReplayCapture::query()->where('project_id', $project->getKey())->delete();

        return response()->json(['deleted' => $count]);
    }

    private function find(Project $project, string $id): ReplayCapture
    {
        return ReplayCapture::query()->where('project_id', $project->getKey())->findOrFail($id);
    }
}
