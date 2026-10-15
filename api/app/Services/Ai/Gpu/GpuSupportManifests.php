<?php

namespace App\Services\Ai\Gpu;

/**
 * What "Install GPU support" puts on a region (pure; GpuInstallService applies it):
 *
 *  - device-plugin (default): the NVIDIA device plugin Helm chart with GPU Feature Discovery
 *    (product / memory / count labels) and Node Feature Discovery (finds the nodes with an
 *    NVIDIA PCI device, where the plugin runs). For nodes that have the NVIDIA driver and the
 *    NVIDIA container toolkit. Optional time-slicing: every GPU is offered `replicas` times, so
 *    several pods share one GPU (no memory or fault isolation between them).
 *  - operator: the NVIDIA GPU Operator, which also installs the driver and the container
 *    toolkit on the nodes (driver / toolkit can be switched off where they exist).
 *  - RuntimeClass `nvidia` (handler nvidia) where the node runtime registers the NVIDIA runtime
 *    next to runc (k3s / RKE2, the GPU Operator's containerd config); GPU pods under runc use it.
 *  - GPU nodes only (restrict_to_gpu_nodes, on by default): the device plugin and GPU Feature
 *    Discovery DaemonSets select nvidia.com/gpu.present=true, which RunR puts on the GPU nodes —
 *    the ones chosen (gpu_nodes) or, automatically, nodes with GPU evidence (a device plugin
 *    resource, GFD labels, the NFD PCI vendor label 10de). Nodes without a GPU, Kind workers that
 *    share the host's PCI view and mixed clusters never get the plugin. With the GPU Operator,
 *    chosen nodes keep the operands and every other node gets nvidia.com/gpu.deploy.operands=false.
 */
class GpuSupportManifests
{
    public const DEVICE_PLUGIN_RELEASE = 'nvidia-device-plugin';

    public const OPERATOR_RELEASE = 'gpu-operator';

    public const DEVICE_PLUGIN_REPO = 'nvdp';

    public const OPERATOR_REPO = 'nvidia';

    public const RUNTIME_CLASS = 'nvidia';

    public const TIME_SLICING_CONFIG = 'runr-time-slicing';

    /** Every taint: the plugin must run on dedicated RunR workers and tainted GPU nodes alike. */
    public const TOLERATE_ALL = [['operator' => 'Exists']];

    /** The label the plugin / GFD DaemonSets select (also what the GPU Operator's NFD rule sets). */
    public const GPU_NODE_LABEL = 'nvidia.com/gpu.present';

    /** GPU Operator: per-node opt-out of every operand. */
    public const OPERANDS_LABEL = 'nvidia.com/gpu.deploy.operands';

    /** Marks labels RunR set (only these are removed again). */
    public const LABELLED_ANNOTATION = 'runr.dev/gpu-node-label';

    public const OPERANDS_ANNOTATION = 'runr.dev/gpu-operands-off';

    /**
     * Helm values of the device plugin chart (nvdp/nvidia-device-plugin).
     *
     * @param  array{time_slicing_replicas?: ?int, runtime_class?: ?string}  $settings
     */
    public static function devicePluginValues(array $settings): array
    {
        $values = [
            'gfd' => ['enabled' => true],
            'nfd' => ['enabled' => true],
            'tolerations' => self::TOLERATE_ALL,
            'priorityClassName' => 'system-node-critical',
            // Discovery components schedule anywhere too (NFD master / GC on any node)
            'node-feature-discovery' => [
                'worker' => ['tolerations' => self::TOLERATE_ALL],
                'master' => ['tolerations' => self::TOLERATE_ALL],
            ],
        ];

        if (! empty($settings['runtime_class'])) {
            // The plugin itself needs the NVIDIA runtime to see the GPUs (k3s / RKE2)
            $values['runtimeClassName'] = $settings['runtime_class'];
        }

        $replicas = (int) ($settings['time_slicing_replicas'] ?? 0);
        if ($replicas > 1) {
            $values['config'] = [
                'map' => ['default' => self::timeSlicingConfigYaml($replicas)],
                'default' => 'default',
            ];
        }

        // Device plugin + GFD only on GPU nodes (the chart's own affinity also accepts any node
        // NFD reports an NVIDIA PCI device for — every Kind worker of a GPU host)
        if ($settings['restrict_to_gpu_nodes'] ?? true) {
            $values['nodeSelector'] = [self::GPU_NODE_LABEL => 'true'];
            $values['affinity'] = self::gpuNodeAffinity();
        }

        return $values;
    }

