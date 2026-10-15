<?php

namespace App\Services\Ai\Gpu;

use App\Models\AiModelService;
use App\Models\KnativeRegion;
use App\Models\Setting;
use App\Services\KnativeNodeService;
use App\Services\KubernetesClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * GPU nodes of a region and what is allocated on them.
 *
 * A node is a GPU node when the NVIDIA device plugin advertises `nvidia.com/gpu` in its
 * allocatable (schedulable GPU units; with time-slicing every physical GPU counts `replicas`
 * times). GPU Feature Discovery (GFD) labels describe the hardware: nvidia.com/gpu.product,
 * .memory (MiB), .count (physical GPUs), .replicas, .compute.major/.minor and the driver / CUDA
 * versions. A node whose PCI vendor 10de is present (Node Feature Discovery) or that carries
 * nvidia.com/gpu.present but advertises no GPU has NVIDIA hardware without a working device
 * plugin — the region's "Install GPU support" is what it needs.
 *
 * gpu_available on the region stores the inventory, gpu_allocated the GPUs requested by running
 * pods (all namespaces) per node and per RunR owner (project / model service).
 */
class GpuInventory
{
    public const RESOURCE = 'nvidia.com/gpu';

    public const SHARED_RESOURCE = 'nvidia.com/gpu.shared';

    public const LABEL_PRODUCT = 'nvidia.com/gpu.product';

    public const LABEL_MEMORY = 'nvidia.com/gpu.memory';

    public const LABEL_COUNT = 'nvidia.com/gpu.count';

    public const LABEL_REPLICAS = 'nvidia.com/gpu.replicas';

    public const LABEL_PRESENT = 'nvidia.com/gpu.present';

    public const LABEL_PCI = 'feature.node.kubernetes.io/pci-10de.present';

    /** Taints GPU nodes commonly carry; GPU workloads tolerate them (GpuScheduling). */
    public const GPU_TAINT_KEYS = ['nvidia.com/gpu', 'sku'];

