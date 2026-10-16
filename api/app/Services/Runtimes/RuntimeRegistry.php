<?php

namespace App\Services\Runtimes;

use App\Jobs\DeleteRuntimeImagesJob;
use App\Models\ExecutorImage;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\RuntimeBuild;
use App\Models\UploadedImage;
use App\Services\FunctionBuildService;
use App\Services\Images\ImageOwner;
use App\Services\Images\UploadedImages;
use App\Services\Wasm\WasmRuntimes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Stancl\Tenancy\Tenancy;

/**
 * Every language runtime RunR knows, whatever its source: the built-in catalog (manifest in the
 * release), platform runtimes and custom (organization) runtimes (manifest in the runtimes
 * table). Versions live in executor_images for all of them — built-ins get theirs from
 * executor:sync (versionDiscovery), the others from their manifest's versions list.
 *
 * Bound scoped (one instance per request / job, Octane-safe): rows are cached per instance.
 */
class RuntimeRegistry
{
    /** @var array<string, Runtime|false> */
    private array $rows = [];

    public function __construct(
        private BuiltinCatalog $catalog,
        private ManifestValidator $validator,
    ) {}

    // ── Lookup ──────────────────────────────────────────────────────────────

    /** Manifest of a runtime id (built-in catalog first), null when RunR does not know it. */
    public function manifest(?string $id): ?RuntimeManifest
    {
        if ($id === null || $id === '') {
            return null;
        }

        if ($builtin = $this->catalog->get($id)) {
            return $builtin;
        }

        $row = $this->row($id);

        return $row && $row->manifest ? new RuntimeManifest($row->manifest) : null;
    }

    /**
     * @throws \RuntimeException the runtime is not registered
     */
    public function require(?string $id): RuntimeManifest
    {
        return $this->manifest($id)
            ?? throw new \RuntimeException("Runtime '{$id}' is not registered on this platform.");
    }

    /** The manifest a code project runs on (projects without a runtime are Python, as before). */
    public function forProject(Project $project): RuntimeManifest
    {
        return $this->require($project->runtime ?? 'python');
    }

    public function row(string $id): ?Runtime
    {
        if (! array_key_exists($id, $this->rows)) {
            $this->rows[$id] = Runtime::find($id) ?? false;
        }

        return $this->rows[$id] ?: null;
    }

    public function forget(?string $id = null): void
    {
        if ($id === null) {
            $this->rows = [];
        } else {
            unset($this->rows[$id]);
        }
    }

    /** Built-in ids offered on this platform (EXECUTOR_RUNTIMES, default: the whole catalog). */
    public function builtinIds(): array
    {
        $enabled = config('executor.runtimes', []);
        $ids = array_keys($this->catalog->all());

        return $enabled ? array_values(array_intersect($ids, $enabled)) : $ids;
    }

    /**
     * Runtimes the current context sees: built-in and platform runtimes, plus the custom runtimes
     * of the current tenant (all custom runtimes outside tenant context). Disabled ones only with
     * $includeDisabled.
     *
     * @return Collection<int, Runtime>
     */
    public function visible(bool $includeDisabled = false, ?string $regionId = null): Collection
    {
        $tenantId = self::currentTenantId();
        $builtins = $this->builtinIds();

        return Runtime::query()
            ->where(fn ($q) => $q
                ->where(fn ($b) => $b->where('source', Runtime::SOURCE_BUILTIN)->whereIn('id', $builtins))
                ->orWhere('source', Runtime::SOURCE_PLATFORM)
                ->orWhere(fn ($c) => $c->where('source', Runtime::SOURCE_CUSTOM)
                    ->when($tenantId !== null, fn ($t) => $t->where(fn ($w) => $w->whereNull('tenant_id')->orWhere('tenant_id', $tenantId)))))
            ->when(! $includeDisabled, fn ($q) => $q->where('is_enabled', true))
            ->get()
            ->filter(fn (Runtime $r) => $this->manifest($r->id) !== null && $r->availableIn($regionId)
                // WebAssembly runtimes: only where the region's WebAssembly support has their shim
                && app(WasmRuntimes::class)->offeredIn($this->manifest($r->id), $regionId))
            ->sortBy(fn (Runtime $r) => [$r->source === Runtime::SOURCE_BUILTIN ? 0 : 1, array_search($r->id, $builtins, true) ?: 0, $r->name])
            ->values();
    }

