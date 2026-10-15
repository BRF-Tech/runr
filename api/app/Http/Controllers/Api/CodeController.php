<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Code\SaveFaaSFilesRequest;
use App\Http\Requests\Code\SaveXaaSCodeRequest;
use App\Http\Resources\VersionResource;
use App\Models\Project;
use App\Models\Version;
use App\Services\Concerns\BroadcastsModelEvents;
use App\Services\GitService;
use App\Services\ProjectService;
use App\Services\Runtimes\ProjectEntrypoint;
use App\Services\Runtimes\RuntimeRegistry;
use App\Support\Rbac\EnvVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class CodeController extends Controller
{
    use BroadcastsModelEvents;

    public function __construct(
        private ProjectService $projectService,
        private GitService $gitService,
        private RuntimeRegistry $runtimes,
    ) {}

    public function getXaaSCode(Request $request, string $projectId): JsonResponse
    {
        $project = $this->getProject($projectId, ['xaas', 'aaas']);
        $versionId = $request->query('version_id');

        if ($versionId) {
            $version = Version::where('project_id', $project->id)->with('creator:id,name')->findOrFail($versionId);
        } else {
            $version = $project->latestVersion?->load('creator:id,name');
        }

        if (! $version) {
            $code = $this->projectService->getDefaultCode($project);

            return response()->json(['code' => $code, 'version' => null, 'is_default' => true]);
        }

        $files = $this->gitService->getAllFilesAtVersion($project, $version->commit_id);
        $filename = $project->service_type === 'aaas'
            ? ($project->aaasConfig->filename ?? 'agent.py')
            : $project->xaasConfig->filename;
        $code = $files[$filename] ?? '';

        return response()->json(['code' => $code, 'version' => new VersionResource($version)]);
    }

    public function saveXaaSCode(SaveXaaSCodeRequest $request, string $projectId): JsonResponse
    {
        // Saving creates a new Version: former AI types are read-only
        $project = $this->getProject($projectId, ['xaas']);
        $this->abortIfEol($project);
        $data = $request->validated();

        $this->validateHandlerExists($project, $data['code']);

        $version = $this->projectService->saveXaaSCode(
            $project,
            $data['code'],
            $request->user()->id,
            $data['message'] ?? null
        );

        $version->load('creator:id,name');

        $this->broadcastModel('version.created', [
            'project_id' => $project->id,
            'version' => EnvVisibility::withoutEnv(fn () => (new VersionResource($version))->resolve()),
        ]);

        return response()->json(['message' => __('api.code.saved'), 'version' => new VersionResource($version)], 201);
    }

    public function getFaaSFiles(Request $request, string $projectId): JsonResponse
    {
        $project = $this->getProject($projectId, ['faas', 'agentflow']);
        $versionId = $request->query('version_id');

        if ($versionId) {
            $version = Version::where('project_id', $project->id)->with('creator:id,name')->findOrFail($versionId);
        } else {
            $version = $project->latestVersion?->load('creator:id,name');
        }

        if (! $version) {
            $files = $this->projectService->getDefaultFiles($project);

            return response()->json(['files' => $files, 'version' => null, 'is_default' => true]);
        }

        $files = $this->gitService->getAllFilesAtVersion($project, $version->commit_id);

        return response()->json(['files' => $files, 'version' => new VersionResource($version)]);
    }

    public function saveFaaSFiles(SaveFaaSFilesRequest $request, string $projectId): JsonResponse
    {
        // Saving creates a new Version: former AI types are read-only
        $project = $this->getProject($projectId, ['faas']);
        $this->abortIfEol($project);
        $data = $request->validated();

        $files = collect($data['files'])->mapWithKeys(
            fn ($f) => [$f['name'] => $f['content']]
        )->toArray();

        // The main file (the handler's file, e.g. handler.handler → handler.php; default main.{ext}) must not be deleted
        $mainFile = $this->mainFileName($project);
        if (! isset($files[$mainFile])) {
            throw ValidationException::withMessages([
                'files' => __('api.code.main_file_delete', ['file' => $mainFile]),
            ]);
        }

        // The main file must contain the handler function
        $this->validateHandlerExists($project, $files[$mainFile]);

        $version = $this->projectService->saveFaaSFiles(
            $project,
            $files,
            $request->user()->id,
            $data['message'] ?? null
        );

        $version->load('creator:id,name');

        $this->broadcastModel('version.created', [
            'project_id' => $project->id,
            'version' => EnvVisibility::withoutEnv(fn () => (new VersionResource($version))->resolve()),
        ]);

        return response()->json(['message' => __('api.code.files_saved'), 'version' => new VersionResource($version)], 201);
    }

    public function versions(Request $request, string $projectId): AnonymousResourceCollection
    {
        $versions = Version::where('project_id', $projectId)
            ->with('creator:id,name')
            ->orderByDesc('number')
            ->get();

        return VersionResource::collection($versions);
    }

    public function versionFiles(Request $request, string $projectId, string $versionId): JsonResponse
    {
        $project = Project::findOrFail($projectId);
        $version = Version::where('project_id', $project->id)->with('creator:id,name')->findOrFail($versionId);

        $files = $this->gitService->getAllFilesAtVersion($project, $version->commit_id);

        return response()->json(['version' => new VersionResource($version), 'files' => $files]);
    }

    private function getProject(string $projectId, string|array $serviceType): Project
    {
        $types = (array) $serviceType;

        return Project::whereIn('service_type', $types)
            ->with(['xaasConfig', 'faasConfig', 'aaasConfig', 'agentFlowConfig', 'latestVersion'])
            ->findOrFail($projectId);
    }

    /** The handler file must match the runtime manifest's handler pattern (when it has one). */
    private function validateHandlerExists(Project $project, string $code): void
    {
        $manifest = $this->runtimes->manifest($project->runtime);
        $pattern = $manifest?->handlerPattern(ProjectEntrypoint::for($project, $manifest)->handler);

        if ($pattern && ! preg_match($pattern, $code)) {
            throw ValidationException::withMessages([
                'code' => __('api.code.handler_required'),
            ]);
        }
    }

    private function abortIfEol(Project $project): void
    {
        if ($project->isRuntimeEol()) {
            abort(422, __('api.project.eol_save'));
        }
    }

    /**
     * The file that holds the handler (ProjectEntrypoint: "handler.handler" → handler.php; the
     * runtime's default file when none is set) — the same rule as ProjectService and the editor.
     */
    private function mainFileName(Project $project): string
    {
        $manifest = $this->runtimes->manifest($project->runtime ?? 'python');

        return $manifest ? ProjectEntrypoint::for($project, $manifest)->filename : 'main.txt';
    }
}
