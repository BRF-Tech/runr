<?php

namespace App\Console\Commands\Concerns;

use App\Jobs\EnforceRuntimePatchesJob;
use App\Models\Notification;
use App\Models\Project;
use App\Models\RuntimePatch;
use App\Models\RuntimePatchFeed;
use App\Models\RuntimePatchMigration;
use App\Services\Runtimes\Patches\PatchFeedSync;
use App\Services\Runtimes\Patches\RuntimePatches;
use App\Services\Runtimes\Patches\RuntimePatchMigrations;
use App\Services\Runtimes\Patches\RuntimePatchNotifier;
use App\Services\SupplyChain\CosignVerifier;
use App\Support\Images\OciRegistryClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * E2E of runtime patches (test:e2e --suite=runtime-patches, runtime/lib/test-runtime-patches.sh)
 * on one Python HTTP function in the e2e region. The feed is signed here with a throwaway cosign
 * key (ECDSA P-256) and answered for ghcr.io/brf-tech/runr/runtime-patches only (Http::fake of
 * this process; every other request goes out), read by PatchFeedSync in this process — the
 * signature check, the schema and what follows are the real ones. The patches are real digests of
 * ghcr.io/brf-tech/runr/exec-python:3.14 (the image index and its linux/amd64 manifest: two
 * references of the same image), so the region pulls them from ghcr.io by digest; the zero-day
 * patch names a digest that does not exist, so its revision never becomes ready.
 *
 *  - rp-real-feed  first, with the configuration an install has: the published feed
 *                  ghcr.io/brf-tech/runr/runtime-patches:feed is read anonymously and its cosign
 *                  signature verifies with the default public key (status ok)
 *  - rp-feed       a signed feed with patch A (security) is read; a feed signed with another key
 *                  is refused and the patches stay
 *  - rp-pinned     the function deploys on A by digest (the Knative Service's image is
 *                  exec-python@<A>), the project reports up_to_date
 *  - rp-rollback   a zero-day patch Z past its deadline: the project is overdue; the deadline job
 *                  redeploys onto Z, the revision does not become ready (5 min readiness timeout),
 *                  RunR rolls back to A, pins the project there and notifies (critical); the
 *                  function answers 200 again
 *  - rp-apply      a patch B (the platform manifest of the same image) is applied: the new
 *                  deployment runs B and the function answers
 *  - rp-pin        pinning to A redeploys onto A; unpinning keeps it running
 *
 * Needs the region to reach ghcr.io (the images by digest are pulled, not loaded into Kind).
 */
trait E2eTestRuntimePatchesTrait
{
    private const RP_PYTHON = <<<'PY'
def handler(event, context):
    return {"statusCode": 200, "body": {"ok": True, "suite": "runtime-patches"}}
PY;

    private const RP_IMAGE = 'ghcr.io/brf-tech/runr/exec-python';

    private const RP_FEED_PATH = '/v2/brf-tech/runr/runtime-patches/';

    /** @var array<string, array{0: int, 1: string, 2: array<string, string>}> path → fake registry answer */
    private array $rpRegistry = [];

    private string $rpPrivateKey = '';

    private function runRuntimePatchesSuite(string $regionId): array
    {
        $this->info('');
        $this->info('── Runtime patches (signed feed, digests, deadline migration and rollback, apply, pin) ──');

        // Patch A (the image index) and B (its linux/amd64 manifest) of exec-python:3.14
        try {
            [$indexDigest, $platformDigest] = $this->rpPythonDigests();
        } catch (\Throwable $e) {
            return [$this->rpCheck('rp-feed', fn () => 'exec-python:3.14 digests not readable from ghcr.io: '.$e->getMessage())];
        }

        // The published feed with the default key, before this process answers for the feed
        $real = $this->rpCheck('rp-real-feed', fn () => $this->rpRealFeed());

        $this->rpFakeRegistry();
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $this->rpPrivateKey);
        config([
            'runtime_patches.public_key' => openssl_pkey_get_details($key)['key'],
            'runtime_patches.feed' => 'ghcr.io/brf-tech/runr/runtime-patches:feed',
            'runtime_patches.via_region' => '',
        ]);

