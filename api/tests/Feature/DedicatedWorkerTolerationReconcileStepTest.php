<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use App\Services\KnativeNodeService;
use App\Services\KubernetesClient;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\RegionReconciler;
use App\Services\RegionReconcile\Steps\DedicatedWorkerTolerationStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * A component RunR installed with a node-bound volume before its node was selected as a dedicated
 * worker (taint runr.dev/dedicated=projects:NoSchedule) cannot start there again without the
 * taint's toleration: its next pod stays Pending ("untolerated taint" + "volume node affinity
 * conflict"). The worker selection and region:reconcile add the toleration to the registry, Prometheus,
 * Loki, Tempo and the data services whose pods run on a dedicated worker or wait Pending on the
 * taint, and replace such a Pending pod (a StatefulSet does not replace a pod that never became
 * ready by itself).
 */
class DedicatedWorkerTolerationReconcileStepTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER = 'https://workers.test:6443';

    private const TOLERATION = ['key' => 'runr.dev/dedicated', 'operator' => 'Equal', 'value' => 'projects', 'effect' => 'NoSchedule'];

    private const ZOT = '/apis/apps/v1/namespaces/runr-registry/statefulsets/runr-zot';

    private const LOKI = '/apis/apps/v1/namespaces/observability/statefulsets/loki-stack';

    private const TEMPO = '/apis/apps/v1/namespaces/observability/statefulsets/tempo';

    private const PROMETHEUS = '/apis/monitoring.coreos.com/v1/namespaces/observability/prometheuses/knative-kube-prometheus-st-prometheus';

    private const KV = '/apis/apps/v1/namespaces/runr-data/deployments/runr-kv';

    private const GARAGE = '/apis/apps/v1/namespaces/runr-data/statefulsets/runr-objects';

    private const PG = '/apis/postgresql.cnpg.io/v1/namespaces/runr-data/clusters/pg-orders';

    /** @var array<string, array> namespaced objects by API path */
    private array $objects = [];

    /** @var array<string, list<array>> pods by namespace */
    private array $pods = [];

    /** @var list<array> */
    private array $nodes = [];

    /** @var list<array{method: string, path: string, body: mixed}> */
    private array $writes = [];

    /** GETs of a patched StatefulSet that still answer with the previous observedGeneration */
    private int $observeAfter = 0;

    private int $staleGets = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();

        $this->nodes = [
            ['metadata' => ['name' => 'n0'], 'spec' => []],
            ['metadata' => ['name' => 'w1', 'labels' => ['runr.dev/dedicated' => 'projects']], 'spec' => ['taints' => [['key' => 'runr.dev/dedicated', 'value' => 'projects', 'effect' => 'NoSchedule']]]],
        ];
    }

    // ── Fake cluster ────────────────────────────────────────────────────────

    private function fakeCluster(): void
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $selector = [];
            foreach (array_filter(explode(',', (string) ($query['labelSelector'] ?? ''))) as $pair) {
                [$key, $value] = explode('=', $pair, 2);
                $selector[$key] = $value;
            }
            $method = $request->method();
            $notFound = Http::response(['kind' => 'Status', 'code' => 404], 404);

            if ($method !== 'GET') {
                $this->writes[] = ['method' => $method, 'path' => $path, 'body' => json_decode($request->body(), true)];
            }

            if ($path === '/api/v1/nodes') {
                return Http::response(['items' => $this->nodes]);
            }
            if (preg_match('~^/api/v1/nodes/([^/]+)$~', $path, $m)) {
                foreach ($this->nodes as $i => $node) {
                    if ($node['metadata']['name'] === $m[1]) {
                        if ($method === 'PATCH') {
                            // the worker selection's label and taint changes (labels merge, the taint list is replaced)
                            $this->nodes[$i] = self::mergePatch($node, json_decode($request->body(), true));
                        }

                        return Http::response($this->nodes[$i]);
                    }
                }

                return $notFound;
            }
            if ($path === '/api/v1/pods') {
                return Http::response(['items' => array_merge([], ...array_values($this->pods))]);
            }
            if (preg_match('~^/api/v1/namespaces/([^/]+)/pods$~', $path, $m)) {
                return Http::response(['items' => array_values(array_filter($this->pods[$m[1]] ?? [], fn (array $pod) => self::selects($pod, $selector)))]);
            }
            if (preg_match('~^/api/v1/namespaces/([^/]+)/pods/([^/]+)$~', $path, $m) && $method === 'DELETE') {
                $this->pods[$m[1]] = array_values(array_filter($this->pods[$m[1]] ?? [], fn (array $pod) => $pod['metadata']['name'] !== $m[2]));

                return Http::response(['kind' => 'Status']);
            }

            if (isset($this->objects[$path])) {
                if ($method === 'PATCH') {
                    $object = self::mergePatch($this->objects[$path], json_decode($request->body(), true));
                    $object['metadata']['generation'] = ($object['metadata']['generation'] ?? 1) + 1;
                    $this->objects[$path] = $object;
                    $this->staleGets = $this->observeAfter;
                }
                $object = $this->objects[$path];
                if ($method === 'GET' && $this->staleGets > 0) {
                    $this->staleGets--;
                    $object['status']['observedGeneration'] = $object['metadata']['generation'] - 1;
                } else {
                    $object['status']['observedGeneration'] = $object['metadata']['generation'] ?? 1;
                }

                return Http::response($object);
            }

            // collections (Prometheus, CloudNativePG clusters) by label selector
            $items = [];
            foreach ($this->objects as $key => $object) {
                if (str_starts_with($key, "{$path}/") && ! str_contains(substr($key, strlen($path) + 1), '/') && self::selects($object, $selector)) {
                    $items[] = $object;
                }
            }
            if ($method === 'GET' && $items !== []) {
                return Http::response(['items' => $items]);
            }

            return $method === 'GET' ? $notFound : Http::response(['kind' => 'Status']);
        });
    }

    private static function selects(array $object, array $selector): bool
    {
        foreach ($selector as $key => $value) {
            if (($object['metadata']['labels'][$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    /** JSON merge patch (RFC 7386): objects merge, lists and scalars replace, null removes */
    private static function mergePatch(array $target, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if ($value === null) {
                unset($target[$key]);
            } elseif (is_array($value) && $value !== [] && ! array_is_list($value) && is_array($target[$key] ?? null)) {
                $target[$key] = self::mergePatch($target[$key], $value);
            } else {
                $target[$key] = $value;
            }
        }

        return $target;
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /** A workload with a pod template: labels on the object, pod labels and tolerations on the template */
    private function workload(string $path, array $labels = [], array $tolerations = [], array $podLabels = []): void
    {
        $this->objects[$path] = [
            'metadata' => ['name' => basename($path), 'generation' => 1, 'labels' => $labels],
            'spec' => ['template' => ['metadata' => ['labels' => $podLabels], 'spec' => array_filter(['tolerations' => $tolerations, 'containers' => [['name' => 'c']]])]],
        ];
    }

    private function pod(string $ns, string $name, array $labels, ?string $node, array $tolerations = []): void
    {
        $this->pods[$ns][] = [
            'metadata' => ['name' => $name, 'labels' => $labels],
            'spec' => array_filter(['nodeName' => $node, 'tolerations' => $tolerations]),
            'status' => ['phase' => 'Running', 'conditions' => [['type' => 'Ready', 'status' => 'True']]],
        ];
    }

    /** A pod the scheduler cannot place: its volume is bound to the dedicated worker w1 */
    private function pendingPod(string $ns, string $name, array $labels, array $tolerations = []): void
    {
        $this->pods[$ns][] = [
            'metadata' => ['name' => $name, 'labels' => $labels],
            'spec' => array_filter(['tolerations' => $tolerations]),
            'status' => ['phase' => 'Pending', 'conditions' => [[
                'type' => 'PodScheduled', 'status' => 'False', 'reason' => 'Unschedulable',
                'message' => '0/2 nodes are available: 1 node(s) had untolerated taint {runr.dev/dedicated: projects}, 1 node(s) had volume node affinity conflict. preemption: 0/2 nodes are available: 2 Preemption is not helpful for scheduling.',
            ]]],
        ];
    }

    private function region(array $attributes = []): KnativeRegion
    {
        return KnativeRegion::create(array_merge([
            'name' => 'fn',
            'project_namespace' => 'fn',
            'kubeconfig' => Yaml::dump([
                'apiVersion' => 'v1',
                'kind' => 'Config',
                'current-context' => 'c',
                'clusters' => [['name' => 'c', 'cluster' => ['server' => self::SERVER, 'insecure-skip-tls-verify' => true]]],
                'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
            ], 10, 2),
            'is_active' => true,
            'knative_serving_status' => 'ready',
            'registry_mode' => 'internal',
            'registry_status' => 'ready',
            'registry_address' => '10.43.0.5:5000',
            'registry_namespace' => 'runr-registry',
        ], $attributes));
    }

    private function reconcile(KnativeRegion $region, bool $dryRun = false): array
    {
        return (new DedicatedWorkerTolerationStep)->run(new ClusterGroup('uid-w', [new ReconcileTarget($region)], new KubernetesClient($region->kubeconfig)), $dryRun);
    }

    private function tolerations(string $path, array $at = ['spec', 'template', 'spec', 'tolerations']): array
    {
        return data_get($this->objects[$path], $at) ?? [];
    }

    /** @return list<string> "METHOD path" of the writes */
    private function writes(): array
    {
        return array_map(fn (array $w) => "{$w['method']} {$w['path']}", $this->writes);
    }

    private function zot(array $tolerations = []): void
    {
        $this->workload(self::ZOT, ['app.kubernetes.io/instance' => 'runr-zot'], $tolerations);
    }

    // ── Tests ───────────────────────────────────────────────────────────────

    public function test_components_on_a_dedicated_worker_get_the_toleration_and_the_others_are_left_alone(): void
    {
        $gpu = ['key' => 'nvidia.com/gpu', 'operator' => 'Exists', 'effect' => 'NoSchedule'];
        $this->zot();
        $this->pod('runr-registry', 'runr-zot-0', ['app.kubernetes.io/instance' => 'runr-zot'], 'w1');
        $this->workload(self::LOKI, ['release' => 'loki-stack'], [$gpu]);
        $this->pod('observability', 'loki-stack-0', ['app' => 'loki', 'release' => 'loki-stack'], 'w1');
        // on a node that is no dedicated worker: nothing to do
        $this->workload(self::TEMPO, [], [], ['app.kubernetes.io/part-of' => 'runr']);
        $this->pod('observability', 'tempo-0', ['app.kubernetes.io/name' => 'tempo', 'app.kubernetes.io/instance' => 'tempo'], 'n0');
        $this->fakeCluster();
        $region = $this->region();

        $lines = $this->reconcile($region);

        $this->assertSame([
            'added the toleration of runr.dev/dedicated=projects:NoSchedule to StatefulSet runr-registry/runr-zot (pod runr-zot-0 runs on dedicated worker w1 and restarts once, on that node)',
            'added the toleration of runr.dev/dedicated=projects:NoSchedule to StatefulSet observability/loki-stack (pod loki-stack-0 runs on dedicated worker w1 and restarts once, on that node)',
        ], $lines);
        $this->assertSame(['PATCH '.self::ZOT, 'PATCH '.self::LOKI], $this->writes(), 'merge patches only, no pod deleted');
        // only the tolerations of the pod template, the ones there kept
        $this->assertSame(['spec' => ['template' => ['spec' => ['tolerations' => [self::TOLERATION]]]]], $this->writes[0]['body']);
        $this->assertSame([$gpu, self::TOLERATION], $this->tolerations(self::LOKI));
        $this->assertSame([], $this->tolerations(self::TEMPO));
        Sleep::assertNeverSlept();

        // up to date: a second run changes nothing
        $this->writes = [];
        $this->assertSame([], $this->reconcile($region));
        $this->assertSame([], $this->writes);
    }

    public function test_a_registry_pending_on_the_taint_is_healed(): void
    {
        $this->zot();
        $this->pendingPod('runr-registry', 'runr-zot-0', ['app.kubernetes.io/instance' => 'runr-zot']);
        $this->observeAfter = 2;
        $this->fakeCluster();

        $lines = $this->reconcile($this->region());

        $this->assertSame(['added the toleration of runr.dev/dedicated=projects:NoSchedule to StatefulSet runr-registry/runr-zot (pod runr-zot-0 waits Pending on the taint and is replaced)'], $lines);
        $this->assertSame([self::TOLERATION], $this->tolerations(self::ZOT));
        // the pod goes once the StatefulSet controller has the new template: it starts again from it
        $this->assertSame(['PATCH '.self::ZOT, 'DELETE /api/v1/namespaces/runr-registry/pods/runr-zot-0'], $this->writes());
        Sleep::assertSleptTimes(2);
    }

    public function test_a_pod_created_before_the_toleration_is_replaced(): void
    {
        // the template tolerates the taint, the Pending pod is of the template before
        $this->zot([self::TOLERATION]);
        $this->pendingPod('runr-registry', 'runr-zot-0', ['app.kubernetes.io/instance' => 'runr-zot']);
        $this->fakeCluster();
        $region = $this->region();

        $lines = $this->reconcile($region);

        $this->assertSame(['replaced pod(s) runr-zot-0 of StatefulSet runr-registry/runr-zot: Pending on runr.dev/dedicated=projects:NoSchedule, created before StatefulSet runr-zot tolerated it'], $lines);
        $this->assertSame(['DELETE /api/v1/namespaces/runr-registry/pods/runr-zot-0'], $this->writes());

        // a Pending pod that tolerates the taint waits for something else: left alone
        $this->writes = [];
        $this->pendingPod('runr-registry', 'runr-zot-1', ['app.kubernetes.io/instance' => 'runr-zot'], [self::TOLERATION]);
        $this->assertSame([], $this->reconcile($region));
        $this->assertSame([], $this->writes);
    }

    public function test_prometheus_and_the_data_services(): void
    {
        $this->objects[self::PROMETHEUS] = ['metadata' => ['name' => 'knative-kube-prometheus-st-prometheus', 'generation' => 1, 'labels' => ['release' => 'knative']], 'spec' => ['retention' => '15d']];
        $this->pod('observability', 'prometheus-knative-kube-prometheus-st-prometheus-0', ['app.kubernetes.io/name' => 'prometheus', 'app.kubernetes.io/instance' => 'knative-kube-prometheus-st-prometheus'], 'w1');
        // KV Redis: its Deployment replaces the Pending pod itself
        $this->workload(self::KV, ['app.kubernetes.io/managed-by' => 'runr']);
        $this->pendingPod('runr-data', 'runr-kv-7d9f-abc', ['app.kubernetes.io/name' => 'runr-kv']);
        $this->workload(self::GARAGE, ['app.kubernetes.io/managed-by' => 'runr']);
        $this->pod('runr-data', 'runr-objects-0', ['app.kubernetes.io/name' => 'runr-objects'], 'w1');
        // a CloudNativePG cluster: the operator recreates the deleted instance from the patched spec
        $this->objects[self::PG] = ['metadata' => ['name' => 'pg-orders', 'generation' => 1, 'labels' => ['app.kubernetes.io/managed-by' => 'runr']], 'spec' => ['instances' => 1, 'affinity' => ['enablePodAntiAffinity' => true]]];
        $this->pendingPod('runr-data', 'pg-orders-1', ['cnpg.io/cluster' => 'pg-orders', 'cnpg.io/podRole' => 'instance']);
        $this->fakeCluster();

        $lines = $this->reconcile($this->region(['registry_mode' => 'external']));

        $this->assertCount(4, $lines);
        $this->assertStringContainsString('to Prometheus observability/knative-kube-prometheus-st-prometheus (pod prometheus-knative-kube-prometheus-st-prometheus-0 runs on dedicated worker w1', $lines[0]);
        $this->assertSame('added the toleration of runr.dev/dedicated=projects:NoSchedule to Deployment runr-data/runr-kv (pod runr-kv-7d9f-abc waits Pending on the taint)', $lines[1]);
        $this->assertStringContainsString('to StatefulSet runr-data/runr-objects (pod runr-objects-0 runs on dedicated worker w1', $lines[2]);
        $this->assertSame('added the toleration of runr.dev/dedicated=projects:NoSchedule to Cluster runr-data/pg-orders (pod pg-orders-1 waits Pending on the taint and is replaced)', $lines[3]);

        $this->assertSame([self::TOLERATION], $this->objects[self::PROMETHEUS]['spec']['tolerations']);
        $this->assertSame([self::TOLERATION], $this->tolerations(self::KV));
        $this->assertSame([self::TOLERATION], $this->tolerations(self::GARAGE));
        $this->assertSame(['enablePodAntiAffinity' => true, 'tolerations' => [self::TOLERATION]], $this->objects[self::PG]['spec']['affinity']);
        $this->assertSame([
            'PATCH '.self::PROMETHEUS,
            'PATCH '.self::KV,
            'PATCH '.self::GARAGE,
            'PATCH '.self::PG,
            'DELETE /api/v1/namespaces/runr-data/pods/pg-orders-1',
        ], $this->writes());
        // the operator gets a moment to pick the patched cluster up before its pod goes
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 2, 1);
    }

    public function test_components_runr_did_not_install_are_left_alone(): void
    {
        // a Tempo, Loki and KV of the operator's own; Prometheus reused (prometheus_managed off)
        $this->workload(self::TEMPO);
        $this->pod('observability', 'tempo-0', ['app.kubernetes.io/name' => 'tempo', 'app.kubernetes.io/instance' => 'tempo'], 'w1');
        $this->workload(self::LOKI, ['release' => 'grafana-loki']);
        $this->pod('observability', 'loki-stack-0', ['app' => 'loki', 'release' => 'loki-stack'], 'w1');
        $this->workload(self::KV);
        $this->pod('runr-data', 'runr-kv-1', ['app.kubernetes.io/name' => 'runr-kv'], 'w1');
        $this->objects[self::PROMETHEUS] = ['metadata' => ['name' => 'knative-kube-prometheus-st-prometheus', 'labels' => ['release' => 'knative']], 'spec' => []];
        $this->pod('observability', 'prometheus-0', ['app.kubernetes.io/name' => 'prometheus', 'app.kubernetes.io/instance' => 'knative-kube-prometheus-st-prometheus'], 'w1');
        // the S3-backed registry is a Deployment without a volume
        $this->workload('/apis/apps/v1/namespaces/runr-registry/deployments/runr-zot', ['app.kubernetes.io/instance' => 'runr-zot']);
        $this->pod('runr-registry', 'runr-zot-6c8d-x', ['app.kubernetes.io/instance' => 'runr-zot'], 'w1');
        $this->fakeCluster();

        $this->assertSame([], $this->reconcile($this->region(['prometheus_managed' => false])));
        $this->assertSame([], $this->writes);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->zot();
        $this->pendingPod('runr-registry', 'runr-zot-0', ['app.kubernetes.io/instance' => 'runr-zot']);
        $this->fakeCluster();

        $lines = $this->reconcile($this->region(), true);

        $this->assertSame(['would add the toleration of runr.dev/dedicated=projects:NoSchedule to StatefulSet runr-registry/runr-zot (pod runr-zot-0 waits Pending on the taint and would be replaced)'], $lines);
        $this->assertSame([], $this->writes);
    }

    public function test_nothing_to_do_without_dedicated_workers(): void
    {
        $this->nodes = [['metadata' => ['name' => 'n0'], 'spec' => []]];
        $this->zot();
        $this->pod('runr-registry', 'runr-zot-0', ['app.kubernetes.io/instance' => 'runr-zot'], 'n0');
        // installed without dedicated workers: on every node, Knative where it likes
        $this->workload(self::MIRROR, [], [['operator' => 'Exists']]);
        $this->workload(self::activator(), [], []);
        $this->fakeCluster();

        $this->assertSame([], $this->reconcile($this->region()));
        $this->assertSame([], $this->writes);
        // no workload of the components with a node-bound volume is looked at
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/observability/') || str_contains($request->url(), '/runr-data/'));
    }

    // ── Deselected workers ──────────────────────────────────────────────────

    private const MIRROR = '/apis/apps/v1/namespaces/runr-registry/daemonsets/runr-registry-mirror';

    private static function activator(string $ns = 'knative-serving'): string
    {
        return "/apis/apps/v1/namespaces/{$ns}/deployments/activator";
    }

    /** What applyWorkerSelection() / a workersOnly install leave: nodeSelector + toleration of the dedicated workers */
    private function onTheWorkers(string $path): void
    {
        $this->objects[$path]['spec']['template']['spec']['nodeSelector'] = ['runr.dev/dedicated' => 'projects'];
        $this->objects[$path]['spec']['template']['spec']['tolerations'] = [self::TOLERATION];
    }

    /** A pod whose volume is bound to a node that lost the label: no node matches its nodeSelector there */
    private function pendingOnSelector(string $ns, string $name, array $labels): void
    {
        $this->pods[$ns][] = [
            'metadata' => ['name' => $name, 'labels' => $labels],
            'spec' => ['nodeSelector' => ['runr.dev/dedicated' => 'projects'], 'tolerations' => [self::TOLERATION]],
            'status' => ['phase' => 'Pending', 'conditions' => [[
                'type' => 'PodScheduled', 'status' => 'False', 'reason' => 'Unschedulable',
                'message' => "0/2 nodes are available: 1 node(s) didn't match Pod's node affinity/selector, 1 node(s) had volume node affinity conflict. preemption: 0/2 nodes are available: 2 Preemption is not helpful for scheduling.",
            ]]],
        ];
    }

    public function test_without_dedicated_workers_the_registry_its_mirror_and_knative_leave_the_node_selector(): void
    {
        $this->nodes = [['metadata' => ['name' => 'n0'], 'spec' => []], ['metadata' => ['name' => 'n1'], 'spec' => []]];
        $this->zot();
        $this->onTheWorkers(self::ZOT);
        $this->pod('runr-registry', 'runr-zot-0', ['app.kubernetes.io/instance' => 'runr-zot'], 'n1');
        $this->workload(self::MIRROR, ['app.kubernetes.io/managed-by' => 'runr']);
        $this->onTheWorkers(self::MIRROR);
        $this->workload(self::activator());
        $this->onTheWorkers(self::activator());
        $this->workload('/apis/apps/v1/namespaces/knative-serving/deployments/controller');
        $this->fakeCluster();
        $region = $this->region();

        $dry = $this->reconcile($region, true);
        $this->assertCount(3, $dry);
        $this->assertStringStartsWith('would move StatefulSet runr-registry/runr-zot from the dedicated workers to any node', $dry[0]);
        $this->assertStringStartsWith('would apply DaemonSet runr-registry/runr-registry-mirror for every node', $dry[1]);
        $this->assertSame("would drop the nodeSelector runr.dev/dedicated=projects of Knative's activator in knative-serving: no node is a dedicated worker any more", $dry[2]);
        $this->assertSame([], $this->writes);

        $lines = $this->reconcile($region);

        $this->assertSame([
            'moved StatefulSet runr-registry/runr-zot from the dedicated workers to any node (nodeSelector runr.dev/dedicated=projects dropped, the toleration of runr.dev/dedicated=projects:NoSchedule kept, the other nodes preferred): no node is a dedicated worker any more',
            'applied DaemonSet runr-registry/runr-registry-mirror for every node: no node is a dedicated worker any more, projects run on any node and pull from the registry there (a node whose containerd configuration it changes needs the restart its log names)',
            "dropped the nodeSelector runr.dev/dedicated=projects of Knative's activator in knative-serving: no node is a dedicated worker any more",
        ], $lines);

        // Zot: any node, the toleration kept, the other nodes preferred; its pod restarts on its volume's node
        $zot = $this->objects[self::ZOT]['spec']['template']['spec'];
        $this->assertSame([], $zot['nodeSelector']);
        $this->assertSame([self::TOLERATION], $zot['tolerations']);
        $this->assertSame('NotIn', $zot['affinity']['nodeAffinity']['preferredDuringSchedulingIgnoredDuringExecution'][0]['preference']['matchExpressions'][0]['operator']);
        $this->assertSame(['spec' => ['template' => ['spec' => [
            'nodeSelector' => ['runr.dev/dedicated' => null],
            'tolerations' => [self::TOLERATION],
            'affinity' => ['nodeAffinity' => ['preferredDuringSchedulingIgnoredDuringExecution' => [[
                'weight' => 100,
                'preference' => ['matchExpressions' => [['key' => 'runr.dev/dedicated', 'operator' => 'NotIn', 'values' => ['projects']]]],
            ]]]],
        ]]]], $this->writes[0]['body']);

        // the mirror: applied as an install without dedicated workers makes it — every node, every taint
        $applied = $this->writes[1]['body'];
        $this->assertSame('PATCH '.self::MIRROR, $this->writes()[1]);
        $this->assertArrayNotHasKey('nodeSelector', $applied['spec']['template']['spec']);
        $this->assertSame([['operator' => 'Exists']], $applied['spec']['template']['spec']['tolerations']);
        $script = $applied['spec']['template']['spec']['containers'][0]['command'][2];
        $this->assertStringContainsString('CURRENT_IP="10.43.0.5"', $script);
        $this->assertStringContainsString('registry:rolling-restart '.$region->id, $script);
        $this->assertSame([], $this->objects[self::MIRROR]['spec']['template']['spec']['nodeSelector']);

        // Knative: the deployments RunR put on the workers only
        $this->assertSame([], $this->objects[self::activator()]['spec']['template']['spec']['nodeSelector']);
        $this->assertSame([self::TOLERATION], $this->objects[self::activator()]['spec']['template']['spec']['tolerations']);
        $this->assertNotContains('PATCH /apis/apps/v1/namespaces/knative-serving/deployments/controller', $this->writes());
        $this->assertCount(4, $this->writes, 'Zot, the mirror (apply + nodeSelector), activator');

        // done: a second run changes nothing
        $this->writes = [];
        $this->assertSame([], $this->reconcile($region));
        $this->assertSame([], $this->writes);
    }

    /**
     * Zot's volume is on a node that lost the label while other workers remain: the restarted pod
     * waits Pending on the nodeSelector. Zot moves off it and its pod is replaced; the mirror and
     * Knative stay on the remaining workers.
     */
    public function test_a_registry_whose_volume_node_lost_the_label_moves_off_the_node_selector(): void
    {
        $this->nodes = [
            ['metadata' => ['name' => 'n0'], 'spec' => []],
            ['metadata' => ['name' => 'w2', 'labels' => ['runr.dev/dedicated' => 'projects']], 'spec' => ['taints' => [['key' => 'runr.dev/dedicated', 'value' => 'projects', 'effect' => 'NoSchedule']]]],
        ];
        $this->zot();
        $this->onTheWorkers(self::ZOT);
        $this->pendingOnSelector('runr-registry', 'runr-zot-0', ['app.kubernetes.io/instance' => 'runr-zot']);
        $this->workload(self::MIRROR);
        $this->onTheWorkers(self::MIRROR);
        $this->workload(self::activator());
        $this->onTheWorkers(self::activator());
        $this->observeAfter = 1;
        $this->fakeCluster();

        $lines = $this->reconcile($this->region());

        $this->assertSame(['moved StatefulSet runr-registry/runr-zot from the dedicated workers to any node (nodeSelector runr.dev/dedicated=projects dropped, the toleration of runr.dev/dedicated=projects:NoSchedule kept, the other nodes preferred): pod runr-zot-0 waits Pending on the nodeSelector and is replaced'], $lines);
        $this->assertSame(['PATCH '.self::ZOT, 'DELETE /api/v1/namespaces/runr-registry/pods/runr-zot-0'], $this->writes());
        Sleep::assertSleptTimes(1);
        $this->assertSame(['runr.dev/dedicated' => 'projects'], $this->objects[self::MIRROR]['spec']['template']['spec']['nodeSelector'], 'follows the remaining workers');
    }

    public function test_a_registry_running_on_a_node_that_lost_the_label_moves_before_its_next_restart(): void
    {
        $this->nodes[] = ['metadata' => ['name' => 'n9'], 'spec' => []];
        $this->zot();
        $this->onTheWorkers(self::ZOT);
        $this->pod('runr-registry', 'runr-zot-0', ['app.kubernetes.io/instance' => 'runr-zot'], 'n9');
        $this->fakeCluster();

        $lines = $this->reconcile($this->region());

        $this->assertCount(1, $lines);
        $this->assertStringEndsWith('the other nodes preferred): its volume keeps pod runr-zot-0 on n9, no dedicated worker any more', $lines[0]);
        $this->assertSame(['PATCH '.self::ZOT], $this->writes());
    }

    public function test_the_s3_registry_follows_the_workers_while_there_are_any(): void
    {
        $deployment = '/apis/apps/v1/namespaces/runr-registry/deployments/runr-zot';
        $this->workload($deployment, ['app.kubernetes.io/instance' => 'runr-zot']);
        $this->onTheWorkers($deployment);
        $this->pod('runr-registry', 'runr-zot-6c8d-x', ['app.kubernetes.io/instance' => 'runr-zot'], 'n0');
        $this->fakeCluster();
        $region = $this->region();

        // w1 still carries the label: the next pod goes there
        $this->assertSame([], $this->reconcile($region));
        $this->assertSame([], $this->writes);

        // the last worker deselected
        $this->nodes = [['metadata' => ['name' => 'n0'], 'spec' => []]];
        $lines = $this->reconcile($region);
        $this->assertSame(['moved Deployment runr-registry/runr-zot from the dedicated workers to any node (nodeSelector runr.dev/dedicated=projects dropped, the toleration of runr.dev/dedicated=projects:NoSchedule kept, the other nodes preferred): no node is a dedicated worker any more'], $lines);
        $this->assertSame(['PATCH '.$deployment], $this->writes(), 'its Deployment rolls the pod');
    }

    /**
     * Deselecting the last worker: the registry, its node mirror and Knative leave the nodeSelector
     * no node satisfies any more, in the same request.
     */
    public function test_the_worker_selection_releases_the_registry_when_the_last_worker_is_deselected(): void
    {
        $deployment = '/apis/apps/v1/namespaces/runr-registry/deployments/runr-zot';
        $this->workload($deployment, ['app.kubernetes.io/instance' => 'runr-zot']);
        $this->onTheWorkers($deployment);
        $this->workload(self::MIRROR);
        $this->onTheWorkers(self::MIRROR);
        $this->workload(self::activator());
        $this->onTheWorkers(self::activator());
        $this->fakeCluster();
        $region = $this->region(['selected_workers' => ['w1']]);

        app(KnativeNodeService::class)->applyWorkerSelection($region, []);

        $w1 = collect($this->nodes)->firstWhere('metadata.name', 'w1');
        $this->assertArrayNotHasKey('runr.dev/dedicated', $w1['metadata']['labels'] ?? [], 'untainted and unlabelled');
        $this->assertSame([], $this->objects[$deployment]['spec']['template']['spec']['nodeSelector']);
        $this->assertSame([], $this->objects[self::MIRROR]['spec']['template']['spec']['nodeSelector']);
        $this->assertSame([], $this->objects[self::activator()]['spec']['template']['spec']['nodeSelector']);
        $this->assertSame([], $region->fresh()->selected_workers);
    }

    public function test_it_runs_before_the_steps_that_restart_the_registry_loki_and_tempo(): void
    {
        $steps = array_map(fn ($s) => $s->name(), app(RegionReconciler::class)->steps());

        $this->assertSame('dedicated-worker-toleration', $steps[0]);
        foreach (['registry-sync-config', 'loki-deletion', 'tracing', 'image-registry'] as $restarts) {
            $this->assertGreaterThan(0, array_search($restarts, $steps, true), $restarts);
        }
    }

    /**
     * The worker selection taints the nodes and gives the components on them the toleration at
     * once: their pods keep running, the next one starts on the same node.
     */
    public function test_the_worker_selection_adds_the_toleration_on_the_selected_nodes(): void
    {
        $this->nodes = [
            ['metadata' => ['name' => 'n0'], 'spec' => [], 'status' => ['allocatable' => ['cpu' => '4', 'memory' => '8Gi'], 'conditions' => [['type' => 'Ready', 'status' => 'True']]]],
            ['metadata' => ['name' => 'w1'], 'spec' => [], 'status' => ['allocatable' => ['cpu' => '4', 'memory' => '8Gi'], 'conditions' => [['type' => 'Ready', 'status' => 'True']]]],
        ];
        $this->zot();
        $this->pod('runr-registry', 'runr-zot-0', ['app.kubernetes.io/instance' => 'runr-zot'], 'w1');
        $this->workload(self::LOKI, ['release' => 'loki-stack']);
        $this->pod('observability', 'loki-stack-0', ['app' => 'loki', 'release' => 'loki-stack'], 'n0');
        $this->fakeCluster();
        $region = $this->region();

        app(KnativeNodeService::class)->applyWorkerSelection($region, ['w1']);

        $this->assertContains('PATCH /api/v1/nodes/w1', $this->writes(), 'tainted');
        $this->assertSame([self::TOLERATION], $this->tolerations(self::ZOT));
        $this->assertSame([], $this->tolerations(self::LOKI), 'Loki runs on n0');
        $this->assertSame(['w1'], $region->fresh()->selected_workers);
    }
}
