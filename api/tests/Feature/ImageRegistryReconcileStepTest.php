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

    /** @var (\Closure(string, array): bool)|null refuses a PATCH by path and operations */
    private ?\Closure $refuseIf = null;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'PATCH') {
                $this->patches[] = ['path' => $path, 'ops' => json_decode($request->body(), true), 'type' => $request->header('Content-Type')[0] ?? ''];

                $ops = (array) json_decode($request->body(), true);

                return in_array($path, $this->refuse, true) || ($this->refuseIf && ($this->refuseIf)($path, $ops))
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
                $this->redis(),
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
            // the queue Redis's ReplicaSets: the one that runs its pod, and one of an earlier template
            '/apis/apps/v1/namespaces/eu-1/replicasets' => [
                $this->replicaSet('runr-redis-7c9d5f6b8', 1, $this->redis()['spec']['template']),
                $this->replicaSet('runr-redis-55f4c8d7f9', 0, ['metadata' => ['labels' => ['app.kubernetes.io/name' => 'runr-redis']], 'spec' => ['containers' => [['name' => 'redis', 'image' => 'ghcr.io/runrsh/reqs/redis:6-alpine']]]]),
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

    /** The region's queue Redis as KnativeRedisService deploys it (RedisManifests::deployment()), RunR 0.5.0's image. */
    private function redis(array $spec = [], array $annotations = []): array
    {
        return [
            'metadata' => ['name' => 'runr-redis', 'namespace' => 'eu-1', 'uid' => 'uid-redis',
                'labels' => ['app.kubernetes.io/name' => 'runr-redis', 'app.kubernetes.io/managed-by' => 'runr'],
                'annotations' => ['deployment.kubernetes.io/revision' => '2'] + $annotations],
            'spec' => $spec + [
                'replicas' => 1,
                'selector' => ['matchLabels' => ['app.kubernetes.io/name' => 'runr-redis']],
                'template' => [
                    'metadata' => ['labels' => ['app.kubernetes.io/name' => 'runr-redis']],
                    'spec' => ['containers' => [['name' => 'redis', 'image' => 'ghcr.io/runrsh/reqs/redis:7-alpine', 'args' => ['--port', '6379']]]],
                ],
            ],
        ];
    }

    /** A ReplicaSet of the queue Redis's Deployment: its pod template with the controller's hash label. */
    private function replicaSet(string $name, int $replicas, array $template): array
    {
        $template['metadata']['labels']['pod-template-hash'] = substr($name, strlen('runr-redis-'));

        return [
            'metadata' => ['name' => $name, 'namespace' => 'eu-1',
                'ownerReferences' => [['apiVersion' => 'apps/v1', 'kind' => 'Deployment', 'name' => 'runr-redis', 'uid' => 'uid-redis', 'controller' => true]]],
            'spec' => ['replicas' => $replicas, 'template' => $template],
            'status' => ['replicas' => $replicas],
        ];
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

    private const REDIS_MOVED = 'moved Deployment eu-1/runr-redis: ghcr.io/runrsh/reqs/redis:7-alpine → ghcr.io/brf-tech/runr/reqs/redis:7-alpine — '
        .'its pod keeps running (the queue Redis holds its streams and the KV data in memory only): its ReplicaSet takes the moved image first, '
        .'a pod created later starts from it';

    private const PINNED = ' — its pods keep running (update strategy OnDelete: rolling them would re-run the install on every node, '
        ."and Kata's install restarts the container runtime); a pod created later starts from the moved image";

    private const REDIS_PATH = '/apis/apps/v1/namespaces/eu-1/deployments/runr-redis';

    private const REDIS_RS_PATH = '/apis/apps/v1/namespaces/eu-1/replicasets/runr-redis-7c9d5f6b8';

    /** The queue Redis's image: test + replace (the Deployment's and its ReplicaSet's pod template alike). */
    private const REDIS_OPS = [
        ['op' => 'test', 'path' => '/spec/template/spec/containers/0/image', 'value' => 'ghcr.io/runrsh/reqs/redis:7-alpine'],
        ['op' => 'replace', 'path' => '/spec/template/spec/containers/0/image', 'value' => 'ghcr.io/brf-tech/runr/reqs/redis:7-alpine'],
    ];

    private const RESUME_OPS = [
        ['op' => 'test', 'path' => '/metadata/annotations/runr.dev~1image-move-paused', 'value' => 'true'],
        ['op' => 'add', 'path' => '/spec/paused', 'value' => false],
        ['op' => 'remove', 'path' => '/metadata/annotations/runr.dev~1image-move-paused'],
    ];

    public function test_workloads_move_to_ghcr_io_brf_tech_runr_with_one_guarded_patch_each(): void
    {
        $lines = (new ImageRegistryStep)->run($this->cluster(), false);

        $this->assertSame([
            'moved Service eu-1/fn-hello: ghcr.io/runrsh/runr-wrapper:1.1.0 → ghcr.io/brf-tech/runr/wrapper:1.1.0, '
                .'ghcr.io/runrsh/exec-python:3.12 → ghcr.io/brf-tech/runr/exec-python:3.12, ghcr.io/runrsh/reqs/curl:latest → ghcr.io/brf-tech/runr/reqs/curl:latest'
                .' — a new revision takes the traffic once it is ready',
            'moved Deployment observability/runr-agent: ghcr.io/runrsh/runr-agent:latest → ghcr.io/brf-tech/runr/agent:latest — its pods roll once',
            self::REDIS_MOVED,
            'moved Deployment eu-1/runr-queue-consumer: ghcr.io/brf-tech/runr-queue-consumer:latest → ghcr.io/brf-tech/runr/queue-consumer:latest — its pods roll once',
            'moved StatefulSet observability/loki-stack: ghcr.io/runrsh/reqs/busybox:latest → ghcr.io/brf-tech/runr/reqs/busybox:latest — its pods roll once',
            'moved DaemonSet runr-sandbox/kata-installer: ghcr.io/runrsh/reqs/busybox:latest → ghcr.io/brf-tech/runr/reqs/busybox:latest'.self::PINNED,
            'moved DaemonSet kube-system/runr-registry-mirror: ghcr.io/runrsh/reqs/busybox:latest → ghcr.io/brf-tech/runr/reqs/busybox:latest — its pods roll once',
            'moved CronJob eu-1/fn-nightly: ghcr.io/runrsh/runr-wrapper:1.1.0 → ghcr.io/brf-tech/runr/wrapper:1.1.0 — from its next run',
        ], $lines);

        $this->assertSame([
            '/apis/serving.knative.dev/v1/namespaces/eu-1/services/fn-hello',
            '/apis/apps/v1/namespaces/observability/deployments/runr-agent',
            self::REDIS_PATH,
            self::REDIS_RS_PATH,
            self::REDIS_PATH,
            '/apis/apps/v1/namespaces/eu-1/deployments/runr-queue-consumer',
            '/apis/apps/v1/namespaces/observability/statefulsets/loki-stack',
            '/apis/apps/v1/namespaces/runr-sandbox/daemonsets/kata-installer',
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
        ], $this->patches[9]['ops']);
        $this->assertSame([
            ['op' => 'test', 'path' => '/spec/template/spec/containers/0/image', 'value' => 'ghcr.io/brf-tech/runr-queue-consumer:latest'],
            ['op' => 'replace', 'path' => '/spec/template/spec/containers/0/image', 'value' => 'ghcr.io/brf-tech/runr/queue-consumer:latest'],
        ], $this->patches[5]['ops'], 'another package of the organization stays');
        // a node installer: moved with its pods pinned, in the same patch
        $this->assertSame([
            ['op' => 'test', 'path' => '/spec/template/spec/initContainers/0/image', 'value' => 'ghcr.io/runrsh/reqs/busybox:latest'],
            ['op' => 'replace', 'path' => '/spec/template/spec/initContainers/0/image', 'value' => 'ghcr.io/brf-tech/runr/reqs/busybox:latest'],
            ['op' => 'add', 'path' => '/spec/updateStrategy', 'value' => ['type' => 'OnDelete']],
        ], $this->patches[7]['ops']);
        $this->assertSame([
            ['op' => 'test', 'path' => '/spec/template/spec/containers/0/image', 'value' => 'ghcr.io/runrsh/reqs/busybox:latest'],
            ['op' => 'replace', 'path' => '/spec/template/spec/containers/0/image', 'value' => 'ghcr.io/brf-tech/runr/reqs/busybox:latest'],
        ], $this->patches[8]['ops'], 'a DaemonSet outside runr-sandbox rolls');

        // moved: nothing left, nothing reported
        $this->collections = array_map(fn ($items) => array_map(fn ($o) => RegistryMove::value($o), $items), $this->collections);
        $this->patches = [];
        $this->assertSame([], (new ImageRegistryStep)->run($this->cluster(), false));
        $this->assertSame([], $this->patches);
    }

    public function test_the_queue_redis_keeps_its_pod_its_replica_set_moves_while_the_deployment_is_paused(): void
    {
        $this->onlyRedis();

        $this->assertSame([self::REDIS_MOVED], (new ImageRegistryStep)->run($this->cluster(), false));

        // 1. paused (guarded by the images it moves), marked as paused by this step
        // 2. the ReplicaSet that runs the pod: its template, for the pods it creates later
        // 3. the Deployment: the same template, resumed in the same patch — its controller takes that
        //    ReplicaSet as the current one and starts no pod
        $this->assertSame([self::REDIS_PATH, self::REDIS_RS_PATH, self::REDIS_PATH], array_column($this->patches, 'path'));
        $this->assertSame([
            self::REDIS_OPS[0],
            ['op' => 'add', 'path' => '/spec/paused', 'value' => true],
            ['op' => 'add', 'path' => '/metadata/annotations/runr.dev~1image-move-paused', 'value' => 'true'],
        ], $this->patches[0]['ops']);
        $this->assertSame(self::REDIS_OPS, $this->patches[1]['ops']);
        $this->assertSame([...self::REDIS_OPS, ...self::RESUME_OPS], $this->patches[2]['ops']);

        // dry run: read-only, says the same
        $this->patches = [];
        $this->assertSame(['would move'.substr(self::REDIS_MOVED, strlen('moved'))], (new ImageRegistryStep)->run($this->cluster(), true));
        $this->assertSame([], $this->patches);
    }

    public function test_a_queue_redis_without_annotations_gets_the_pause_marker_as_a_map(): void
    {
        $redis = $this->redis();
        unset($redis['metadata']['annotations']);
        $this->onlyRedis($redis);

        (new ImageRegistryStep)->run($this->cluster(), false);

        $this->assertSame(['op' => 'add', 'path' => '/metadata/annotations', 'value' => ['runr.dev/image-move-paused' => 'true']], $this->patches[0]['ops'][2]);
    }

    public function test_a_refused_replica_set_patch_resumes_the_queue_redis_as_it_was(): void
    {
        $this->onlyRedis();
        $this->refuse = [self::REDIS_RS_PATH];

        try {
            (new ImageRegistryStep)->run($this->cluster(), false);
            $this->fail('a refused patch must fail the step');
        } catch (\RuntimeException $e) {
            $this->assertSame('1 workload(s) not moved to ghcr.io/brf-tech/runr (patch refused, see the API log; the next run tries again): '
                .'Deployment eu-1/runr-redis (its ReplicaSet runr-redis-7c9d5f6b8 refused the patch; the Deployment is resumed as it was)', $e->getMessage());
        }
        $this->assertSame([self::REDIS_PATH, self::REDIS_RS_PATH, self::REDIS_PATH], array_column($this->patches, 'path'));
        $this->assertSame([self::REDIS_OPS[0], ...self::RESUME_OPS], $this->patches[2]['ops'], 'its template untouched');
    }

    public function test_a_queue_redis_left_paused_by_a_refused_deployment_patch_is_finished_by_the_next_run(): void
    {
        $this->onlyRedis();
        $this->refuseIf = fn (string $path, array $ops) => $path === self::REDIS_PATH && in_array('replace', array_column($ops, 'op'), true);

        try {
            (new ImageRegistryStep)->run($this->cluster(), false);
            $this->fail('a refused patch must fail the step');
        } catch (\RuntimeException $e) {
            $this->assertStringEndsWith('Deployment eu-1/runr-redis (the Deployment refused the patch; it stays paused (its ReplicaSet '
                .'runr-redis-7c9d5f6b8 is moved) until the next run finishes the move)', $e->getMessage());
        }

        // what the cluster holds now: the Deployment paused by this step, its ReplicaSet moved
        $this->onlyRedis($this->redis(['paused' => true], [ImageRegistryStep::PAUSED_ANNOTATION => 'true']), true);
        $this->refuseIf = null;

        $this->assertSame([self::REDIS_MOVED], (new ImageRegistryStep)->run($this->cluster(), false));
        $this->assertSame([self::REDIS_PATH], array_column($this->patches, 'path'), 'neither a second pause nor a second ReplicaSet patch');
        $this->assertSame([...self::REDIS_OPS, ...self::RESUME_OPS], $this->patches[0]['ops']);
    }

    public function test_a_queue_redis_paused_by_an_earlier_run_whose_template_moved_meanwhile_is_resumed(): void
    {
        $this->onlyRedis(RegistryMove::value($this->redis(['paused' => true], [ImageRegistryStep::PAUSED_ANNOTATION => 'true'])), true);

        $this->assertSame(['would resume Deployment eu-1/runr-redis (paused by an earlier run; its ReplicaSet runr-redis-7c9d5f6b8 is moved)'],
            (new ImageRegistryStep)->run($this->cluster(), true));
        $this->assertSame(['resumed Deployment eu-1/runr-redis (paused by an earlier run; its ReplicaSet runr-redis-7c9d5f6b8 is moved)'],
            (new ImageRegistryStep)->run($this->cluster(), false));
        $this->assertSame([self::REDIS_PATH], array_column($this->patches, 'path'));
        $this->assertSame(self::RESUME_OPS, $this->patches[0]['ops']);
    }

    public function test_the_queue_redis_is_kept_while_paused_by_someone_else_or_during_a_rollout(): void
    {
        $kept = 'kept: Deployment eu-1/runr-redis runs ghcr.io/runrsh/reqs/redis:7-alpine — ';

        // paused, not by this step
        $this->onlyRedis($this->redis(['paused' => true]));
        $this->assertSame([$kept.'the Deployment is paused; it moves once it is resumed'], (new ImageRegistryStep)->run($this->cluster(), false));

        // two ReplicaSets with pods
        $this->onlyRedis();
        $this->collections['/apis/apps/v1/namespaces/eu-1/replicasets'][1]['spec']['replicas'] = 1;
        $this->assertSame([$kept.'2 of its ReplicaSets run pods (a rollout in progress); the next run moves it'], (new ImageRegistryStep)->run($this->cluster(), false));

        // the ReplicaSet with the pod runs another template
        $this->onlyRedis();
        $this->collections['/apis/apps/v1/namespaces/eu-1/replicasets'][0]['spec']['template']['spec']['containers'][0]['args'] = ['--port', '6380'];
        $this->assertSame([$kept.'its ReplicaSet runr-redis-7c9d5f6b8 runs another pod template (a rollout in progress); the next run moves it'],
            (new ImageRegistryStep)->run($this->cluster(), false));

        // … also when this step paused it: the next run, or a resume, rolls it
        $this->onlyRedis($this->redis(['paused' => true], [ImageRegistryStep::PAUSED_ANNOTATION => 'true']));
        $this->collections['/apis/apps/v1/namespaces/eu-1/replicasets'][0]['spec']['template']['spec']['containers'][0]['args'] = ['--port', '6380'];
        $this->assertSame([$kept.'its ReplicaSet runr-redis-7c9d5f6b8 runs another pod template (a rollout in progress), paused by an earlier run: '
            .'`kubectl -n eu-1 rollout resume deployment/runr-redis` rolls it; the next run moves it'], (new ImageRegistryStep)->run($this->cluster(), false));

        $this->assertSame([], $this->patches);
    }

    public function test_a_queue_redis_without_a_running_pod_moves_in_one_patch(): void
    {
        $this->onlyRedis($this->redis(['replicas' => 0]));
        $this->collections['/apis/apps/v1/namespaces/eu-1/replicasets'][0]['spec']['replicas'] = 0;
        $this->collections['/apis/apps/v1/namespaces/eu-1/replicasets'][0]['status']['replicas'] = 0;

        $this->assertSame(['moved Deployment eu-1/runr-redis: ghcr.io/runrsh/reqs/redis:7-alpine → ghcr.io/brf-tech/runr/reqs/redis:7-alpine — no pod runs'],
            (new ImageRegistryStep)->run($this->cluster(), false));
        $this->assertSame([self::REDIS_PATH], array_column($this->patches, 'path'));
        $this->assertSame(self::REDIS_OPS, $this->patches[0]['ops']);
    }

    public function test_the_node_installers_of_gvisor_and_kata_firecracker_keep_their_pods(): void
    {
        $this->collections = array_fill_keys(['/apis/serving.knative.dev/v1/services', '/apis/apps/v1/deployments', '/apis/apps/v1/statefulsets', '/apis/batch/v1/cronjobs'], []);
        $this->collections['/apis/apps/v1/daemonsets'] = [
            $this->workload('gvisor-installer', 'runr-sandbox', ['containers' => [['name' => 'installer', 'image' => 'ghcr.io/runrsh/reqs/alpine:3.21']]]),
            $this->workload('kata-devmapper', 'runr-sandbox', ['containers' => [['name' => 'devmapper', 'image' => 'ghcr.io/brf-tech/reqs/alpine:3.21']]]),
            // installed by this release: nothing to patch
            $this->workload('kata-installer', 'runr-sandbox', ['containers' => [['name' => 'kata-deploy', 'image' => 'quay.io/kata-containers/kata-deploy:3.28.0']]]),
        ];

        $this->assertSame([
            'moved DaemonSet runr-sandbox/gvisor-installer: ghcr.io/runrsh/reqs/alpine:3.21 → ghcr.io/brf-tech/runr/reqs/alpine:3.21'.self::PINNED,
            'moved DaemonSet runr-sandbox/kata-devmapper: ghcr.io/brf-tech/reqs/alpine:3.21 → ghcr.io/brf-tech/runr/reqs/alpine:3.21'.self::PINNED,
        ], (new ImageRegistryStep)->run($this->cluster(), false));
        $this->assertCount(2, $this->patches);
        foreach ($this->patches as $patch) {
            $this->assertSame(['op' => 'add', 'path' => '/spec/updateStrategy', 'value' => ['type' => 'OnDelete']], end($patch['ops']));
        }
    }

    public function test_dry_run_changes_nothing(): void
    {
        $lines = (new ImageRegistryStep)->run($this->cluster(), true);

        $this->assertCount(8, $lines);
        $this->assertStringStartsWith('would move Service eu-1/fn-hello: ghcr.io/runrsh/runr-wrapper:1.1.0 → ghcr.io/brf-tech/runr/wrapper:1.1.0', $lines[0]);
        $this->assertSame('would move'.substr(self::REDIS_MOVED, strlen('moved')), $lines[2]);
        $this->assertSame('would move DaemonSet runr-sandbox/kata-installer: ghcr.io/runrsh/reqs/busybox:latest → ghcr.io/brf-tech/runr/reqs/busybox:latest'.self::PINNED, $lines[5]);
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
        $this->assertCount(10, $this->patches, 'every other workload was patched');
    }

    /** A cluster with the queue Redis only ($movedReplicaSet: its ReplicaSet runs the moved template already). */
    private function onlyRedis(?array $redis = null, bool $movedReplicaSet = false): void
    {
        $redis ??= $this->redis();
        $template = $this->redis()['spec']['template'];
        $this->collections = array_fill_keys(['/apis/serving.knative.dev/v1/services', '/apis/apps/v1/statefulsets', '/apis/apps/v1/daemonsets', '/apis/batch/v1/cronjobs'], []);
        $this->collections['/apis/apps/v1/deployments'] = [$redis];
        $this->collections['/apis/apps/v1/namespaces/eu-1/replicasets'] = [
            $this->replicaSet('runr-redis-7c9d5f6b8', 1, $movedReplicaSet ? RegistryMove::value($template) : $template),
            $this->replicaSet('runr-redis-55f4c8d7f9', 0, ['metadata' => ['labels' => ['app.kubernetes.io/name' => 'runr-redis']], 'spec' => ['containers' => [['name' => 'redis', 'image' => 'ghcr.io/runrsh/reqs/redis:6-alpine']]]]),
            // another Deployment's ReplicaSet with the same labels
            ['metadata' => ['name' => 'other-7f7f7f7f7f', 'namespace' => 'eu-1', 'ownerReferences' => [['kind' => 'Deployment', 'name' => 'other', 'uid' => 'uid-other', 'controller' => true]]],
                'spec' => ['replicas' => 1, 'template' => $template], 'status' => ['replicas' => 1]],
        ];
        $this->patches = [];
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
