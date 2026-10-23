<?php

namespace Tests\Concerns;

use App\Models\KnativeRegion;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;

/**
 * A small in-memory Kubernetes API for the Kata install / uninstall / reconcile tests: nodes,
 * the runr-sandbox DaemonSets and their pods (one per node, carrying the template annotations),
 * pod logs, RuntimeClasses and the cluster's pods. Everything else answers 404 (GET) or success.
 */
trait FakesKataCluster
{
    /** @var list<string> "METHOD path" of every call */
    protected array $calls = [];

    /** @var array<string, mixed> last decoded body per "METHOD path" */
    protected array $bodies = [];

    /** @var array<string, array> node name → Node */
    protected array $nodes = [];

    /** @var array<string, array> DaemonSet name → manifest as applied */
    protected array $daemonSets = [];

    /** @var array<string, array> RuntimeClass name → object */
    protected array $runtimeClasses = [];

    /** @var array<string, array> ConfigMap name (runr-sandbox) → manifest as applied */
    protected array $configMaps = [];

    /** @var list<array> pods of the whole cluster (in-use checks) */
    protected array $clusterPods = [];

    /** @var array<string, string> pod name → log */
    protected array $podLogs = [];

    /** @var array<string, array> container status overrides per pod name */
    protected array $podStatuses = [];

    /** @var array<string, ?bool> what the /dev/kvm check finds per node (missing: KVM; null: no answer) */
    protected array $nodeKvm = [];

    /** @var array<string, int> node → its /dev/shm in MiB as the /dev/kvm check reports it (default: not reported) */
    protected array $nodeShmMb = [];

    /** @var array<string, string> node → dbus=yes|missing|n/a as the /dev/kvm check reports it (default: not reported) */
    protected array $nodeDbus = [];

    /**
     * @var array<string, list<array>> pods of an earlier DaemonSet object per component, listed
     *                                 next to the current ones until the DaemonSet is deleted (e.g. RunR 0.4.1's crash-looping
     *                                 kata-devmapper pod)
     */
    protected array $stalePods = [];

    /** @var array<string, int> DaemonSet name → updatedNumberScheduled (missing: every node) */
    protected array $updatedPods = [];

    /** @var array<string, Closure(array): void> reaction to a DaemonSet apply (the "node side") */
    protected array $onApply = [];

    /** @var array<string, Closure(): void> reaction to a DaemonSet delete */
    protected array $onDelete = [];

    protected function kataKubeconfig(): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://kata.test:6443', 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }

    protected function kataRegion(array $attributes = []): KnativeRegion
    {
        return KnativeRegion::create($attributes + [
            'name' => 'op',
            'kubeconfig' => $this->kataKubeconfig(),
            'is_active' => true,
        ]);
    }

    protected function addNode(string $name, array $labels = [], string $runtime = 'containerd://2.2.7-k3s1', string $kubelet = 'v1.34.11+k3s1'): void
    {
        $this->nodes[$name] = [
            'metadata' => ['name' => $name, 'labels' => $labels + ['kubernetes.io/os' => 'linux', 'kubernetes.io/hostname' => $name]],
            'status' => ['nodeInfo' => ['containerRuntimeVersion' => $runtime, 'kubeletVersion' => $kubelet, 'architecture' => 'amd64']],
        ];
    }

    protected function labelNode(string $name, string $key, ?string $value): void
    {
        if ($value === null) {
            unset($this->nodes[$name]['metadata']['labels'][$key]);
        } else {
            $this->nodes[$name]['metadata']['labels'][$key] = $value;
        }
    }

    /** Pod name of a DaemonSet on a node. */
    protected function podName(string $component, string $node): string
    {
        return "{$component}-{$node}";
    }

