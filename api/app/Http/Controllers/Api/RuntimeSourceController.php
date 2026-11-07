<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\RuntimeResource;
use App\Models\Runtime;
use App\Models\RuntimePatch;
use App\Models\RuntimePatchFeed;
use App\Models\RuntimeSource;
use App\Models\Setting;
use App\Services\Runtimes\BuiltinCatalog;
use App\Services\Runtimes\Sources\RuntimeSourceException;
use App\Services\Runtimes\Sources\RuntimeSources;
use App\Services\Runtimes\Sources\RuntimeSourceSync;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Runtime sources (docs/runtimes.md#runtime-sources): where runtimes come from — RunR's signed
 * catalog (the built-in runtimes), and the OCI catalogs and Git repositories platform
 * administrators (runtimes.manage, for everyone) and organizations (runtimes.use_custom while
 * custom runtimes are enabled, for themselves) connect. Credentials are write-only: an answer says
 * which are set, never their value.
 */
class RuntimeSourceController extends Controller
{
    public function __construct(
        private readonly RuntimeSources $sources,
        private readonly RuntimeSourceSync $sync,
    ) {}

    /** The validation of a source's fields (create: name, kind and url required) */
    private function rules(bool $creating, bool $testing = false): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, ...($testing ? ['nullable'] : []), 'string', 'max:64'],
            'kind' => [$required, 'string', 'in:'.implode(',', RuntimeSource::KINDS)],
            'url' => [$required, 'string', 'max:512'],
            'ref' => ['sometimes', 'nullable', 'string', 'max:255'],
            'path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'public_key' => ['sometimes', 'nullable', 'string', 'max:8192'],
            'patch_feed' => ['sometimes', 'nullable', 'string', 'max:512'],
            'registry_host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'interval_minutes' => ['sometimes', 'integer', 'min:'.(int) config('runtime_sources.min_interval_minutes', 5), 'max:10080'],
            'is_enabled' => ['sometimes', 'boolean'],
            // write-only credentials: omitted keeps, null / "" removes
            'git_token' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'git_username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ssh_private_key' => ['sometimes', 'nullable', 'string', 'max:16384'],
            'ssh_known_hosts' => ['sometimes', 'nullable', 'string', 'max:16384'],
            'registry_username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'registry_password' => ['sometimes', 'nullable', 'string', 'max:4096'],
        ];
    }

    /**
     * List the runtime sources
     *
     * Every source for runtimes.manage; the organization's own for runtimes.use_custom. `catalog`
     * says which built-in catalog is in use: RunR's as last read (`source`) or the API image's
     * (`bundle`).
     */
    #[OpenApiResponse(200, 'The sources, which built-in catalog is in use, what the caller may do', type: 'array{data: list<'.RuntimeSource::OPENAPI_TYPE.'>, catalog: array{mode: "source"|"bundle", runtimes: int}, abilities: array{manage: bool, organization: bool, use_custom: bool}, custom_enabled: bool, orphan_grace_days: int}')]
    public function index(): JsonResponse
    {
        abort_unless(RuntimeSources::scopeForUser() !== null, 403, __('api.runtime_sources.not_allowed'));
        RuntimeSource::default();

        return response()->json([
            'data' => RuntimeSources::visible()->map(fn (RuntimeSource $s) => $s->toApi())->values(),
            'catalog' => ['mode' => app(BuiltinCatalog::class)->mode(), 'runtimes' => count(app(BuiltinCatalog::class)->all())],
            'abilities' => [
                'manage' => Gate::allows('runtimes.manage'),
                'organization' => Gate::allows('runtimes.use_custom'),
                // the name GET /runtimes uses for the same ability
                'use_custom' => Gate::allows('runtimes.use_custom'),
            ],
            'custom_enabled' => Setting::get('custom_runtimes_enabled', 'false') === 'true',
            // how long an orphaned runtime stays deployable (docs/runtimes.md#orphaned-runtimes)
            'orphan_grace_days' => (int) config('runtime_sources.orphan_grace_days', 90),
        ]);
    }

    /**
     * Connect a runtime source
     *
     * Holders of runtimes.manage connect platform sources, holders of runtimes.use_custom sources
     * of their organization. The source is read right away (its status tells how that went).
     */
    #[OpenApiResponse(201, 'The source, after its first read', type: 'array{data: '.RuntimeSource::OPENAPI_TYPE.'}')]
    public function store(Request $request): JsonResponse
    {
        $scope = RuntimeSources::scopeForUser();
        abort_unless($scope !== null, 403, __('api.runtime_sources.not_allowed'));
        $data = $request->validate($this->rules(true));

        $source = $this->sources->create($data, $scope, (string) $request->user()->id);
        $source = $this->readNow($source);

        return response()->json(['data' => $source->toApi()], 201);
    }

    /**
     * Show a runtime source
     *
     * With its runtimes (each with its lifecycle) and the patches it carries for them.
     */
    #[OpenApiResponse(200, 'The source, its runtimes and patches, its patch feed', type: 'array{data: array{source: '.RuntimeSource::OPENAPI_TYPE.', runtimes: list<array<string, mixed>>, patches: list<array{'.RuntimePatch::OPENAPI_FIELDS.'}>, patch_feed: '.RuntimePatchFeed::OPENAPI_TYPE.'|null}}')]
    public function show(Request $request, RuntimeSource $source): JsonResponse
    {
        abort_unless(RuntimeSources::canView($source), 404);

        $runtimes = Runtime::query()->where('source_id', $source->id)
            ->when($source->is_default, fn ($q) => $q->orWhere(fn ($b) => $b->where('source', Runtime::SOURCE_BUILTIN)->whereNull('source_id')))
            ->orderBy('id')->get();
        $feed = RuntimePatchFeed::query()->where('source_id', $source->id)->first();

        return response()->json(['data' => [
            'source' => $source->toApi(),
            'runtimes' => RuntimeResource::collection($runtimes)->resolve($request),
            'patches' => RuntimePatch::query()->where('source_id', $source->id)->where('in_feed', true)
                ->orderBy('runtime')->orderBy('version')->orderByDesc('published_at')->get()
                ->map(fn (RuntimePatch $p) => $p->toApi())->values(),
            'patch_feed' => $feed ? array_merge($feed->toApi(), ['reference' => (string) $source->patch_feed, 'enabled' => $feed->status !== RuntimePatchFeed::DISABLED]) : null,
        ]]);
    }

    /**
     * Change a runtime source
     *
     * RunR's catalog accepts is_enabled and interval_minutes only. A new location, key or
     * credentials make the next read start anew; the source is read right away.
     */
    #[OpenApiResponse(200, 'The source, after the read', type: 'array{data: '.RuntimeSource::OPENAPI_TYPE.'}')]
    public function update(Request $request, RuntimeSource $source): JsonResponse
    {
        $this->authorizeChange($source);
        $data = $request->validate($this->rules(false));

        $source = $this->sources->update($source, $data);
        $source = $this->readNow($source);

        return response()->json(['data' => $source->toApi()]);
    }

    /**
     * Remove a runtime source
     *
     * Its runtimes that no project uses are deleted; the others stay, orphaned: hidden from new
     * projects, running and deployable until their sunset. 422 for RunR's catalog.
     */
    public function destroy(RuntimeSource $source): JsonResponse
    {
        $this->authorizeChange($source);
        $this->sources->delete($source);

        return response()->json(null, 204);
    }

    /**
     * Read a runtime source now
     *
     * Reads the source even when its revision did not change. 502 when it could not be read or did
     * not verify (its status and error say why), 409 while another read of it runs.
     */
    #[OpenApiResponse(200, 'The source after the read', type: 'array{data: '.RuntimeSource::OPENAPI_TYPE.'}')]
    #[OpenApiResponse(502, 'The source could not be read or did not verify: its status and error', type: 'array{data: '.RuntimeSource::OPENAPI_TYPE.'}')]
    public function sync(RuntimeSource $source): JsonResponse
    {
        $this->authorizeChange($source);

        try {
            $source = $this->sync->sync($source, force: true);
        } catch (RuntimeSourceException $e) {
            abort(409, $e->getMessage());
        }

        return response()->json(['data' => $source->toApi()], in_array($source->status, RuntimeSource::FAILED, true) ? 502 : 200);
    }

    /**
     * Test a runtime source
     *
     * Reads and validates a source as given (not saved): whether it can be read, its revision,
     * each runtime it defines and every problem with its file and JSON path. With `source` the
     * fields change a connected source and its stored credentials are used.
     */
    #[OpenApiResponse(200, 'What a read of the source sees', type: 'array{data: array{ok: bool, status: string, error: string|null, revision: string|null, signature_verified: bool, runtimes: list<array{id: string|null, path: string, valid: bool}>, problems: list<array{file: string|null, runtime: string|null, path: string, message: string}>}}')]
    public function test(Request $request): JsonResponse
    {
        $scope = RuntimeSources::scopeForUser();
        abort_unless($scope !== null, 403, __('api.runtime_sources.not_allowed'));
        $data = $request->validate($this->rules(false, true) + ['source' => ['sometimes', 'nullable', 'string', 'max:26']]);
        $data = array_filter($data, fn ($v, $k) => ! ($k === 'name' && $v === null), ARRAY_FILTER_USE_BOTH);

        $base = null;
        if (! empty($data['source'])) {
            $base = RuntimeSource::query()->find($data['source']);
            abort_unless($base && RuntimeSources::canView($base), 404);
            $this->authorizeChange($base);
        } else {
            $request->validate(['kind' => ['required'], 'url' => ['required']]);
        }

        $draft = $this->sources->draft(array_diff_key($data, ['source' => true]), $base, $scope);

        return response()->json(['data' => $this->sync->test($draft)]);
    }

    /** Read a source right after it changed; another read running is no error here. */
    private function readNow(RuntimeSource $source): RuntimeSource
    {
        try {
            return $this->sync->sync($source, force: true);
        } catch (RuntimeSourceException) {
            return $source->fresh() ?? $source;
        }
    }

    private function authorizeChange(RuntimeSource $source): void
    {
        abort_unless(RuntimeSources::canView($source), 404);
        if (Gate::allows('runtimes.manage')) {
            return;
        }

        abort_unless($source->isOrganization() && Gate::allows('runtimes.use_custom'), 403, __('api.runtime_sources.not_allowed'));
    }
}
