<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Gpu\GpuInventory;
use App\Services\Ai\Gpu\GpuSupportManifests;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\BuildsAiFixtures;

/**
 * "Install GPU support": Helm values of the NVIDIA device plugin (GFD + NFD, tolerations,
 * RuntimeClass, time-slicing) and of the GPU Operator (driver / toolkit switches,
 * time-slicing ConfigMap), the nvidia RuntimeClass, its resolution per node runtime and the
 * Knative pod spec features GPU / model workloads need.
 */
class GpuSupportManifestsTest extends TestCase
{
    use BuildsAiFixtures;

    /** A Kind-like worker: NFD reports the host's NVIDIA PCI device on every node. */
    private function pciNode(string $name, array $labels = [], array $annotations = []): array
    {
        $node = $this->cpuNode($name);
        $node['metadata']['labels'][GpuInventory::LABEL_PCI] = 'true';
        $node['metadata']['labels'] += $labels;
        $node['metadata']['annotations'] = $annotations;

        return $node;
    }

    public function test_device_plugin_values_enable_feature_discovery_and_tolerate_every_taint(): void
    {
        $values = GpuSupportManifests::devicePluginValues([]);

        $this->assertTrue($values['gfd']['enabled']);
        $this->assertTrue($values['nfd']['enabled']);
        $this->assertSame([['operator' => 'Exists']], $values['tolerations'], 'runs on dedicated RunR workers and tainted GPU nodes');
        $this->assertSame([['operator' => 'Exists']], $values['node-feature-discovery']['worker']['tolerations']);
        $this->assertArrayNotHasKey('config', $values, 'no time-slicing by default');
        $this->assertArrayNotHasKey('runtimeClassName', $values);
    }

    public function test_device_plugin_runs_with_the_nvidia_runtime_class_where_needed(): void
    {
        $values = GpuSupportManifests::devicePluginValues(['runtime_class' => 'nvidia']);

        $this->assertSame('nvidia', $values['runtimeClassName']);
    }

    public function test_time_slicing_config_offers_each_gpu_n_times(): void
    {
        $values = GpuSupportManifests::devicePluginValues(['time_slicing_replicas' => 4]);

        $this->assertSame('default', $values['config']['default']);
        $config = Yaml::parse($values['config']['map']['default']);
        $this->assertSame('v1', $config['version']);
        $this->assertSame([['name' => 'nvidia.com/gpu', 'replicas' => 4]], $config['sharing']['timeSlicing']['resources']);
        $this->assertFalse($config['sharing']['timeSlicing']['renameByDefault'], 'shared GPUs stay nvidia.com/gpu');

        // 1 replica is no sharing
        $this->assertArrayNotHasKey('config', GpuSupportManifests::devicePluginValues(['time_slicing_replicas' => 1]));
    }

    public function test_operator_values_switch_driver_and_toolkit(): void
    {
        $values = GpuSupportManifests::operatorValues(['operator_driver' => false, 'operator_toolkit' => true]);

        $this->assertFalse($values['driver']['enabled'], 'nodes with a driver keep it');
        $this->assertTrue($values['toolkit']['enabled']);
        $this->assertSame('nvidia', $values['operator']['runtimeClass']);
        $this->assertSame([['operator' => 'Exists']], $values['daemonsets']['tolerations']);
        $this->assertArrayNotHasKey('devicePlugin', $values);

        $sliced = GpuSupportManifests::operatorValues(['time_slicing_replicas' => 2]);
        $this->assertTrue($sliced['driver']['enabled'], 'driver on by default');
        $this->assertSame(['name' => GpuSupportManifests::TIME_SLICING_CONFIG, 'default' => 'any'], $sliced['devicePlugin']['config']);

        $cm = GpuSupportManifests::operatorTimeSlicingConfigMap(2, 'runr-gpu');
        $this->assertSame('runr-gpu', $cm['metadata']['namespace']);
        $this->assertSame(2, Yaml::parse($cm['data']['any'])['sharing']['timeSlicing']['resources'][0]['replicas']);
    }