    /**
     * Why a project can not be created / deployed on the runtime, null when it can.
     */
    public function unavailableReason(?string $id, ?string $regionId = null, ?string $trigger = null): ?string
    {
        $trigger = $trigger === null ? null : RuntimeManifest::runtimeTrigger($trigger);
        $manifest = $this->manifest($id);
        $row = $id ? $this->row($id) : null;

        if (! $manifest || ($row === null && ! $this->catalog->has((string) $id))) {
            return __('api.runtimes.unknown', ['id' => $id]);
        }
        if ($this->catalog->has($id) && ! in_array($id, $this->builtinIds(), true)) {
            return __('api.runtimes.unknown', ['id' => $id]);
        }
        if ($row && ! $row->is_enabled) {
            return __('api.runtimes.disabled', ['name' => $manifest->name()]);
        }
        if ($row && $row->source === Runtime::SOURCE_CUSTOM && ($tenant = self::currentTenantId()) !== null
            && $row->tenant_id !== null && $row->tenant_id !== $tenant) {
            return __('api.runtimes.unknown', ['id' => $id]);
        }
        if ($row && ! $row->availableIn($regionId)) {
            return __('api.runtimes.not_in_region', ['name' => $manifest->name()]);
        }
        if ($wasm = app(WasmRuntimes::class)->unavailableReason($manifest, $regionId)) {
            return $wasm;
        }
        if ($trigger !== null && in_array($trigger, RuntimeManifest::TRIGGERS, true) && ! $manifest->supportsTrigger($trigger)) {
            return __('api.runtimes.trigger_unsupported', ['name' => $manifest->name(), 'trigger' => $trigger]);
        }

        return null;
    }

    /**
     * Why a deploy on the runtime is refused (disabled, not offered in the region), null when it
     * may go ahead. Running deployments are not touched by either.
     */
    public function deployBlockedReason(string $id, ?string $regionId): ?string
    {
        $manifest = $this->manifest($id);
        $row = $this->row($id);

        if (! $manifest) {
            return __('api.runtimes.unknown', ['id' => $id]);
        }
        if ($row && ! $row->is_enabled) {
            return __('api.runtimes.disabled', ['name' => $manifest->name()]);
        }
        if ($row && ! $row->availableIn($regionId)) {
            return __('api.runtimes.not_in_region', ['name' => $manifest->name()]);
        }
        if ($wasm = app(WasmRuntimes::class)->unavailableReason($manifest, $regionId)) {
            return $wasm;
        }

        return null;
    }

    // ── Images ──────────────────────────────────────────────────────────────

    /**
     * The executor version record a project runs on (ExecutorImage::resolve() semantics).
     *
     * @throws \RuntimeException the version is gone or past its EOL
     */
    public function executorFor(Project $project): ExecutorImage
    {
        $runtime = $project->runtime ?? 'python';
        $executor = ExecutorImage::resolve($runtime, $project->runtime_version);

        if (! $executor) {
            $v = $project->runtime_version ?? 'latest';
            throw new \RuntimeException("Runtime {$runtime}:{$v} is no longer available. Please upgrade to a supported version before deploying.");
        }

        return $executor;
    }

    /**
     * The image of a runtime version in a region: the version's image, or — for a version built
     * from a dockerfile / git source — the region's build of it.
     *
     * @throws RuntimeImagePendingException the region has no ready build of that version yet
     */
    public function versionImage(RuntimeManifest $manifest, ExecutorImage $executor, ?KnativeRegion $region): string
    {
        $spec = $manifest->version($executor->version);

        if ($spec === null || $spec['image'] !== null) {
            if (! $executor->docker_image && $spec === null) {
                throw new \RuntimeException("Runtime {$manifest->id()}:{$executor->version} has no image.");
            }

            return $spec['image'] ?? $executor->docker_image;
        }

        // An uploaded image (docs/images.md): the owner's upload of that name in the region, pinned by digest
        if (($spec['upload'] ?? null) !== null) {
            return $this->uploadedVersionImage($manifest, $spec, $region);
        }

        $builds = app(RuntimeImageBuildService::class);
        $row = $this->row($manifest->id());
        $build = $region && $row ? $builds->readyBuild($row, $spec, $region) : null;
        if (! $build) {
            if ($region && $row) {
                $builds->ensure($row, $spec, $region);
            }
            throw new RuntimeImagePendingException($manifest, $executor->version, $region);
        }

        return $build->image;
    }

