<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Gpu\GpuInventory;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\BuildsAiFixtures;

/**
 * GPU node detection from Node items: the device plugin's allocatable nvidia.com/gpu, GPU Feature
 * Discovery labels (product, memory, count, replicas, compute capability, driver / CUDA),
 * time-slicing, NVIDIA hardware without a device plugin (NFD PCI label), and allocation from
 * the pods per node and per RunR owner.
 */
class GpuInventoryTest extends TestCase
{
    use BuildsAiFixtures;

    private function inventory(): GpuInventory
    {
        return new GpuInventory;
    }

    public function test_a_gpu_feature_discovery_node_is_described(): void
    {
        $entry = $this->inventory()->nodeEntry($this->gpuNode('gpu-a', 2));

        $this->assertSame('gpu-a', $entry['name']);
        $this->assertTrue($entry['ready']);
        $this->assertSame('NVIDIA-L4', $entry['product']);
        $this->assertSame(23034, $entry['memory_mb']);
        $this->assertSame(2, $entry['count']);
        $this->assertSame(2, $entry['allocatable']);
        $this->assertSame(1, $entry['replicas']);
        $this->assertSame('8.9', $entry['compute_capability']);
        $this->assertSame('550.54.15', $entry['driver_version']);
        $this->assertSame('12.4', $entry['cuda_version']);
        $this->assertTrue($entry['has_device_plugin']);
        $this->assertTrue($entry['hardware_detected']);
    }

    public function test_time_slicing_offers_every_gpu_replicas_times(): void
    {
        $node = $this->gpuNode('shared', 8, [GpuInventory::LABEL_REPLICAS => '4']);
        unset($node['metadata']['labels'][GpuInventory::LABEL_COUNT]);

        $entry = $this->inventory()->nodeEntry($node);

        $this->assertSame(8, $entry['allocatable'], 'schedulable GPU units');
        $this->assertSame(4, $entry['replicas']);
        $this->assertSame(2, $entry['count'], 'physical GPUs = capacity / replicas');
    }

    public function test_nvidia_hardware_without_a_device_plugin_is_reported(): void
    {
        $node = $this->cpuNode('raw-gpu');
        $node['metadata']['labels'][GpuInventory::LABEL_PCI] = 'true';

        $inventory = $this->inventory()->fromNodes([$node, $this->cpuNode('cpu-only')]);

        $this->assertCount(1, $inventory['nodes'], 'CPU-only nodes are not GPU nodes');
        $this->assertFalse($inventory['nodes'][0]['has_device_plugin']);
        $this->assertTrue($inventory['nodes'][0]['hardware_detected']);
        $this->assertSame(['raw-gpu'], $inventory['summary']['hardware_without_plugin']);
        $this->assertSame(0, $inventory['summary']['allocatable']);
        $this->assertSame(0, $inventory['summary']['gpu_nodes']);
    }

    public function test_cpu_nodes_are_ignored(): void
    {
        $this->assertNull($this->inventory()->nodeEntry($this->cpuNode('cpu-1')));
    }

    public function test_allocation_per_node_and_owner(): void
    {
        $pods = [
            $this->gpuPod('fn-1', 'gpu-a', 1, ['runr/project-id' => '01PROJECT', 'runr/tenant' => 'acme']),
            $this->gpuPod('m-1', 'gpu-a', 1, ['runr.sh/model-service' => '01SERVICE']),
            $this->gpuPod('other', 'gpu-b', 1),
            $this->gpuPod('done', 'gpu-b', 1, [], 'Succeeded'),
            array_replace_recursive($this->gpuPod('pending', 'gpu-b', 1), ['spec' => ['nodeName' => '']]),
        ];

        $allocation = $this->inventory()->allocation($pods);

        $this->assertSame(3, $allocation['total'], 'finished and unbound pods hold no GPU');
        $this->assertSame(['gpu-a' => 2, 'gpu-b' => 1], $allocation['by_node']);

        $owners = array_column($allocation['workloads'], 'owner', 'pod');
        $this->assertSame(['fn-1' => 'project', 'm-1' => 'model', 'other' => 'other'], $owners);
        $this->assertSame('acme', $allocation['workloads'][0]['tenant']);
    }

    public function test_pod_gpus_count_containers_and_the_largest_init_container(): void
    {
        $pod = $this->gpuPod('p', 'gpu-a', 1);
        $pod['spec']['containers'][] = ['name' => 'side', 'resources' => ['limits' => [GpuInventory::RESOURCE => '1']]];
        $this->assertSame(2, GpuInventory::podGpus($pod));

        $pod['spec']['initContainers'] = [['name' => 'warm', 'resources' => ['limits' => [GpuInventory::RESOURCE => '3']]]];
        $this->assertSame(3, GpuInventory::podGpus($pod));

        $shared = ['spec' => ['containers' => [['resources' => ['limits' => [GpuInventory::SHARED_RESOURCE => '1']]]]]];
        $this->assertSame(1, GpuInventory::podGpus($shared), 'renamed shared resource counts too');
    }

    public function test_summary_groups_products_and_subtracts_allocation(): void
    {
        $nodes = [
            $this->gpuNode('l4-a', 2),
            $this->gpuNode('l4-b', 1),
            $this->gpuNode('a100', 4, [GpuInventory::LABEL_PRODUCT => 'NVIDIA-A100-SXM4-80GB', GpuInventory::LABEL_MEMORY => '81920']),
            $this->gpuNode('down', 1, [], ['status' => ['conditions' => [['type' => 'Ready', 'status' => 'False']]]]),
        ];
        $pods = [$this->gpuPod('x', 'l4-a', 2)];

        $inventory = $this->inventory()->fromNodes($nodes, $pods);
        $summary = $inventory['summary'];

        $this->assertSame(4, $summary['gpu_nodes']);
        $this->assertSame(8, $summary['total']);
        $this->assertSame(7, $summary['allocatable'], 'a NotReady node schedules nothing');
        $this->assertSame(2, $summary['allocated']);
        $this->assertSame(5, $summary['available']);

        $products = array_column($summary['products'], null, 'product');
        $this->assertSame(4, $products['NVIDIA-L4']['count']);
        $this->assertSame(3, $products['NVIDIA-L4']['nodes']);
        $this->assertSame(81920, $products['NVIDIA-A100-SXM4-80GB']['memory_mb']);

        $byName = array_column($inventory['nodes'], null, 'name');
        $this->assertSame(2, $byName['l4-a']['allocated']);
        $this->assertSame(['a100', 'down', 'l4-a', 'l4-b'], array_column($inventory['nodes'], 'name'), 'sorted by name');
    }

    public function test_demo_node_is_a_gpu_node(): void
    {
        $entry = $this->inventory()->nodeEntry(GpuInventory::demoNode());

        $this->assertSame(1, $entry['allocatable']);
        $this->assertSame('NVIDIA-L4', $entry['product']);
    }
}
