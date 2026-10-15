<?php

namespace App\Services\Ai\Gpu;

use App\Models\KnativeRegion;
use App\Models\Setting;

/**
 * Where GPU workloads may run (docs/ai.md#supported-sandbox-combinations):
 *
 *  - runc (no sandbox): supported; the pod runs with the RuntimeClass `nvidia` when the region's
 *    GPU support needs one (k3s / RKE2 register the NVIDIA runtime next to runc).
 *  - gVisor: supported when the region's runsc runs with nvproxy (GPU settings → gVisor nvproxy);
 *    the pod keeps the gvisor RuntimeClass.
 *  - Kata Containers: not offered for GPUs (GPU passthrough into the VM is not configured by
 *    RunR); map the project type to runc or gVisor on GPU regions.
 *
 * A request is also checked against the region's GPU inventory: GPU nodes, the product (type)
 * and the GPU memory (memory_min).
 */
class GpuPolicy
{
    public function __construct(private GpuInventory $inventory) {}

    /**
     * Sandbox support of GPUs for a project type in a region.
     *
     * @return array{runtime_class: ?string, gpu: 'ok'|'nvproxy'|'unsupported', message: ?string}
     */
    public function sandboxSupport(KnativeRegion $region, string $serviceType): array
    {
        $runtimeClass = $region->resolveRuntimeClass($serviceType);

        if ($runtimeClass === null) {
            return ['runtime_class' => null, 'gpu' => 'ok', 'message' => null];
        }

        if ($runtimeClass === 'gvisor') {
            $nvproxy = (bool) ($region->gpu_settings['gvisor_nvproxy'] ?? false);

            return [
                'runtime_class' => 'gvisor',
                'gpu' => $nvproxy ? 'nvproxy' : 'unsupported',
                'message' => $nvproxy ? null : __('ai.gpu.gvisor_needs_nvproxy', ['type' => strtoupper($serviceType)]),
            ];
        }

        return [
            'runtime_class' => $runtimeClass,
            'gpu' => 'unsupported',
            'message' => __('ai.gpu.kata_unsupported', ['type' => strtoupper($serviceType)]),
        ];
    }

    /**
     * Every reason a GPU request cannot run in the region for the given project type ("caas",
     * "xaas", "faas" — model services check as "model", which runs without the type's sandbox).
     *
     * @return list<string>
     */
    public function problems(?KnativeRegion $region, string $serviceType, GpuRequest $request): array
    {
        if (! $region) {
            return [__('ai.gpu.no_region')];
        }

        $problems = [];
        $max = (int) config('ai.gpu.max_count', 8);

        if ($request->count > $max) {
            $problems[] = __('ai.gpu.count_too_high', ['max' => $max]);
        }

        if ($serviceType !== 'model') {
            $sandbox = $this->sandboxSupport($region, $serviceType);
            if ($sandbox['gpu'] === 'unsupported') {
                $problems[] = $sandbox['message'];
            }
        }

        if ($region->is_demo) {
            if (Setting::get('gpu_demo_enabled', 'false') !== 'true') {
                $problems[] = __('ai.gpu.demo_disabled');
            }

            return $problems;
        }

        $stored = $this->inventory->stored($region);
        $nodes = array_values(array_filter($stored['nodes'], fn ($n) => $n['allocatable'] > 0));

        if ($nodes === []) {
            $problems[] = ($stored['summary']['hardware_without_plugin'] ?? []) !== []
                ? __('ai.gpu.no_device_plugin', ['region' => $region->name])
                : __('ai.gpu.no_gpu_nodes', ['region' => $region->name]);

            return $problems;
        }

        $matching = array_filter($nodes, fn ($n) => $request->type === null || $n['product'] === $request->type);
        if ($request->type !== null && $matching === []) {
            $products = array_values(array_unique(array_filter(array_column($nodes, 'product'))));
            $problems[] = __('ai.gpu.type_unknown', [
                'type' => $request->type,
                'products' => $products ? implode(', ', $products) : '—',
            ]);

            return $problems;
        }

        if ($request->memoryMin !== null) {
            $matching = array_filter($matching, fn ($n) => ($n['memory_mb'] ?? 0) >= $request->memoryMin);
            if ($matching === []) {
                $problems[] = __('ai.gpu.memory_too_high', ['memory' => $request->memoryMin]);

                return $problems;
            }
        }

        $largest = max(array_map(fn ($n) => (int) $n['allocatable'], $matching) ?: [0]);
        if ($request->count > $largest) {
            $problems[] = __('ai.gpu.count_exceeds_node', ['count' => $request->count, 'max' => $largest]);
        }

        return $problems;
    }

    /**
     * RuntimeClass of a GPU pod: the sandbox's (gvisor with nvproxy) or, under runc, the
     * region's GPU RuntimeClass (`nvidia` where the node runtime needs it, else none).
     *
     * @throws \RuntimeException a sandbox that does not run GPUs (Kata, gVisor without nvproxy)
     */
    public function runtimeClassFor(KnativeRegion $region, ?string $sandboxRuntimeClass): ?string
    {
        if ($sandboxRuntimeClass === null) {
            $gpuClass = $region->gpu_settings['runtime_class'] ?? null;

            return is_string($gpuClass) && $gpuClass !== '' ? $gpuClass : null;
        }

        if ($sandboxRuntimeClass === 'gvisor') {
            if ($region->gpu_settings['gvisor_nvproxy'] ?? false) {
                return 'gvisor';
            }

            throw new \RuntimeException(__('ai.gpu.gvisor_needs_nvproxy', ['type' => 'GPU']));
        }

        if (str_starts_with($sandboxRuntimeClass, 'kata')) {
            throw new \RuntimeException(__('ai.gpu.kata_unsupported', ['type' => 'GPU']));
        }

        // A RuntimeClass of the runtime itself (platform runtimes, e.g. a WebAssembly shim) or
        // `nvidia`: kept as it is
        return $sandboxRuntimeClass;
    }
}