    /**
     * Inventory from v1 Node items (and, for allocation, v1 Pod items of all namespaces).
     *
     * @return array{nodes: list<array>, summary: array, allocation: array}
     */
    public function fromNodes(array $nodeItems, ?array $podItems = null): array
    {
        $allocation = $podItems === null ? null : $this->allocation($podItems);
        $nodes = [];

        foreach ($nodeItems as $node) {
            $entry = $this->nodeEntry($node, $allocation['by_node'] ?? []);
            if ($entry !== null) {
                $nodes[] = $entry;
            }
        }

        usort($nodes, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return [
            'nodes' => $nodes,
            'summary' => $this->summary($nodes, $allocation),
            'allocation' => $allocation ?? ['total' => 0, 'by_node' => [], 'workloads' => []],
        ];
    }

    /**
     * One node's GPU facts; null for a node without GPU resources or NVIDIA hardware labels.
     *
     * @param  array<string, int>  $allocatedByNode
     */
    public function nodeEntry(array $node, array $allocatedByNode = []): ?array
    {
        $labels = $node['metadata']['labels'] ?? [];
        $name = (string) ($node['metadata']['name'] ?? '');
        $allocatable = self::units($node['status']['allocatable'] ?? []);
        $capacity = self::units($node['status']['capacity'] ?? []);
        $hardware = ($labels[self::LABEL_PCI] ?? null) === 'true' || ($labels[self::LABEL_PRESENT] ?? null) === 'true'
            || isset($labels[self::LABEL_PRODUCT]);

        if ($allocatable === 0 && $capacity === 0 && ! $hardware) {
            return null;
        }

        $replicas = max(1, (int) ($labels[self::LABEL_REPLICAS] ?? 1));
        $physical = isset($labels[self::LABEL_COUNT])
            ? (int) $labels[self::LABEL_COUNT]
            : ($capacity > 0 ? max(1, intdiv($capacity, $replicas)) : 0);

        $major = $labels['nvidia.com/gpu.compute.major'] ?? null;
        $minor = $labels['nvidia.com/gpu.compute.minor'] ?? null;

        $driver = $this->version($labels, 'nvidia.com/cuda.driver-version', 'nvidia.com/cuda.driver');
        $cuda = $this->version($labels, 'nvidia.com/cuda.runtime-version', 'nvidia.com/cuda.runtime');

        $taints = [];
        foreach ($node['spec']['taints'] ?? [] as $taint) {
            $taints[] = [
                'key' => (string) ($taint['key'] ?? ''),
                'value' => isset($taint['value']) ? (string) $taint['value'] : null,
                'effect' => (string) ($taint['effect'] ?? ''),
            ];
        }

        return [
            'name' => $name,
            'ready' => self::isReady($node),
            'product' => $labels[self::LABEL_PRODUCT] ?? null,
            'memory_mb' => isset($labels[self::LABEL_MEMORY]) ? (int) $labels[self::LABEL_MEMORY] : null,
            'count' => $physical,
            'capacity' => $capacity,
            'allocatable' => $allocatable,
            'allocated' => (int) ($allocatedByNode[$name] ?? 0),
            'replicas' => $replicas,
            'compute_capability' => $major !== null ? $major.'.'.($minor ?? '0') : null,
            'driver_version' => $driver,
            'cuda_version' => $cuda,
            'has_device_plugin' => $capacity > 0,
            'hardware_detected' => $hardware || $capacity > 0,
            'taints' => $taints,
        ];
    }

    /**
     * GPUs requested by unfinished pods bound to nodes: per node, and per RunR owner (project id
     * label runr/project-id, model service label runr.sh/model-service, else "other").
     *
     * @return array{total: int, by_node: array<string, int>, workloads: list<array>}
     */
    public function allocation(array $podItems): array
    {
        $byNode = [];
        $workloads = [];
        $total = 0;

        foreach ($podItems as $pod) {
            $node = (string) ($pod['spec']['nodeName'] ?? '');
            if ($node === '' || in_array($pod['status']['phase'] ?? '', ['Succeeded', 'Failed'], true)) {
                continue;
            }

            $gpus = self::podGpus($pod);
            if ($gpus === 0) {
                continue;
            }

            $byNode[$node] = ($byNode[$node] ?? 0) + $gpus;
            $total += $gpus;

            $labels = $pod['metadata']['labels'] ?? [];
            [$ownerType, $ownerId] = match (true) {
                ! empty($labels[AiModelService::LABEL]) => ['model', (string) $labels[AiModelService::LABEL]],
                ! empty($labels[KnativeNodeService::PROJECT_POD_LABEL]) => ['project', (string) $labels[KnativeNodeService::PROJECT_POD_LABEL]],
                default => ['other', null],
            };

            $workloads[] = [
                'namespace' => (string) ($pod['metadata']['namespace'] ?? ''),
                'pod' => (string) ($pod['metadata']['name'] ?? ''),
                'node' => $node,
                'gpus' => $gpus,
                'owner' => $ownerType,
                'owner_id' => $ownerId,
                'tenant' => $labels['runr/tenant'] ?? null,
                'phase' => (string) ($pod['status']['phase'] ?? ''),
            ];
        }

        ksort($byNode);

        return ['total' => $total, 'by_node' => $byNode, 'workloads' => $workloads];
    }

    /** GPU units a pod requests: the larger of its containers' sum and its largest init container. */
    public static function podGpus(array $pod): int
    {
        $read = fn (array $c) => self::units($c['resources']['limits'] ?? []) ?: self::units($c['resources']['requests'] ?? []);

        $sum = 0;
        foreach ($pod['spec']['containers'] ?? [] as $container) {
            $sum += $read($container);
        }

        $init = 0;
        foreach ($pod['spec']['initContainers'] ?? [] as $container) {
            $init = max($init, $read($container));
        }

        return max($sum, $init);
    }

    /**
     * Refresh the region's GPU inventory from its cluster (nodes + pods) and store it
     * (gpu_available / gpu_allocated). A failed node list keeps the stored inventory.
     */
    public function refresh(KnativeRegion $region, ?KubernetesClient $k8s = null): ?array
    {
        if ($region->is_demo) {
            return $this->storeDemo($region);
        }

        if (! $region->kubeconfig) {
            return null;
        }

        try {
            $k8s ??= new KubernetesClient($region->kubeconfig);
            $nodes = $k8s->listClusterResources('v1', 'Node')['items'] ?? null;
            if ($nodes === null) {
                return null;
            }

            try {
                $pods = $k8s->listActivePods();
            } catch (\Throwable) {
                $pods = null;
            }
        } catch (\Throwable $e) {
            Log::warning('GPU inventory refresh failed', ['region' => $region->name, 'error' => $e->getMessage()]);

            return null;
        }

        $inventory = $this->fromNodes($nodes, $pods);
        $this->store($region, $inventory, $pods !== null);

        return $inventory;
    }

    /** Persist an inventory on the region (only when it changed — every save syncs to tenants). */
    public function store(KnativeRegion $region, array $inventory, bool $withAllocation = true): void
    {
        $available = [
            'nodes' => $inventory['nodes'],
            'summary' => $inventory['summary'],
            'detected_at' => now()->toIso8601String(),
        ];

        $previous = (array) ($region->gpu_available ?? []);
        $detectedAt = $previous['detected_at'] ?? null;
        unset($previous['detected_at']);
        $compare = $available;
        unset($compare['detected_at']);

        $updates = [];
        // detected_at is kept fresh at most every 10 minutes (regions without changes are not
        // rewritten — and synced to every tenant — on each refresh)
        $stale = ! is_string($detectedAt)
            || abs(now()->diffInMinutes(Carbon::parse($detectedAt))) >= 10;

        if ($previous != $compare || $stale) {
            $updates['gpu_available'] = $available;
        }

        if ($withAllocation) {
            $allocated = [
                'total' => $inventory['allocation']['total'],
                'by_node' => $inventory['allocation']['by_node'],
                'workloads' => array_slice($inventory['allocation']['workloads'], 0, 200),
            ];
            if ((array) ($region->gpu_allocated ?? []) != $allocated) {
                $updates['gpu_allocated'] = $allocated;
            }
        }

        // A cluster whose nodes already advertise GPUs (installed by the cluster admin) counts as
        // "external" GPU support until RunR installs its own
        if (($region->gpu_mode ?? 'none') === 'none' && $inventory['summary']['allocatable'] > 0) {
            $updates['gpu_mode'] = 'external';
            $updates['gpu_status'] = 'ready';
        }

        if ($updates) {
            $region->update($updates);
        }
    }

    /**
     * Summary of an inventory.
     *
     * @return array{gpu_nodes: int, total: int, allocatable: int, allocated: int, available: int, products: list<array>, hardware_without_plugin: list<string>}
     */
    public function summary(array $nodes, ?array $allocation = null): array
    {
        $products = [];
        $withoutPlug = [];
        $total = $allocatable = $allocated = $gpuNodes = 0;

        foreach ($nodes as $node) {
            if ($node['allocatable'] > 0) {
                $gpuNodes++;
            }
            if (! $node['has_device_plugin'] && $node['hardware_detected']) {
                $withoutPlug[] = $node['name'];
            }

            $total += $node['count'];
            $allocatable += $node['ready'] ? $node['allocatable'] : 0;
            $allocated += $node['allocated'];

            if ($node['product'] !== null || $node['allocatable'] > 0) {
                $key = ($node['product'] ?? 'unknown').'|'.($node['memory_mb'] ?? 0);
                $products[$key] ??= [
                    'product' => $node['product'],
                    'memory_mb' => $node['memory_mb'],
                    'count' => 0,
                    'allocatable' => 0,
                    'nodes' => 0,
                ];
                $products[$key]['count'] += $node['count'];
                $products[$key]['allocatable'] += $node['allocatable'];
                $products[$key]['nodes']++;
            }
        }

        $allocated = $allocation['total'] ?? $allocated;

        return [
            'gpu_nodes' => $gpuNodes,
            'total' => $total,
            'allocatable' => $allocatable,
            'allocated' => $allocated,
            'available' => max(0, $allocatable - $allocated),
            'products' => array_values($products),
            'hardware_without_plugin' => $withoutPlug,
        ];
    }

    /** Stored inventory of a region, in the shape of fromNodes()['summary'] (+ nodes). */
    public function stored(KnativeRegion $region): array
    {
        $available = (array) ($region->gpu_available ?? []);
        $nodes = $available['nodes'] ?? [];

        return [
            'nodes' => $nodes,
            'summary' => $available['summary'] ?? $this->summary($nodes),
            'detected_at' => $available['detected_at'] ?? null,
        ];
    }

    /** Products of the region's GPU nodes (nvidia.com/gpu.product values) with their memory. */
    public function products(KnativeRegion $region): array
    {
        return $this->stored($region)['summary']['products'] ?? [];
    }

    /** Compact GPU facts for the region resource. */
    public function publicSummary(KnativeRegion $region): array
    {
        $stored = $this->stored($region);

        return [
            'mode' => $region->gpu_mode ?? 'none',
            'status' => $region->gpu_status ?? 'not_installed',
            'total' => (int) ($stored['summary']['total'] ?? 0),
            'allocatable' => (int) ($stored['summary']['allocatable'] ?? 0),
            'available' => (int) ($stored['summary']['available'] ?? 0),
            'products' => array_map(fn ($p) => [
                'product' => $p['product'],
                'memory_mb' => $p['memory_mb'],
                'count' => $p['count'],
            ], $stored['summary']['products'] ?? []),
        ];
    }

    /** Compute capability (e.g. "8.9") of the GPUs of a product, from the stored inventory. */
    public function computeCapability(KnativeRegion $region, ?string $product = null): ?string
    {
        foreach ($this->stored($region)['nodes'] as $node) {
            if (($product === null || $node['product'] === $product) && ! empty($node['compute_capability'])) {
                return $node['compute_capability'];
            }
        }

        return null;
    }

    /** GPU units in a resource list (allocatable / capacity / limits). */
    public static function units(array $resources): int
    {
        return (int) ($resources[self::RESOURCE] ?? 0) + (int) ($resources[self::SHARED_RESOURCE] ?? 0);
    }

    private static function isReady(array $node): bool
    {
        foreach ($node['status']['conditions'] ?? [] as $c) {
            if (($c['type'] ?? '') === 'Ready') {
                return ($c['status'] ?? '') === 'True';
            }
        }

        return false;
    }

    /** "550.54.15" from GFD's dotted label, or major.minor[.rev] labels. */
    private function version(array $labels, string $exact, string $prefix): ?string
    {
        if (! empty($labels[$exact])) {
            return (string) $labels[$exact];
        }

        $major = $labels[$prefix.'.major'] ?? null;
        if ($major === null) {
            return null;
        }

        return implode('.', array_filter([
            $major,
            $labels[$prefix.'.minor'] ?? null,
            $labels[$prefix.'.rev'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));
    }

    /** Demo regions with gpu_demo_enabled show one simulated GPU node. */
    private function storeDemo(KnativeRegion $region): ?array
    {
        if (Setting::get('gpu_demo_enabled', 'false') !== 'true') {
            return null;
        }

        $inventory = $this->fromNodes([self::demoNode()], []);
        $this->store($region, $inventory);

        return $inventory;
    }

    public static function demoNode(): array
    {
        return [
            'metadata' => [
                'name' => 'demo-gpu-1',
                'labels' => [
                    self::LABEL_PRODUCT => 'NVIDIA-L4',
                    self::LABEL_MEMORY => '23034',
                    self::LABEL_COUNT => '1',
                    'nvidia.com/gpu.compute.major' => '8',
                    'nvidia.com/gpu.compute.minor' => '9',
                ],
            ],
            'status' => [
                'allocatable' => [self::RESOURCE => '1'],
                'capacity' => [self::RESOURCE => '1'],
                'conditions' => [['type' => 'Ready', 'status' => 'True']],
            ],
        ];
    }
}
