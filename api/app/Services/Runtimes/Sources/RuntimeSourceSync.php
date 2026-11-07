<?php

namespace App\Services\Runtimes\Sources;

use App\Models\ExecutorImage;
use App\Models\Runtime;
use App\Models\RuntimeSource;
use App\Services\Runtimes\BuiltinCatalog;
use App\Services\Runtimes\InvalidManifestException;
use App\Services\Runtimes\ManifestValidator;
use App\Services\Runtimes\Patches\PatchFeedSync;
use App\Services\Runtimes\Patches\RuntimePatches;
use App\Services\Runtimes\Patches\RuntimePatchNotifier;
use App\Services\Runtimes\RuntimeImageBuildService;
use App\Services\Runtimes\RuntimeLifecycle;
use App\Services\Runtimes\RuntimeManifest;
use App\Services\Runtimes\RuntimeRegistry;
use App\Services\Runtimes\RuntimeVersionDiscovery;
use App\Services\SupplyChain\ImageRef;
use App\Support\Images\OciRegistryClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads runtime sources into the runtimes table (docs/runtimes.md#runtime-sources):
 *
 *  1. read — an OCI catalog (OciCatalogReader) or a Git repository (GitSourceReader); unchanged
 *     since the last good read: nothing more. A source that cannot be read, or whose catalog does
 *     not verify, keeps its runtimes as they were (status unreachable / not_found / auth_failed /
 *     invalid_signature / invalid_catalog, the error masked).
 *  2. validate — every manifest strictly (ManifestValidator, catalog features allowed); a bad one is
 *     a problem with its file and JSON path and does not stop the others (status partial). An
 *     organization's manifests may not set runtimeClass.
 *  3. claim — runtime ids are one namespace (projects store the id), see claim():
 *       - RunR's catalog keeps the built-in ids: no other source (and no registration) takes an id
 *         of the catalog in use or of the catalog the API image ships;
 *       - otherwise the first registration wins: an id held by another source, by a runtime
 *         registered by hand or by another organization is a problem naming the holder, and the
 *         runtime is skipped;
 *       - an orphaned runtime is adopted by a source of the same owner (scope and organization):
 *         its projects follow;
 *       - an id twice in one source: the first (by path) wins.
 *  4. apply — the source's runtimes are created or updated (the manifest on the row, versions from
 *     the manifest or discovered with the source's registry credentials, region builds of
 *     dockerfile / git versions); a runtime that left the source is orphaned: hidden from new
 *     projects, its projects keep running and deploying until the grace period's sunset
 *     (RuntimeLifecycle); one that comes back is no longer orphaned. A runtime whose manifest is
 *     invalid this time keeps its last good manifest.
 *  5. patches — the patch entries of a signed catalog and the source's own signed patch feed, for
 *     the source's runtimes only (PatchFeedSync).
 *
 * One sync per source at a time (a cache lock).
 */
class RuntimeSourceSync
{
    public function __construct(
        private readonly RuntimeRegistry $registry,
        private readonly ManifestValidator $validator,
        private readonly OciCatalogReader $oci,
        private readonly GitSourceReader $git,
        private readonly RuntimeImageBuildService $builds,
        private readonly PatchFeedSync $patchFeeds,
        private readonly RuntimeLifecycle $lifecycle,
        private readonly RuntimeVersionDiscovery $discovery,
        private readonly RuntimePatchNotifier $notifier,
    ) {}

    /**
     * Every source whose interval has passed (the scheduler, every five minutes), RunR's catalog
     * first; $force: every enabled source, read anew.
     *
     * @return array<string, string> source id => status after the run
     */
    public function syncDue(bool $force = false): array
    {
        RuntimeSource::default();
        $results = [];

        $sources = RuntimeSource::query()->orderByDesc('is_default')->orderBy('created_at')->get();
        foreach ($sources as $source) {
            if (! $force && ! $source->due() && $source->status !== RuntimeSource::NEVER) {
                continue;
            }
            if (! $source->is_enabled && $source->status === RuntimeSource::DISABLED) {
                continue;
            }
            try {
                $results[$source->id] = $this->sync($source, $force)->status;
            } catch (\Throwable $e) {
                Log::error('Runtime source sync failed', ['source' => $source->id, 'error' => SourceCredentials::maskFor($e->getMessage(), $source)]);
                $results[$source->id] = 'error';
            }
        }

        return $results;
    }

