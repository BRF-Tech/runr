<?php

namespace Tests\Feature;

use App\Events\PlatformEvent;
use App\Jobs\CheckKnativeStatusJob;
use App\Jobs\CheckMigrationDnsJob;
use App\Models\KnativeRegion;
use App\Services\KnativeNodeService;
use App\Services\KnativeStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The periodic region status check fills what a manual / adopted install leaves empty:
 * knative_serving_version from the KnativeServing CR, and keeps the region capacity equal to
 * what its nodes leave for projects — the schedulable nodes (or the dedicated workers), each
 * with its allocatable minus the requests of the non-project pods on it.
 */
class RegionStatusRefreshTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER = 'https://region.test:6443';

    /** Pods of all namespaces (GET /api/v1/pods), or null to fail that list */
    private ?array $pods = [];

    private function pod(string $node, string $cpu, string $memory, array $labels = [], string $phase = 'Running'): array
    {
        return [
            'metadata' => ['name' => 'p-'.md5($node.$cpu.$memory.json_encode($labels).$phase), 'labels' => $labels],
            'spec' => ['nodeName' => $node, 'containers' => [['name' => 'c', 'resources' => ['requests' => ['cpu' => $cpu, 'memory' => $memory]]]]],
            'status' => ['phase' => $phase],
        ];
    }

    private function platformAndProjectPods(): array
    {
        return [
            $this->pod('master', '2', '4Gi'),                                   // on a node that takes no workloads
            $this->pod('worker-1', '1000m', '1536Mi'),                          // e.g. RunR control plane
            $this->pod('worker-1', '500m', '512Mi'),                            // e.g. observability
            $this->pod('worker-2', '500m', '1Gi'),                              // e.g. Traefik
            $this->pod('worker-2', '4', '8Gi', ['runr/project-id' => '01J9ZPROJECT00000000000000']),
            $this->pod('worker-2', '4', '8Gi', [], 'Succeeded'),
        ];
    }

    private function fakeCluster(): void
    {
        $pods = $this->pods;

        $nodes = [
            [
                'metadata' => ['name' => 'master', 'labels' => ['node-role.kubernetes.io/control-plane' => '']],
                'spec' => ['taints' => [['key' => 'node-role.kubernetes.io/control-plane', 'effect' => 'NoSchedule']]],
                'status' => ['allocatable' => ['cpu' => '4', 'memory' => '8Gi'], 'conditions' => [['type' => 'Ready', 'status' => 'True']]],
            ],
            [
                'metadata' => ['name' => 'worker-1', 'labels' => ['kubernetes.io/os' => 'linux']],
                'spec' => [],
                'status' => ['allocatable' => ['cpu' => '16', 'memory' => '32Gi'], 'conditions' => [['type' => 'Ready', 'status' => 'True']]],
            ],
            [
                'metadata' => ['name' => 'worker-2', 'labels' => ['kubernetes.io/os' => 'linux']],
                'spec' => [],
                'status' => ['allocatable' => ['cpu' => '15500m', 'memory' => '31Gi'], 'conditions' => [['type' => 'Ready', 'status' => 'True']]],
            ],
        ];

        Http::fake(function (Request $request) use ($nodes, $pods) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/version' => Http::response(['gitVersion' => 'v1.34.1']),
                str_contains($path, '/knativeservings/knative-serving') => Http::response([
                    'spec' => ['version' => '1.21'],
                    'status' => ['version' => '1.21.3', 'conditions' => [['type' => 'Ready', 'status' => 'True']]],
                ]),
                str_ends_with($path, '/namespaces/knative-serving/pods') => Http::response(['items' => [['metadata' => ['name' => 'traefik-0'], 'status' => ['phase' => 'Running']]]]),
                str_ends_with($path, '/namespaces/knative-serving/services/traefik') => Http::response(['status' => ['loadBalancer' => ['ingress' => [['ip' => '10.0.0.10']]]]]),
                $path === '/api/v1/nodes' => Http::response(['items' => $nodes]),
                $path === '/api/v1/pods' => $pods === null
                        ? Http::response(['kind' => 'Status', 'code' => 403], 403)
                        : Http::response(['items' => $pods]),
                default => Http::response(['kind' => 'Status', 'code' => 404], 404),
            };
        });
    }

    private function region(array $attributes = []): KnativeRegion
    {
        $region = KnativeRegion::create([
            'name' => 'fn',
            'kubeconfig' => Yaml::dump([
                'apiVersion' => 'v1',
                'kind' => 'Config',
                'current-context' => 'c',
                'clusters' => [['name' => 'c', 'cluster' => ['server' => self::SERVER, 'insecure-skip-tls-verify' => true]]],
                'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
            ], 10, 2),
            'is_active' => true,
        ]);
        $region->forceFill(array_merge([
            'knative_serving_status' => 'ready',
            'knative_operator_status' => 'not_installed',
            'knative_serving_version' => null,
            'selected_workers' => null,
            'total_cpu_m' => 0,
            'total_memory_mb' => 0,
        ], $attributes))->save();

        return $region;
    }

    public function test_status_check_fills_serving_version_and_capacity(): void
    {
        $this->pods = $this->platformAndProjectPods();
        $this->fakeCluster();
        $region = $this->region();

        $result = app(KnativeStatusService::class)->checkStatus($region);

        $this->assertSame('ready', $result['status']);
        $fresh = $region->fresh();
        $this->assertSame('1.21.3', $fresh->knative_serving_version);
        // workers only — the tainted master takes no workloads; per worker: allocatable minus
        // the platform pods (the project pod and the finished pod do not count)
        $this->assertSame((16000 - 1500) + (15500 - 500), $fresh->total_cpu_m);
        $this->assertSame((32 * 1024 - 2048) + (31 * 1024 - 1024), $fresh->total_memory_mb);

        // only unfinished pods are listed, across all namespaces
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), self::SERVER.'/api/v1/pods?')
            && urldecode((string) parse_url($request->url(), PHP_URL_QUERY)) === 'fieldSelector=status.phase!=Succeeded,status.phase!=Failed');
    }

    public function test_a_capacity_measured_on_allocatable_is_corrected(): void
    {
        $this->pods = $this->platformAndProjectPods();
        $this->fakeCluster();
        // what RunR 0.4.0 stored: allocatable of both workers
        $region = $this->region(['total_cpu_m' => 31500, 'total_memory_mb' => 64512]);

        app(KnativeStatusService::class)->checkStatus($region);

        $this->assertSame(29500, $region->fresh()->total_cpu_m);
        $this->assertSame(61440, $region->fresh()->total_memory_mb);
    }

    public function test_known_version_is_kept_and_selected_workers_capacity_follows_their_pods(): void
    {
        $this->pods = $this->platformAndProjectPods();
        $this->fakeCluster();
        $region = $this->region([
            'knative_serving_version' => '1.20.1',
            'selected_workers' => ['worker-1'],
            'total_cpu_m' => 16000,
            'total_memory_mb' => 32768,
        ]);

        app(KnativeStatusService::class)->checkStatus($region);

        $fresh = $region->fresh();
        $this->assertSame('1.20.1', $fresh->knative_serving_version);
        $this->assertSame(16000 - 1500, $fresh->total_cpu_m);
        $this->assertSame(32768 - 2048, $fresh->total_memory_mb);
    }

    public function test_worker_selection_stores_the_workers_capacity_minus_platform_pods(): void
    {
        $this->pods = $this->platformAndProjectPods();
        $this->fakeCluster();
        $region = $this->region();
        $service = app(KnativeNodeService::class);

        // the selection dialog shows what each worker leaves for projects next to its allocatable
        $nodes = collect($service->listWorkerNodes($region))->keyBy('name');
        $this->assertSame(['worker-1', 'worker-2'], $nodes->keys()->all());
        $this->assertSame(16000, $nodes['worker-1']['cpu_m']);
        $this->assertSame(16000 - 1500, $nodes['worker-1']['available_cpu_m']);
        $this->assertSame(32768 - 2048, $nodes['worker-1']['available_memory_mb']);

        $result = $service->applyWorkerSelection($region, ['worker-2']);

        $this->assertSame(['total_cpu_m' => 15500 - 500, 'total_memory_mb' => 31 * 1024 - 1024], $result);
        $this->assertSame(15000, $region->fresh()->total_cpu_m);
        $this->assertSame(['worker-2'], $region->fresh()->selected_workers);
    }

    public function test_capacity_is_kept_when_the_pods_cannot_be_listed(): void
    {
        $this->pods = null;
        $this->fakeCluster();
        $region = $this->region(['total_cpu_m' => 20000, 'total_memory_mb' => 40000]);

        $result = app(KnativeStatusService::class)->checkStatus($region);

        $this->assertSame('ready', $result['status']);
        // node allocatable alone would over-admit again: the known figure stays
        $this->assertSame(20000, $region->fresh()->total_cpu_m);
        $this->assertSame(40000, $region->fresh()->total_memory_mb);
    }

    public function test_a_region_fully_requested_by_platform_pods_admits_nothing(): void
    {
        $this->pods = [
            $this->pod('worker-1', '16', '32Gi'),
            $this->pod('worker-2', '15500m', '31Gi'),
        ];
        $this->fakeCluster();
        $region = $this->region(['total_cpu_m' => 31500, 'total_memory_mb' => 64512]);

        app(KnativeStatusService::class)->checkStatus($region);

        // 1, not 0: a 0 capacity means "unknown" and would skip the admission check
        $this->assertSame(1, $region->fresh()->total_cpu_m);
        $this->assertSame(1, $region->fresh()->total_memory_mb);
    }

    public function test_the_scheduled_check_queues_one_job_per_region_with_a_cluster(): void
    {
        Queue::fake();
        $checked = $this->region();
        $this->region()->forceFill(['name' => 'installing', 'knative_status' => 'installing'])->save();
        $this->region()->forceFill(['name' => 'leaving', 'knative_status' => 'uninstalling'])->save();
        $this->region()->forceFill(['name' => 'demo', 'is_demo' => true])->save();
        $this->region()->forceFill(['name' => 'blank', 'kubeconfig' => null])->save();

        $this->artisan('knative:check-status')->expectsOutput('Dispatched status check for 1 region(s).')->assertSuccessful();

        Queue::assertPushed(CheckKnativeStatusJob::class, 1);
        Queue::assertPushed(CheckKnativeStatusJob::class, fn (CheckKnativeStatusJob $job) => $job->region->is($checked));
    }

    public function test_the_status_job_records_the_check_and_announces_a_serving_change(): void
    {
        $this->pods = $this->platformAndProjectPods();
        $this->fakeCluster();
        Queue::fake();
        Event::fake([PlatformEvent::class]);
        // Serving had failed; its KnativeServing CR is Ready again — and the region is moving
        $region = $this->region(['knative_serving_status' => 'error', 'migration_status' => 'dns_check']);

        (new CheckKnativeStatusJob($region))->handle(app(KnativeStatusService::class));

        $fresh = $region->fresh();
        // the stored check (knative_status as read is computed from the component statuses)
        $this->assertSame('ready', $fresh->getRawOriginal('knative_status'));
        $this->assertSame('Knative is running', $fresh->knative_status_message);
        $this->assertSame('online', $fresh->cluster_status);
        $this->assertSame('ready', $fresh->knative_serving_status);
        $this->assertSame('not_installed', $fresh->knative_operator_status, 'a component that is not installed is not checked');
        $this->assertNotNull($fresh->knative_status_checked_at);
        $this->assertSame('10.0.0.10', $fresh->external_ip);
        $this->assertNull($fresh->dns_resolved, 'no domain to check yet');
        Event::assertDispatched(PlatformEvent::class, fn (PlatformEvent $e) => $e->topic === 'region.updated' && $e->payload['region']['id'] === $region->id);
        Queue::assertPushed(CheckMigrationDnsJob::class, fn (CheckMigrationDnsJob $job) => $job->region->is($region));

        // the next check finds the same: no announcement
        Event::fake([PlatformEvent::class]);
        (new CheckKnativeStatusJob($region->fresh()))->handle(app(KnativeStatusService::class));
        Event::assertNotDispatched(PlatformEvent::class, fn (PlatformEvent $e) => $e->topic === 'region.updated');
    }

    public function test_an_unreachable_cluster_is_recorded(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to region.test port 6443'));
        $region = $this->region();

        (new CheckKnativeStatusJob($region))->handle(app(KnativeStatusService::class));

        $fresh = $region->fresh();
        $this->assertSame('error', $fresh->getRawOriginal('knative_status'));
        $this->assertStringStartsWith('Cannot connect to cluster:', (string) $fresh->knative_status_message);
        $this->assertSame('unreachable', $fresh->cluster_status);
        $this->assertStringContainsString('Failed to connect to region.test', (string) $fresh->cluster_status_message);
    }
}