    /**
     * The image of an `upload:` version in a region: the runtime owner's uploaded image (platform
     * runtimes: platform uploads; custom runtimes: their organization's), by digest.
     *
     * @throws \RuntimeException the image is not uploaded in the region
     */
    public function uploadedVersionImage(RuntimeManifest $manifest, array $spec, ?KnativeRegion $region): string
    {
        $owner = self::uploadOwner($this->row($manifest->id()));
        $image = $region ? app(UploadedImages::class)->resolve((string) $spec['upload'], $owner, (string) $region->id) : null;

        if (! $image) {
            throw new \RuntimeException(__('images.runtime_upload_missing', [
                'runtime' => $manifest->id(),
                'version' => $spec['version'],
                'reference' => $spec['upload'],
                'region' => $region?->name ?? '-',
            ]));
        }

        return $image->imageRef($region);
    }

    /** Whose uploads a runtime's `upload:` versions name: a custom runtime's organization, else the platform. */
    public static function uploadOwner(?Runtime $row): string
    {
        return ImageOwner::keyFor($row && $row->source === Runtime::SOURCE_CUSTOM ? $row->tenant_id : null);
    }

    /**
     * `upload:` versions must name an uploaded image of the runtime's owner in every region the
     * runtime is offered in (active regions with a registry; $regions null = every one).
     *
     * @param  list<string>|null  $regions
     *
     * @throws InvalidManifestException
     */
    public function checkUploads(RuntimeManifest $manifest, string $source, ?array $regions, ?string $tenantId): void
    {
        if ($manifest->uploadVersions() === []) {
            return;
        }

        $uploads = app(UploadedImages::class);
        $owner = ImageOwner::keyFor($source === Runtime::SOURCE_CUSTOM ? $tenantId : null);
        $ownRegions = UploadedImage::query()->where('owner_key', $owner)->distinct()->pluck('knative_region_id')->all();
        $regionIds = KnativeRegion::query()->get()
            ->filter(fn (KnativeRegion $r) => $r->is_active && ! $r->is_demo && $r->has_registry && ($regions === null || in_array($r->id, $regions, true)))
            // a platform runtime offered "everywhere" is checked in the platform's regions, not in an organization's own
            ->filter(fn (KnativeRegion $r) => $source === Runtime::SOURCE_CUSTOM || $regions !== null || ($r->owner_tenant_id ?? null) === null)
            ->pluck('id')->values()->all();

        $problems = [];
        foreach ($manifest->versions() as $i => $v) {
            if (($v['upload'] ?? null) === null) {
                continue;
            }
            $known = collect($ownRegions)->contains(fn ($regionId) => $uploads->resolve((string) $v['upload'], $owner, (string) $regionId) !== null);
            if (! $known) {
                $problems[] = ['path' => "/versions/{$i}/upload", 'message' => __('images.runtime_upload_unknown', ['reference' => $v['upload']])];

                continue;
            }
            if ($missing = $uploads->missingRegions((string) $v['upload'], $owner, $regionIds)) {
                $problems[] = ['path' => "/versions/{$i}/upload", 'message' => __('images.runtime_upload_regions', ['reference' => $v['upload'], 'regions' => implode(', ', $missing)])];
            }
        }

        if ($problems !== []) {
            throw new InvalidManifestException($problems);
        }
    }

    /**
     * Images the platform runs functions on: active versions of built-in and platform runtimes
     * that have a plain image (custom runtimes and region builds excluded), the WebAssembly
     * runtimes left out — their image is the build toolchain (wasmBuildImages()).
     *
     * @return list<string>
     */
    public function runtimeImages(): array
    {
        return $this->activeImages(fn (string $id) => ! WasmRuntimes::isWasm($this->manifest($id)));
    }

    /**
     * Images worth pre-pulling on every region node: runtimeImages() without the ML runtimes
     * (category ml: PyTorch, TensorFlow and their GPU builds, several GB each), which the nodes
     * their functions run on pull on first use, like custom runtimes and region builds.
     *
     * @return list<string>
     */
    public function prepullImages(): array
    {
        return $this->activeImages(function (string $id) {
            $manifest = $this->manifest($id);

            return ! WasmRuntimes::isWasm($manifest) && $manifest?->category() !== 'ml';
        });
    }