    /**
     * Read one source and bring its runtimes up to date.
     *
     * @throws RuntimeSourceException another sync of the source is running (status sync_running)
     */
    public function sync(RuntimeSource $source, bool $force = false): RuntimeSource
    {
        $lock = Cache::lock('runtime-source-sync:'.$source->id, 900);
        if (! $lock->get()) {
            throw new RuntimeSourceException('sync_running', __('api.runtime_sources.sync_running'));
        }

        try {
            return $this->syncLocked($source, $force);
        } finally {
            $lock->release();
            RuntimePatches::flushSources();
        }
    }

    private function syncLocked(RuntimeSource $source, bool $force): RuntimeSource
    {
        $source->checked_at = now();

        if (! $source->is_enabled || ($source->is_default && ! $source->configured())) {
            $source->forceFill(['status' => RuntimeSource::DISABLED, 'error' => null])->save();
            if ($source->is_default) {
                BuiltinCatalog::flush();
            }

            return $source;
        }

        try {
            $snapshot = $this->read($source, $force);
        } catch (RuntimeSourceException $e) {
            $source->forceFill([
                'status' => in_array($e->status, RuntimeSource::FAILED, true) ? $e->status : RuntimeSource::UNREACHABLE,
                'error' => mb_substr(SourceCredentials::maskFor($e->getMessage(), $source), 0, 1000),
            ])->save();
            Log::warning('Runtime source not read', ['source' => $source->id, 'name' => $source->name, 'status' => $source->status, 'error' => $source->error]);

            return $source;
        }

        if ($snapshot->unchanged) {
            $problems = (array) ($source->problems ?? []);
            $feedProblems = $this->sourcePatchFeed($source, $force);
            $source->forceFill([
                'problems' => $this->mergeFeedProblems($problems, $feedProblems),
                'error' => null,
            ])->save();

            return $source;
        }

        $problems = $this->apply($source, $snapshot);

        // Patches of the source's own runtimes: from its signed catalog, from its patch feed
        $owned = Runtime::query()->where('source_id', $source->id)->whereNull('orphaned_at')->pluck('id')->map(fn ($id) => (string) $id)->all();
        $feedProblems = [];
        if (! $source->is_default && $snapshot->patches !== null) {
            foreach ($this->patchFeeds->applySource($source, $snapshot->patches, $owned) as $message) {
                $feedProblems[] = ['file' => null, 'runtime' => null, 'path' => '/patches', 'message' => $message];
            }
        }
        array_push($feedProblems, ...$this->sourcePatchFeed($source, $force, $owned));

        $problems = array_merge($problems, $feedProblems);
        $source->forceFill([
            'status' => $problems === [] ? RuntimeSource::OK : RuntimeSource::PARTIAL,
            'error' => null,
            'problems' => array_slice($problems, 0, 200),
            'revision' => $snapshot->revision,
            'rejected_revision' => null,
            'signature_verified' => $snapshot->signatureVerified,
            'runtimes_count' => count($owned),
            'synced_at' => now(),
        ])->save();

        Log::info('Runtime source read', ['source' => $source->id, 'name' => $source->name, 'revision' => $snapshot->revision, 'runtimes' => count($owned), 'problems' => count($problems)]);

        return $source;
    }