    /** Pods of a DaemonSet: one per node, with its current template annotations. */
    protected function podsOf(string $component): array
    {
        $ds = $this->daemonSets[$component] ?? null;
        if (! $ds) {
            return [];
        }

        $pods = [];
        foreach (array_keys($this->nodes) as $node) {
            $name = $this->podName($component, $node);
            $pods[] = [
                'metadata' => [
                    'name' => $name,
                    'labels' => ['runr.dev/component' => $component, 'pod-template-generation' => (string) ($ds['metadata']['generation'] ?? 1)],
                    'annotations' => $ds['spec']['template']['metadata']['annotations'] ?? [],
                    'ownerReferences' => isset($ds['metadata']['uid']) ? [['kind' => 'DaemonSet', 'name' => $component, 'uid' => $ds['metadata']['uid']]] : [],
                ],
                'spec' => ['nodeName' => $node],
                'status' => [
                    'phase' => 'Running',
                    'containerStatuses' => [$this->podStatuses[$name] ?? ['name' => 'main', 'restartCount' => 0, 'state' => ['running' => []]]],
                ],
            ];
        }

        return $pods;
    }

    protected function fakeKataCluster(): void
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $method = $request->method();
            $body = json_decode($request->body(), true);

            $this->calls[] = "{$method} {$path}";
            if ($body !== null) {
                $this->bodies["{$method} {$path}"] = $body;
            }

