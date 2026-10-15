<?php

namespace App\Services;

use App\Models\KnativeRegion;
use App\Services\Ai\Gpu\GpuInstallService;
use App\Services\Concerns\KnativeNodeTaintTrait;
use App\Services\Helpers\KubeQuantity;
use App\Services\Wasm\WasmKnativeFeatures;
use Illuminate\Support\Facades\Log;

/**
 * Worker selection and region capacity.
 *
 * Region capacity (total_cpu_m / total_memory_mb — what region admission, tenant quota defaults,
 * the no-overcommit check and the resource thresholds measure against) is, per node RunR
 * projects run on, the node's allocatable minus the requests of the pods on it that are NOT
 * RunR project workloads: Kubernetes, Knative, the ingress, the RunR control plane,
 * observability, the registry, DaemonSets and anything else the cluster runs. A pod is a project
 * workload when it carries the label runr/project-id — Knative Service pods (template labels),
 * queue consumers, CronJob pods and Kaniko build Job pods. Build pods are transient (≤ 300 s,
 * one per build): counted as platform they would lower the capacity for as long as a build
 * happened to run at the refresh, so they count with the projects.
 */
class KnativeNodeService
{
    use KnativeNodeTaintTrait;

    /** Knative system deployments that need worker tolerations */
    public const KNATIVE_SYSTEM_DEPLOYMENTS = [
        'knative-serving' => ['activator', 'autoscaler', 'controller', 'webhook', 'autoscaler-hpa'],
    ];

    /** Pod label of every RunR project workload (Knative Service, queue consumer, CronJob, build Job pods) */
    public const PROJECT_POD_LABEL = 'runr/project-id';

    // ── Node listing ────────────────────────────────────────────────────────

    /**
     * List worker nodes from the cluster (excluding control-plane nodes). cpu_m / memory_mb are
     * the node's allocatable, available_cpu_m / available_memory_mb what is left of it for
     * projects next to the platform pods already requesting resources there.
     */
    public function listWorkerNodes(KnativeRegion $region): array
    {
        $k8s = new KubernetesClient($region->kubeconfig);
        $list = $k8s->listClusterResources('v1', 'Node');

        if (! $list || empty($list['items'])) {
            return [];
        }

        return $this->buildNodeList($list['items'], $region->selected_workers ?? [], $this->platformRequests($k8s));
    }

    /**
     * List worker nodes using raw kubeconfig (no region required).
     */
    public function listWorkerNodesByKubeconfig(string $kubeconfig): array
    {
        $k8s = new KubernetesClient($kubeconfig);
        $list = $k8s->listClusterResources('v1', 'Node');

        if (! $list || empty($list['items'])) {
            return [];
        }

        return $this->buildNodeList($list['items'], [], $this->platformRequests($k8s));
    }

    // ── Pre-label workers (before DaemonSet deployment) ────────────────────

    /**
     * Label and taint worker nodes using raw kubeconfig (no region required).
     * Used before Zot DaemonSet deployment so nodeSelector can match.
     */
    public function labelWorkerNodesByKubeconfig(string $kubeconfig, array $nodeNames): void
    {
        $k8s = new KubernetesClient($kubeconfig);

        foreach ($nodeNames as $nodeName) {
            $this->taintNode($k8s, $nodeName, true);
        }
    }

    // ── Worker selection ────────────────────────────────────────────────────