    /**
     * Read and validate a source without changing anything (Test connection): what a sync would
     * see.
     *
     * @return array{ok: bool, status: string, error: string|null, revision: string|null, signature_verified: bool, runtimes: list<array{id: string|null, path: string, valid: bool}>, problems: list<array{file: string|null, runtime: string|null, path: string, message: string}>}
     */
    public function test(RuntimeSource $source): array
    {
        try {
            $snapshot = $this->read($source, true);
        } catch (RuntimeSourceException $e) {
            return [
                'ok' => false, 'status' => in_array($e->status, RuntimeSource::FAILED, true) ? $e->status : RuntimeSource::UNREACHABLE,
                'error' => SourceCredentials::maskFor($e->getMessage(), $source), 'revision' => null, 'signature_verified' => false,
                'runtimes' => [], 'problems' => [],
            ];
        }

        $problems = [];
        $runtimes = [];
        $seen = [];
        foreach ($snapshot->entries as $entry) {
            [$manifest, $entryProblems] = $this->validateEntry($source, $entry);
            $id = $manifest?->id();
            if ($manifest !== null) {
                if (isset($seen[$id])) {
                    $entryProblems[] = $this->problem($entry['path'], $id, '/id', __('api.runtime_sources.clash_same', ['id' => $id, 'path' => $seen[$id]]));
                } elseif ($clash = $this->claim($source, $id, Runtime::query()->find($id))) {
                    $entryProblems[] = $this->problem($entry['path'], $id, '/id', $clash);
                }
                $seen[$id] ??= $entry['path'];
            }
            array_push($problems, ...$entryProblems);
            $runtimes[] = ['id' => $id ?? self::directoryId($entry['path']), 'path' => $entry['path'], 'valid' => $entryProblems === []];
        }

        return [
            'ok' => true, 'status' => $problems === [] ? RuntimeSource::OK : RuntimeSource::PARTIAL, 'error' => null,
            'revision' => $snapshot->revision, 'signature_verified' => $snapshot->signatureVerified,
            'runtimes' => $runtimes, 'problems' => array_slice($problems, 0, 200),
        ];
    }

    /**
     * @throws RuntimeSourceException
     */
    private function read(RuntimeSource $source, bool $force): SourceSnapshot
    {
        return $source->kind === RuntimeSource::KIND_GIT
            ? $this->git->read($source, $force)
            : $this->oci->read($source, $force);
    }

    /**
     * Validate, claim and store the runtimes of a read; orphan the ones that left.
     *
     * @return list<array{file: string|null, runtime: string|null, path: string, message: string}>
     */
    private function apply(RuntimeSource $source, SourceSnapshot $snapshot): array
    {
        $problems = [];
        /** @var array<string, array{manifest: RuntimeManifest, entry: array}> $accepted */
        $accepted = [];
        /** @var array<string, true> ids present in the source, valid or not (an invalid one is not orphaned) */
        $present = [];
        $seen = [];

        foreach ($snapshot->entries as $entry) {
            [$manifest, $entryProblems] = $this->validateEntry($source, $entry);
            $present[$manifest?->id() ?? self::directoryId($entry['path'])] = true;
            if ($manifest === null || $entryProblems !== []) {
                array_push($problems, ...$entryProblems);

                continue;
            }

            $id = $manifest->id();
            if (isset($seen[$id])) {
                $problems[] = $this->problem($entry['path'], $id, '/id', __('api.runtime_sources.clash_same', ['id' => $id, 'path' => $seen[$id]]));

                continue;
            }
            $seen[$id] = $entry['path'];

            $row = Runtime::query()->find($id);
            if ($clash = $this->claim($source, $id, $row)) {
                $problems[] = $this->problem($entry['path'], $id, '/id', $clash);
                Log::notice('Runtime source id clash', ['source' => $source->id, 'runtime' => $id, 'holder' => $row ? ($row->source_id ?? $row->source) : null]);

                continue;
            }
            $accepted[$id] = ['manifest' => $manifest, 'entry' => $entry];
        }

        foreach ($accepted as $id => ['manifest' => $manifest, 'entry' => $entry]) {
            try {
                $this->store($source, $manifest, $entry);
            } catch (\Throwable $e) {
                $problems[] = $this->problem($entry['path'], $id, '/', SourceCredentials::maskFor($e->getMessage(), $source));
                Log::warning('Runtime of a source not stored', ['source' => $source->id, 'runtime' => $id, 'error' => SourceCredentials::maskFor($e->getMessage(), $source)]);
            }
        }

        $this->orphanMissing($source, $present);

        $this->registry->forget();
        if ($source->is_default) {
            BuiltinCatalog::flush();
        }

        return $problems;
    }

