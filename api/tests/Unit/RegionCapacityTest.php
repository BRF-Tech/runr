<?php

namespace Tests\Unit;

use App\Services\Helpers\KubeQuantity;
use App\Services\Helpers\ManagedTracing;
use App\Services\KnativeNodeService;
use PHPUnit\Framework\TestCase;

/**
 * Region capacity: the nodes RunR projects run on, each with its allocatable minus what the
 * pods that are not RunR project workloads request there (tenant quotas and admission need a
 * figure the scheduler can actually place).
 */
class RegionCapacityTest extends TestCase
{
    private function node(string $name, string $cpu, string $memory, array $opts = []): array
    {
        return [
            'metadata' => ['name' => $name, 'labels' => $opts['labels'] ?? ['kubernetes.io/os' => 'linux']],
            'spec' => array_filter([
                'taints' => $opts['taints'] ?? null,
                'unschedulable' => $opts['unschedulable'] ?? null,
            ]),
            'status' => [
                'allocatable' => ['cpu' => $cpu, 'memory' => $memory],
                'conditions' => [['type' => 'Ready', 'status' => $opts['ready'] ?? 'True']],
            ],
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $containers  [cpu, memory] requests per container
     */
    private function pod(?string $node, array $containers, array $opts = []): array
    {
        return [
            'metadata' => ['name' => $opts['name'] ?? 'pod', 'labels' => $opts['labels'] ?? []],
            'spec' => array_filter([
                'nodeName' => $node,
                'containers' => array_map(fn (array $r) => ['name' => 'c', 'resources' => ['requests' => ['cpu' => $r[0], 'memory' => $r[1]]]], $containers),
                'initContainers' => $opts['init'] ?? null,
                'overhead' => $opts['overhead'] ?? null,
                'resources' => $opts['resources'] ?? null,
            ]),
            'status' => ['phase' => $opts['phase'] ?? 'Running'],
        ];
    }

    private function mib(int $bytes): int
    {
        return (int) round($bytes / 1048576);
    }

    public function test_capacity_counts_only_nodes_that_take_workloads(): void
    {
        $nodes = [
            // Magnum-style master: NoSchedule taint → not counted
            $this->node('master-0', '4', '7Gi', [
                'labels' => ['node-role.kubernetes.io/control-plane' => '', 'kubernetes.io/os' => 'linux'],
                'taints' => [['key' => 'node-role.kubernetes.io/control-plane', 'effect' => 'NoSchedule']],
            ]),
            $this->node('worker-0', '16', '32748500Ki'),
            $this->node('worker-1', '15800m', '31Gi', ['taints' => [['key' => 'soft', 'effect' => 'PreferNoSchedule']]]),
            $this->node('worker-2', '16', '31Gi', ['unschedulable' => true]),
            $this->node('worker-3', '16', '31Gi', ['ready' => 'False']),
            $this->node('worker-4', '16', '31Gi', ['taints' => [['key' => 'node.kubernetes.io/unreachable', 'effect' => 'NoExecute']]]),
            $this->node('win-0', '8', '16Gi', ['labels' => ['kubernetes.io/os' => 'windows']]),
        ];

        $capacity = (new KnativeNodeService)->schedulableCapacity($nodes);

        $this->assertSame(['worker-0', 'worker-1'], $capacity['nodes']);
        $this->assertSame(16000 + 15800, $capacity['total_cpu_m']);
        $this->assertSame((int) round(32748500 / 1024) + 31 * 1024, $capacity['total_memory_mb']);
    }

    public function test_untainted_single_node_control_plane_counts(): void
    {
        // single-node k3s: the server node is control-plane AND runs the workloads
        $k3s = $this->node('runr-test', '7250m', '14491804Ki', [
            'labels' => ['node-role.kubernetes.io/control-plane' => 'true', 'node-role.kubernetes.io/master' => 'true'],
        ]);

        $capacity = (new KnativeNodeService)->schedulableCapacity([$k3s]);

        $this->assertSame(7250, $capacity['total_cpu_m']);
        $this->assertSame((int) round(14491804 / 1024), $capacity['total_memory_mb']);
    }

    /**
     * The measured OP test VM: single-node k3s, 7250m allocatable, 2715m requested by the
     * platform (Knative, Traefik, RunR control plane, observability, registry…). Scale-from-zero
     * pods waited on "Insufficient cpu" while RunR admitted projects against 7250m.
     */
    public function test_platform_requests_are_subtracted_on_a_single_node_k3s(): void
    {
        $k3s = $this->node('runr-test', '7250m', '14491804Ki', [
            'labels' => ['node-role.kubernetes.io/control-plane' => 'true'],
        ]);

        $project = ['runr/project-id' => '01J9ZPROJECT00000000000000'];
        $pods = [
            // platform: 2715m / 3 GiB in total
            $this->pod('runr-test', [['300m', '60Mi']], ['name' => 'activator']),
            $this->pod('runr-test', [['100m', '100Mi'], ['15m', '28Mi']], ['name' => 'autoscaler']),
            $this->pod('runr-test', [['100m', '512Mi']], ['name' => 'traefik']),
            $this->pod('runr-test', [['1200m', '1Gi']], ['name' => 'runr-api']),
            $this->pod('runr-test', [['1000m', '1348Mi']], ['name' => 'prometheus', 'phase' => 'Pending']), // bound, still starting
            // RunR project workloads: never subtracted (admission reserves them itself)
            $this->pod('runr-test', [['500m', '512Mi'], ['100m', '100Mi']], ['name' => 'orders-00001-deployment', 'labels' => $project + ['runr/version' => '1']]),
            $this->pod('runr-test', [['50m', '64Mi']], ['name' => 'orders-consumer', 'labels' => $project + ['runr/component' => 'queue-consumer']]),
            $this->pod('runr-test', [['250m', '256Mi']], ['name' => 'report-cron-29000', 'labels' => $project + ['runr/version' => '3']]),
            $this->pod('runr-test', [['1000m', '2Gi']], ['name' => 'build-orders-v2-abcde', 'labels' => $project + ['runr/build' => 'true', 'job-name' => 'build-orders-v2']]),
            // finished or not placed: holds nothing on the node
            $this->pod('runr-test', [['2000m', '4Gi']], ['name' => 'old-job', 'phase' => 'Succeeded']),
            $this->pod('runr-test', [['2000m', '4Gi']], ['name' => 'crashed-job', 'phase' => 'Failed']),
            $this->pod(null, [['2000m', '4Gi']], ['name' => 'unschedulable', 'phase' => 'Pending']),
            // another node (not schedulable for projects): irrelevant
            $this->pod('elsewhere', [['3000m', '1Gi']], ['name' => 'other-node']),
        ];

        $service = new KnativeNodeService;
        $capacity = $service->schedulableCapacity([$k3s], $pods);

        $allocMemBytes = 14491804 * 1024;
        $platformMemBytes = (60 + 100 + 28 + 512 + 1024 + 1348) * 1048576;

        $this->assertSame(7250 - 2715, $capacity['total_cpu_m']);
        $this->assertSame($this->mib($allocMemBytes - $platformMemBytes), $capacity['total_memory_mb']);
        $this->assertSame(7250, $capacity['allocatable_cpu_m']);
        $this->assertSame(2715, $capacity['platform_cpu_m']);

        $byNode = $service->platformRequestsByNode($pods);
        $this->assertSame(['runr-test', 'elsewhere'], array_keys($byNode));
        $this->assertSame(2715, $byNode['runr-test']['cpu_m']);
        $this->assertSame($platformMemBytes, $byNode['runr-test']['memory_bytes']);
    }

    public function test_a_node_requested_beyond_its_allocatable_adds_nothing(): void
    {
        $nodes = [$this->node('small', '2', '4Gi'), $this->node('big', '8', '16Gi')];
        $pods = [
            $this->pod('small', [['2500m', '5Gi']]),   // overcommitted by requests
            $this->pod('big', [['1000m', '2Gi']]),
        ];

        $capacity = (new KnativeNodeService)->schedulableCapacity($nodes, $pods);

        $this->assertSame(0 + 7000, $capacity['total_cpu_m']);
        $this->assertSame(0 + 14 * 1024, $capacity['total_memory_mb']);
    }

    public function test_selected_workers_count_their_allocatable_minus_platform_pods(): void
    {
        $nodes = [
            $this->node('master', '4', '8Gi', ['labels' => ['node-role.kubernetes.io/control-plane' => '']]),
            // dedicated workers carry the RunR taint — they count anyway
            $this->node('w1', '16', '32Gi', ['taints' => [['key' => 'runr.dev/dedicated', 'value' => 'projects', 'effect' => 'NoSchedule']]]),
            $this->node('w2', '16', '32Gi', ['taints' => [['key' => 'runr.dev/dedicated', 'value' => 'projects', 'effect' => 'NoSchedule']]]),
            $this->node('w3', '16', '32Gi'),
        ];
        $pods = [
            $this->pod('w1', [['300m', '60Mi']], ['name' => 'activator']),      // Knative moved onto the workers
            $this->pod('w2', [['100m', '128Mi']], ['name' => 'zot-mirror']),   // DaemonSet
            $this->pod('w3', [['4000m', '8Gi']], ['name' => 'not-selected']),
        ];

        // the control-plane node is never a worker, even when its name is passed
        $capacity = (new KnativeNodeService)->selectedCapacity($nodes, ['w1', 'w2', 'master', 'gone'], $pods);

        $this->assertSame(['w1', 'w2'], $capacity['nodes']);
        $this->assertSame(16000 - 300 + 16000 - 100, $capacity['total_cpu_m']);
        $this->assertSame(32768 - 60 + 32768 - 128, $capacity['total_memory_mb']);
    }

    /**
     * The tracing backend (OpenTelemetry Collector, Tempo with its fix-permissions init container,
     * namespace observability) is a platform add-on like Loki: what it requests is not offered to
     * tenants nor to the FaaS budget.
     */
    public function test_the_tracing_backend_is_subtracted_like_the_other_add_ons(): void
    {
        $node = $this->node('runr-test', '4', '8Gi');
        $tracing = ['app.kubernetes.io/part-of' => 'runr'];
        $pods = [
            $this->pod('runr-test', [['100m', '128Mi']], ['name' => 'loki-stack-0', 'labels' => ['app' => 'loki']]),
            $this->pod('runr-test', [array_values(ManagedTracing::COLLECTOR_REQUESTS)], [
                'name' => 'otel-collector-6d9f', 'labels' => $tracing + ['app.kubernetes.io/name' => 'opentelemetry-collector'],
            ]),
            $this->pod('runr-test', [array_values(ManagedTracing::TEMPO_REQUESTS)], [
                'name' => 'tempo-0', 'labels' => $tracing + ['app.kubernetes.io/name' => 'tempo'],
                'init' => [['name' => 'fix-permissions', 'resources' => ['requests' => ['cpu' => '10m', 'memory' => '16Mi']]]],
            ]),
        ];

        $capacity = (new KnativeNodeService)->schedulableCapacity([$node], $pods);

        $this->assertSame(100 + 50 + 50, $capacity['platform_cpu_m']);
        $this->assertSame(4000 - 200, $capacity['total_cpu_m']);
        $this->assertSame(8192 - 128 - 128 - 256, $capacity['total_memory_mb']);
    }

    public function test_pod_requests_follow_the_scheduler(): void
    {
        // containers add up; a regular init container counts when it is larger; overhead adds
        $pod = $this->pod('n', [['100m', '64Mi'], ['200m', '128Mi']], [
            'init' => [['name' => 'init', 'resources' => ['requests' => ['cpu' => '500m', 'memory' => '32Mi']]]],
            'overhead' => ['cpu' => '250m', 'memory' => '120Mi'],
        ]);
        $this->assertSame(['cpu_m' => 750, 'memory_bytes' => (192 + 120) * 1048576], KnativeNodeService::podRequests($pod));

        // a sidecar (init container with restartPolicy Always) runs alongside the containers and
        // under every later init container
        $pod = $this->pod('n', [['100m', '100Mi']], ['init' => [
            ['name' => 'proxy', 'restartPolicy' => 'Always', 'resources' => ['requests' => ['cpu' => '50m', 'memory' => '50Mi']]],
            ['name' => 'migrate', 'resources' => ['requests' => ['cpu' => '400m', 'memory' => '10Mi']]],
        ]]);
        $this->assertSame(['cpu_m' => 450, 'memory_bytes' => 150 * 1048576], KnativeNodeService::podRequests($pod));

        // pod-level resources replace the container sum
        $pod = $this->pod('n', [['100m', '100Mi']], ['resources' => ['requests' => ['cpu' => '2', 'memory' => '1Gi']]]);
        $this->assertSame(['cpu_m' => 2000, 'memory_bytes' => 1073741824], KnativeNodeService::podRequests($pod));

        // BestEffort: nothing requested
        $this->assertSame(['cpu_m' => 0, 'memory_bytes' => 0], KnativeNodeService::podRequests(['spec' => ['containers' => [['name' => 'c']]]]));
    }

    public function test_project_workloads_are_recognised_by_the_project_label(): void
    {
        $this->assertTrue(KnativeNodeService::isProjectWorkload(['metadata' => ['labels' => ['runr/project-id' => '01J9']]]));
        $this->assertFalse(KnativeNodeService::isProjectWorkload(['metadata' => ['labels' => ['runr/project-id' => '']]]));
        $this->assertFalse(KnativeNodeService::isProjectWorkload(['metadata' => ['labels' => ['app' => 'traefik']]]));
        $this->assertFalse(KnativeNodeService::isProjectWorkload(['metadata' => []]));
    }

    public function test_kubernetes_quantities(): void
    {
        $this->assertSame(100, KubeQuantity::millicores('100m'));
        $this->assertSame(100, KubeQuantity::millicores('0.1'));
        $this->assertSame(1500, KubeQuantity::millicores('1.5'));
        $this->assertSame(2000, KubeQuantity::millicores(2));
        $this->assertSame(1, KubeQuantity::millicores('0.0001'));        // rounds up like Kubernetes
        $this->assertSame(0, KubeQuantity::millicores(null));

        $this->assertSame(134217728, KubeQuantity::bytes('128Mi'));
        $this->assertSame(1610612736, KubeQuantity::bytes('1.5Gi'));
        $this->assertSame(65536, KubeQuantity::bytes('64Ki'));
        $this->assertSame(1000000000, KubeQuantity::bytes('1G'));
        $this->assertSame(500000000, KubeQuantity::bytes('500M'));
        $this->assertSame(1000000000, KubeQuantity::bytes('1e9'));
        $this->assertSame(12345, KubeQuantity::bytes('12345'));
        $this->assertSame(1288490189, KubeQuantity::bytes('1288490188800m'));
        $this->assertSame(0, KubeQuantity::bytes(''));
        $this->assertSame(0, KubeQuantity::bytes('lots'));

        $this->assertSame(954, KubeQuantity::toMib(KubeQuantity::bytes('1G')));
    }
}
