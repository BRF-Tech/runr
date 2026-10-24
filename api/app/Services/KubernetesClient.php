<?php

namespace App\Services;

use App\Services\Concerns\KubernetesClusterTrait;
use App\Services\Concerns\KubernetesNamespacedTrait;
use App\Services\Contracts\KnativeConstants;
use App\Services\Helpers\PrivateIngress;
use App\Services\InternalNetwork\InternalNetwork;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;

class KubernetesClient
{
    use KubernetesClusterTrait;
    use KubernetesNamespacedTrait;

    /**
     * Seconds a connection to an API server is kept for the next call after its last one: below
     * the idle timeouts of API servers and of the load balancers / NAT in front of them, above the
     * gaps between the calls of a deploy and its readiness checks.
     */
    public const IDLE_SECONDS = 20;

    /** API servers a process keeps connections to at once (the longest unused one goes first) */
    private const MAX_TRANSPORTS = 16;

    /**
     * Per API server and credentials: the request options (with the CA / client certificate files
     * written once) and one cURL handler, whose handles keep their connections open between
     * requests. Without it every call opened a new TCP + TLS connection: on an API server a few
     * milliseconds away that is two to three round trips per call, and a deploy (its secret
     * values, manifests, internal-network routes, the replaced deployment's teardown, every
     * readiness check) makes a dozen calls one after the other. Shared by the clients of a process
     * (a Horizon worker runs the deploy and its checks), each PHP thread its own.
     *
     * @var array<string, array{options: array<string, mixed>, handler: callable|null, used: float}>
     */
    private static array $transports = [];

    private string $server;

    private string $transportKey;

    /** @var array<string, mixed> the kubeconfig's cluster entry */
    private array $cluster;

    /** @var array<string, mixed> the kubeconfig's user entry */
    private array $user;

    public function __construct(string $kubeconfigYaml)
    {
        $config = Yaml::parse($kubeconfigYaml);

        $contextName = $config['current-context'];
        $context = collect($config['contexts'])->firstWhere('name', $contextName)['context'];
        $cluster = collect($config['clusters'])->firstWhere('name', $context['cluster'])['cluster'];
        $user = collect($config['users'])->firstWhere('name', $context['user'])['user'];

        $this->server = rtrim($cluster['server'], '/');
        $this->cluster = (array) $cluster;
        $this->user = (array) $user;
        $this->transportKey = hash('sha256', serialize([
            $this->server,
            $cluster['certificate-authority-data'] ?? null,
            ! empty($cluster['insecure-skip-tls-verify']),
            $user['token'] ?? null,
            $user['client-certificate-data'] ?? null,
            $user['client-key-data'] ?? null,
        ]));
    }

    /** The cluster's API server URL (from the kubeconfig). */
    public function server(): string
    {
        return $this->server;
    }

    // ── Cluster info ────────────────────────────────────────────────────────

    /**
     * Get the Kubernetes server version via /version endpoint.
     */
    public function getServerVersion(): ?array
    {
        $response = $this->request()->get($this->server.'/version');

        return $response->successful() ? $response->json() : null;
    }

    /**
     * The JSON of a GET on an API server path that is not a resource — e.g. the service account
     * issuer's discovery document (/.well-known/openid-configuration) and keys (/openid/v1/jwks).
     * Null when the request fails.
     */
    public function getPath(string $path): ?array
    {
        $response = $this->request()->get($this->server.'/'.ltrim($path, '/'));

        return $response->successful() && is_array($response->json()) ? $response->json() : null;
    }

    /**
     * Is an API group/version served by the cluster (discovery document, e.g.
     * "cert-manager.io/v1" → GET /apis/cert-manager.io/v1)?
     */
    public function hasApiVersion(string $apiVersion): bool
    {
        return $this->request()->get($this->server.$this->apiBase($apiVersion))->successful();
    }

    /**
     * API discovery: the APIResourceList of a group version ("skupper.io/v2alpha1"), or null
     * when the cluster does not serve it (CRDs not installed).
     */
    public function getApiResources(string $groupVersion): ?array
    {
        $response = $this->request()->get($this->server.$this->apiBase($groupVersion));

        return $response->successful() ? $response->json() : null;
    }