    /**
     * Apply worker node selection: taint selected nodes, untaint deselected, update region.
     */
    public function applyWorkerSelection(KnativeRegion $region, array $nodeNames): array
    {
        $k8s = new KubernetesClient($region->kubeconfig);
        $nodeItems = $k8s->listClusterResources('v1', 'Node')['items'] ?? [];
        $allNodes = $this->buildNodeList($nodeItems, $region->selected_workers ?? []);

        foreach ($allNodes as $node) {
            $nodeName = $node['name'];
            $selected = in_array($nodeName, $nodeNames);

            if ($selected) {
                $this->taintNode($k8s, $nodeName, true);
            } else {
                if ($node['is_selected'] ?? false) {
                    $this->taintNode($k8s, $nodeName, false);
                }
            }
        }

        // Capacity = the nodes projects run on, minus what platform pods request there. Without
        // the pod list (API error) it falls back to allocatable; the status check corrects it.
        $pods = $this->activePods($k8s);
        if ($pods === null) {
            Log::warning('Region capacity: pods could not be listed, using node allocatable', ['region' => $region->name]);
        }

        $capacity = empty($nodeNames)
            // No dedicated workers: projects run on every schedulable node (after the untaint
            // above, so the deselected nodes count again)
            ? $this->schedulableCapacity($k8s->listClusterResources('v1', 'Node')['items'] ?? [], $pods)
            : $this->selectedCapacity($nodeItems, $nodeNames, $pods);

        ['total_cpu_m' => $totalCpuM, 'total_memory_mb' => $totalMemMb] = self::storedTotals($capacity);

        $servingNs = $region->knative_serving_namespace ?? 'knative-serving';

        // Enable/disable pod spec features + patch Knative system deployments
        if (! empty($nodeNames)) {
            $this->enablePodSpecFeatures($k8s, $servingNs);
            $this->applySystemTolerations($region);
        } else {
            // WebAssembly functions select their shim nodes: nodeSelector stays enabled for them
            $this->disablePodSpecFeatures($k8s, $servingNs, WasmKnativeFeatures::regionNeedsNodeSelector($region));
            // GPU workloads keep node selection / tolerations / RuntimeClass / PVC (docs/ai.md)
            if (($region->gpu_mode ?? 'none') !== 'none') {
                app(GpuInstallService::class)->enableKnativeFeatures($k8s, $region);
            }
        }

        // Update region
        $region->update([
            'selected_workers' => $nodeNames,
            'total_cpu_m' => $totalCpuM,
            'total_memory_mb' => $totalMemMb,
        ]);

        return [
            'total_cpu_m' => $totalCpuM,
            'total_memory_mb' => $totalMemMb,
        ];
    }

    // ── Region capacity ─────────────────────────────────────────────────────

    /**
     * Capacity of the nodes RunR projects can run on when no dedicated workers are selected:
     * Ready Linux nodes that are not cordoned and have no NoSchedule/NoExecute taint. A
     * control-plane node counts only when it takes workloads (untainted, e.g. single-node k3s).
     * With $podItems, each node counts with its allocatable minus the requests of the
     * non-project pods on it (platformRequestsByNode); without, with its allocatable.
     *
     * @param  array  $nodeItems  v1 Node items
     * @param  array|null  $podItems  v1 Pod items of all namespaces (listActivePods)
     * @return array{total_cpu_m: int, total_memory_mb: int, nodes: list<string>, allocatable_cpu_m: int, allocatable_memory_mb: int, platform_cpu_m: int, platform_memory_mb: int}
     */
    public function schedulableCapacity(array $nodeItems, ?array $podItems = null): array
    {
        $nodes = array_filter($nodeItems, fn (array $node) => $this->isSchedulableForWorkloads($node));

        return $this->sumCapacity($nodes, $podItems === null ? [] : $this->platformRequestsByNode($podItems));
    }

    /**
     * Capacity of the dedicated workers of a region (selected_workers): the selected nodes that
     * exist and are not control-plane nodes (the ones the selection offers, buildNodeList),
     * whatever their readiness — minus the platform pods on them (Knative system deployments
     * move onto the workers with the selection, DaemonSets run there too).
     *
     * @param  list<string>  $selected
     */
    public function selectedCapacity(array $nodeItems, array $selected, ?array $podItems = null): array
    {
        $nodes = array_filter($nodeItems, fn (array $node) => in_array($node['metadata']['name'] ?? '', $selected, true)
            && ! $this->isControlPlane($node['metadata']['labels'] ?? []));

        return $this->sumCapacity($nodes, $podItems === null ? [] : $this->platformRequestsByNode($podItems));
    }