    /**
     * The manifest of one entry and what is wrong with it.
     *
     * @return array{0: RuntimeManifest|null, 1: list<array{file: string|null, runtime: string|null, path: string, message: string}>}
     */
    private function validateEntry(RuntimeSource $source, array $entry): array
    {
        $dirId = self::directoryId($entry['path']);
        if ($entry['problem'] !== null || ! is_array($entry['data'])) {
            // "/build/files/x: references …" (a reader's problem with its JSON path) or a plain message
            $message = (string) ($entry['problem'] ?? 'has no manifest');
            [$path, $message] = preg_match('~^(/[^\s:]*): (.+)$~s', $message, $m) ? [$m[1], $m[2]] : ['/', $message];

            return [null, [$this->problem($entry['path'], $dirId, $path, $message)]];
        }

        try {
            $manifest = $this->validator->validate($entry['data'], $entry['files'], true);
        } catch (InvalidManifestException $e) {
            $id = is_string($entry['data']['id'] ?? null) ? $entry['data']['id'] : $dirId;

            return [null, array_map(fn (array $p) => $this->problem($entry['path'], $id, $p['path'], $p['message']), $e->problems)];
        }

        $problems = [];
        if ($dirId !== null && $dirId !== $manifest->id()) {
            $problems[] = $this->problem($entry['path'], $manifest->id(), '/id', __('api.runtime_sources.id_directory', ['id' => $manifest->id(), 'directory' => $dirId]));
        }
        if ($source->isOrganization() && $manifest->runtimeClass() !== null) {
            $problems[] = $this->problem($entry['path'], $manifest->id(), '/runtimeClass', __('api.runtimes.runtime_class_platform_only'));
        }

        return [$manifest, $problems];
    }

    /**
     * Whether $source may provide the runtime $id (docs/runtimes.md#runtime-ids): null when it
     * may, else why not.
     */
    public function claim(RuntimeSource $source, string $id, ?Runtime $row): ?string
    {
        if ($source->is_default) {
            // RunR's catalog keeps the built-in rows (also those from before sources existed)
            return $row === null || $row->isBuiltin() ? null : $this->clashMessage($source, $id, $row);
        }

        if ($this->registry->isReservedId($id)) {
            return __('api.runtime_sources.clash', ['id' => $id, 'owner' => __('api.runtime_sources.owner_builtin')]);
        }
        if ($row === null || ($row->source_id !== null && $row->source_id === $source->id)) {
            return null;
        }
        if ($row->isOrphaned() && $this->sameOwner($row, $source)) {
            return null;    // adopted: its projects follow
        }

        return $this->clashMessage($source, $id, $row);
    }

    private function sameOwner(Runtime $row, RuntimeSource $source): bool
    {
        return $row->source === $source->runtimeSource()
            && ($source->isOrganization() ? $row->tenant_id === $source->tenant_id : true);
    }

    /** Who holds the id, as the source's owner may read it (another organization stays unnamed). */
    private function clashMessage(RuntimeSource $source, string $id, Runtime $row): string
    {
        $holder = $row->source_id ? RuntimeSource::query()->find($row->source_id) : null;
        $otherOrganization = $row->tenant_id !== null && $row->tenant_id !== ($source->isOrganization() ? $source->tenant_id : null);

        if ($source->isOrganization() && $otherOrganization) {
            return __('api.runtime_sources.clash_other', ['id' => $id]);
        }

        $owner = match (true) {
            $row->isBuiltin() => __('api.runtime_sources.owner_builtin'),
            $holder !== null => __('api.runtime_sources.owner_source', ['name' => $holder->name]),
            $row->source === Runtime::SOURCE_CUSTOM => __('api.runtime_sources.owner_custom'),
            default => __('api.runtime_sources.owner_platform'),
        };
        if (! $source->isOrganization() && $row->tenant_id !== null) {
            $owner .= " ({$row->tenant_id})";
        }

        return __('api.runtime_sources.clash', ['id' => $id, 'owner' => $owner]);
    }

