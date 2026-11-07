<?php

namespace App\Services\Runtimes\Patches;

use App\Jobs\AnnounceRuntimePatchesJob;
use App\Models\ExecutorImage;
use App\Models\KnativeRegion;
use App\Models\PlatformAdvisory;
use App\Models\RuntimePatch;
use App\Models\RuntimePatchFeed;
use App\Models\RuntimeSource;
use App\Services\CacheVersionService;
use App\Services\Observability\TenantScope;
use App\Services\Runtimes\Sources\OciCatalogReader;
use App\Services\Runtimes\Sources\SourceCredentials;
use App\Services\SupplyChain\CosignVerifier;
use App\Services\SupplyChain\ImageRef;
use App\Support\Images\OciRegistryClient;
use App\Support\Images\RegistryMove;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reads the signed runtime patch feed (config/runtime_patches.php) into runtime_patches and
 * platform_advisories:
 *
 *  1. HEAD of the feed tag: the manifest digest. Unchanged since the last good read (or the digest
 *     refused last time) — nothing more to do: a check every five minutes costs one HEAD.
 *  2. The manifest by that digest, its one layer of type application/vnd.runr.patch-feed.v1+json.
 *  3. The cosign signature of the manifest digest, verified with the configured public key
 *     (CosignVerifier). A feed that does not verify, or that is no valid schema v1 feed
 *     (PatchFeedSchema), is refused: the last good feed stays, platform administrators are told
 *     once per refused digest.
 *  4. Patches upserted by id; patches the feed no longer lists leave it (in_feed false, kept for
 *     the records); executor_images.digest = the newest patch of each version; new patches are
 *     announced (AnnounceRuntimePatchesJob).
 *
 * Without a public key the feed is off (status disabled). Through `via_region` the feed is read
 * through that region's registry (OciRegistryClient::viaRegion()).
 *
 * Runtime sources (docs/runtimes.md#patches-of-a-source) carry patches of their own runtimes the
 * same way: a signed patch feed of their own (syncSource(), verified with the source's public key,
 * read with its registry credentials) or patch entries in their signed catalog (applySource()).
 * Their patches carry the source's id (runtime_patches.source_id) and are stored only for runtimes
 * of that source; RunR's feed only patches the built-in runtimes (RuntimePatches::of()).
 */
class PatchFeedSync
{
    public const MEDIA_TYPE = 'application/vnd.runr.patch-feed.v1+json';

    /** Largest feed accepted (bytes) */
    private const MAX_FEED_BYTES = 4 * 1024 * 1024;

    public function __construct(private readonly RuntimePatchNotifier $notifier) {}

    /** Check the feed; $force reads it again even when the digest did not change. */
    public function sync(bool $force = false): RuntimePatchFeed
    {
        $feed = RuntimePatchFeed::current();
        $feed->checked_at = now();

        if (! RuntimePatchFeed::enabled()) {
            $feed->forceFill(['status' => RuntimePatchFeed::DISABLED, 'error' => null])->save();

            return $feed;
        }

        $ref = ImageRef::parse((string) config('runtime_patches.feed'));
        $repository = $ref->name();

        try {
            $registry = $this->registry();
            $digest = $ref->digest ?? $registry->manifestDigest($repository, (string) ($ref->tag ?? 'feed'));
        } catch (\Throwable $e) {
            return $this->unreachable($feed, $e->getMessage());
        }

        if ($digest === null) {
            return $this->unreachable($feed, "The feed {$ref->canonical()} could not be read");
        }

        if (! $force && $feed->status === RuntimePatchFeed::OK && $digest === $feed->manifest_digest) {
            $feed->save();

            return $feed;
        }
        if (! $force && $digest === $feed->rejected_digest) {
            $feed->save();

            return $feed;
        }

        try {
            $data = $this->read($registry, $repository, $digest);
        } catch (PatchFeedException $e) {
            return $e->status === RuntimePatchFeed::UNREACHABLE
                ? $this->unreachable($feed, $e->getMessage())
                : $this->reject($feed, $digest, $e->status, $e->getMessage());
        }

        $created = $this->apply($data);

        $feed->forceFill([
            'status' => RuntimePatchFeed::OK,
            'error' => null,
            'manifest_digest' => $digest,
            'signature_verified' => true,
            'generated_at' => Carbon::parse((string) $data['generated_at']),
            'fetched_at' => now(),
            'rejected_digest' => null,
        ])->save();

        Log::info('Runtime patch feed read', ['digest' => $digest, 'patches' => count($data['patches']), 'new' => count($created)]);

        if ($created !== []) {
            AnnounceRuntimePatchesJob::dispatch($created);
        }
        $this->forgetCachedProjects();

        return $feed;
    }

    /**
     * Manifest, feed layer, signature and schema of the feed at $digest.
     *
     * @throws PatchFeedException
     */
    private function read(OciRegistryClient $registry, string $repository, string $digest, ?string $publicKey = null): array
    {
        $manifest = $registry->manifest($repository, $digest);
        if ($manifest === null) {
            throw new PatchFeedException(RuntimePatchFeed::UNREACHABLE, "The feed manifest {$digest} could not be read");
        }
        if (! hash_equals($digest, $manifest['digest'])) {
            throw new PatchFeedException(RuntimePatchFeed::INVALID_SCHEMA, 'The feed manifest does not match its digest');
        }

        $layer = collect((array) (json_decode($manifest['body'], true)['layers'] ?? []))
            ->first(fn ($l) => is_array($l) && ($l['mediaType'] ?? null) === self::MEDIA_TYPE);
        if (! $layer || ! is_string($layer['digest'] ?? null)) {
            throw new PatchFeedException(RuntimePatchFeed::INVALID_SCHEMA, 'The feed manifest has no '.self::MEDIA_TYPE.' layer');
        }
        if ((int) ($layer['size'] ?? 0) > self::MAX_FEED_BYTES) {
            throw new PatchFeedException(RuntimePatchFeed::INVALID_SCHEMA, 'The feed is larger than '.self::MAX_FEED_BYTES.' bytes');
        }

        // The signature before the content is trusted
        $problem = (new CosignVerifier($registry))->verify($repository, $digest, $publicKey ?? (string) config('runtime_patches.public_key'));
        if ($problem !== null) {
            throw new PatchFeedException(RuntimePatchFeed::INVALID_SIGNATURE, $problem);
        }

        $body = $registry->blob($repository, $layer['digest']);
        if ($body === null) {
            throw new PatchFeedException(RuntimePatchFeed::UNREACHABLE, 'The feed layer could not be read');
        }

        $data = json_decode($body, true);
        $errors = PatchFeedSchema::errors($data);
        if ($errors !== []) {
            throw new PatchFeedException(RuntimePatchFeed::INVALID_SCHEMA, implode('; ', array_slice($errors, 0, 5)));
        }

        $data['patches'] = array_values((array) $data['patches']);
        $data['platform'] = array_values((array) ($data['platform'] ?? []));

        return $data;
    }

    /**
     * Store a valid feed: RunR's ($source null), or a runtime source's — only the patches of
     * $runtimes (the source's own runtimes; the rest are returned as $ignored).
     *
     * @param  list<string>|null  $runtimes
     * @param  list<array{id: string, runtime: string}>  $ignored  entries left out (another source's runtime)
     * @return list<string> ids of the patches seen for the first time
     */
    public function apply(array $data, ?RuntimeSource $source = null, ?array $runtimes = null, array &$ignored = []): array
    {
        $created = [];
        $sourceId = $source?->id;

        DB::connection((new RuntimePatch)->getConnectionName())->transaction(function () use ($data, $sourceId, $runtimes, &$created, &$ignored) {
            $listed = [];
            foreach ($data['patches'] as $entry) {
                if ($runtimes !== null && ! in_array($entry['runtime'], $runtimes, true)) {
                    $ignored[] = ['id' => (string) $entry['id'], 'runtime' => (string) $entry['runtime']];

                    continue;
                }
                $id = self::patchId((string) $entry['id'], $sourceId);
                $patch = RuntimePatch::query()->find($id) ?? new RuntimePatch(['id' => $id]);
                $isNew = ! $patch->exists;
                $published = Carbon::parse($entry['published_at']);

                $patch->fill([
                    'runtime' => $entry['runtime'],
                    'version' => $entry['version'],
                    'tag' => $entry['tag'],
                    'image' => RegistryMove::ref((string) $entry['image']),
                    'digest' => $entry['digest'],
                    'level' => $entry['level'],
                    'reason' => array_filter(['en' => $entry['reason']['en'], 'tr' => $entry['reason']['tr'] ?? null], fn ($v) => is_string($v) && $v !== ''),
                    'cves' => array_values(array_unique(array_map('strval', (array) ($entry['cves'] ?? [])))),
                    'url' => $entry['url'] ?? null,
                    'upstream' => $entry['upstream'] ?? null,
                    'published_at' => $published,
                    'deadline_at' => $this->deadline($entry, $published),
                    'in_feed' => true,
                    'removed_at' => null,
                ]);
                $patch->forceFill(['source_id' => $sourceId])->save();

                if ($isNew) {
                    $created[] = $patch->id;
                }
                $listed[] = $patch->id;
            }

            // Only the same feed's patches leave it: RunR's feed never drops a source's and the other way round
            RuntimePatch::query()->where('in_feed', true)->whereNotIn('id', $listed ?: [''])
                ->when($sourceId === null, fn ($q) => $q->whereNull('source_id'), fn ($q) => $q->where('source_id', $sourceId))
                ->update(['in_feed' => false, 'removed_at' => now(), 'updated_at' => now()]);

            $this->syncExecutorDigests();

            if ($sourceId !== null) {
                return;     // platform advisories come from RunR's feed only
            }

            $versions = [];
            foreach ($data['platform'] as $entry) {
                PlatformAdvisory::query()->updateOrCreate(['version' => $entry['version']], [
                    'level' => $entry['level'],
                    'reason' => array_filter(['en' => $entry['reason']['en'], 'tr' => $entry['reason']['tr'] ?? null], fn ($v) => is_string($v) && $v !== ''),
                    'url' => $entry['url'] ?? null,
                    'published_at' => isset($entry['published_at']) ? Carbon::parse($entry['published_at']) : null,
                    'in_feed' => true,
                ]);
                $versions[] = $entry['version'];
            }
            PlatformAdvisory::query()->whereNotIn('version', $versions ?: [''])->update(['in_feed' => false]);
        });

        app(RuntimePatches::class)->flush();

        return $created;
    }

    /**
     * The deadline of a critical / zero-day patch: the feed's, else published_at plus the default
     * days of its level; none for the other levels.
     */
    private function deadline(array $entry, Carbon $published): ?Carbon
    {
        if (! in_array($entry['level'], RuntimePatch::DEADLINE_LEVELS, true)) {
            return null;
        }
        if (! empty($entry['deadline_at'])) {
            return Carbon::parse($entry['deadline_at']);
        }

        return $published->copy()->addDays((int) config("runtime_patches.default_deadline_days.{$entry['level']}", 7));
    }

    /**
     * executor_images.digest: the digest of each version's newest patch (what its floating tag
     * points to) — of the feed that patches the runtime (RuntimePatches::patchSourceOf()).
     */
    private function syncExecutorDigests(): void
    {
        $newest = [];
        foreach (RuntimePatch::query()->where('in_feed', true)->orderBy('published_at')->get(['runtime', 'version', 'digest', 'source_id']) as $patch) {
            $newest[(string) $patch->source_id."\0".$patch->runtime."\0".$patch->version] = $patch->digest;
        }

        $sources = [];
        foreach (ExecutorImage::query()->get(['id', 'runtime', 'version', 'digest']) as $image) {
            $source = $sources[$image->runtime] ??= RuntimePatches::patchSourceOf((string) $image->runtime);
            $digest = $source === false ? null : ($newest[$source."\0".$image->runtime."\0".$image->version] ?? null);
            if ($image->digest !== $digest) {
                $image->forceFill(['digest' => $digest])->save();
            }
        }
    }

    /**
     * The id a patch of $sourceId is stored under: the feed's id, with the source's after a "~"
     * (two sources may name their patches alike; RunR's patches keep their id).
     */
    public static function patchId(string $entryId, ?string $sourceId): string
    {
        if ($sourceId === null) {
            return $entryId;
        }
        if (strlen($entryId) > 100) {
            $entryId = substr($entryId, 0, 90).'-'.substr(sha1($entryId), 0, 9);
        }

        return $entryId.'~'.strtolower($sourceId);
    }

    /**
     * Patch entries a source's signed catalog carries (RuntimeSourceSync): validated as a schema v1
     * feed and stored for the source's own runtimes.
     *
     * @param  list<array>  $patches
     * @param  list<string>  $runtimes  the source's runtimes
     * @return list<string> what was wrong (the feed's errors, entries of runtimes the source does not have)
     */
    public function applySource(RuntimeSource $source, array $patches, array $runtimes): array
    {
        $data = ['schema' => PatchFeedSchema::VERSION, 'generated_at' => now()->toIso8601String(), 'patches' => $patches, 'platform' => []];
        $errors = PatchFeedSchema::errors($data);
        if ($errors !== []) {
            return [__('api.runtime_sources.patches_invalid', ['error' => implode('; ', array_slice($errors, 0, 5))])];
        }

        $ignored = [];
        $created = $this->apply($data, $source, $runtimes, $ignored);
        if ($created !== []) {
            AnnounceRuntimePatchesJob::dispatch($created);
        }
        $this->forgetCachedProjects();

        return array_map(fn (array $i) => __('api.runtime_sources.patch_foreign', ['patch' => $i['id'], 'runtime' => $i['runtime']]), $ignored);
    }

    /**
     * The signed patch feed of a runtime source (its patch_feed reference): read like RunR's — a
     * HEAD per check, the signature verified with the source's public key (required), the source's
     * registry credentials — and stored for the source's own runtimes. Its state is a
     * runtime_patch_feeds row of the source.
     *
     * @param  list<string>  $runtimes  the source's runtimes
     * @return list<string> what was wrong
     */
    public function syncSource(RuntimeSource $source, array $runtimes, bool $force = false): array
    {
        $feed = RuntimePatchFeed::query()->firstOrCreate(
            ['source_id' => $source->id],
            ['reference' => 'source/'.$source->id, 'status' => RuntimePatchFeed::DISABLED],
        );
        $feed->checked_at = now();
        $reference = trim((string) $source->patch_feed);
        $key = $source->verificationKey();
        if ($reference === '' || $key === null) {
            $feed->forceFill(['status' => RuntimePatchFeed::DISABLED, 'error' => $reference === '' ? null : 'A patch feed needs the source\'s public key'])->save();

            return $reference === '' ? [] : ['The patch feed is not read: the source has no public key to verify it with'];
        }

        $ref = ImageRef::parse($reference);
        $repository = $ref->name();
        try {
            $registry = app(OciCatalogReader::class)->client($source);
            $digest = $ref->digest ?? $registry->manifestDigest($repository, (string) ($ref->tag ?? 'feed'));
        } catch (\Throwable $e) {
            return $this->sourceFeedFailed($feed, $source, RuntimePatchFeed::UNREACHABLE, $e->getMessage());
        }
        if ($digest === null) {
            return $this->sourceFeedFailed($feed, $source, RuntimePatchFeed::UNREACHABLE, "The patch feed {$ref->canonical()} could not be read");
        }
        if (! $force && $feed->status === RuntimePatchFeed::OK && $digest === $feed->manifest_digest) {
            $feed->save();

            return [];
        }

        try {
            $data = $this->read($registry, $repository, $digest, $key);
        } catch (PatchFeedException $e) {
            return $this->sourceFeedFailed($feed, $source, $e->status, $e->getMessage());
        }

        $ignored = [];
        $created = $this->apply($data, $source, $runtimes, $ignored);
        $feed->forceFill([
            'status' => RuntimePatchFeed::OK,
            'error' => null,
            'manifest_digest' => $digest,
            'signature_verified' => true,
            'generated_at' => Carbon::parse((string) $data['generated_at']),
            'fetched_at' => now(),
            'rejected_digest' => null,
        ])->save();
        if ($created !== []) {
            AnnounceRuntimePatchesJob::dispatch($created);
        }
        $this->forgetCachedProjects();

        return array_map(fn (array $i) => __('api.runtime_sources.patch_foreign', ['patch' => $i['id'], 'runtime' => $i['runtime']]), $ignored);
    }

    /** @return list<string> */
    private function sourceFeedFailed(RuntimePatchFeed $feed, RuntimeSource $source, string $status, string $error): array
    {
        $error = SourceCredentials::maskFor($error, $source);
        $feed->forceFill(['status' => $status, 'error' => mb_substr($error, 0, 1000)])->save();
        Log::warning('Runtime source patch feed not read', ['source' => $source->id, 'status' => $status, 'error' => $error]);

        return ["The patch feed was not read ({$status}): {$error}"];
    }

    private function registry(): OciRegistryClient
    {
        $timeout = max(1, (int) config('runtime_patches.timeout', 15));
        $via = trim((string) config('runtime_patches.via_region'));
        if ($via === '') {
            return OciRegistryClient::direct($timeout);
        }

        $region = KnativeRegion::query()->whereKey($via)->first() ?? KnativeRegion::query()->where('name', $via)->first();
        if (! $region) {
            throw new \RuntimeException("Region {$via} (runtime_patches.via_region) not found");
        }

        return OciRegistryClient::viaRegion($region, $timeout);
    }

    private function unreachable(RuntimePatchFeed $feed, string $error): RuntimePatchFeed
    {
        // The last good feed stays in force; only the status says the check failed
        $feed->forceFill(['status' => RuntimePatchFeed::UNREACHABLE, 'error' => mb_substr($error, 0, 1000)])->save();
        Log::warning('Runtime patch feed not reachable', ['error' => $error]);

        return $feed;
    }

    /** A feed whose signature or schema is wrong: ignored, the last good one stays, platform admins told once. */
    private function reject(RuntimePatchFeed $feed, string $digest, string $status, string $error): RuntimePatchFeed
    {
        $feed->forceFill(['status' => $status, 'error' => mb_substr($error, 0, 1000), 'rejected_digest' => $digest])->save();
        Log::error('Runtime patch feed refused', ['digest' => $digest, 'status' => $status, 'error' => $error]);

        $this->notifier->feedRejected($digest, $status, $error);

        return $feed;
    }

    /** Projects show their patch state: cached project answers are read anew. */
    private function forgetCachedProjects(): void
    {
        TenantScope::each(function () {
            try {
                app(CacheVersionService::class)->bump('projects');
            } catch (\Throwable) {
            }
        });
    }
}