    /**
     * What RunR projects can use on a region right now: its dedicated workers (selected_workers)
     * or, without a selection, its schedulable nodes — each minus the requests of the
     * non-project pods on it. Null when the nodes or the pods cannot be listed or no node
     * counts: a failed list must not replace a known capacity, and without the pods the
     * figure would be node allocatable again.
     */
    public function regionCapacity(KnativeRegion $region, KubernetesClient $k8s): ?array
    {
        $nodeItems = $k8s->listClusterResources('v1', 'Node')['items'] ?? [];
        if ($nodeItems === []) {
            return null;
        }

        $pods = $this->activePods($k8s);
        if ($pods === null) {
            return null;
        }

        $selected = array_values(array_filter((array) ($region->selected_workers ?? [])));
        $capacity = $selected === []
            ? $this->schedulableCapacity($nodeItems, $pods)
            : $this->selectedCapacity($nodeItems, $selected, $pods);

        return $capacity['nodes'] === [] ? null : $capacity;
    }

    /**
     * Keep total_cpu_m / total_memory_mb of a region equal to what its nodes leave for projects
     * (regionCapacity) — nodes come and go, platform pods are added, moved and resized. Run by
     * the periodic region status check (KnativeStatusService) and before a tenant quota is
     * derived from a region without a capacity yet. Returns the stored totals, or null when
     * nothing could be measured (the stored capacity is kept).
     */
    public function refreshSchedulableCapacity(KnativeRegion $region, ?KubernetesClient $k8s = null): ?array
    {
        if (! $region->kubeconfig) {
            return null;
        }

        $k8s ??= new KubernetesClient($region->kubeconfig);
        $capacity = $this->regionCapacity($region, $k8s);

        if ($capacity === null) {
            return null;
        }

        $totals = self::storedTotals($capacity);

        if ((int) $region->total_cpu_m !== $totals['total_cpu_m'] || (int) $region->total_memory_mb !== $totals['total_memory_mb']) {
            $region->update($totals);
        }

        return $totals;
    }

    /**
     * CPU / memory requested per node by pods that are not RunR project workloads, from a pod
     * list of all namespaces. Only pods bound to a node (spec.nodeName) that are not finished
     * (phase Succeeded / Failed) count — what the scheduler counts on a node.
     *
     * @return array<string, array{cpu_m: int, memory_bytes: int}>
     */
    public function platformRequestsByNode(array $podItems): array
    {
        $byNode = [];

        foreach ($podItems as $pod) {
            $node = $pod['spec']['nodeName'] ?? '';
            if ($node === '' || in_array($pod['status']['phase'] ?? '', ['Succeeded', 'Failed'], true)) {
                continue;
            }
            if (self::isProjectWorkload($pod)) {
                continue;
            }

            $requests = self::podRequests($pod);
            $byNode[$node]['cpu_m'] = ($byNode[$node]['cpu_m'] ?? 0) + $requests['cpu_m'];
            $byNode[$node]['memory_bytes'] = ($byNode[$node]['memory_bytes'] ?? 0) + $requests['memory_bytes'];
        }

        return $byNode;
    }

    /** A RunR project workload: the pod carries runr/project-id. */
    public static function isProjectWorkload(array $pod): bool
    {
        return (string) ($pod['metadata']['labels'][self::PROJECT_POD_LABEL] ?? '') !== '';
    }

