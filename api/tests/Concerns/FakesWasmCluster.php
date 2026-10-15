<?php

namespace Tests\Concerns;

use App\Models\KnativeRegion;
use App\Services\Wasm\WasmInstallService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;

/**
 * A fake region cluster for WebAssembly support (Http::fake): nodes with labels, RuntimeClasses,
 * the wasm-installer / wasm-cleanup DaemonSets with one pod per node their nodeSelector matches
 * (ready at once, or failing with a node script error), the cluster's pods and the Knative
 * Serving CR / config-features ConfigMap. Every call is recorded as "METHOD path".
 */
trait FakesWasmCluster
{
    protected const WASM_SERVER = 'https://region.test:6443';

    /** @var array<string, array> name → Node */
    protected array $nodes = [];

    /** @var array<string, array> name → RuntimeClass */
    protected array $runtimeClasses = [];

    /** @var array<string, array> name → DaemonSet as applied */
    protected array $daemonSets = [];

    /** @var list<array> pods of every namespace (in-use checks) */
    protected array $clusterPods = [];

    /** KnativeServing CR spec.config.features; null = no operator (config-features ConfigMap) */
    protected ?array $servingFeatures = [];

    /** @var array<string, string> data of the config-features ConfigMap */
    protected array $configFeatures = [];

    /** @var array<string, string> DaemonSet name → node script error of its pods */
    protected array $failingDaemonSets = [];

    /** @var list<string> "METHOD path" of every API call */
    protected array $calls = [];

    /** @var list<array{method: string, path: string, body: mixed}> */
    protected array $requests = [];

