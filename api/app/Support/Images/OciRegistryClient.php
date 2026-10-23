<?php

namespace App\Support\Images;

use App\Models\KnativeRegion;
use App\Services\KubernetesClient;
use App\Services\Manifests\ZotManifests;
use App\Services\SupplyChain\ImageRef;
use App\Services\ZotRegistryService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Anonymous reads of an OCI distribution registry (manifests and blobs of public repositories):
 * a registry that answers 401 with a Bearer challenge gets a pull token from its token service
 * first (bearerToken()). Through a region (viaRegion()) the same requests go to the region's
 * internal registry through the Kubernetes API server — its ghcr.io pull-through serves
 * ghcr.io/<repository> under the path ghcr.io/<repository> (ZotManifests::GHCR_NAMESPACE), for
 * installs whose API has no way out.
 *
 * Repositories are named with their registry ("ghcr.io/brf-tech/runr/runtime-patches").
 */
class OciRegistryClient
{
    public const MANIFEST_TYPES = [
        'application/vnd.oci.image.manifest.v1+json',
        'application/vnd.docker.distribution.manifest.v2+json',
        'application/vnd.oci.image.index.v1+json',
        'application/vnd.docker.distribution.manifest.list.v2+json',
    ];

    /** @var array<string, string|null> pull tokens per repository */
    private array $tokens = [];

    public function __construct(
        private readonly int $timeout = 15,
        private readonly ?KnativeRegion $region = null,
    ) {}

    public static function direct(int $timeout = 15): self
    {
        return new self($timeout);
    }

    public static function viaRegion(KnativeRegion $region, int $timeout = 15): self
    {
        return new self($timeout, $region);
    }

    /** The digest a tag (or digest) of a manifest names now (HEAD, Docker-Content-Digest); null when unknown. */
    public function manifestDigest(string $repository, string $reference): ?string
    {
        $response = $this->send('HEAD', $repository, "manifests/{$reference}", ['Accept' => implode(', ', self::MANIFEST_TYPES)]);
        if (! $response->successful()) {
            return null;
        }

        $digest = (string) $response->header('Docker-Content-Digest');

        return preg_match('/^sha256:[a-f0-9]{64}$/', $digest) ? $digest : null;
    }

    /**
     * A manifest by tag or digest: its bytes, the digest of those bytes and its media type; null
     * when the registry does not have it.
     *
     * @return array{body: string, digest: string, media_type: string}|null
     */
    public function manifest(string $repository, string $reference): ?array
    {
        $response = $this->send('GET', $repository, "manifests/{$reference}", ['Accept' => implode(', ', self::MANIFEST_TYPES)]);
        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();

        return [
            'body' => $body,
            'digest' => 'sha256:'.hash('sha256', $body),
            'media_type' => (string) (json_decode($body, true)['mediaType'] ?? $response->header('Content-Type')),
        ];
    }

    /** A blob's bytes, only when they match its digest; null otherwise. */
    public function blob(string $repository, string $digest): ?string
    {
        $response = $this->send('GET', $repository, "blobs/{$digest}", ['Accept' => '*/*']);
        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();

        return hash_equals($digest, 'sha256:'.hash('sha256', $body)) ? $body : null;
    }

    /**
     * An anonymous pull token from the token service a 401 names (WWW-Authenticate: Bearer
     * realm="…",service="…",scope="…"); null without a Bearer challenge or when the service
     * gives none.
     */
    public static function bearerToken(string $challenge, string $name, int $timeout = 10): ?string
    {
        if (! preg_match('/^Bearer\s+(.+)$/i', trim($challenge), $m)) {
            return null;
        }

        preg_match_all('/(\w+)="([^"]*)"/', $m[1], $pairs, PREG_SET_ORDER);
        $params = [];
        foreach ($pairs as $pair) {
            $params[strtolower($pair[1])] = $pair[2];
        }
        if (empty($params['realm'])) {
            return null;
        }

        $query = http_build_query(array_filter([
            'service' => $params['service'] ?? null,
            'scope' => $params['scope'] ?? "repository:{$name}:pull",
        ]));
        $response = Http::timeout($timeout)->get($params['realm'].(str_contains($params['realm'], '?') ? '&' : '?').$query);
        $token = $response->successful() ? ($response->json('token') ?? $response->json('access_token')) : null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /** One request to /v2/<name>/<path>, directly or through the region's registry. */
    private function send(string $method, string $repository, string $path, array $headers): Response
    {
        $ref = ImageRef::parse($repository);

        if ($this->region !== null) {
            return $this->sendViaRegion($method, $ref, $path, $headers);
        }

        $host = $ref->registry === 'docker.io' ? 'registry-1.docker.io' : $ref->registry;
        $url = "https://{$host}/v2/{$ref->repository}/{$path}";
        $key = "{$host}/{$ref->repository}";

        $response = $this->request($method, $url, $headers, $this->tokens[$key] ?? null);
        if ($response->status() === 401 && ! array_key_exists($key, $this->tokens)) {
            $this->tokens[$key] = self::bearerToken((string) $response->header('WWW-Authenticate'), $ref->repository, $this->timeout);
            if ($this->tokens[$key] !== null) {
                $response = $this->request($method, $url, $headers, $this->tokens[$key]);
            }
        }

        return $response;
    }

    private function request(string $method, string $url, array $headers, ?string $token): Response
    {
        return Http::timeout($this->timeout)
            ->withHeaders($headers + ($token === null ? [] : ['Authorization' => "Bearer {$token}"]))
            ->send($method, $url);
    }

    /**
     * Through the region's internal registry: only ghcr.io repositories, which its pull-through
     * fetches on demand (a region registry without the ghcr.io upstream answers 404).
     */
    private function sendViaRegion(string $method, ImageRef $ref, string $path, array $headers): Response
    {
        if ($ref->registry !== ZotManifests::GHCR_NAMESPACE) {
            throw new \RuntimeException("Only {$ref->registry} repositories under ghcr.io can be read through a region's registry");
        }
        if (! $this->region->kubeconfig || ! $this->region->has_registry) {
            throw new \RuntimeException("Region {$this->region->name} has no registry to read through");
        }

        $k8s = new KubernetesClient($this->region->kubeconfig);
        $namespace = $this->region->registry_namespace ?: ZotRegistryService::ZOT_DEFAULT_NS;

        return $k8s->serviceProxyRequest(
            $method, $namespace, ZotRegistryService::ZOT_RELEASE_NAME, 5000,
            'v2/'.ZotManifests::GHCR_NAMESPACE."/{$ref->repository}/{$path}", $headers,
        );
    }
}