    /**
     * Effective CPU / memory request of a pod as the scheduler computes it: the sum of its
     * containers plus its sidecars (init containers with restartPolicy Always), at least the
     * largest regular init container (plus the sidecars started before it), plus the
     * RuntimeClass overhead. Pod-level resources (spec.resources) replace the container sum.
     *
     * @return array{cpu_m: int, memory_bytes: int}
     */
    public static function podRequests(array $pod): array
    {
        $spec = $pod['spec'] ?? [];
        $read = fn (array $container) => [
            'cpu_m' => KubeQuantity::millicores($container['resources']['requests']['cpu'] ?? 0),
            'memory_bytes' => KubeQuantity::bytes($container['resources']['requests']['memory'] ?? 0),
        ];
        $add = fn (array $a, array $b) => ['cpu_m' => $a['cpu_m'] + $b['cpu_m'], 'memory_bytes' => $a['memory_bytes'] + $b['memory_bytes']];
        $max = fn (array $a, array $b) => ['cpu_m' => max($a['cpu_m'], $b['cpu_m']), 'memory_bytes' => max($a['memory_bytes'], $b['memory_bytes'])];

        $total = ['cpu_m' => 0, 'memory_bytes' => 0];
        foreach ($spec['containers'] ?? [] as $container) {
            $total = $add($total, $read($container));
        }

        $sidecars = ['cpu_m' => 0, 'memory_bytes' => 0];
        $initMax = ['cpu_m' => 0, 'memory_bytes' => 0];
        foreach ($spec['initContainers'] ?? [] as $container) {
            $request = $read($container);
            if (($container['restartPolicy'] ?? null) === 'Always') {
                $total = $add($total, $request);
                $sidecars = $add($sidecars, $request);
                $initMax = $max($initMax, $sidecars);
            } else {
                $initMax = $max($initMax, $add($request, $sidecars));
            }
        }
        $total = $max($total, $initMax);

        // Pod-level resources (KEP-2837) take the place of the container sum where set
        $podLevel = $spec['resources']['requests'] ?? [];
        if (isset($podLevel['cpu'])) {
            $total['cpu_m'] = KubeQuantity::millicores($podLevel['cpu']);
        }
        if (isset($podLevel['memory'])) {
            $total['memory_bytes'] = KubeQuantity::bytes($podLevel['memory']);
        }

        return $add($total, $read(['resources' => ['requests' => $spec['overhead'] ?? []]]));
    }

    /**
     * Totals as stored on the region. A region whose nodes are fully requested by platform pods
     * stores 1 (m / MiB) instead of 0: 0 means "no capacity known" and skips the capacity check.
     *
     * @return array{total_cpu_m: int, total_memory_mb: int}
     */
    private static function storedTotals(array $capacity): array
    {
        $hasNodes = ! empty($capacity['nodes']);

        return [
            'total_cpu_m' => $hasNodes ? max(1, $capacity['total_cpu_m']) : $capacity['total_cpu_m'],
            'total_memory_mb' => $hasNodes ? max(1, $capacity['total_memory_mb']) : $capacity['total_memory_mb'],
        ];
    }

    /**
     * @param  iterable<array>  $nodes  v1 Node items
     * @param  array<string, array{cpu_m: int, memory_bytes: int}>  $platform  platformRequestsByNode()
     */
    private function sumCapacity(iterable $nodes, array $platform): array
    {
        $sum = [
            'total_cpu_m' => 0, 'total_memory_mb' => 0, 'nodes' => [],
            'allocatable_cpu_m' => 0, 'allocatable_memory_mb' => 0,
            'platform_cpu_m' => 0, 'platform_memory_mb' => 0,
        ];

        foreach ($nodes as $node) {
            $name = $node['metadata']['name'] ?? '';
            $figures = $this->nodeFigures($node, $platform[$name] ?? null);

            $sum['total_cpu_m'] += $figures['available_cpu_m'];
            $sum['total_memory_mb'] += $figures['available_memory_mb'];
            $sum['allocatable_cpu_m'] += $figures['cpu_m'];
            $sum['allocatable_memory_mb'] += $figures['memory_mb'];
            $sum['platform_cpu_m'] += $figures['cpu_m'] - $figures['available_cpu_m'];
            $sum['platform_memory_mb'] += $figures['memory_mb'] - $figures['available_memory_mb'];
            $sum['nodes'][] = $name;
        }

        return $sum;
    }