    /** Create or update the runtime of an accepted entry, its versions and builds. */
    private function store(RuntimeSource $source, RuntimeManifest $manifest, array $entry): void
    {
        $id = $manifest->id();
        $files = $entry['files'] ?: null;
        $row = Runtime::query()->find($id);
        $wasOrphaned = $row?->isOrphaned() ?? false;
        $changed = $row === null || $row->digest !== $manifest->digest() || $row->source_id !== $source->id
            || ($row->files ?? null) != $files || $wasOrphaned;

        $row = DB::transaction(function () use ($source, $manifest, $entry, $files, $row, $id) {
            $row ??= new Runtime(['id' => $id, 'is_enabled' => true]);
            $row->forceFill([
                'id' => $id,
                'name' => $manifest->name(),
                'source' => $source->runtimeSource(),
                'tenant_id' => $source->isOrganization() ? $source->tenant_id : null,
                'created_by' => $row->created_by ?? $source->created_by,
                'manifest' => $manifest->toArray(),
                'manifest_source' => Yaml::dump($entry['data'], 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),
                'files' => $files,
                'digest' => $manifest->digest(),
                'source_id' => $source->id,
                'source_path' => mb_substr($entry['path'], 0, 255),
                'orphaned_at' => null,
            ]);
            $row->save();

            return $row;
        });
        $this->registry->forget($id);

        if (! $changed && ExecutorImage::query()->where('runtime', $id)->exists()) {
            // the versions follow executor:sync (discovery); the lifecycle dates of the row stay current
            $this->lifecycle->apply($row, $manifest);

            return;
        }

        if ($manifest->versionDiscovery() !== null) {
            $this->discovery->sync($manifest, $this->registryClientFor($row));
        } else {
            $this->registry->syncVersions($row, $manifest);
        }

        if (RuntimeImageBuildService::sourceVersions($manifest) !== []) {
            $this->builds->ensureAll($row, $manifest);
        }

        $this->noticeDeprecations($manifest, $wasOrphaned);
    }