    /** Required node affinity on nvidia.com/gpu.present=true. */
    public static function gpuNodeAffinity(): array
    {
        return [
            'nodeAffinity' => [
                'requiredDuringSchedulingIgnoredDuringExecution' => [
                    'nodeSelectorTerms' => [[
                        'matchExpressions' => [['key' => self::GPU_NODE_LABEL, 'operator' => 'In', 'values' => ['true']]],
                    ]],
                ],
            ],
        ];
    }

    /** Node items that show an NVIDIA GPU: device plugin resource, GFD / operator labels, NFD PCI vendor 10de. */
    public static function hasGpuEvidence(array $node): bool
    {
        $labels = $node['metadata']['labels'] ?? [];

        return GpuInventory::units($node['status']['capacity'] ?? []) > 0
            || ($labels[self::GPU_NODE_LABEL] ?? null) === 'true'
            || isset($labels[GpuInventory::LABEL_PRODUCT])
            || ($labels[GpuInventory::LABEL_PCI] ?? null) === 'true';
    }

    /**
     * The GPU nodes of a region: the chosen ones (gpu_nodes) that exist, else every node with GPU
     * evidence. Null when the plugin is not restricted (restrict_to_gpu_nodes off).
     *
     * @param  list<array>  $nodes  v1 Node items
     * @return list<string>|null
     */
    public static function gpuNodeNames(array $nodes, array $settings): ?array
    {
        if (! ($settings['restrict_to_gpu_nodes'] ?? true)) {
            return null;
        }

        $names = array_map(fn ($n) => (string) ($n['metadata']['name'] ?? ''), $nodes);
        $chosen = array_values(array_filter((array) ($settings['gpu_nodes'] ?? []), 'is_string'));

        if ($chosen !== []) {
            return array_values(array_intersect($chosen, $names));
        }

        return array_values(array_map(
            fn ($n) => (string) $n['metadata']['name'],
            array_filter($nodes, [self::class, 'hasGpuEvidence']),
        ));
    }

    /**
     * Node label changes that put the plugin (device-plugin) or the operands (operator, chosen
     * nodes only) where the GPUs are: [node => strategic merge patch]. Only labels RunR set
     * (annotation) are removed; labels set by the cluster admin stay.
     *
     * @param  list<array>  $nodes  v1 Node items
     * @return array<string, array>
     */
    public static function nodeLabelPatches(array $nodes, string $mode, array $settings): array
    {
        $targets = self::gpuNodeNames($nodes, $settings);
        $chosen = array_values(array_filter((array) ($settings['gpu_nodes'] ?? []), 'is_string'));
        $patches = [];

        foreach ($nodes as $node) {
            $name = (string) ($node['metadata']['name'] ?? '');
            $labels = $node['metadata']['labels'] ?? [];
            $annotations = $node['metadata']['annotations'] ?? [];
            $isTarget = $targets !== null && in_array($name, $targets, true);

            if ($mode === 'operator') {
                // Opt nodes out of the operands only when the GPU nodes are chosen explicitly
                $optOut = $targets !== null && $chosen !== [] && ! $isTarget;
                if ($optOut && ($labels[self::OPERANDS_LABEL] ?? null) !== 'false') {
                    $patches[$name] = ['metadata' => [
                        'labels' => [self::OPERANDS_LABEL => 'false'],
                        'annotations' => [self::OPERANDS_ANNOTATION => 'true'],
                    ]];
                } elseif (! $optOut && ($annotations[self::OPERANDS_ANNOTATION] ?? null) === 'true') {
                    $patches[$name] = ['metadata' => [
                        'labels' => [self::OPERANDS_LABEL => null],
                        'annotations' => [self::OPERANDS_ANNOTATION => null],
                    ]];
                }

                continue;
            }

            if ($isTarget && ($labels[self::GPU_NODE_LABEL] ?? null) !== 'true') {
                $patches[$name] = ['metadata' => [
                    'labels' => [self::GPU_NODE_LABEL => 'true'],
                    'annotations' => [self::LABELLED_ANNOTATION => 'true'],
                ]];
            } elseif (! $isTarget && ($annotations[self::LABELLED_ANNOTATION] ?? null) === 'true') {
                $patches[$name] = ['metadata' => [
                    'labels' => [self::GPU_NODE_LABEL => null],
                    'annotations' => [self::LABELLED_ANNOTATION => null],
                ]];
            }
        }

        return $patches;
    }