    // ── Docker environment adapter ───────────────────────────────────────────

    /**
     * Adapt kubeconfig for Docker/WSL2 environments.
     * - host.docker.internal in server → strip CA, set insecure
     * - 127.0.0.1/localhost unreachable → fallback to host.docker.internal
     */
    public static function adaptKubeconfig(string $yaml): string
    {
        $config = Yaml::parse($yaml);
        $contextName = $config['current-context'] ?? null;
        if (! $contextName) {
            return $yaml;
        }

        $context = collect($config['contexts'])->firstWhere('name', $contextName)['context'] ?? null;
        $clusterIdx = null;
        $server = '';

        foreach ($config['clusters'] as $i => $c) {
            if ($c['name'] === ($context['cluster'] ?? '')) {
                $clusterIdx = $i;
                $server = $c['cluster']['server'] ?? '';
                break;
            }
        }

        if ($clusterIdx === null) {
            return $yaml;
        }

        // host.docker.internal → force insecure TLS
        if (str_contains($server, 'host.docker.internal')) {
            unset($config['clusters'][$clusterIdx]['cluster']['certificate-authority-data']);
            $config['clusters'][$clusterIdx]['cluster']['insecure-skip-tls-verify'] = true;

            return Yaml::dump($config, 10, 2);
        }

        // 127.0.0.1 / localhost → try as-is, fallback to host.docker.internal
        if (preg_match('/(127\.0\.0\.1|localhost)/', $server)) {
            try {
                $client = new self($yaml);
                if ($client->getServerVersion()) {
                    return $yaml;
                }
            } catch (\Throwable) {
                // unreachable — fall through to fallback
            }

            $newServer = preg_replace('/(127\.0\.0\.1|localhost)/', 'host.docker.internal', $server);
            $config['clusters'][$clusterIdx]['cluster']['server'] = $newServer;
            unset($config['clusters'][$clusterIdx]['cluster']['certificate-authority-data']);
            $config['clusters'][$clusterIdx]['cluster']['insecure-skip-tls-verify'] = true;

            return Yaml::dump($config, 10, 2);
        }

        return $yaml;
    }

    // ── Service proxy ───────────────────────────────────────────────────────

    /**
     * Proxy a GET request to a cluster-internal service via the K8s API server.
     * Works even when the caller has no direct network access to the cluster.
     *
     * URL: /api/v1/namespaces/{ns}/services/{scheme}:{svc}:{port}/proxy/{path}
     */
    public function serviceProxyGet(string $namespace, string $service, int $port, string $path, array $query = [], array $headers = [], string $scheme = 'http'): ?Response
    {
        $url = $this->serviceProxyBase($namespace, $service, $port, $scheme).ltrim($path, '/');

        if ($query) {
            $url .= '?'.http_build_query($query);
        }

        $request = $this->request()->timeout(10);
        if ($headers) {
            // Replace, not merge: withHeaders() would add a given Accept to the client's
            // default application/json instead of sending only the requested types
            $request = $request->replaceHeaders($headers);
        }

        $response = $request->get($url);

        return $response->successful() ? $response : null;
    }

    /**
     * Proxy a request with any method (e.g. HEAD, POST) to a cluster-internal service and return
     * the response whatever its status. Given $headers replace the client defaults; $query is
     * sent as the query string; $scheme "https" makes the API server talk TLS to the Service.
     *
     * URL: /api/v1/namespaces/{ns}/services/{scheme}:{svc}:{port}/proxy/{path}
     */
    public function serviceProxyRequest(string $method, string $namespace, string $service, int $port, string $path, array $headers = [], array $query = [], string $scheme = 'http'): Response
    {
        $url = $this->serviceProxyBase($namespace, $service, $port, $scheme).ltrim($path, '/');

        if ($query) {
            $url .= '?'.http_build_query($query);
        }

        $request = $this->request()->timeout(10);
        if ($headers) {
            $request = $request->replaceHeaders($headers);
        }

        return $request->send(strtoupper($method), $url);
    }

