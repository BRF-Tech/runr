<?php

namespace Tests\Unit\Wasm;

use App\Services\Wasm\WasmNodes;
use PHPUnit\Framework\TestCase;

/**
 * Which nodes can get WebAssembly support, and what they have (labels).
 */
class WasmNodesTest extends TestCase
{
    private function node(string $name, array $labels = [], array $taints = [], string $runtime = 'containerd://2.1.4', string $kubelet = 'v1.34.1', bool $unschedulable = false): array
    {
        return [
            'metadata' => ['name' => $name, 'labels' => $labels + ['kubernetes.io/os' => 'linux', 'kubernetes.io/arch' => 'amd64']],
            'spec' => array_filter(['taints' => $taints, 'unschedulable' => $unschedulable ?: null]),
            'status' => ['nodeInfo' => ['containerRuntimeVersion' => $runtime, 'kubeletVersion' => $kubelet]],
        ];
    }

    public function test_eligibility_reasons(): void
    {
        $nodes = [
            $this->node('ok'),
            $this->node('k3s', [], [], 'containerd://2.0.5-k3s1', 'v1.34.1+k3s1'),
            $this->node('win', ['kubernetes.io/os' => 'windows']),
            $this->node('cp', [], [['key' => 'node-role.kubernetes.io/control-plane', 'effect' => 'NoSchedule']]),
            $this->node('cordoned', [], [], 'containerd://2.1.4', 'v1.34.1', true),
            $this->node('crio', [], [], 'cri-o://1.31.0'),
            $this->node('s390', ['kubernetes.io/arch' => 's390x']),
        ];

        $described = array_column(WasmNodes::describe($nodes), null, 'name');

        $this->assertTrue($described['ok']['eligible']);
        $this->assertTrue($described['k3s']['eligible']);
        $this->assertSame('k3s', $described['k3s']['flavor']);
        $this->assertSame(WasmNodes::REASON_NOT_LINUX, $described['win']['reason']);
        $this->assertSame(WasmNodes::REASON_TAINTED, $described['cp']['reason']);
        $this->assertSame(WasmNodes::REASON_TAINTED, $described['cordoned']['reason']);
        $this->assertSame(WasmNodes::REASON_RUNTIME, $described['crio']['reason']);
        $this->assertSame(WasmNodes::REASON_ARCH, $described['s390']['reason']);
        $this->assertSame(['ok', 'k3s'], WasmNodes::eligibleNames($nodes));
    }

    public function test_with_dedicated_workers_only_they_are_eligible(): void
    {
        $nodes = [
            $this->node('shared'),
            $this->node('worker-1', ['runr.dev/dedicated' => 'projects'], [['key' => 'runr.dev/dedicated', 'value' => 'projects', 'effect' => 'NoSchedule']]),
        ];

        $described = array_column(WasmNodes::describe($nodes), null, 'name');

        $this->assertSame(WasmNodes::REASON_NOT_DEDICATED, $described['shared']['reason']);
        $this->assertTrue($described['worker-1']['eligible'], 'the runr.dev/dedicated taint is tolerated');
        $this->assertSame(['worker-1'], WasmNodes::eligibleNames($nodes));
    }

    public function test_targets_shims_and_prepared_nodes_come_from_labels(): void
    {
        $nodes = [
            $this->node('a', ['runr.dev/wasm-target' => 'true', 'runr.dev/wasm' => 'true', 'runr.dev/wasm-spin' => 'true', 'runr.dev/wasm-wasmtime' => 'true']),
            $this->node('b', ['runr.dev/wasm-cleanup' => 'true']),
            $this->node('c'),
        ];

        $described = array_column(WasmNodes::describe($nodes), null, 'name');
        $this->assertSame(['spin', 'wasmtime'], $described['a']['shims']);
        $this->assertTrue($described['a']['target']);
        $this->assertSame([], $described['c']['shims']);
        $this->assertSame(['a'], WasmNodes::targetNames($nodes));
        $this->assertSame(['a', 'b'], WasmNodes::preparedNames($nodes));
        $this->assertSame(['c'], array_column(array_map(fn ($n) => $n['metadata'], WasmNodes::pick($nodes, ['c'])), 'name'));
    }
}
