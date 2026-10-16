<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use App\Services\KubernetesClient;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\RegionReconciler;
use App\Services\RegionReconcile\Steps\ImageRegistryStep;
use App\Support\Images\RegistryMove;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * region:reconcile moves the workloads RunR deployed before 0.5.2 to RunR's image namespace
 * (ImageRegistryStep): one guarded JSON patch per workload whose pod template runs an image of
 * ghcr.io/runrsh/ or one of RunR's packages directly under ghcr.io/brf-tech/; what other steps own
 * or what must keep its pods is left alone.
 */
class ImageRegistryReconcileStepTest extends TestCase
{
    use RefreshDatabase;

    /** collection path → items */
    private array $collections = [];

    /** group version → kinds it serves (missing: 404) */
    private array $served = ['serving.knative.dev/v1' => ['Service', 'Revision']];

    /** @var list<array{path: string, ops: array, type: string}> */
    private array $patches = [];

    /** paths whose PATCH is refused */
    private array $refuse = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'PATCH') {
                $this->patches[] = ['path' => $path, 'ops' => json_decode($request->body(), true), 'type' => $request->header('Content-Type')[0] ?? ''];

                return in_array($path, $this->refuse, true)
                    ? Http::response(['kind' => 'Status', 'message' => 'the test operation failed'], 422)
                    : Http::response(['kind' => 'Status'], 200);
            }
            foreach ($this->served as $groupVersion => $kinds) {
                if ($path === "/apis/{$groupVersion}") {
                    return Http::response(['resources' => array_map(fn ($k) => ['kind' => $k, 'name' => strtolower($k).($k === 'Prometheus' ? 'es' : 's')], $kinds)]);
                }
            }
            if (array_key_exists($path, $this->collections)) {
                return Http::response(['items' => $this->collections[$path]]);
            }

            return Http::response(['kind' => 'Status', 'code' => 404], 404);
        });

        $this->collections = [
            '/apis/serving.knative.dev/v1/services' => [
                $this->workload('fn-hello', 'eu-1', [
                    'initContainers' => [['name' => 'runr-wrapper', 'image' => 'ghcr.io/runrsh/runr-wrapper:1.1.0']],
                    'containers' => [['name' => 'user-container', 'image' => 'ghcr.io/runrsh/exec-python:3.12',
                        'env' => [['name' => 'TOOLS_IMAGE', 'value' => 'ghcr.io/runrsh/reqs/curl:latest'], ['name' => 'PLAIN', 'value' => 'x'], ['name' => 'FROM_SECRET', 'valueFrom' => ['secretKeyRef' => ['name' => 's', 'key' => 'k']]]]]],
                ]),
                $this->workload('api', 'eu-1', ['containers' => [['image' => 'registry.example.com/team/api:1']]]),
            ],
            '/apis/apps/v1/deployments' => [
                $this->workload('runr-agent', 'observability', ['containers' => [['name' => 'agent', 'image' => 'ghcr.io/runrsh/runr-agent:latest']]]),
                $this->workload('runr-redis', 'eu-1', ['containers' => [['image' => 'ghcr.io/runrsh/reqs/redis:7-alpine']]], ['labels' => ['app.kubernetes.io/name' => 'runr-redis']]),
                $this->workload('traefik', 'knative-serving', ['containers' => [['name' => 'traefik', 'image' => 'ghcr.io/runrsh/reqs/traefik:v3.7.4']]]),
                $this->workload('fn-hello-00001-deployment', 'eu-1', ['containers' => [['image' => 'ghcr.io/runrsh/exec-python:3.12']]],
                    ['ownerReferences' => [['kind' => 'Revision', 'name' => 'fn-hello-00001', 'controller' => true]]]),
                $this->workload('runr-api', 'runr', ['containers' => [['image' => 'ghcr.io/runrsh/runr/api:0.5.0']]], ['labels' => ['helm.sh/chart' => 'runr-0.5.0']]),
                $this->workload('builder', 'eu-1', ['containers' => [['image' => '10.43.0.5:5000/ghcr.io/runrsh/exec-go:1.26']]]),
                // RunR 0.5.1: its packages directly under ghcr.io/brf-tech; another package of the organization stays
                $this->workload('runr-queue-consumer', 'eu-1', ['containers' => [
                    ['name' => 'consumer', 'image' => 'ghcr.io/brf-tech/runr-queue-consumer:latest'],
                    ['name' => 'sidecar', 'image' => 'ghcr.io/brf-tech/other-app:1'],
                ]]),
            ],
            '/apis/apps/v1/statefulsets' => [
                $this->workload('loki-stack', 'observability', [
                    'initContainers' => [['name' => 'fix-permissions', 'image' => 'ghcr.io/runrsh/reqs/busybox:latest']],
                    'containers' => [['name' => 'loki', 'image' => 'grafana/loki:2.9.3']],
                ]),
            ],
            '/apis/apps/v1/daemonsets' => [
                $this->workload('wasm-installer', 'runr-sandbox', ['initContainers' => [['image' => 'ghcr.io/runrsh/containerd-shim-spin:v0.26.0-runr.1']]]),
                $this->workload('kata-installer', 'runr-sandbox', ['initContainers' => [['image' => 'ghcr.io/runrsh/reqs/busybox:latest']]]),
                $this->workload('runr-registry-mirror', 'kube-system', ['containers' => [['name' => 'mirror-config', 'image' => 'ghcr.io/runrsh/reqs/busybox:latest']]]),
            ],
            '/apis/batch/v1/cronjobs' => [[
                'metadata' => ['name' => 'fn-nightly', 'namespace' => 'eu-1'],
                'spec' => ['jobTemplate' => ['spec' => ['template' => ['spec' => [
                    'initContainers' => [['image' => 'ghcr.io/runrsh/runr-wrapper:1.1.0']],
                    'containers' => [['image' => '10.43.0.5:5000/runr-builds/fn-nightly:v3']],
                ]]]]],
            ]],
        ];
    }

    private function workload(string $name, string $namespace, array $podSpec, array $meta = []): array
    {
        return ['metadata' => ['name' => $name, 'namespace' => $namespace] + $meta, 'spec' => ['template' => ['spec' => $podSpec]]];
    }

    private ?ClusterGroup $cluster = null;

    private function cluster(): ClusterGroup
    {
        if ($this->cluster) {
            return $this->cluster;
        }

        $region = KnativeRegion::create([
            'name' => 'eu-1',
            'project_namespace' => 'eu-1',
            'kubeconfig' => Yaml::dump([
                'apiVersion' => 'v1',
                'kind' => 'Config',
                'current-context' => 'c',
                'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://cluster.test:6443', 'insecure-skip-tls-verify' => true]]],
                'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
            ], 10, 2),
            'is_active' => true,
            'knative_serving_status' => 'ready',
        ]);

        return $this->cluster = new ClusterGroup('uid-c', [new ReconcileTarget($region)], new KubernetesClient($region->kubeconfig));
    }

    private const KEPT = [
        "kept: Deployment eu-1/runr-redis runs ghcr.io/runrsh/reqs/redis:7-alpine — the region's queue Redis keeps its streams in memory only "
            .'(a restart drops queued messages); it moves to ghcr.io/brf-tech/runr when it is installed again',
        'kept: DaemonSet runr-sandbox/kata-installer runs ghcr.io/runrsh/reqs/busybox:latest — node installer of a sandbox runtime: rolling it '
            ."re-runs the install on every node (Kata: kata-deploy's cleanup restarts the container runtime); it moves to ghcr.io/brf-tech/runr "
            .'with the next change of that installation',
    ];

    public function test_workloads_move_to_ghcr_io_brf_tech_runr_with_one_guarded_patch_each(): void
    {
        $lines = (new ImageRegistryStep)->run($this->cluster(), false);

        $this->assertSame([
            'moved Service eu-1/fn-hello: ghcr.io/runrsh/runr-wrapper:1.1.0 → ghcr.io/brf-tech/runr/wrapper:1.1.0, '
                .'ghcr.io/runrsh/exec-python:3.12 → ghcr.io/brf-tech/runr/exec-python:3.12, ghcr.io/runrsh/reqs/curl:latest → ghcr.io/brf-tech/runr/reqs/curl:latest'
                .' — a new revision takes the traffic once it is ready',
            'moved Deployment observability/runr-agent: ghcr.io/runrsh/runr-agent:latest → ghcr.io/brf-tech/runr/agent:latest — its pods roll once',
            self::KEPT[0],
            'moved Deployment eu-1/runr-queue-consumer: ghcr.io/brf-tech/runr-queue-consumer:latest → ghcr.io/brf-tech/runr/queue-consumer:latest — its pods roll once',
            'moved StatefulSet observability/loki-stack: ghcr.io/runrsh/reqs/busybox:latest → ghcr.io/brf-tech/runr/reqs/busybox:latest — its pods roll once',
            self::KEPT[1],
            'moved DaemonSet kube-system/runr-registry-mirror: ghcr.io/runrsh/reqs/busybox:latest → ghcr.io/brf-tech/runr/reqs/busybox:latest — its pods roll once',
            'moved CronJob eu-1/fn-nightly: ghcr.io/runrsh/runr-wrapper:1.1.0 → ghcr.io/brf-tech/runr/wrapper:1.1.0 — from its next run',
        ], $lines);

        $this->assertSame([
            '/apis/serving.knative.dev/v1/namespaces/eu-1/services/fn-hello',
            '/apis/apps/v1/namespaces/observability/deployments/runr-agent',
            '/apis/apps/v1/namespaces/eu-1/deployments/runr-queue-consumer',
            '/apis/apps/v1/namespaces/observability/statefulsets/loki-stack',
            '/apis/apps/v1/namespaces/kube-system/daemonsets/runr-registry-mirror',
            '/apis/batch/v1/namespaces/eu-1/cronjobs/fn-nightly',
        ], array_column($this->patches, 'path'));
        $this->assertSame('application/json-patch+json', $this->patches[0]['type']);
        $this->assertSame([
            ['op' => 'test', 'path' => '/spec/template/spec/initContainers/0/image', 'value' => 'ghcr.io/runrsh/runr-wrapper:1.1.0'],
            ['op' => 'replace', 'path' => '/spec/template/spec/initContainers/0/image', 'value' => 'ghcr.io/brf-tech/runr/wrapper:1.1.0'],
            ['op' => 'test', 'path' => '/spec/template/spec/containers/0/image', 'value' => 'ghcr.io/runrsh/exec-python:3.12'],
            ['op' => 'replace', 'path' => '/spec/template/spec/containers/0/image', 'value' => 'ghcr.io/brf-tech/runr/exec-python:3.12'],
            ['op' => 'test', 'path' => '/spec/template/spec/containers/0/env/0/value', 'value' => 'ghcr.io/runrsh/reqs/curl:latest'],
            ['op' => 'replace', 'path' => '/spec/template/spec/containers/0/env/0/value', 'value' => 'ghcr.io/brf-tech/runr/reqs/curl:latest'],
        ], $this->patches[0]['ops']);
        $this->assertSame([
            ['op' => 'test', 'path' => '/spec/jobTemplate/spec/template/spec/initContainers/0/image', 'value' => 'ghcr.io/runrsh/runr-wrapper:1.1.0'],
            ['op' => 'replace', 'path' => '/spec/jobTemplate/spec/template/spec/initContainers/0/image', 'value' => 'ghcr.io/brf-tech/runr/wrapper:1.1.0'],
        ], $this->patches[5]['ops']);
        $this->assertSame([
            ['op' => 'test', 'path' => '/spec/template/spec/containers/0/image', 'value' => 'ghcr.io/brf-tech/runr-queue-consumer:latest'],
            ['op' => 'replace', 'path' => '/spec/template/spec/containers/0/image', 'value' => 'ghcr.io/brf-tech/runr/queue-consumer:latest'],
        ], $this->patches[2]['ops'], 'another package of the organization stays');

        // moved: only what keeps its pods is reported again
        $this->collections = array_map(fn ($items) => array_map(function ($o) {
            $kept = ($o['metadata']['labels']['app.kubernetes.io/name'] ?? null) === 'runr-redis' || $o['metadata']['name'] === 'kata-installer';

            return $kept ? $o : RegistryMove::value($o);
        }, $items), $this->collections);
        $this->patches = [];
        $this->assertSame(self::KEPT, (new ImageRegistryStep)->run($this->cluster(), false));
        $this->assertSame([], $this->patches);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $lines = (new ImageRegistryStep)->run($this->cluster(), true);

        $this->assertCount(8, $lines);
        $this->assertStringStartsWith('would move Service eu-1/fn-hello: ghcr.io/runrsh/runr-wrapper:1.1.0 → ghcr.io/brf-tech/runr/wrapper:1.1.0', $lines[0]);
        $this->assertSame(self::KEPT[0], $lines[2]);
        $this->assertSame([], $this->patches);
    }

    public function test_a_refused_patch_fails_the_step_after_the_others_moved(): void
    {
        $this->refuse = ['/apis/apps/v1/namespaces/observability/deployments/runr-agent'];

        try {
            (new ImageRegistryStep)->run($this->cluster(), false);
            $this->fail('a refused patch must fail the step');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('1 workload(s) not moved to ghcr.io/brf-tech/runr (patch refused, see the API log; the next run tries again): Deployment observability/runr-agent — done: moved Service eu-1/fn-hello', $e->getMessage());
        }
        $this->assertCount(6, $this->patches, 'every other workload was patched');
    }

    public function test_prometheus_of_the_operator_moves_through_its_resource(): void
    {
        $this->served['monitoring.coreos.com/v1'] = ['Prometheus', 'ServiceMonitor'];
        $this->collections = ['/apis/monitoring.coreos.com/v1/prometheuses' => [[
            'metadata' => ['name' => 'kps-prometheus', 'namespace' => 'monitoring'],
            'spec' => ['initContainers' => [['name' => 'fix-permissions', 'image' => 'ghcr.io/runrsh/reqs/busybox:latest']]],
        ]]] + array_fill_keys(['/apis/serving.knative.dev/v1/services', '/apis/apps/v1/deployments', '/apis/apps/v1/statefulsets', '/apis/apps/v1/daemonsets', '/apis/batch/v1/cronjobs'], []);

        $lines = (new ImageRegistryStep)->run($this->cluster(), false);

        $this->assertSame(['moved Prometheus monitoring/kps-prometheus: ghcr.io/runrsh/reqs/busybox:latest → ghcr.io/brf-tech/runr/reqs/busybox:latest — the operator rolls it once'], $lines);
        $this->assertSame('/apis/monitoring.coreos.com/v1/namespaces/monitoring/prometheuses/kps-prometheus', $this->patches[0]['path']);
        $this->assertSame('/spec/initContainers/0/image', $this->patches[0]['ops'][1]['path']);
    }

    public function test_a_cluster_without_knative_and_a_failing_list(): void
    {
        $this->served = [];
        unset($this->collections['/apis/serving.knative.dev/v1/services']);
        $this->assertCount(7, (new ImageRegistryStep)->run($this->cluster(), true), 'no Knative Services to list');

        unset($this->collections['/apis/apps/v1/statefulsets']);
        $this->expectExceptionMessage('cannot list StatefulSets (apps/v1)');
        (new ImageRegistryStep)->run($this->cluster(), true);
    }

    public function test_the_admission_policy_is_synced_before_the_workloads_move(): void
    {
        $steps = array_map(fn ($s) => $s->name(), app(RegionReconciler::class)->steps());

        // registry-prewarm follows: its Job asks the registry this step may roll
        $this->assertSame(['admission-policy', 'image-registry', 'registry-prewarm'], array_slice($steps, -3));
        $this->assertLessThan(array_search('image-registry', $steps), array_search('traefik-image', $steps));
        $this->assertLessThan(array_search('image-registry', $steps), array_search('wasm-sandbox', $steps));
        $this->assertLessThan(array_search('image-registry', $steps), array_search('kata-sandbox', $steps));
    }
}