    /** A runtime or version that is deprecated now hears about it once (RuntimePatchNotifier). */
    private function noticeDeprecations(RuntimeManifest $manifest, bool $wasOrphaned): void
    {
        try {
            if (RuntimeLifecycle::reached($manifest->deprecated() === true ? now()->toDateString() : (is_string($manifest->deprecated()) ? $manifest->deprecated() : null))) {
                $this->notifier->lifecycleNotice($manifest->id(), null, 'deprecated', [
                    'date' => $manifest->sunset(), 'replacement' => $manifest->replacement(), 'notice' => $manifest->notice(),
                ]);
            }
            foreach ($manifest->versions() as $version) {
                $deprecated = $version['deprecated'] ?? null;
                if ($deprecated === true || RuntimeLifecycle::reached(is_string($deprecated) ? $deprecated : null)) {
                    $this->notifier->lifecycleNotice($manifest->id(), $version['version'], 'deprecated', [
                        'date' => $version['sunset'] ?? null, 'notice' => $manifest->notice(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Runtime deprecation notice not sent', ['runtime' => $manifest->id(), 'error' => $e->getMessage()]);
        }
    }

    /**
     * Runtimes of the source that are no longer in it: orphaned (once) — hidden from new projects,
     * a sunset at the end of the grace period, their projects' owners told. The id stays taken.
     *
     * @param  array<string, true>  $present
     */
    private function orphanMissing(RuntimeSource $source, array $present): void
    {
        $rows = Runtime::query()
            ->where(fn ($q) => $q->where('source_id', $source->id)
                ->when($source->is_default, fn ($d) => $d->orWhere(fn ($b) => $b->where('source', Runtime::SOURCE_BUILTIN)->whereNull('source_id'))))
            ->whereNull('orphaned_at')
            ->get();

        foreach ($rows as $row) {
            if (isset($present[$row->id])) {
                continue;
            }
            // A built-in from before sources whose manifest only the bundle has: keep it on the
            // bundle's manifest for its projects
            if ($source->is_default && $row->manifest === null && ($bundled = app(BuiltinCatalog::class)->bundled()[$row->id] ?? null)) {
                $row->manifest = $bundled->toArray();
            }
            $row->forceFill(['orphaned_at' => now(), 'source_id' => $source->id])->save();
            $this->registry->forget($row->id);

            $manifest = $this->registry->manifest($row->id) ?? ($row->manifest ? new RuntimeManifest($row->manifest) : null);
            if ($manifest) {
                $this->lifecycle->apply($row, $manifest);
            }
            Log::notice('Runtime left its source: orphaned', ['source' => $source->id, 'runtime' => $row->id]);

            try {
                $this->notifier->lifecycleNotice($row->id, null, 'orphaned', [
                    'date' => RuntimeLifecycle::runtime($row, $manifest)['sunset_at'],
                    'source' => $source->name,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Runtime orphan notice not sent', ['runtime' => $row->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * The source's own patch feed (patch_feed), for its runtimes.
     *
     * @param  list<string>|null  $owned
     * @return list<array{file: string|null, runtime: string|null, path: string, message: string}>
     */
    private function sourcePatchFeed(RuntimeSource $source, bool $force, ?array $owned = null): array
    {
        if ($source->is_default || trim((string) $source->patch_feed) === '') {
            return [];
        }
        $owned ??= Runtime::query()->where('source_id', $source->id)->whereNull('orphaned_at')->pluck('id')->map(fn ($id) => (string) $id)->all();

        return array_map(
            fn (string $message) => ['file' => null, 'runtime' => null, 'path' => '/patch_feed', 'message' => $message],
            $this->patchFeeds->syncSource($source, $owned, $force),
        );
    }

    /** Replace the patch feed's problems of an earlier read by this check's. */
    private function mergeFeedProblems(array $problems, array $feedProblems): array
    {
        $kept = array_values(array_filter($problems, fn ($p) => ($p['path'] ?? null) !== '/patch_feed'));

        return array_slice(array_merge($kept, $feedProblems), 0, 200);
    }

    /**
     * A registry client for the images of a runtime's source: its registry credentials for
     * registry_host (version discovery of private images); anonymous otherwise.
     */
    public function registryClientFor(Runtime $runtime): ?OciRegistryClient
    {
        $source = $runtime->source_id ? RuntimeSource::query()->find($runtime->source_id) : null;
        if (! $source || ! $source->hasRegistryCredentials()) {
            return null;
        }

        $host = $source->registry_host ?: ($source->kind === RuntimeSource::KIND_OCI ? ImageRef::parse($source->reference())->registry : null);
        if (! $host) {
            return null;
        }

        return OciRegistryClient::direct(max(1, (int) config('runtime_sources.timeout', 20)))
            ->withCredentials($host, (string) $source->credential('registry', 'username'), (string) $source->credential('registry', 'password'));
    }

    /** @return array{file: string|null, runtime: string|null, path: string, message: string} */
    private function problem(?string $file, ?string $runtime, string $path, string $message): array
    {
        return ['file' => $file, 'runtime' => $runtime, 'path' => $path === '' ? '/' : $path, 'message' => mb_substr($message, 0, 1000)];
    }

    /** "runtimes/q/runtime.yaml" → "q" (the directory of a manifest), null for another shape. */
    public static function directoryId(string $path): ?string
    {
        return preg_match('~(?:^|/)([a-z][a-z0-9-]{0,31})/runtime\.ya?ml$~', $path, $m) ? $m[1] : null;
    }
}
