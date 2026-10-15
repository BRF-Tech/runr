<?php

namespace App\Services\Ai\Gpu;

use App\Models\KnativeRegion;

/**
 * GPU scheduling of a pod spec (Knative Service revision template, CronJob pod, model service):
 *
 *  - the first container gets `resources.limits["nvidia.com/gpu"] = count` (extended resources
 *    are requested by their limit);
 *  - a chosen GPU type pins the pod to nodes with that nvidia.com/gpu.product (nodeSelector), a
 *    minimum GPU memory adds a node affinity on nvidia.com/gpu.memory (MiB, GFD label);
 *  - tolerations for the taints GPU nodes commonly carry (nvidia.com/gpu, sku=gpu);
 *  - the RuntimeClass: gvisor (with nvproxy) stays, under runc the region's GPU RuntimeClass
 *    (`nvidia`) where the node runtime needs it (GpuPolicy::runtimeClassFor());
 *  - NVIDIA_DRIVER_CAPABILITIES=compute,utility unless the container sets it (what CUDA
 *    inference needs; also the set gVisor's nvproxy supports).
 *
 * Knative needs the pod spec features nodeselector, affinity, tolerations and runtimeclassname;
 * the GPU support install enables them (GpuInstallService::enableKnativeFeatures()).
 */
class GpuScheduling
{
    public const TOLERATIONS = [
        ['key' => 'nvidia.com/gpu', 'operator' => 'Exists', 'effect' => 'NoSchedule'],
        ['key' => 'sku', 'operator' => 'Equal', 'value' => 'gpu', 'effect' => 'NoSchedule'],
    ];

    public function __construct(private GpuPolicy $policy) {}

    /**
     * @throws \RuntimeException the region's sandbox for the workload does not run GPUs
     */
    public function apply(array $podSpec, GpuRequest $request, KnativeRegion $region): array
    {
        $container = $podSpec['containers'][0] ?? [];
        $container['resources']['limits'][$request->resource] = (string) $request->count;
        // Extended resources: request == limit (Kubernetes fills the request from the limit; a
        // request that differs is rejected)
        if (isset($container['resources']['requests'][$request->resource])) {
            $container['resources']['requests'][$request->resource] = (string) $request->count;
        }

        $present = array_column($container['env'] ?? [], 'name');
        if (! in_array('NVIDIA_DRIVER_CAPABILITIES', $present, true)) {
            $container['env'][] = ['name' => 'NVIDIA_DRIVER_CAPABILITIES', 'value' => 'compute,utility'];
        }
        $podSpec['containers'][0] = $container;

        if ($request->type !== null) {
            $podSpec['nodeSelector'] = array_merge($podSpec['nodeSelector'] ?? [], [GpuInventory::LABEL_PRODUCT => $request->type]);
        }

        if ($request->memoryMin !== null) {
            $podSpec['affinity']['nodeAffinity']['requiredDuringSchedulingIgnoredDuringExecution']['nodeSelectorTerms'] = [[
                'matchExpressions' => [[
                    'key' => GpuInventory::LABEL_MEMORY,
                    'operator' => 'Gt',
                    'values' => [(string) max(0, $request->memoryMin - 1)],
                ]],
            ]];
        }

        $podSpec['tolerations'] = self::mergeTolerations($podSpec['tolerations'] ?? [], self::TOLERATIONS);

        $runtimeClass = $this->policy->runtimeClassFor($region, $podSpec['runtimeClassName'] ?? null);
        if ($runtimeClass !== null) {
            $podSpec['runtimeClassName'] = $runtimeClass;
        } else {
            unset($podSpec['runtimeClassName']);
        }

        return $podSpec;
    }

    /**
     * Revision annotations of GPU workloads: a longer start-up deadline (CUDA images and model
     * weights are large) and a scale-to-zero retention so a warm GPU pod serves the next
     * request (scale to zero stays on).
     */
    public static function annotations(): array
    {
        return [
            'serving.knative.dev/progress-deadline' => (string) config('ai.gpu.progress_deadline', '1800s'),
            'autoscaling.knative.dev/scale-to-zero-pod-retention-period' => (string) config('ai.gpu.scale_to_zero_retention', '5m'),
        ];
    }

    private static function mergeTolerations(array $existing, array $add): array
    {
        foreach ($add as $toleration) {
            if (! in_array($toleration, $existing, true)) {
                $existing[] = $toleration;
            }
        }

        return $existing;
    }
}