    protected function wasmKubeconfig(): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => self::WASM_SERVER, 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }

    protected function wasmRegion(array $attributes = []): KnativeRegion
    {
        return KnativeRegion::create(array_merge([
            'name' => 'fn',
            'project_namespace' => 'fn',
            'kubeconfig' => $this->wasmKubeconfig(),
            'is_active' => true,
            'knative_serving_status' => 'ready',
        ], $attributes));
    }

    protected function addNode(string $name, array $labels = [], string $runtime = 'containerd://2.1.4', string $kubelet = 'v1.34.1', array $taints = []): void
    {
        $this->nodes[$name] = [
            'metadata' => ['name' => $name, 'labels' => $labels + ['kubernetes.io/os' => 'linux', 'kubernetes.io/arch' => 'amd64']],
            'spec' => $taints ? ['taints' => $taints] : [],
            'status' => ['nodeInfo' => ['containerRuntimeVersion' => $runtime, 'kubeletVersion' => $kubelet, 'architecture' => 'amd64']],
        ];
    }

    /** A WasmInstallService that does not wait in real time. */
    protected function wasmService(): WasmInstallService
    {
        $service = app(WasmInstallService::class);
        $service->installWaitSeconds = 3;
        $service->cleanupWaitSeconds = 3;
        $service->pollSeconds = 0;

        return $service;
    }

    protected function labelsOf(string $node): array
    {
        return $this->nodes[$node]['metadata']['labels'] ?? [];
    }

    protected function called(string $call): bool
    {
        return in_array($call, $this->calls, true);
    }

    /** Body of the last request of METHOD path. */
    protected function lastBody(string $method, string $path): mixed
    {
        $match = array_values(array_filter($this->requests, fn ($r) => $r['method'] === $method && $r['path'] === $path));

        return $match ? end($match)['body'] : null;
    }

    protected function fakeWasmCluster(): void
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $method = $request->method();
            $body = json_decode($request->body(), true);

            $this->calls[] = "{$method} {$path}";
            $this->requests[] = ['method' => $method, 'path' => $path, 'body' => $body];

            return $this->route($method, $path, $query, is_array($body) ? $body : []);
        });
    }

    private function route(string $method, string $path, array $query, array $body)
    {
        $selector = self::selector((string) ($query['labelSelector'] ?? ''));
        $ns = '/apis/apps/v1/namespaces/runr-sandbox/daemonsets/';

        // ── nodes ──
        if ($path === '/api/v1/nodes' && $method === 'GET') {
            return Http::response(['items' => array_values(array_filter($this->nodes, fn ($n) => self::labelsMatch($n['metadata']['labels'], $selector)))]);
        }
        if (preg_match('#^/api/v1/nodes/([^/]+)$#', $path, $m)) {
            if (! isset($this->nodes[$m[1]])) {
                return Http::response(['kind' => 'Status', 'code' => 404], 404);
            }
            if ($method === 'PATCH') {
                foreach ($body['metadata']['labels'] ?? [] as $key => $value) {
                    if ($value === null) {
                        unset($this->nodes[$m[1]]['metadata']['labels'][$key]);
                    } else {
                        $this->nodes[$m[1]]['metadata']['labels'][$key] = $value;
                    }
                }
            }

            return Http::response($this->nodes[$m[1]]);
        }

        // ── pods of the cluster ──
        if ($path === '/api/v1/pods' && $method === 'GET') {
            return Http::response(['items' => $this->clusterPods]);
        }

        // ── RuntimeClasses ──
        if (preg_match('#^/apis/node\.k8s\.io/v1/runtimeclasses/([^/]+)$#', $path, $m)) {
            return match ($method) {
                'GET' => isset($this->runtimeClasses[$m[1]]) ? Http::response($this->runtimeClasses[$m[1]]) : Http::response(['kind' => 'Status', 'code' => 404], 404),
                'PATCH' => Http::response($this->runtimeClasses[$m[1]] = $body),
                'DELETE' => (function () use ($m) {
                    unset($this->runtimeClasses[$m[1]]);

                    return Http::response(['kind' => 'Status', 'status' => 'Success']);
                })(),
                default => Http::response([], 405),
            };
        }

        // ── node DaemonSets and their pods ──
        if (str_starts_with($path, $ns)) {
            $name = substr($path, strlen($ns));
            if ($method === 'PATCH') {
                $this->daemonSets[$name] = $body;

                return Http::response($body);
            }
            if ($method === 'DELETE') {
                unset($this->daemonSets[$name]);

                return Http::response(['kind' => 'Status', 'status' => 'Success']);
            }
            if (! isset($this->daemonSets[$name])) {
                return Http::response(['kind' => 'Status', 'code' => 404], 404);
            }
            $pods = $this->podsOf($name);
            $ready = isset($this->failingDaemonSets[$name]) ? 0 : count($pods);

            return Http::response($this->daemonSets[$name] + ['status' => [
                'desiredNumberScheduled' => count($pods),
                'updatedNumberScheduled' => count($pods),
                'numberReady' => $ready,
                'observedGeneration' => 1,
            ]]);
        }
        if ($path === '/api/v1/namespaces/runr-sandbox/pods' && $method === 'GET') {
            return Http::response(['items' => $this->podsOf((string) ($selector['runr.dev/component'] ?? ''))]);
        }

        // ── Knative Serving features ──
        if ($path === '/apis/operator.knative.dev/v1beta1/namespaces/knative-serving/knativeservings/knative-serving') {
            if ($this->servingFeatures === null) {
                return Http::response(['kind' => 'Status', 'code' => 404], 404);
            }
            if ($method === 'PATCH') {
                $this->servingFeatures = array_merge($this->servingFeatures, $body['spec']['config']['features'] ?? []);
            }

            return Http::response(['metadata' => ['name' => 'knative-serving'], 'spec' => ['config' => ['features' => $this->servingFeatures]]]);
        }
        if ($path === '/api/v1/namespaces/knative-serving/configmaps/config-features') {
            if ($method === 'PATCH') {
                $this->configFeatures = array_merge($this->configFeatures, $body['data'] ?? []);
            }

            return Http::response(['data' => $this->configFeatures]);
        }

        return $method === 'GET'
            ? Http::response(['kind' => 'Status', 'code' => 404], 404)
            : Http::response(['kind' => 'Status', 'status' => 'Success']);
    }

    /** One pod per node the DaemonSet's nodeSelector matches (ready, or failed with its error). */
    private function podsOf(string $ds): array
    {
        $spec = $this->daemonSets[$ds]['spec']['template']['spec'] ?? null;
        if ($spec === null) {
            return [];
        }

        $pods = [];
        foreach ($this->nodes as $name => $node) {
            if (! self::labelsMatch($node['metadata']['labels'], $spec['nodeSelector'] ?? [])) {
                continue;
            }
            $failure = $this->failingDaemonSets[$ds] ?? null;
            $pods[] = [
                'metadata' => ['name' => "{$ds}-{$name}", 'labels' => ['runr.dev/component' => $ds, 'pod-template-generation' => '1']],
                'spec' => ['nodeName' => $name],
                'status' => $failure === null
                    ? ['phase' => 'Running', 'conditions' => [['type' => 'Ready', 'status' => 'True']]]
                    : ['phase' => 'Running', 'conditions' => [['type' => 'Ready', 'status' => 'False']], 'containerStatuses' => [[
                        'lastState' => ['terminated' => ['exitCode' => 1, 'message' => "runr: ERROR: {$failure} (node {$name})"]],
                    ]]],
            ];
        }

        return $pods;
    }

    /** "a=b,c=d" → [a => b, c => d] */
    private static function selector(string $raw): array
    {
        $out = [];
        foreach (array_filter(explode(',', $raw)) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $out[$k] = $v;
        }

        return $out;
    }

    private static function labelsMatch(array $labels, array $selector): bool
    {
        foreach ($selector as $k => $v) {
            if (($labels[$k] ?? null) !== $v) {
                return false;
            }
        }

        return true;
    }
}