    /** Patches removing every label RunR set (uninstall). @return array<string, array> */
    public static function nodeLabelCleanup(array $nodes): array
    {
        $patches = [];
        foreach ($nodes as $node) {
            $name = (string) ($node['metadata']['name'] ?? '');
            $annotations = $node['metadata']['annotations'] ?? [];
            $labels = [];
            $marks = [];
            if (($annotations[self::LABELLED_ANNOTATION] ?? null) === 'true') {
                $labels[self::GPU_NODE_LABEL] = null;
                $marks[self::LABELLED_ANNOTATION] = null;
            }
            if (($annotations[self::OPERANDS_ANNOTATION] ?? null) === 'true') {
                $labels[self::OPERANDS_LABEL] = null;
                $marks[self::OPERANDS_ANNOTATION] = null;
            }
            if ($labels) {
                $patches[$name] = ['metadata' => ['labels' => $labels, 'annotations' => $marks]];
            }
        }

        return $patches;
    }

    /**
     * Helm values of the GPU Operator chart (nvidia/gpu-operator).
     *
     * @param  array{operator_driver?: bool, operator_toolkit?: bool, time_slicing_replicas?: ?int}  $settings
     */
    public static function operatorValues(array $settings, ?string $namespace = null): array
    {
        $values = [
            'driver' => ['enabled' => (bool) ($settings['operator_driver'] ?? true)],
            'toolkit' => ['enabled' => (bool) ($settings['operator_toolkit'] ?? true)],
            'operator' => ['defaultRuntime' => 'containerd', 'runtimeClass' => self::RUNTIME_CLASS],
            'daemonsets' => ['tolerations' => self::TOLERATE_ALL],
            'node-feature-discovery' => [
                'worker' => ['tolerations' => self::TOLERATE_ALL],
            ],
        ];

        $replicas = (int) ($settings['time_slicing_replicas'] ?? 0);
        if ($replicas > 1) {
            $values['devicePlugin'] = ['config' => ['name' => self::TIME_SLICING_CONFIG, 'default' => 'any']];
        }

        return $values;
    }

    /** ConfigMap the GPU Operator's device plugin reads the time-slicing config from. */
    public static function operatorTimeSlicingConfigMap(int $replicas, string $namespace): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'ConfigMap',
            'metadata' => [
                'name' => self::TIME_SLICING_CONFIG,
                'namespace' => $namespace,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr'],
            ],
            'data' => ['any' => self::timeSlicingConfigYaml($replicas)],
        ];
    }

    /** Device plugin config (v1) offering every GPU `replicas` times. */
    public static function timeSlicingConfigYaml(int $replicas): string
    {
        return implode("\n", [
            'version: v1',
            'flags:',
            '  migStrategy: none',
            'sharing:',
            '  timeSlicing:',
            '    renameByDefault: false',
            '    failRequestsGreaterThanOne: false',
            '    resources:',
            '      - name: nvidia.com/gpu',
            '        replicas: '.max(2, $replicas),
            '',
        ]);
    }

    public static function runtimeClass(): array
    {
        return [
            'apiVersion' => 'node.k8s.io/v1',
            'kind' => 'RuntimeClass',
            'metadata' => [
                'name' => self::RUNTIME_CLASS,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr'],
            ],
            'handler' => self::RUNTIME_CLASS,
        ];
    }

    /**
     * RuntimeClass GPU pods use under runc: "auto" → nvidia on k3s / RKE2 (the NVIDIA runtime is
     * registered next to runc) and with the GPU Operator (it creates the RuntimeClass);
     * "nvidia" always; "none" never (the node's default runtime is the NVIDIA runtime).
     */
    public static function resolveRuntimeClass(string $choice, string $mode, string $runtimeFlavor): ?string
    {
        return match ($choice) {
            'nvidia' => self::RUNTIME_CLASS,
            'none' => null,
            default => ($mode === 'operator' || in_array($runtimeFlavor, ['k3s', 'rke2'], true)) ? self::RUNTIME_CLASS : null,
        };
    }

    /**
     * Knative pod spec features GPU and model workloads need: node selection / affinity on GPU
     * labels, tolerations of GPU taints, RuntimeClasses, and PVC mounts of the model volume.
     */
    public static function knativeFeatures(): array
    {
        return [
            'kubernetes.podspec-nodeselector' => 'enabled',
            'kubernetes.podspec-affinity' => 'enabled',
            'kubernetes.podspec-tolerations' => 'enabled',
            'kubernetes.podspec-runtimeclassname' => 'enabled',
            'kubernetes.podspec-persistent-volume-claim' => 'enabled',
            'kubernetes.podspec-persistent-volume-write' => 'enabled',
            'kubernetes.podspec-init-containers' => 'enabled',
            'kubernetes.podspec-emptydir' => 'enabled',
        ];
    }
}
