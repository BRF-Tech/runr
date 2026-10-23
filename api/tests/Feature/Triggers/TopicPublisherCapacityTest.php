<?php

namespace Tests\Feature\Triggers;

use App\Services\Helpers\DedicatedWorkers;
use App\Services\KnativeNodeService;
use App\Triggers\Topics\TopicPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTriggerFixtures;
use Tests\TestCase;

/**
 * The region's topic publisher (runr-topics) in the region capacity: a region component, counted
 * like gateway-auth and the internal authz — its pod's requests are subtracted from what the nodes
 * offer projects (KnativeNodeService, refreshed by the status check every minute), never counted as
 * a project's reservation. It tolerates dedicated workers.
 */
class TopicPublisherCapacityTest extends TestCase
{
    use BuildsTriggerFixtures;
    use RefreshDatabase;

    /** The publisher's pod as the cluster lists it once scheduled on $node. */
    private function publisherPod(string $node): array
    {
        $deployment = collect((new TopicPublisher)->manifests($this->triggerRegion()))->firstWhere('kind', 'Deployment');
        $template = $deployment['spec']['template'];

        return [
            'metadata' => ['name' => 'runr-topics-6d9f-abcde', 'labels' => $template['metadata']['labels']],
            'spec' => $template['spec'] + ['nodeName' => $node],
            'status' => ['phase' => 'Running'],
        ];
    }

    public function test_the_publisher_is_a_platform_pod_of_the_region_capacity(): void
    {
        $pod = $this->publisherPod('worker-1');
        $nodes = new KnativeNodeService;

        $this->assertFalse(KnativeNodeService::isRunrWorkload($pod), 'not a project workload: admission does not count it from a row');
        $this->assertSame(['worker-1' => ['cpu_m' => 50, 'memory_bytes' => 64 * 1048576]], $nodes->platformRequestsByNode([$pod]));

        $node = [
            'metadata' => ['name' => 'worker-1', 'labels' => ['kubernetes.io/os' => 'linux']],
            'spec' => [],
            'status' => ['allocatable' => ['cpu' => '2', 'memory' => '4Gi'], 'conditions' => [['type' => 'Ready', 'status' => 'True']]],
        ];
        $capacity = $nodes->schedulableCapacity([$node], [$pod]);
        $this->assertSame(2000 - 50, $capacity['total_cpu_m']);
        $this->assertSame(4096 - 64, $capacity['total_memory_mb']);
    }

    public function test_the_publisher_runs_on_a_cluster_of_dedicated_workers(): void
    {
        $spec = $this->publisherPod('w')['spec'];

        $this->assertTrue(DedicatedWorkers::tolerated($spec['tolerations']));
        $this->assertSame(DedicatedWorkers::preferOtherNodes(), $spec['affinity']);
        $resources = $spec['containers'][0]['resources'];
        $this->assertSame($resources['requests'], $resources['limits']);
    }
}