    /**
     * Proxy a request with a body (e.g. POST JSON) to a cluster-internal service and return the
     * response whatever its status. Given $headers replace the client defaults (Host and the
     * Knative routing headers reach the Service as sent).
     *
     * URL: /api/v1/namespaces/{ns}/services/{scheme}:{svc}:{port}/proxy/{path}
     */
    public function serviceProxySend(string $method, string $namespace, string $service, int $port, string $path, array $headers = [], ?string $body = null, int $timeout = 30, string $scheme = 'http'): Response
    {
        $url = $this->serviceProxyBase($namespace, $service, $port, $scheme).ltrim($path, '/');
        $request = $this->request()->timeout($timeout)->replaceHeaders($headers);

        if ($body !== null) {
            $request = $request->withBody($body, $headers['Content-Type'] ?? 'application/octet-stream');
        }

        return $request->send(strtoupper($method), $url);
    }

    /**
     * A request to a Knative Service on its cluster-local host <service>.<namespace>.svc.cluster.local,
     * through the region Traefik's private entry point (PrivateIngress): the route its in-cluster
     * callers take, scale from zero through the activator included. The API server's service proxy
     * passes the Host header on. The revision pods and the activator admit only Traefik and
     * Knative (RegionNetworkPolicyManifests), so neither is called directly. A region whose Traefik
     * has no traefik-internal yet (region:reconcile has not run since the upgrade) is called on
     * Traefik's main Service, where its cluster-local routes still are.
     *
     * $token: RunR's own token on the region's internal network (InternalNetwork::systemToken),
     * which the ForwardAuth of the private entry point admits for every service.
     */
    public function knativePrivateRequest(string $method, string $servingNamespace, string $namespace, string $serviceName, string $path = '', array $headers = [], ?string $body = null, int $timeout = 30, ?string $token = null): Response
    {
        $headers = ['Host' => "{$serviceName}.{$namespace}.svc.cluster.local"] + ($token !== null ? [InternalNetwork::HEADER => $token] : []) + $headers;
        $response = $this->serviceProxySend($method, $servingNamespace, PrivateIngress::SERVICE, 80, $path, $headers, $body, $timeout);

        // the API server's own 404 for a Service it does not know (Traefik answers 404 as text)
        if ($response->status() === 404 && $response->json('kind') === 'Status' && $response->json('details.kind') === 'services') {
            return $this->serviceProxySend($method, $servingNamespace, KnativeConstants::TRAEFIK_RELEASE, 80, $path, $headers, $body, $timeout);
        }

        return $response;
    }

    /**
     * Proxy a DELETE request to a cluster-internal service via the K8s API server.
     * Works even when the caller has no direct network access to the cluster.
     * Mirrors deleteResource(): a 404 counts as success — the target is already gone.
     *
     * URL: /api/v1/namespaces/{ns}/services/http:{svc}:{port}/proxy/{path}
     */
    public function serviceProxyDelete(string $namespace, string $service, int $port, string $path): bool
    {
        $url = "{$this->server}/api/v1/namespaces/{$namespace}/services/http:{$service}:{$port}/proxy/{$path}";

        $response = $this->request()->timeout(10)->delete($url);

        return $response->successful() || $response->status() === 404;
    }

