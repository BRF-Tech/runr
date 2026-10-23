<?php

namespace App\Services\Runtimes\Patches;

use App\Jobs\AnnounceRuntimePatchesJob;
use App\Models\ExecutorImage;
use App\Models\KnativeRegion;
use App\Models\PlatformAdvisory;
use App\Models\RuntimePatch;
use App\Models\RuntimePatchFeed;
use App\Services\CacheVersionService;
use App\Services\Observability\TenantScope;
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
    private function read(OciRegistryClient $registry, string $repository, string $digest): array
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
        $problem = (new CosignVerifier($registry))->verify($repository, $digest, (string) config('runtime_patches.public_key'));
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
     * Store a valid feed.
     *
     * @return list<string> ids of the patches seen for the first time
     */
    public function apply(array $data): array
    {
        $created = [];

        DB::connection((new RuntimePatch)->getConnectionName())->transaction(function () use ($data, &$created) {
            $listed = [];
            foreach ($data['patches'] as $entry) {
                $patch = RuntimePatch::query()->find($entry['id']) ?? new RuntimePatch(['id' => $entry['id']]);
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
                $patch->save();

                if ($isNew) {
                    $created[] = $patch->id;
                }
                $listed[] = $patch->id;
            }

            RuntimePatch::query()->where('in_feed', true)->whereNotIn('id', $listed ?: [''])
                ->update(['in_feed' => false, 'removed_at' => now(), 'updated_at' => now()]);

            $this->syncExecutorDigests();

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

    /** executor_images.digest: the digest of each version's newest patch (what its floating tag points to). */
    private function syncExecutorDigests(): void
    {
        $newest = [];
        foreach (RuntimePatch::query()->where('in_feed', true)->orderBy('published_at')->get(['runtime', 'version', 'digest']) as $patch) {
            $newest[$patch->runtime."\0".$patch->version] = $patch->digest;
        }

        foreach (ExecutorImage::query()->get(['id', 'runtime', 'version', 'digest']) as $image) {
            $digest = $newest[$image->runtime."\0".$image->version] ?? null;
            if ($image->digest !== $digest) {
                $image->forceFill(['digest' => $digest])->save();
            }
        }
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