    /**
     * Plain images of the active versions of built-in and platform runtimes that pass $keep.
     *
     * @param  callable(string): bool  $keep
     * @return list<string>
     */
    private function activeImages(callable $keep): array
    {
        $ids = array_merge(
            $this->builtinIds(),
            Runtime::where('source', Runtime::SOURCE_PLATFORM)->where('is_enabled', true)->pluck('id')->all(),
        );
        $ids = array_values(array_filter($ids, $keep));

        return ExecutorImage::where('is_active', true)
            ->whereIn('runtime', $ids)
            ->where('docker_image', '!=', '')
            ->pluck('docker_image')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The images function builds start FROM (FunctionBuildService::stageImages()) for the default
     * version of every active built-in and platform runtime that builds with Kaniko — one with a
     * build step or dependencies, not WebAssembly — when that version runs an image (a version
     * built in the region or uploaded is in the region registry already), the ML runtimes left out
     * as in prepullImages(). `region:reconcile` has the region registry fetch them before the first
     * build needs them (RegistryPrewarmStep).
     *
     * @return list<string>
     */
    public function buildBaseImages(): array
    {
        $ids = array_merge(
            $this->builtinIds(),
            Runtime::where('source', Runtime::SOURCE_PLATFORM)->where('is_enabled', true)->pluck('id')->all(),
        );

        $images = [];
        foreach ($ids as $id) {
            $manifest = $this->manifest($id);
            if ($manifest === null || WasmRuntimes::isWasm($manifest) || $manifest->category() === 'ml'
                || ($manifest->build() === null && $manifest->dependencies() === null)) {
                continue;
            }
            $executor = ExecutorImage::resolve($id);
            if (! $executor) {
                continue;
            }
            $spec = $manifest->version($executor->version);
            $image = $spec === null ? (string) $executor->docker_image : (string) ($spec['image'] ?? '');
            if ($image === '') {
                continue;
            }

            array_push($images, ...FunctionBuildService::stageImages($manifest, ['version' => $executor->version, 'image' => $image]));
        }

        return array_values(array_unique(array_filter($images)));
    }

    /**
     * Toolchain images the build Jobs of WebAssembly runtimes run in a project namespace (the
     * manifest's build.image, by default the version image; WasmBuildService::job()): active
     * versions of built-in and platform runtimes. Like runtimeImages(), what the platform chose,
     * never a tenant — the admission policy exempts both (AdmissionManifests::systemGlobs()). A
     * version built in the region or uploaded is in the region registry (runr-runtimes/,
     * runr-uploads/), signed there.
     *
     * @return list<string>
     */
    public function wasmBuildImages(): array
    {
        $ids = array_merge(
            $this->builtinIds(),
            Runtime::where('source', Runtime::SOURCE_PLATFORM)->where('is_enabled', true)->pluck('id')->all(),
        );

        $images = [];
        foreach ($ids as $id) {
            $manifest = $this->manifest($id);
            if ($manifest === null || ! WasmRuntimes::isWasm($manifest)) {
                continue;
            }
            $build = (string) ($manifest->build()['image'] ?? '{image}');
            foreach (ExecutorImage::where('is_active', true)->where('runtime', $id)->get() as $executor) {
                $spec = $manifest->version($executor->version);
                $image = $spec === null ? (string) $executor->docker_image : (string) ($spec['image'] ?? '');
                if ($image === '' && str_contains($build, '{image}')) {
                    continue;   // the region's build or upload of the version
                }
                $images[] = Placeholders::render($build, ['version' => $executor->version, 'image' => $image]);
            }
        }

        return array_values(array_unique(array_filter($images)));
    }

    // ── Registration ────────────────────────────────────────────────────────

    /** Rows of the built-in catalog (state only: enabled, regions). */
    public function syncBuiltins(): void
    {
        foreach ($this->catalog->all() as $id => $manifest) {
            Runtime::firstOrCreate(
                ['id' => $id],
                ['name' => $manifest->name(), 'source' => Runtime::SOURCE_BUILTIN, 'is_enabled' => true],
            );
        }
        $this->forget();
    }

    /**
     * Register a platform or custom runtime.
     *
     * @param  array<string, string>  $files
     *
     * @throws ValidationException
     */
    public function register(string $source, string $text, array $files, ?array $regions, ?string $userId): Runtime
    {
        $manifest = $this->parseForSource($source, $text, $files);
        $id = $manifest->id();

        if ($this->catalog->has($id) || Runtime::whereKey($id)->exists()) {
            throw ValidationException::withMessages(['manifest' => [__('api.runtimes.id_taken', ['id' => $id])]]);
        }
        $this->checkUploads($manifest, $source, $regions, self::currentTenantId());

        return DB::transaction(function () use ($source, $text, $files, $regions, $userId, $manifest) {
            $runtime = Runtime::create([
                'id' => $manifest->id(),
                'name' => $manifest->name(),
                'source' => $source,
                'tenant_id' => $source === Runtime::SOURCE_CUSTOM ? self::currentTenantId() : null,
                'created_by' => $userId,
                'manifest' => $manifest->toArray(),
                'manifest_source' => $text,
                'files' => $files ?: null,
                'digest' => $manifest->digest(),
                'is_enabled' => true,
                'regions' => $regions,
            ]);
            $this->syncVersions($runtime, $manifest);
            $this->forget($runtime->id);

            return $runtime;
        });
    }

    /**
     * Update a runtime. Built-ins: only is_enabled / regions; the manifest id can not change.
     *
     * @param  array<string, string>|null  $files  null = keep the uploaded files
     *
     * @throws ValidationException
     */
    public function update(Runtime $runtime, ?string $text, ?array $files, array $state): Runtime
    {
        return DB::transaction(function () use ($runtime, $text, $files, $state) {
            if (($text !== null || $files !== null) && ! $runtime->isBuiltin()) {
                $files = $files ?? ($runtime->files ?? []);
                $text = $text ?? (string) $runtime->manifest_source;
                $manifest = $this->parseForSource($runtime->source, $text, $files);

                if ($manifest->id() !== $runtime->id) {
                    throw ValidationException::withMessages(['manifest' => [__('api.runtimes.id_immutable', ['id' => $runtime->id])]]);
                }
                $this->checkUploads($manifest, $runtime->source, array_key_exists('regions', $state) ? $state['regions'] : $runtime->regions, $runtime->tenant_id);

                $runtime->fill([
                    'name' => $manifest->name(),
                    'manifest' => $manifest->toArray(),
                    'manifest_source' => $text,
                    'files' => $files ?: null,
                    'digest' => $manifest->digest(),
                ]);
                $this->syncVersions($runtime, $manifest);
            }

            $runtime->fill(array_intersect_key($state, array_flip(['is_enabled', 'regions'])));
            $runtime->save();
            $this->forget($runtime->id);

            return $runtime;
        });
    }

    /** Delete a runtime, its versions and builds; its built images leave the region registries. */
    public function delete(Runtime $runtime): void
    {
        $builtIn = RuntimeBuild::where('runtime_id', $runtime->id)->whereNotNull('image')
            ->pluck('knative_region_id')->map(fn ($id) => (string) $id)->unique()->values()->all();

        DB::transaction(function () use ($runtime) {
            ExecutorImage::where('runtime', $runtime->id)->delete();
            RuntimeBuild::where('runtime_id', $runtime->id)->delete();
            $runtime->delete();
        });
        $this->forget($runtime->id);

        if ($builtIn !== []) {
            try {
                DeleteRuntimeImagesJob::dispatch($runtime->id, $builtIn);
            } catch (\Throwable $e) {
                Log::warning('Runtime image cleanup not dispatched', ['runtime' => $runtime->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * executor_images rows of a platform / custom runtime from its manifest: one per version
     * (docker_image empty for a version built from source), versions dropped from the manifest
     * deactivated — projects pinned to them fall back to the default like any retired version.
     */
    public function syncVersions(Runtime $runtime, RuntimeManifest $manifest): void
    {
        $listed = [];
        foreach ($manifest->versions() as $i => $v) {
            $listed[] = $v['version'];
            ExecutorImage::updateOrCreate(
                ['runtime' => $runtime->id, 'version' => $v['version']],
                [
                    'docker_image' => $v['image'] ?? '',
                    'is_default' => $v['default'],
                    'is_active' => true,
                    'eol_at' => $v['eol'],
                    'sort_order' => $i + 1,
                    'category' => $manifest->category(),
                    'gpu_required' => $v['gpu'],
                ],
            );
        }

        ExecutorImage::where('runtime', $runtime->id)
            ->whereNotIn('version', $listed)
            ->update(['is_active' => false, 'is_default' => false]);
    }

    /**
     * @param  array<string, string>  $files
     *
     * @throws InvalidManifestException
     */
    public function validateText(string $source, string $text, array $files = []): RuntimeManifest
    {
        return $this->parseForSource($source, $text, $files);
    }

    /**
     * @throws InvalidManifestException
     */
    private function parseForSource(string $source, string $text, array $files): RuntimeManifest
    {
        $manifest = $this->validator->parse($text, $files);

        if ($source === Runtime::SOURCE_CUSTOM && $manifest->runtimeClass() !== null) {
            throw new InvalidManifestException([['path' => '/runtimeClass', 'message' => __('api.runtimes.runtime_class_platform_only')]]);
        }

        return $manifest;
    }

    /** Tenant key in tenant context (Tenancy module), null otherwise. */
    public static function currentTenantId(): ?string
    {
        if (! function_exists('tenancy') || ! app()->bound(Tenancy::class)) {
            return null;
        }

        $tenancy = tenancy();

        return $tenancy->initialized && $tenancy->tenant ? (string) $tenancy->tenant->getTenantKey() : null;
    }
}
