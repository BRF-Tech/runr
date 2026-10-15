<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Runtime\SaveRuntimeRequest;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\RuntimeResource;
use App\Jobs\RuntimeTestDeployJob;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\RuntimeBuild;
use App\Models\Setting;
use App\Services\ProjectService;
use App\Services\Runtimes\ProjectEntrypoint;
use App\Services\Runtimes\RuntimeImageBuildService;
use App\Services\Runtimes\RuntimeRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Runtime plugins: the catalog every user picks from, and registration of platform runtimes
 * (runtimes.manage) and custom organization runtimes (runtimes.use_custom).
 */
class RuntimeController extends Controller
{
    public function __construct(
        private RuntimeRegistry $runtimes,
        private RuntimeImageBuildService $builds,
    ) {}

    /** GET /runtimes — ?all=1 (manage) includes disabled runtimes, ?region=<id> filters by availability */
    public function index(Request $request): JsonResponse
    {
        $manage = Gate::allows('runtimes.manage');
        $all = $request->boolean('all') && ($manage || Gate::allows('runtimes.use_custom'));

        $list = $this->runtimes->visible($all, $request->query('region'))
            ->filter(fn (Runtime $r) => $all ? $this->canSee($r, $manage) : true)
            ->values();

        return response()->json([
            'data' => RuntimeResource::collection($list)->resolve($request),
            'abilities' => [
                'manage' => $manage,
                'use_custom' => Gate::allows('runtimes.use_custom'),
            ],
            'custom_enabled' => Setting::get('custom_runtimes_enabled', 'false') === 'true',
        ]);
    }

    public function show(Runtime $runtime): JsonResponse
    {
        $this->abortUnlessVisible($runtime);

        return response()->json(['data' => (new RuntimeResource($runtime))->detail()->resolve()]);
    }

    /** POST /runtimes/validate — the manifest editor's live check */
    public function validateManifest(Request $request): JsonResponse
    {
        abort_unless(Gate::any(['runtimes.manage', 'runtimes.use_custom']), 403);
        $request->validate(['manifest' => 'required|string|max:262144', 'files' => 'sometimes|nullable|array']);

        $manifest = $this->runtimes->validateText(
            Gate::allows('runtimes.manage') ? Runtime::SOURCE_PLATFORM : Runtime::SOURCE_CUSTOM,
            $request->input('manifest'),
            array_map('strval', (array) ($request->input('files') ?? [])),
        );

        return response()->json(['valid' => true, 'manifest' => $manifest->toArray()]);
    }

    public function store(SaveRuntimeRequest $request): JsonResponse
    {
        $source = match (true) {
            Gate::allows('runtimes.manage') => Runtime::SOURCE_PLATFORM,
            Gate::allows('runtimes.use_custom') => Runtime::SOURCE_CUSTOM,
            default => abort(403),
        };

        $runtime = $this->runtimes->register(
            $source,
            $request->input('manifest'),
            $request->files() ?? [],
            $request->has('regions') ? $request->input('regions') : null,
            (string) $request->user()->id,
        );

        $this->builds->ensureAll($runtime);

        return response()->json(['data' => (new RuntimeResource($runtime->fresh()))->detail()->resolve()], 201);
    }

    public function update(SaveRuntimeRequest $request, Runtime $runtime): JsonResponse
    {
        $this->authorizeChange($runtime);

        if ($runtime->isBuiltin() && ($request->has('manifest') || $request->has('files'))) {
            throw ValidationException::withMessages(['manifest' => [__('api.runtimes.builtin_readonly')]]);
        }

        $state = [];
        if ($request->has('is_enabled')) {
            $state['is_enabled'] = $request->boolean('is_enabled');
        }
        if ($request->has('regions')) {
            $state['regions'] = $request->input('regions');
        }

        $runtime = $this->runtimes->update($runtime, $request->input('manifest'), $request->files(), $state);

        if (! $runtime->isBuiltin()) {
            $this->builds->ensureAll($runtime);
        }

        return response()->json(['data' => (new RuntimeResource($runtime->fresh()))->detail()->resolve()]);
    }

    public function destroy(Runtime $runtime): JsonResponse
    {
        $this->authorizeChange($runtime);
        abort_if($runtime->isBuiltin(), 403, __('api.runtimes.builtin_delete'));

        $count = Project::where('runtime', $runtime->id)->count();
        abort_if($count > 0, 409, __('api.runtimes.in_use', ['count' => $count]));

        $this->runtimes->delete($runtime);

        return response()->json(null, 204);
    }

