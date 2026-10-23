<?php

namespace Tests\Concerns;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\RuntimePatch;
use App\Models\Version;
use App\Services\Runtimes\Patches\PatchFeedSync;
use App\Services\Runtimes\Patches\RuntimePatches;
use App\Services\SupplyChain\CosignVerifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Runtime patch fixtures (docs/runtimes.md#runtime-patches): patches of python 3.14 (the version
 * RegistersRuntimes::seedBuiltinVersions() offers), live deployments, and a fake registry serving
 * a signed patch feed the way `oras push` + `cosign sign --key` leave it (manifest, feed layer,
 * sha256-<digest>.sig simple-signing signature).
 */
trait BuildsRuntimePatches
{
    /** PEM key pair of the feed signer */
    protected string $feedPrivateKey;

    protected string $feedPublicKey;

    /** @var array<string, array{status: int, body: string, headers: array<string, string>}> path → answer of the fake registry */
    protected array $registry = [];

    /** @var list<string> "METHOD path" of every registry request */
    protected array $registryRequests = [];

    /**
     * Answers before the fake registry (a token service, a 401 challenge): a response, or null to
     * let the registry answer. A second Http::fake() would never be asked — the first fake answers.
     */
    protected ?\Closure $registryGate = null;

    protected static function digestOf(string $seed): string
    {
        return 'sha256:'.hash('sha256', $seed);
    }

    /** A patch of python 3.14 (stored; the in-memory patch sets are forgotten). */
    protected function runtimePatch(array $attributes = []): RuntimePatch
    {
        static $n = 0;
        $n++;
        $tag = $attributes['tag'] ?? "3.14.{$n}-r1";

        $patch = RuntimePatch::query()->create(array_merge([
            'id' => "python-3.14-{$tag}",
            'runtime' => 'python',
            'version' => '3.14',
            'tag' => $tag,
            'image' => 'ghcr.io/brf-tech/runr/exec-python',
            'digest' => self::digestOf("python:{$tag}"),
            'level' => RuntimePatch::SECURITY,
            'reason' => ['en' => "Fixes CVE-2026-{$n}", 'tr' => "CVE-2026-{$n} düzeltmesi"],
            'cves' => ["CVE-2026-{$n}"],
            'url' => "https://example.test/advisories/{$n}",
            'upstream' => "Python 3.14.{$n}",
            // newer with every call, and newer than a deployment of a few days ago (a patch a test
            // creates without a date is the newest one so far)
            'published_at' => now()->subHours(36)->addMinutes($n),
            'in_feed' => true,
        ], $attributes));
        app(RuntimePatches::class)->flush();

        return $patch;
    }

    /** An active deployment of the project's version, created at $createdAt, running $patch. */
    protected function liveOn(Project $project, Version $version, ?RuntimePatch $patch = null, $createdAt = null, array $attributes = []): Deployment
    {
        $deployment = Deployment::query()->create(array_merge([
            'project_id' => $project->id,
            'version_id' => $version->id,
            'status' => 'active',
            'k8s_job_name' => 'run-'.$project->slug.'-v'.$version->number,
            'deployed_by' => 'dev',
            'runtime_digest' => $patch?->digest,
            'runtime_patch_id' => $patch?->id,
            'ready_at' => now(),
        ], $attributes));
        if ($createdAt !== null) {
            $deployment->forceFill(['created_at' => $createdAt])->save();
        }
        $project->forceFill(['runtime_patch_id' => $patch?->id, 'runtime_digest' => $patch?->digest])->save();
        app(RuntimePatches::class)->flush();

        return $deployment;
    }

    // ── Signed feed ─────────────────────────────────────────────────────────