        $patchA = $this->rpPatch('3.14.90-r1', $indexDigest, 'security', now()->subDays(3));
        $tests = [$real, $this->rpCheck('rp-feed', fn () => $this->rpFeed([$patchA]))];
        if ($tests[1]['steps']['verify'] !== true) {
            return $tests;
        }

        $function = $this->makeTestEntry('rp-function', [
            'service_type' => 'xaas',
            'runtime' => 'python',
            'runtime_version' => '3.14',
            'trigger_type' => 'http',
            'code' => self::RP_PYTHON,
        ], $regionId);
        $batch = [$function];
        $this->runTestsSequentially($batch);
        $function = $batch[0];
        $function['steps']['verify'] = $function['steps']['ready'] === true ? true : 'skipped';
        $tests[] = $function;
        if ($function['steps']['ready'] !== true) {
            return $tests;
        }

        $tests[] = $this->rpCheck('rp-pinned', fn () => $this->rpPinned($function, $indexDigest));
        $tests[] = $this->rpCheck('rp-rollback', fn () => $this->rpRollback($function, $patchA, $indexDigest));
        $tests[] = $this->rpCheck('rp-apply', fn () => $this->rpApply($function, $patchA, $platformDigest));
        $tests[] = $this->rpCheck('rp-pin', fn () => $this->rpPin($function, $indexDigest));

