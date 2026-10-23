<?php

namespace App\Services\Secrets\OpenBao;

use App\Models\KnativeRegion;
use App\Services\KubernetesClient;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Talks to the region's OpenBao (docs/secret-stores.md, "OpenBao in the region") through the
 * region's API server (service proxy to https:openbao:8200 in runr-openbao): RunR needs no
 * network path into the cluster and keeps no OpenBao token. It logs in as the service account
 * runr-control with Kubernetes auth — a token of that account comes from the TokenRequest API,
 * the OpenBao token it gets lives ten minutes and only in this object.
 *
 * Values pass through memory only; errors carry the HTTP status and OpenBao's error text, never
 * a request body.
 */
class OpenBaoClient
{
    public const NAMESPACE = 'runr-openbao';

    public const SERVICE = 'openbao';

    public const PORT = 8200;

    /** The service account and Kubernetes auth role RunR logs in with. */
    public const CONTROL = 'runr-control';

    /** KV v2 mount of the project values: runr/data/projects/<project id>. */
    public const KV_MOUNT = 'runr';

    private ?string $token = null;

    private ?KubernetesClient $k8s = null;

    public function __construct(
        private readonly string $kubeconfig,
        private readonly ?string $region = null,
    ) {}

    public static function forRegion(KnativeRegion $region): self
    {
        return new self((string) $region->kubeconfig, $region->name);
    }

    // ── Bootstrap (root token, unseal key: used by OpenBaoInstallService, never kept) ──

    /**
     * sys/health that answers for every state: initialized, sealed, version. Null when OpenBao
     * does not answer (not running yet).
     *
     * @return array{initialized: bool, sealed: bool, version: string|null}|null
     */
    public function health(): ?array
    {
        try {
            $response = $this->send('GET', 'v1/sys/health?standbyok=true&uninitcode=200&sealedcode=200&perfstandbyok=true');
        } catch (\Throwable) {
            return null;
        }
        if (! $response->successful() || ! is_array($response->json())) {
            return null;
        }

        return [
            'initialized' => (bool) $response->json('initialized'),
            'sealed' => (bool) $response->json('sealed'),
            'version' => is_string($response->json('version')) ? $response->json('version') : null,
        ];
    }

    /**
     * Initialize with one key share: the unseal key and the root token, for the caller to put
     * where they belong (the region's Secret; revoked after the configuration).
     *
     * @return array{key: string, root: string}
     */
    public function init(): array
    {
        $response = $this->send('PUT', 'v1/sys/init', ['secret_shares' => 1, 'secret_threshold' => 1]);
        $this->ensure($response, 'initializing OpenBao');
        $key = $response->json('keys_base64.0') ?? $response->json('keys.0');
        $root = $response->json('root_token');
        if (! is_string($key) || ! is_string($root)) {
            throw new RuntimeException('OpenBao answered the initialization without a key or root token');
        }

        return ['key' => $key, 'root' => $root];
    }

    public function unseal(string $key): bool
    {
        $response = $this->send('PUT', 'v1/sys/unseal', ['key' => $key]);
        $this->ensure($response, 'unsealing OpenBao');

        return $response->json('sealed') === false;
    }

    /** Run requests with a given token (the root token during the first configuration). */
    public function withToken(string $token): self
    {
        $clone = clone $this;
        $clone->token = $token;

        return $clone;
    }

    // ── RunR's identity ─────────────────────────────────────────────────────

    /** Log in as runr-control (Kubernetes auth); the token is kept by this object only. */
    public function login(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $jwt = $this->kubernetes()->requestServiceAccountToken(self::NAMESPACE, self::CONTROL, (int) config('secrets.openbao.token_ttl', 600));
        $response = $this->send('POST', 'v1/auth/kubernetes/login', ['role' => self::CONTROL, 'jwt' => $jwt]);
        unset($jwt);
        $this->ensure($response, 'logging in to OpenBao as '.self::CONTROL);

        $token = $response->json('auth.client_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('OpenBao answered the login without a token');
        }

        return $this->token = $token;
    }

    // ── API ─────────────────────────────────────────────────────────────────