            $selector = [];
            foreach (array_filter(explode(',', (string) ($query['labelSelector'] ?? ''))) as $part) {
                [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
                $selector[$k] = $v;
            }

            // Nodes
            if ($path === '/api/v1/nodes' && $method === 'GET') {
                $items = array_values(array_filter($this->nodes, function ($n) use ($selector) {
                    foreach ($selector as $k => $v) {
                        if (($n['metadata']['labels'][$k] ?? null) !== $v) {
                            return false;
                        }
                    }

                    return true;
                }));

                return Http::response(['items' => $items]);
            }
            if (preg_match('#^/api/v1/nodes/([^/]+)$#', $path, $m)) {
                if (! isset($this->nodes[$m[1]])) {
                    return Http::response(['kind' => 'Status', 'code' => 404], 404);
                }
                if ($method === 'PATCH') {
                    foreach ($body['metadata']['labels'] ?? [] as $k => $v) {
                        $this->labelNode($m[1], $k, $v);
                    }
                    foreach ($body['metadata']['annotations'] ?? [] as $k => $v) {
                        $this->nodes[$m[1]]['metadata']['annotations'][$k] = $v;
                    }
                }

                return Http::response($this->nodes[$m[1]]);
            }

            // DaemonSets in runr-sandbox
            if (preg_match('#^/apis/apps/v1/namespaces/runr-sandbox/daemonsets/([^/]+)$#', $path, $m)) {
                $name = $m[1];
                if ($method === 'PATCH') {
                    $generation = ($this->daemonSets[$name]['metadata']['generation'] ?? 0) + 1;
                    $uid = $this->daemonSets[$name]['metadata']['uid'] ?? ('uid-'.bin2hex(random_bytes(4)));
                    $this->daemonSets[$name] = $body;
                    $this->daemonSets[$name]['metadata']['generation'] = $generation;
                    $this->daemonSets[$name]['metadata']['uid'] = $uid;
                    if (isset($this->onApply[$name])) {
                        ($this->onApply[$name])($body);
                    } elseif ($name === 'kata-kvm-probe') {
                        // the /dev/kvm check: $nodeKvm per node (default: the node has KVM; null: no answer)
                        foreach (array_keys($this->nodes) as $node) {
                            $kvm = array_key_exists($node, $this->nodeKvm) ? $this->nodeKvm[$node] : true;
                            if ($kvm !== null) {
                                $shm = (isset($this->nodeShmMb[$node]) ? " shm_mb={$this->nodeShmMb[$node]}" : '')
                                    .(isset($this->nodeDbus[$node]) ? " dbus={$this->nodeDbus[$node]}" : '');
                                $this->podLogs[$this->podName($name, $node)] = $kvm
                                    ? "runr: kvm probe: kvm=yes dev_kvm=yes cpu=vmx module=kvm_intel{$shm} node={$node}\n"
                                    : "runr: kvm probe: kvm=no dev_kvm=no cpu=none module=none{$shm} node={$node}\n";
                            }
                        }
                    }

                    return Http::response($body);
                }
                if ($method === 'DELETE') {
                    // its pods go with it (GC): a re-created DaemonSet starts with fresh pods
                    unset($this->daemonSets[$name], $this->stalePods[$name]);
                    foreach (array_keys($this->nodes) as $node) {
                        unset($this->podStatuses[$this->podName($name, $node)], $this->podLogs[$this->podName($name, $node)]);
                    }
                    if (isset($this->onDelete[$name])) {
                        ($this->onDelete[$name])();
                    }

                    return Http::response(['kind' => 'Status', 'status' => 'Success']);
                }
                if (! isset($this->daemonSets[$name])) {
                    return Http::response(['kind' => 'Status', 'code' => 404], 404);
                }
                $n = count($this->nodes);
                $ds = $this->daemonSets[$name];
                $ds['status'] = [
                    'observedGeneration' => $ds['metadata']['generation'] ?? 1,
                    'desiredNumberScheduled' => $n,
                    'updatedNumberScheduled' => $this->updatedPods[$name] ?? $n,
                    'numberReady' => $n,
                ];

                return Http::response($ds);
            }

            // ConfigMaps in runr-sandbox
            if (preg_match('#^/api/v1/namespaces/runr-sandbox/configmaps/([^/]+)$#', $path, $m)) {
                if ($method === 'PATCH') {
                    $this->configMaps[$m[1]] = $body;

                    return Http::response($body);
                }
                if ($method === 'DELETE') {
                    unset($this->configMaps[$m[1]]);

                    return Http::response(['kind' => 'Status', 'status' => 'Success']);
                }

                return isset($this->configMaps[$m[1]])
                    ? Http::response($this->configMaps[$m[1]])
                    : Http::response(['kind' => 'Status', 'code' => 404], 404);
            }

            // Pods and pod logs in runr-sandbox
            if ($path === '/api/v1/namespaces/runr-sandbox/pods' && $method === 'GET') {
                $component = $selector['runr.dev/component'] ?? '';

                return Http::response(['items' => array_merge($this->stalePods[$component] ?? [], $this->podsOf($component))]);
            }
            if (preg_match('#^/api/v1/namespaces/runr-sandbox/pods/([^/]+)/log$#', $path, $m)) {
                return Http::response($this->podLogs[$m[1]] ?? '');
            }
            if ($path === '/api/v1/namespaces/runr-sandbox/secrets' && $method === 'GET') {
                return Http::response(['items' => []]);
            }

            // The cluster's pods (sandbox in-use checks)
            if ($path === '/api/v1/pods' && $method === 'GET') {
                return Http::response(['items' => $this->clusterPods]);
            }

            // RuntimeClasses
            if (preg_match('#^/apis/node\.k8s\.io/v1/runtimeclasses/([^/]+)$#', $path, $m)) {
                $name = $m[1];
                if ($method === 'PATCH') {
                    if (isset($this->runtimeClasses[$name]) && ($this->runtimeClasses[$name]['handler'] ?? null) !== ($body['handler'] ?? null)) {
                        return Http::response(['kind' => 'Status', 'code' => 422, 'message' => 'handler: field is immutable'], 422);
                    }
                    $this->runtimeClasses[$name] = $body;

                    return Http::response($body);
                }
                if ($method === 'DELETE') {
                    unset($this->runtimeClasses[$name]);

                    return Http::response(['kind' => 'Status', 'status' => 'Success']);
                }

                return isset($this->runtimeClasses[$name])
                    ? Http::response($this->runtimeClasses[$name])
                    : Http::response(['kind' => 'Status', 'code' => 404], 404);
            }

            return in_array($method, ['PATCH', 'POST', 'PUT', 'DELETE'], true)
                ? Http::response(['kind' => 'Status', 'status' => 'Success'])
                : Http::response(['kind' => 'Status', 'code' => 404], 404);
        });
    }

    /** "METHOD path" calls that change something. */
    protected function writes(): array
    {
        return array_values(array_filter($this->calls, fn ($c) => ! str_starts_with($c, 'GET ')));
    }

    protected function callIndex(string $call): int|false
    {
        return array_search($call, $this->calls, true);
    }
}