    protected function makeFeedKeys(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $private);
        $this->feedPrivateKey = $private;
        $this->feedPublicKey = openssl_pkey_get_details($key)['key'];
        config(['runtime_patches.public_key' => $this->feedPublicKey, 'runtime_patches.feed' => 'ghcr.io/brf-tech/runr/runtime-patches:feed']);
    }

    /** A schema v1 feed entry of python 3.14. */
    protected function feedPatch(string $tag, string $level = 'security', array $extra = []): array
    {
        return array_merge([
            'id' => "python-3.14-{$tag}",
            'runtime' => 'python',
            'version' => '3.14',
            'tag' => $tag,
            'image' => 'ghcr.io/brf-tech/runr/exec-python',
            'digest' => self::digestOf("python:{$tag}"),
            'level' => $level,
            'reason' => ['en' => "Python {$tag} fixes things", 'tr' => "Python {$tag} bir şeyleri düzeltir"],
            'cves' => ['CVE-2026-1111'],
            'url' => 'https://example.test/advisory',
            'upstream' => 'Python 3.14.9',
            'published_at' => now()->subDay()->toIso8601String(),
            'deadline_at' => in_array($level, ['critical', 'zero-day'], true) ? now()->addDays(3)->toIso8601String() : null,
        ], $extra);
    }

    /**
     * Publish $feed in the fake registry under the "feed" tag, signed with $signingKey (default:
     * the configured key's private half). Returns the manifest digest.
     */
    protected function publishFeed(array $feed, ?string $signingKey = null, ?string $signedDigest = null): string
    {
        $repo = '/v2/brf-tech/runr/runtime-patches';
        $layer = json_encode($feed);
        $layerDigest = 'sha256:'.hash('sha256', $layer);
        $manifest = json_encode([
            'schemaVersion' => 2,
            'mediaType' => 'application/vnd.oci.image.manifest.v1+json',
            'artifactType' => 'application/vnd.runr.patch-feed.v1+json',
            'config' => ['mediaType' => 'application/vnd.oci.empty.v1+json', 'digest' => self::digestOf('{}'), 'size' => 2],
            'layers' => [['mediaType' => PatchFeedSync::MEDIA_TYPE, 'digest' => $layerDigest, 'size' => strlen($layer)]],
        ]);
        $manifestDigest = 'sha256:'.hash('sha256', $manifest);

        // cosign simple signing: the payload names the manifest digest, signed with the key
        $payload = json_encode([
            'critical' => [
                'identity' => ['docker-reference' => 'ghcr.io/brf-tech/runr/runtime-patches'],
                'image' => ['docker-manifest-digest' => $signedDigest ?? $manifestDigest],
                'type' => CosignVerifier::SIGNATURE_TYPE,
            ],
            'optional' => null,
        ]);
        openssl_sign($payload, $signature, openssl_pkey_get_private($signingKey ?? $this->feedPrivateKey), OPENSSL_ALGO_SHA256);
        $payloadDigest = 'sha256:'.hash('sha256', $payload);
        $sigManifest = json_encode([
            'schemaVersion' => 2,
            'mediaType' => 'application/vnd.oci.image.manifest.v1+json',
            'config' => ['mediaType' => 'application/vnd.oci.image.config.v1+json', 'digest' => self::digestOf('cfg'), 'size' => 3],
            'layers' => [[
                'mediaType' => CosignVerifier::PAYLOAD_TYPE,
                'digest' => $payloadDigest,
                'size' => strlen($payload),
                'annotations' => [CosignVerifier::SIGNATURE_ANNOTATION => base64_encode($signature)],
            ]],
        ]);

        $this->registry["{$repo}/manifests/feed"] = ['status' => 200, 'body' => $manifest, 'headers' => ['Docker-Content-Digest' => $manifestDigest, 'Content-Type' => 'application/vnd.oci.image.manifest.v1+json']];
        $this->registry["{$repo}/manifests/{$manifestDigest}"] = ['status' => 200, 'body' => $manifest, 'headers' => ['Docker-Content-Digest' => $manifestDigest]];
        $this->registry["{$repo}/blobs/{$layerDigest}"] = ['status' => 200, 'body' => $layer, 'headers' => []];
        $this->registry["{$repo}/manifests/".CosignVerifier::signatureTag($manifestDigest)] = ['status' => 200, 'body' => $sigManifest, 'headers' => []];
        $this->registry["{$repo}/blobs/{$payloadDigest}"] = ['status' => 200, 'body' => $payload, 'headers' => []];

        return $manifestDigest;
    }

    /** Http::fake of ghcr.io answering from $this->registry (404 for anything else). */
    protected function fakeRegistry(): void
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->registryRequests[] = $request->method().' '.$path;
            if ($this->registryGate && ($gated = ($this->registryGate)($request)) !== null) {
                return $gated;
            }
            $answer = $this->registry[$path] ?? null;
            if ($answer === null) {
                return Http::response(['errors' => [['code' => 'MANIFEST_UNKNOWN']]], 404);
            }

            return Http::response($request->method() === 'HEAD' ? '' : $answer['body'], $answer['status'], $answer['headers']);
        });
    }

    /** A schema v1 feed. */
    protected function feed(array $patches, array $platform = []): array
    {
        return ['schema' => 1, 'generated_at' => now()->toIso8601String(), 'patches' => $patches, 'platform' => $platform];
    }
}