    /**
     * Allocatable and what is left of it for projects next to the given platform requests.
     *
     * @param  array{cpu_m: int, memory_bytes: int}|null  $platform
     * @return array{cpu_m: int, memory_mb: int, available_cpu_m: int, available_memory_mb: int}
     */
    private function nodeFigures(array $node, ?array $platform): array
    {
        $allocatable = $node['status']['allocatable'] ?? [];
        $cpuM = KubeQuantity::millicores($allocatable['cpu'] ?? 0);
        $memBytes = KubeQuantity::bytes($allocatable['memory'] ?? 0);
        $memMb = KubeQuantity::toMib($memBytes);

        return [
            'cpu_m' => $cpuM,
            'memory_mb' => $memMb,
            'available_cpu_m' => max(0, $cpuM - ($platform['cpu_m'] ?? 0)),
            'available_memory_mb' => $platform ? min($memMb, KubeQuantity::toMib(max(0, $memBytes - $platform['memory_bytes']))) : $memMb,
        ];
    }

    /**
     * Unfinished pods of all namespaces, or null when they cannot be listed.
     *
     * @return list<array>|null
     */
    private function activePods(KubernetesClient $k8s): ?array
    {
        try {
            return $k8s->listActivePods();
        } catch (\Throwable $e) {
            Log::warning('Listing pods for the region capacity failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Platform requests per node for the node list; empty (allocatable shown as available)
     * when the pods cannot be listed.
     */
    private function platformRequests(KubernetesClient $k8s): array
    {
        $pods = $this->activePods($k8s);

        return $pods === null ? [] : $this->platformRequestsByNode($pods);
    }

    private function isControlPlane(array $labels): bool
    {
        return isset($labels['node-role.kubernetes.io/control-plane']) || isset($labels['node-role.kubernetes.io/master']);
    }

    private function isSchedulableForWorkloads(array $node): bool
    {
        if (! empty($node['spec']['unschedulable'])) {
            return false;
        }
        if (($node['metadata']['labels']['kubernetes.io/os'] ?? 'linux') !== 'linux') {
            return false;
        }
        foreach ($node['spec']['taints'] ?? [] as $taint) {
            if (in_array($taint['effect'] ?? '', ['NoSchedule', 'NoExecute'], true)) {
                return false;
            }
        }
        foreach ($node['status']['conditions'] ?? [] as $c) {
            if (($c['type'] ?? '') === 'Ready') {
                return ($c['status'] ?? '') === 'True';
            }
        }

        return false;
    }

    // ── Pod spec features & tolerations (used by install flows too) ──────

    /**
     * Enable pod spec features (tolerations, nodeSelector) in Knative Serving config.
     *
     * Prefers patching CR (operator reconciles ConfigMap).
     * Falls back to direct ConfigMap patch if CR not found (no operator).
     */
    public function enablePodSpecFeatures(KubernetesClient $k8s, string $servingNamespace = 'knative-serving'): void
    {
        $features = [
            'kubernetes.podspec-init-containers' => 'enabled',
            'kubernetes.podspec-emptydir' => 'enabled',
            'kubernetes.podspec-tolerations' => 'enabled',
            'kubernetes.podspec-nodeselector' => 'enabled',
        ];

        $this->patchPodSpecFeatures($k8s, 'KnativeServing', 'knative-serving', $servingNamespace, $features);
    }

    /**
     * Disable pod spec features when no workers are selected.
     */
    public function disablePodSpecFeatures(KubernetesClient $k8s, string $servingNamespace = 'knative-serving', bool $keepNodeSelector = false): void
    {
        $features = [
            'kubernetes.podspec-init-containers' => 'enabled',
            'kubernetes.podspec-emptydir' => 'enabled',
            'kubernetes.podspec-tolerations' => 'disabled',
            'kubernetes.podspec-nodeselector' => $keepNodeSelector ? 'enabled' : 'disabled',
        ];

        $this->patchPodSpecFeatures($k8s, 'KnativeServing', 'knative-serving', $servingNamespace, $features);
    }

    /**
     * Patch pod spec features on a Knative CR, fallback to ConfigMap if CR not found.
     */
    private function patchPodSpecFeatures(KubernetesClient $k8s, string $crKind, string $crName, string $namespace, array $features): void
    {
        $cr = $k8s->getResourceStatus(
            'operator.knative.dev/v1beta1', $crKind, $crName, $namespace
        );

        if (! $cr) {
            // Fallback: patch the ConfigMap directly (no operator)
            $existing = $k8s->getResourceStatus('v1', 'ConfigMap', 'config-features', $namespace);
            if ($existing) {
                $k8s->strategicMergePatch('v1', 'ConfigMap', 'config-features', $namespace, ['data' => $features]);
            } else {
                $k8s->applyResource(['apiVersion' => 'v1', 'kind' => 'ConfigMap', 'metadata' => ['name' => 'config-features', 'namespace' => $namespace], 'data' => $features], $namespace);
            }

            return;
        }

        $k8s->mergePatch(
            'operator.knative.dev/v1beta1', $crKind, $crName, $namespace, [
                'spec' => [
                    'config' => [
                        'features' => $features,
                    ],
                ],
            ]
        );
    }

    /**
     * Apply tolerations + nodeSelector to Knative system deployments so they
     * run on tainted worker nodes.
     */
    public function applySystemTolerations(KnativeRegion $region): void
    {
        $k8s = new KubernetesClient($region->kubeconfig);

        $servingNs = $region->knative_serving_namespace ?? 'knative-serving';

        $nsMap = [
            'knative-serving' => $servingNs,
        ];

        $toleration = [
            'key' => self::TAINT_KEY,
            'operator' => 'Equal',
            'value' => self::TAINT_VALUE,
            'effect' => 'NoSchedule',
        ];

        foreach (self::KNATIVE_SYSTEM_DEPLOYMENTS as $defaultNs => $deployments) {
            $actualNs = $nsMap[$defaultNs] ?? $defaultNs;
            foreach ($deployments as $deployment) {
                $existing = $k8s->getResourceStatus('apps/v1', 'Deployment', $deployment, $actualNs);
                if (! $existing) {
                    continue;
                }

                $patch = [
                    'spec' => [
                        'template' => [
                            'spec' => [
                                'tolerations' => [$toleration],
                                'nodeSelector' => [self::LABEL_KEY => self::LABEL_VALUE],
                            ],
                        ],
                    ],
                ];

                $k8s->strategicMergePatch('apps/v1', 'Deployment', $deployment, $actualNs, $patch);
            }
        }
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    /**
     * Build node list from K8s Node items, filtering out control-plane nodes.
     *
     * @param  array<string, array{cpu_m: int, memory_bytes: int}>  $platform  platformRequestsByNode()
     */
    private function buildNodeList(array $items, array $selectedWorkers = [], array $platform = []): array
    {
        $nodes = [];

        foreach ($items as $node) {
            $labels = $node['metadata']['labels'] ?? [];
            $name = $node['metadata']['name'];

            if ($this->isControlPlane($labels)) {
                continue;
            }

            $conditions = $node['status']['conditions'] ?? [];
            $figures = $this->nodeFigures($node, $platform[$name] ?? null);

            $ready = 'Unknown';
            foreach ($conditions as $c) {
                if ($c['type'] === 'Ready') {
                    $ready = $c['status'] === 'True' ? 'Ready' : 'NotReady';
                    break;
                }
            }

            $entry = [
                'name' => $name,
                'labels' => $labels,
                'cpu_m' => $figures['cpu_m'],
                'memory_mb' => $figures['memory_mb'],
                'available_cpu_m' => $figures['available_cpu_m'],
                'available_memory_mb' => $figures['available_memory_mb'],
                'status' => $ready,
            ];

            if (! empty($selectedWorkers)) {
                $entry['is_selected'] = in_array($name, $selectedWorkers);
            } else {
                // Auto-select nodes that already have the knative-worker role label
                $entry['is_selected'] = isset($labels[self::ROLE_LABEL]);
            }

            $nodes[] = $entry;
        }

        return $nodes;
    }
}