    /** GET an OpenBao path with RunR's token; null when it does not exist (404). */
    public function read(string $path): ?array
    {
        $response = $this->send('GET', 'v1/'.ltrim($path, '/'), null, $this->login());
        if ($response->status() === 404) {
            return null;
        }
        $this->ensure($response, "reading {$path}");

        return (array) $response->json();
    }

    /** POST (create or update) an OpenBao path with RunR's token. */
    public function write(string $path, array $body = []): array
    {
        $response = $this->send('POST', 'v1/'.ltrim($path, '/'), $body, $this->login());
        $this->ensure($response, "writing {$path}");

        return (array) ($response->json() ?? []);
    }

    /** DELETE an OpenBao path with RunR's token (404 = already gone). */
    public function delete(string $path): void
    {
        $response = $this->send('DELETE', 'v1/'.ltrim($path, '/'), null, $this->login());
        if ($response->status() !== 404) {
            $this->ensure($response, "deleting {$path}");
        }
    }

    /**
     * The keys of a KV v2 secret under the RunR mount; null when it does not exist.
     *
     * @return array<string, string>|null
     */
    public function kvRead(string $path): ?array
    {
        $data = $this->read(self::KV_MOUNT.'/data/'.ltrim($path, '/'));
        if ($data === null || ! is_array($data['data']['data'] ?? null)) {
            return null;
        }

        return array_map('strval', $data['data']['data']);
    }

    /**
     * Set (string) and remove (null) keys of a KV v2 secret, creating it when it is missing — a
     * merge patch, so the other keys stay.
     *
     * @param  array<string, string|null>  $data
     */
    public function kvPatch(string $path, array $data): void
    {
        if ($data === []) {
            return;
        }

        $url = 'v1/'.self::KV_MOUNT.'/data/'.ltrim($path, '/');
        $response = $this->send('PATCH', $url, ['data' => $data], $this->login(), 'application/merge-patch+json');
        if ($response->status() !== 404) {
            $this->ensure($response, "patching {$path}");

            return;
        }

        $set = array_filter($data, fn ($v) => $v !== null);
        if ($set === []) {
            return;
        }
        $this->ensure($this->send('POST', $url, ['data' => $set], $this->login()), "writing {$path}");
    }

    /** Remove a KV v2 secret with every version of it. */
    public function kvDestroy(string $path): void
    {
        $this->delete(self::KV_MOUNT.'/metadata/'.ltrim($path, '/'));
    }

    /** PUT an ACL policy (HCL). */
    public function putPolicy(string $name, string $hcl): void
    {
        $response = $this->send('PUT', 'v1/sys/policies/acl/'.$name, ['policy' => $hcl], $this->login());
        $this->ensure($response, "writing policy {$name}");
    }

    public function deletePolicy(string $name): void
    {
        $response = $this->send('DELETE', 'v1/sys/policies/acl/'.$name, null, $this->login());
        if ($response->status() !== 404) {
            $this->ensure($response, "deleting policy {$name}");
        }
    }

    // ── HTTP ────────────────────────────────────────────────────────────────

    private function send(string $method, string $path, ?array $body = null, ?string $token = null, string $contentType = 'application/json'): Response
    {
        $headers = ['Accept' => 'application/json', 'Content-Type' => $contentType];
        if ($token !== null) {
            $headers['X-Vault-Token'] = $token;
        }

        return $this->kubernetes()->serviceProxySend(
            $method,
            self::NAMESPACE,
            self::SERVICE,
            self::PORT,
            $path,
            $headers,
            // an empty body is an empty JSON object: OpenBao refuses [] ("error parsing JSON")
            $body !== null ? ($body === [] ? '{}' : json_encode($body, JSON_UNESCAPED_SLASHES)) : null,
            30,
            'https',
        );
    }

    private function ensure(Response $response, string $what): void
    {
        if ($response->successful()) {
            return;
        }

        $errors = $response->json('errors');
        $text = is_array($errors) ? implode('; ', array_map('strval', $errors)) : '';
        throw new RuntimeException(mb_substr("OpenBao of region {$this->region}: {$what} failed (HTTP {$response->status()})".($text !== '' ? ": {$text}" : ''), 0, 500));
    }

    private function kubernetes(): KubernetesClient
    {
        return $this->k8s ??= new KubernetesClient($this->kubeconfig);
    }
}