    public function test_runtime_class_manifest(): void
    {
        $rc = GpuSupportManifests::runtimeClass();

        $this->assertSame('node.k8s.io/v1', $rc['apiVersion']);
        $this->assertSame('nvidia', $rc['metadata']['name']);
        $this->assertSame('nvidia', $rc['handler']);
    }

    public function test_runtime_class_resolution(): void
    {
        // auto: k3s / RKE2 register the NVIDIA runtime next to runc; the GPU Operator creates the class
        $this->assertSame('nvidia', GpuSupportManifests::resolveRuntimeClass('auto', 'device-plugin', 'k3s'));
        $this->assertSame('nvidia', GpuSupportManifests::resolveRuntimeClass('auto', 'device-plugin', 'rke2'));
        $this->assertSame('nvidia', GpuSupportManifests::resolveRuntimeClass('auto', 'operator', 'containerd'));
        $this->assertNull(GpuSupportManifests::resolveRuntimeClass('auto', 'device-plugin', 'containerd'));

        $this->assertSame('nvidia', GpuSupportManifests::resolveRuntimeClass('nvidia', 'device-plugin', 'containerd'));
        $this->assertNull(GpuSupportManifests::resolveRuntimeClass('none', 'operator', 'k3s'));
    }

    public function test_the_device_plugin_runs_on_gpu_nodes_only_by_default(): void
    {
        $values = GpuSupportManifests::devicePluginValues([]);

        $this->assertSame(['nvidia.com/gpu.present' => 'true'], $values['nodeSelector']);
        $this->assertSame(
            [['matchExpressions' => [['key' => 'nvidia.com/gpu.present', 'operator' => 'In', 'values' => ['true']]]]],
            $values['affinity']['nodeAffinity']['requiredDuringSchedulingIgnoredDuringExecution']['nodeSelectorTerms'],
            'replaces the chart affinity that also accepts every node with an NVIDIA PCI device',
        );

        $open = GpuSupportManifests::devicePluginValues(['restrict_to_gpu_nodes' => false]);
        $this->assertArrayNotHasKey('nodeSelector', $open);
        $this->assertArrayNotHasKey('affinity', $open);
    }

    public function test_gpu_nodes_are_the_chosen_ones_or_those_with_gpu_evidence(): void
    {
        $nodes = [
            $this->gpuNode('gpu-a', 1),
            $this->pciNode('pci-only'),
            $this->cpuNode('cpu-1'),
            array_replace_recursive($this->cpuNode('labelled'), ['metadata' => ['labels' => ['nvidia.com/gpu.present' => 'true']]]),
        ];

        $this->assertSame(['gpu-a', 'pci-only', 'labelled'], GpuSupportManifests::gpuNodeNames($nodes, []));
        $this->assertSame(['pci-only'], GpuSupportManifests::gpuNodeNames($nodes, ['gpu_nodes' => ['pci-only', 'gone']]), 'chosen nodes that exist');
        $this->assertNull(GpuSupportManifests::gpuNodeNames($nodes, ['restrict_to_gpu_nodes' => false]));
        $this->assertFalse(GpuSupportManifests::hasGpuEvidence($this->cpuNode('cpu-2')));
    }

    public function test_kind_workers_sharing_the_host_pci_view_get_the_plugin_only_where_chosen(): void
    {
        $nodes = [
            $this->pciNode('kind-worker'),
            $this->pciNode('kind-worker2', [], [GpuSupportManifests::LABELLED_ANNOTATION => 'true']),
            $this->pciNode('kind-worker3'),
        ];
        // kind-worker2 still carries the label RunR set during an earlier install
        $nodes[1]['metadata']['labels']['nvidia.com/gpu.present'] = 'true';

        $patches = GpuSupportManifests::nodeLabelPatches($nodes, 'device-plugin', ['gpu_nodes' => ['kind-worker3']]);

        $this->assertSame(['kind-worker2', 'kind-worker3'], array_keys($patches));
        $this->assertSame(['nvidia.com/gpu.present' => 'true'], $patches['kind-worker3']['metadata']['labels']);
        $this->assertSame([GpuSupportManifests::LABELLED_ANNOTATION => 'true'], $patches['kind-worker3']['metadata']['annotations']);
        $this->assertSame(['nvidia.com/gpu.present' => null], $patches['kind-worker2']['metadata']['labels'], 'RunR removes only its own label');
    }