        return $tests;
    }

    /**
     * The feed BRF-Tech/runr-runtimes publishes, read as an install reads it (config defaults: the
     * feed's reference and the default public key): its signature verifies. What it stored goes
     * again, so the suite's own feed starts from nothing.
     */
    private function rpRealFeed(): bool|string
    {
        $feed = app(PatchFeedSync::class)->sync(force: true);
        $summary = "status {$feed->status}".($feed->error ? ": {$feed->error}" : '').', digest '.($feed->manifest_digest ?? '-')
            .', '.RuntimePatch::query()->where('in_feed', true)->count().' patch(es)';
        $this->line('    '.config('runtime_patches.feed').": {$summary}");
        $ok = $feed->status === RuntimePatchFeed::OK && $feed->signature_verified;

        RuntimePatch::query()->delete();
        app(RuntimePatches::class)->flush();

        return $ok ? true : "the published feed does not verify with the default key: {$summary}";
    }

    /** A signed feed with $patches is taken; one signed with another key is refused. */
    private function rpFeed(array $patches): bool|string
    {
        $this->rpPublish($patches);
        $feed = app(PatchFeedSync::class)->sync(force: true);
        if ($feed->status !== RuntimePatchFeed::OK) {
            return "feed status {$feed->status}: {$feed->error}";
        }

        $other = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($other, $otherPem);
        $good = $this->rpPrivateKey;
        $this->rpPrivateKey = $otherPem;
        $this->rpPublish(array_merge($patches, [$this->rpPatch('3.14.99-r1', 'sha256:'.str_repeat('9', 64), 'routine', now())]));
        $refused = app(PatchFeedSync::class)->sync(force: true);
        $this->rpPrivateKey = $good;

        if ($refused->status !== RuntimePatchFeed::INVALID_SIGNATURE) {
            return "a feed signed with another key: status {$refused->status} (want invalid_signature)";
        }
        if (app(RuntimePatches::class)->find('python-3.14-3.14.99-r1') !== null) {
            return 'the refused feed\'s patch was stored';
        }

        // Back to the good feed
        $this->rpPublish($patches);
        $feed = app(PatchFeedSync::class)->sync(force: true);

        return $feed->status === RuntimePatchFeed::OK ? true : "feed status {$feed->status} after the refused one";
    }

    private function rpPinned(array $function, string $digest): bool|string
    {
        $image = $this->rpServiceImage($function);
        if ($image !== self::RP_IMAGE.'@'.$digest) {
            return "Knative Service image {$image} (want exec-python@{$digest})";
        }

        $state = $this->api()->get("/api/projects/{$function['projectId']}")->json('data.runtime_patch.state');

        return $state === 'up_to_date' ? true : "patch state {$state} (want up_to_date)";
    }

    /** A zero-day patch with a digest that does not exist, past its deadline: migrated, failed, rolled back. */
    private function rpRollback(array $function, array $patchA, string $digestA): bool|string
    {
        $zeroDay = $this->rpPatch('3.14.91-r1', 'sha256:'.str_repeat('0', 63).'1', 'zero-day', now()->subDays(2), now()->subMinute());
        $this->rpPublish([$patchA, $zeroDay]);
        $feed = app(PatchFeedSync::class)->sync(force: true);
        if ($feed->status !== RuntimePatchFeed::OK) {
            return "feed status {$feed->status}: {$feed->error}";
        }

        $state = $this->api()->get("/api/projects/{$function['projectId']}")->json('data.runtime_patch');
        if (($state['state'] ?? null) !== 'overdue' || ($state['level'] ?? null) !== 'zero-day') {
            return 'patch state '.json_encode($state).' (want overdue / zero-day)';
        }

        $this->rpEnforce();
        $migration = RuntimePatchMigration::query()->where('project_id', $function['projectId'])->where('trigger', RuntimePatchMigration::AUTO)->latest()->first();
        if (! $migration || $migration->patch_id !== $zeroDay['id']) {
            return 'no automatic migration onto the zero-day patch';
        }

        // The revision cannot pull its image: readiness gives up after 300 s, the deadline job watches
        $deadline = time() + 480;
        while (time() < $deadline) {
            $this->rpEnforce();
            $migration->refresh();
            if ($migration->status !== RuntimePatchMigration::STARTED) {
                break;
            }
            sleep(10);
        }
        if ($migration->status !== RuntimePatchMigration::ROLLED_BACK) {
            return "migration {$migration->status}: ".mb_substr((string) $migration->message, 0, 200).' (want rolled_back)';
        }

        $ready = $function;
        $ready['steps']['ready'] = null;
        $this->waitSingleReady($ready);
        if ($ready['steps']['ready'] !== true) {
            return 'rollback deployment: '.$ready['steps']['ready'];
        }

        $image = $this->rpServiceImage($function);
        if ($image !== self::RP_IMAGE.'@'.$digestA) {
            return "after the rollback the Knative Service runs {$image} (want exec-python@{$digestA})";
        }
        $project = Project::query()->find($function['projectId']);
        if ($project?->runtime_patch_pin !== $patchA['id']) {
            return 'after the rollback the project is pinned to '.json_encode($project?->runtime_patch_pin)." (want {$patchA['id']})";
        }
        if (! Notification::query()->where('type', RuntimePatchNotifier::TYPE)->where('level', 'critical')->where('data->event', 'migration_failed')->exists()) {
            return 'no critical notification of the failed migration';
        }

        $served = $this->admWaitHttp($function['slug'], ['200'], 180, 'runtime-patches');

        return $served === true ? true : 'after the rollback: '.$served;
    }

    /** Patch B (newer, a real digest): apply runs it. */
    private function rpApply(array $function, array $patchA, string $digestB): bool|string
    {
        // The zero-day patch leaves the feed; B is the newest
        $patchB = $this->rpPatch('3.14.92-r1', $digestB, 'security', now()->subHour());
        $this->rpPublish([$patchA, $patchB]);
        if (($feed = app(PatchFeedSync::class)->sync(force: true))->status !== RuntimePatchFeed::OK) {
            return "feed status {$feed->status}: {$feed->error}";
        }

        $apply = $this->api()->post("/api/projects/{$function['projectId']}/runtime-patches/apply");
        if ($apply->status() !== 202 || $apply->json('data.patch_id') !== $patchB['id']) {
            return 'apply answered '.$apply->status().': '.mb_substr($apply->body(), 0, 300);
        }

        $ready = $function;
        $ready['steps']['ready'] = null;
        $this->waitSingleReady($ready);
        if ($ready['steps']['ready'] !== true) {
            return 'deployment onto B: '.$ready['steps']['ready'];
        }

        $image = $this->rpServiceImage($function);
        if ($image !== self::RP_IMAGE.'@'.$digestB) {
            return "after apply the Knative Service runs {$image} (want exec-python@{$digestB})";
        }
        $state = $this->api()->get("/api/projects/{$function['projectId']}")->json('data.runtime_patch');
        if (($state['state'] ?? null) !== 'up_to_date' || ($state['pin'] ?? null) !== null) {
            return 'patch state after apply '.json_encode($state).' (want up_to_date, no pin)';
        }

        $served = $this->admWaitHttp($function['slug'], ['200'], 180, 'runtime-patches');

        return $served === true ? true : 'after apply: '.$served;
    }

    /** Pin to A: redeployed onto A; unpin: it keeps running A. */
    private function rpPin(array $function, string $digestA): bool|string
    {
        $pin = $this->api()->put("/api/projects/{$function['projectId']}/runtime-patches/pin", ['patch_id' => 'python-3.14-3.14.90-r1']);
        if ($pin->status() !== 202 || $pin->json('data.state') !== 'pinned') {
            return 'pin answered '.$pin->status().': '.mb_substr($pin->body(), 0, 300);
        }

        $ready = $function;
        $ready['steps']['ready'] = null;
        $this->waitSingleReady($ready);
        if ($ready['steps']['ready'] !== true) {
            return 'deployment onto the pin: '.$ready['steps']['ready'];
        }
        $image = $this->rpServiceImage($function);
        if ($image !== self::RP_IMAGE.'@'.$digestA) {
            return "pinned, the Knative Service runs {$image} (want exec-python@{$digestA})";
        }

        $unpin = $this->api()->delete("/api/projects/{$function['projectId']}/runtime-patches/pin");
        if (! $unpin->successful() || $unpin->json('data.state') !== 'patch_available') {
            return 'unpin answered '.$unpin->status().': '.mb_substr($unpin->body(), 0, 300);
        }

        $served = $this->admWaitHttp($function['slug'], ['200'], 180, 'runtime-patches');

        return $served === true ? true : 'pinned: '.$served;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** The deadline job, in this process (it runs every five minutes in the scheduler too). */
    private function rpEnforce(): void
    {
        app(RuntimePatches::class)->flush();
        (new EnforceRuntimePatchesJob)->handle(app(RuntimePatchMigrations::class), app(RuntimePatchNotifier::class));
    }

    /** @return array{0: string, 1: string} the digest of exec-python:3.14 and of its linux/amd64 manifest */
    private function rpPythonDigests(): array
    {
        $registry = OciRegistryClient::direct(30);
        $manifest = $registry->manifest(self::RP_IMAGE, '3.14');
        if ($manifest === null) {
            throw new \RuntimeException('exec-python:3.14 not found');
        }

        $platform = collect((array) (json_decode($manifest['body'], true)['manifests'] ?? []))
            ->first(fn ($m) => ($m['platform']['os'] ?? null) === 'linux' && ($m['platform']['architecture'] ?? null) === 'amd64');
        if (! $platform) {
            throw new \RuntimeException('exec-python:3.14 is not a multi-platform image with linux/amd64');
        }

        return [$manifest['digest'], (string) $platform['digest']];
    }

    private function rpPatch(string $tag, string $digest, string $level, $published, $deadline = null): array
    {
        return [
            'id' => "python-3.14-{$tag}",
            'runtime' => 'python',
            'version' => '3.14',
            'tag' => $tag,
            'image' => self::RP_IMAGE,
            'digest' => $digest,
            'level' => $level,
            'reason' => ['en' => "e2e patch {$tag}", 'tr' => "e2e yaması {$tag}"],
            'cves' => ['CVE-2026-0000'],
            'url' => 'https://example.test/e2e',
            'upstream' => 'Python 3.14',
            'published_at' => $published->toIso8601String(),
            'deadline_at' => $deadline?->toIso8601String(),
        ];
    }

    /** The feed (and its cosign signature with the current key) in the fake registry. */
    private function rpPublish(array $patches): void
    {
        $layer = json_encode(['schema' => 1, 'generated_at' => now()->toIso8601String(), 'patches' => $patches, 'platform' => []]);
        $layerDigest = 'sha256:'.hash('sha256', $layer);
        $manifest = json_encode([
            'schemaVersion' => 2,
            'mediaType' => 'application/vnd.oci.image.manifest.v1+json',
            'config' => ['mediaType' => 'application/vnd.oci.empty.v1+json', 'digest' => 'sha256:'.hash('sha256', '{}'), 'size' => 2],
            'layers' => [['mediaType' => PatchFeedSync::MEDIA_TYPE, 'digest' => $layerDigest, 'size' => strlen($layer)]],
        ]);
        $digest = 'sha256:'.hash('sha256', $manifest);

        $payload = json_encode(['critical' => [
            'identity' => ['docker-reference' => 'ghcr.io/brf-tech/runr/runtime-patches'],
            'image' => ['docker-manifest-digest' => $digest],
            'type' => CosignVerifier::SIGNATURE_TYPE,
        ], 'optional' => null]);
        openssl_sign($payload, $signature, openssl_pkey_get_private($this->rpPrivateKey), OPENSSL_ALGO_SHA256);
        $payloadDigest = 'sha256:'.hash('sha256', $payload);
        $sig = json_encode([
            'schemaVersion' => 2,
            'mediaType' => 'application/vnd.oci.image.manifest.v1+json',
            'config' => ['mediaType' => 'application/vnd.oci.image.config.v1+json', 'digest' => 'sha256:'.hash('sha256', 'c'), 'size' => 1],
            'layers' => [['mediaType' => CosignVerifier::PAYLOAD_TYPE, 'digest' => $payloadDigest, 'size' => strlen($payload),
                'annotations' => [CosignVerifier::SIGNATURE_ANNOTATION => base64_encode($signature)]]],
        ]);

        $this->rpRegistry = [
            self::RP_FEED_PATH.'manifests/feed' => [200, $manifest, ['Docker-Content-Digest' => $digest]],
            self::RP_FEED_PATH."manifests/{$digest}" => [200, $manifest, ['Docker-Content-Digest' => $digest]],
            self::RP_FEED_PATH."blobs/{$layerDigest}" => [200, $layer, []],
            self::RP_FEED_PATH.'manifests/'.CosignVerifier::signatureTag($digest) => [200, $sig, []],
            self::RP_FEED_PATH."blobs/{$payloadDigest}" => [200, $payload, []],
        ];
    }

    /** ghcr.io/brf-tech/runr/runtime-patches answered from $rpRegistry; every other request goes out. */
    private function rpFakeRegistry(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (! str_starts_with($url, 'https://ghcr.io'.self::RP_FEED_PATH)) {
                return null;
            }
            [$status, $body, $headers] = $this->rpRegistry[(string) parse_url($url, PHP_URL_PATH)] ?? [404, '{"errors":[{"code":"MANIFEST_UNKNOWN"}]}', []];

            return Http::response($request->method() === 'HEAD' ? '' : $body, $status, $headers);
        });
    }

    /** The image of the function's Knative Service container. */
    private function rpServiceImage(array $function): string
    {
        $svc = $this->e2eK8s->getResourceStatus('serving.knative.dev/v1', 'Service', (string) $function['slug'], (string) $this->e2eNamespace);

        return (string) ($svc['spec']['template']['spec']['containers'][0]['image'] ?? '?');
    }

    private function rpCheck(string $name, callable $check): array
    {
        return $this->admCheck($name, $check);
    }
}