    /**
     * Run multiple Prometheus instant queries in parallel via K8s API proxy.
     * Returns [key => ?float] — null when query returns no time series or fails.
     * Parallel execution means total time ≈ slowest single request, not sum of all.
     *
     * @param  array<string,string>  $queries  associative [key => promql_string]
     * @param  string  $pathPrefix  path the Prometheus is served under (e.g. "prometheus"), "" = root
     */
    public function prometheusParallelQuery(
        string $namespace, string $service, int $port, array $queries, string $pathPrefix = '', string $scheme = 'http'
    ): array {
        $prefix = trim($pathPrefix, '/');
        $base = $this->serviceProxyBase($namespace, $service, $port, $scheme).($prefix === '' ? '' : $prefix.'/').'api/v1/query';
        $opts = $this->transport()['options'];

        $responses = Http::pool(function ($pool) use ($base, $queries, $opts) {
            foreach ($queries as $key => $q) {
                $pool->as($key)->withOptions($opts)->timeout(10)->get($base, ['query' => $q]);
            }
        });

        $results = [];
        foreach ($queries as $key => $_) {
            $r = $responses[$key] ?? null;
            if ($r instanceof Response && $r->successful()) {
                $val = $r->json('data.result.0.value.1');
                $results[$key] = $val !== null ? (float) $val : null;
            } else {
                $results[$key] = null;
            }
        }

        return $results;
    }

    /**
     * K8s API service proxy root of a Service port, with a trailing slash.
     * $scheme "https" makes the API server talk TLS to the Service.
     */
    private function serviceProxyBase(string $namespace, string $service, int $port, string $scheme = 'http'): string
    {
        $scheme = $scheme === 'https' ? 'https' : 'http';

        return "{$this->server}/api/v1/namespaces/{$namespace}/services/{$scheme}:{$service}:{$port}/proxy/";
    }

    // ── HTTP layer ──────────────────────────────────────────────────────────

    private function request(): PendingRequest
    {
        $transport = $this->transport();

        // The shared handler under Laravel's own handlers (Http::fake() answers before it)
        return Http::withOptions($transport['options'])
            ->setHandler($transport['handler'])
            ->timeout(30)
            ->acceptJson();
    }

    /**
     * This API server's entry of $transports: kept while it is used, new after IDLE_SECONDS
     * without a call (its connections may have been dropped on the way) or when its certificate
     * files are gone (a temp directory cleaner).
     *
     * @return array{options: array<string, mixed>, handler: callable|null, used: float}
     */
    private function transport(): array
    {
        $now = (float) now()->format('U.u');
        $entry = self::$transports[$this->transportKey] ?? null;
        if ($entry !== null && ($now - $entry['used'] > self::IDLE_SECONDS || ! self::filesPresent($entry['options']))) {
            self::forgetTransport($this->transportKey);
            $entry = null;
        }

        if ($entry === null) {
            while (count(self::$transports) >= self::MAX_TRANSPORTS) {
                $used = array_column(self::$transports, 'used');
                self::forgetTransport((string) array_keys(self::$transports)[array_search(min($used), $used, true)]);
            }
            $entry = [
                'options' => $this->buildHttpOptions($this->cluster, $this->user),
                // a cURL handler keeps its handles — and their open connections — between requests;
                // null (no ext-curl): Guzzle's default, a new connection per request as before
                'handler' => function_exists('curl_init') ? new CurlHandler : null,
            ];
        }

        $entry['used'] = $now;

        return self::$transports[$this->transportKey] = $entry;
    }

    /** @param array<string, mixed> $options */
    private static function filesPresent(array $options): bool
    {
        foreach ([$options['verify'] ?? null, $options['cert'] ?? null, $options['ssl_key'] ?? null] as $path) {
            if (is_string($path) && ! is_file($path)) {
                return false;
            }
        }

        return true;
    }