    public function test_labels_set_by_the_cluster_admin_stay(): void
    {
        $admin = array_replace_recursive($this->cpuNode('admin-gpu'), ['metadata' => ['labels' => ['nvidia.com/gpu.present' => 'true']]]);

        $patches = GpuSupportManifests::nodeLabelPatches([$admin, $this->gpuNode('gpu-a', 1)], 'device-plugin', ['gpu_nodes' => ['gpu-a']]);

        $this->assertArrayNotHasKey('admin-gpu', $patches, 'no RunR annotation: not touched');
        $this->assertArrayHasKey('gpu-a', $patches);
        $this->assertSame([], GpuSupportManifests::nodeLabelPatches([$admin], 'device-plugin', ['restrict_to_gpu_nodes' => false]));
    }

    public function test_the_operator_keeps_operands_on_the_chosen_nodes(): void
    {
        $nodes = [$this->pciNode('gpu'), $this->pciNode('cpu')];

        $patches = GpuSupportManifests::nodeLabelPatches($nodes, 'operator', ['gpu_nodes' => ['gpu']]);
        $this->assertSame(['cpu'], array_keys($patches));
        $this->assertSame(['nvidia.com/gpu.deploy.operands' => 'false'], $patches['cpu']['metadata']['labels']);

        // detected automatically: the operator's own node selection, nothing to patch
        $this->assertSame([], GpuSupportManifests::nodeLabelPatches($nodes, 'operator', []));

        // the opt-out RunR set goes away when the node is chosen again
        $optedOut = $this->pciNode('cpu', ['nvidia.com/gpu.deploy.operands' => 'false'], [GpuSupportManifests::OPERANDS_ANNOTATION => 'true']);
        $undo = GpuSupportManifests::nodeLabelPatches([$optedOut], 'operator', ['gpu_nodes' => ['cpu']]);
        $this->assertSame(['nvidia.com/gpu.deploy.operands' => null], $undo['cpu']['metadata']['labels']);
    }

    public function test_uninstall_cleanup_removes_runr_labels_only(): void
    {
        $nodes = [
            $this->pciNode('a', ['nvidia.com/gpu.present' => 'true'], [GpuSupportManifests::LABELLED_ANNOTATION => 'true']),
            $this->pciNode('b', ['nvidia.com/gpu.deploy.operands' => 'false'], [GpuSupportManifests::OPERANDS_ANNOTATION => 'true']),
            $this->pciNode('c', ['nvidia.com/gpu.present' => 'true']),
        ];

        $cleanup = GpuSupportManifests::nodeLabelCleanup($nodes);

        $this->assertSame(['a', 'b'], array_keys($cleanup));
        $this->assertSame(['nvidia.com/gpu.present' => null], $cleanup['a']['metadata']['labels']);
        $this->assertSame(['nvidia.com/gpu.deploy.operands' => null], $cleanup['b']['metadata']['labels']);
    }

    public function test_knative_features_of_gpu_and_model_workloads(): void
    {
        $features = GpuSupportManifests::knativeFeatures();

        foreach ([
            'kubernetes.podspec-nodeselector',
            'kubernetes.podspec-affinity',
            'kubernetes.podspec-tolerations',
            'kubernetes.podspec-runtimeclassname',
            'kubernetes.podspec-persistent-volume-claim',
            'kubernetes.podspec-persistent-volume-write',
            'kubernetes.podspec-init-containers',
            'kubernetes.podspec-emptydir',
        ] as $flag) {
            $this->assertSame('enabled', $features[$flag] ?? null, $flag);
        }
    }
}