    /** GET /runtimes/{id}/template — starter files for a service type and handler */
    public function template(Request $request, Runtime $runtime): JsonResponse
    {
        $this->abortUnlessVisible($runtime);
        $request->validate([
            'service_type' => 'sometimes|in:xaas,faas',
            'handler' => 'sometimes|nullable|string|max:128',
        ]);

        $manifest = $this->runtimes->require($runtime->id);
        $faas = $request->input('service_type', 'xaas') === 'faas';
        $entry = $faas
            ? ProjectEntrypoint::fromHandlerString($request->input('handler') ?: ProjectEntrypoint::defaultHandlerString($manifest), $manifest)
            : new ProjectEntrypoint($manifest->defaultFilename(), $request->input('handler') ?: $manifest->handlerFunction());

        $files = $manifest->templateFiles($entry->filename, $entry->handler, $faas);

        return response()->json(['files' => (object) ([$entry->filename => $files[$entry->filename] ?? ''] + $files)]);
    }

    /** GET /runtimes/{id}/builds — region builds of the dockerfile / git versions */
    public function builds(Runtime $runtime): JsonResponse
    {
        $this->abortUnlessVisible($runtime);

        $regions = KnativeRegion::query()->pluck('name', 'id');
        $builds = RuntimeBuild::where('runtime_id', $runtime->id)->orderBy('version')->get();

        return response()->json(['data' => $builds->map(fn (RuntimeBuild $b) => [
            'version' => $b->version,
            'knative_region_id' => $b->knative_region_id,
            'region_name' => $regions[$b->knative_region_id] ?? null,
            'status' => $b->status,
            'image' => $b->image,
            'error' => $b->error,
            'updated_at' => $b->updated_at,
        ])->values()]);
    }

    /** POST /runtimes/{id}/builds {knative_region_id, version?} — (re)build in a region */
    public function build(Request $request, Runtime $runtime): JsonResponse
    {
        $this->authorizeChange($runtime);
        $data = $request->validate([
            'knative_region_id' => 'required|string|exists:knative_regions,id',
            'version' => 'sometimes|nullable|string',
        ]);

        $manifest = $this->runtimes->require($runtime->id);
        $region = KnativeRegion::findOrFail($data['knative_region_id']);
        $versions = array_filter(
            RuntimeImageBuildService::sourceVersions($manifest),
            fn ($v) => empty($data['version']) || $v['version'] === $data['version'],
        );

        if (! $versions) {
            throw ValidationException::withMessages(['version' => [__('api.runtimes.no_source_versions')]]);
        }

        foreach ($versions as $spec) {
            $this->builds->ensure($runtime, $spec, $region, true);
        }

        return $this->builds($runtime)->setStatusCode(202);
    }

    /**
     * POST /runtimes/{id}/test-deploy {knative_region_id, version?} — an XaaS HTTP project from
     * the runtime's starter template, built when needed and deployed as soon as it is ready.
     */
    public function testDeploy(Request $request, Runtime $runtime, ProjectService $projects): JsonResponse
    {
        $this->authorizeChange($runtime);
        $data = $request->validate([
            'knative_region_id' => 'required|string|exists:knative_regions,id',
            'version' => 'sometimes|nullable|string',
        ]);

        if ($reason = $this->runtimes->unavailableReason($runtime->id, $data['knative_region_id'], 'http')) {
            throw ValidationException::withMessages(['runtime' => [$reason]]);
        }

        $project = $projects->create([
            'name' => Str::limit("rt-{$runtime->id}", 40, '').'-'.Str::lower(Str::random(5)),
            'service_type' => 'xaas',
            'runtime' => $runtime->id,
            'runtime_version' => $data['version'] ?? null,
            'description' => __('api.runtimes.test_deploy_description', ['name' => $runtime->name]),
            'knative_region_id' => $data['knative_region_id'],
            'created_by' => $request->user()->id,
            'config' => ['trigger_type' => 'http'],
        ]);

        $version = $projects->saveXaaSCode($project, $projects->getDefaultCode($project), (string) $request->user()->id, 'runtime test deploy');
        RuntimeTestDeployJob::dispatch($version, (string) $request->user()->username, $request->user()->id);

        return response()->json(['data' => new ProjectResource($project->fresh(['xaasConfig', 'latestVersion', 'knativeRegion']))], 201);
    }

    // ── Access ──────────────────────────────────────────────────────────────

    /** A custom runtime is changed by its organization (runtimes.use_custom) or a platform admin. */
    private function authorizeChange(Runtime $runtime): void
    {
        if (Gate::allows('runtimes.manage')) {
            return;
        }

        abort_unless(
            $runtime->source === Runtime::SOURCE_CUSTOM
                && Gate::allows('runtimes.use_custom')
                && $runtime->tenant_id === RuntimeRegistry::currentTenantId(),
            403,
        );
    }

    private function canSee(Runtime $runtime, bool $manage): bool
    {
        return $manage || $runtime->is_enabled || $runtime->source === Runtime::SOURCE_CUSTOM;
    }

    private function abortUnlessVisible(Runtime $runtime): void
    {
        $visible = $this->runtimes->visible(true)->contains(fn (Runtime $r) => $r->id === $runtime->id);

        abort_unless($visible && ($runtime->is_enabled || Gate::any(['runtimes.manage', 'runtimes.use_custom'])), 404);
    }
}