    /** Drops an entry: its handler (the connections close with it) and its certificate files. */
    private static function forgetTransport(string $key): void
    {
        $options = self::$transports[$key]['options'] ?? [];
        unset(self::$transports[$key]);
        foreach ([$options['verify'] ?? null, $options['cert'] ?? null, $options['ssl_key'] ?? null] as $path) {
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function buildHttpOptions(array $cluster, array $user): array
    {
        $options = [];

        // TLS — CA certificate
        if (! empty($cluster['certificate-authority-data'])) {
            $caPath = tempnam(sys_get_temp_dir(), 'runr-ca-');
            file_put_contents($caPath, base64_decode($cluster['certificate-authority-data']));
            $options['verify'] = $caPath;
        } elseif (! empty($cluster['insecure-skip-tls-verify'])) {
            $options['verify'] = false;
        }

        // Auth — Bearer token
        if (! empty($user['token'])) {
            $options['headers'] = ['Authorization' => 'Bearer '.$user['token']];
        }

        // Auth — Client certificate + key
        if (! empty($user['client-certificate-data']) && ! empty($user['client-key-data'])) {
            $certPath = tempnam(sys_get_temp_dir(), 'runr-cert-');
            $keyPath = tempnam(sys_get_temp_dir(), 'runr-key-');
            file_put_contents($certPath, base64_decode($user['client-certificate-data']));
            file_put_contents($keyPath, base64_decode($user['client-key-data']));
            $options['cert'] = $certPath;
            $options['ssl_key'] = $keyPath;
        }

        return $options;
    }

    // ── URL builders ────────────────────────────────────────────────────────

    /**
     * Build the K8s API URL for a specific namespaced resource.
     */
    private function resourceUrl(string $apiVersion, string $kind, string $namespace, string $name): string
    {
        $base = $this->apiBase($apiVersion);
        $plural = $this->kindToPlural($kind);

        return "{$this->server}{$base}/namespaces/{$namespace}/{$plural}/{$name}";
    }

    /**
     * Build the K8s API URL for a namespaced resource collection.
     */
    private function collectionUrl(string $apiVersion, string $kind, string $namespace): string
    {
        $base = $this->apiBase($apiVersion);
        $plural = $this->kindToPlural($kind);

        return "{$this->server}{$base}/namespaces/{$namespace}/{$plural}";
    }

    /**
     * Build the K8s API URL for a cluster-scoped resource.
     */
    private function clusterResourceUrl(string $apiVersion, string $kind, string $name): string
    {
        $base = $this->apiBase($apiVersion);
        $plural = $this->kindToPlural($kind);

        return "{$this->server}{$base}/{$plural}/{$name}";
    }

    /**
     * Build the K8s API URL for a cluster-scoped resource collection.
     */
    private function clusterCollectionUrl(string $apiVersion, string $kind): string
    {
        $base = $this->apiBase($apiVersion);
        $plural = $this->kindToPlural($kind);

        return "{$this->server}{$base}/{$plural}";
    }

    /**
     * Convert apiVersion to API base path.
     * "v1" → "/api/v1"
     * "serving.knative.dev/v1" → "/apis/serving.knative.dev/v1"
     * "batch/v1" → "/apis/batch/v1"
     */
    private function apiBase(string $apiVersion): string
    {
        if ($apiVersion === 'v1') {
            return '/api/v1';
        }

        return '/apis/'.$apiVersion;
    }

    /**
     * Convert Kind to plural resource name.
     */
    private function kindToPlural(string $kind): string
    {
        return match ($kind) {
            'ConfigMap' => 'configmaps',
            'Service' => 'services',
            'CronJob' => 'cronjobs',
            'Namespace' => 'namespaces',
            'Secret' => 'secrets',
            'Node' => 'nodes',
            'Pod' => 'pods',
            'Deployment' => 'deployments',
            'DaemonSet' => 'daemonsets',
            'CustomResourceDefinition' => 'customresourcedefinitions',
            'ClusterRole' => 'clusterroles',
            'ClusterRoleBinding' => 'clusterrolebindings',
            'ServiceAccount' => 'serviceaccounts',
            'KnativeServing' => 'knativeservings',
            'KnativeEventing' => 'knativeeventings',  // kept for cleanup of legacy installs
            'StorageClass' => 'storageclasses',
            'IngressClass' => 'ingressclasses',
            'Ingress' => 'ingresses',
            'PriorityClass' => 'priorityclasses',
            'PodDisruptionBudget' => 'poddisruptionbudgets',
            'RuntimeClass' => 'runtimeclasses',
            'ClusterImagePolicy' => 'clusterimagepolicies', // sigstore policy-controller (supply chain)
            'NetworkPolicy' => 'networkpolicies',
            'Prometheus' => 'prometheuses',                 // prometheus-operator (region metrics)
            default => strtolower($kind).'s',
        };
    }
}
