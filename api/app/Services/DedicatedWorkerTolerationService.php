<?php

namespace App\Services;

use App\Models\KnativeRegion;
use App\Services\Bindings\BindingManifests;
use App\Services\Bindings\BindingsCluster;
use App\Services\Helpers\DedicatedWorkers;
use App\Services\Helpers\ManagedLoki;
use App\Services\Helpers\ManagedTracing;
use App\Services\Helpers\RunrClusterIdentity;
use App\Services\Manifests\ZotManifests;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Keeps RunR's components schedulable when the region's worker selection changes
 * (KnativeNodeService::applyWorkerSelection(), region:reconcile's DedicatedWorkerTolerationStep).
 *
 * Nodes selected as dedicated workers get the taint runr.dev/dedicated=projects:NoSchedule. The
 * taint leaves a running pod alone, but the next one — a restart, a new pod template, a Helm
 * upgrade, a moved image — of a component whose node-bound volume is on such a node has to start
 * there again (its volume's node affinity) and stays Pending without the toleration: "untolerated
 * taint {runr.dev/dedicated: projects}" + "volume node affinity conflict". The components
 * (workloads()): the registry (Zot's StatefulSet), Prometheus, Loki, Tempo and the data services
 * (KV Redis, the Garage object store, the CloudNativePG clusters) — where RunR installed them.
 * Installs of this release tolerate the taint already (DedicatedWorkers::systemPlacement()); one
 * installed by an earlier release gets the toleration added to its pod template when it needs it:
 * a pod of it runs on a dedicated worker (it restarts once, on the same node) or waits Pending on
 * the taint. A Pending pod created from the spec without the toleration is replaced: a
 * StatefulSet (OrderedReady) does not replace a pod that never became ready by itself, and an
 * operator does not roll an instance it cannot reach. A Deployment replaces its pods itself.
 *
 * Deselected nodes lose the label runr.dev/dedicated=projects, which a registry installed on the
 * dedicated workers and Knative's system deployments select with a nodeSelector
 * (registryPlacement(), knativePlacement()):
 *  - Zot leaves the nodeSelector for the placement of a system component (any node, the
 *    toleration, the other nodes preferred) when no node carries the label any more, or when its
 *    volume keeps it on a node that lost the label (its pod there, or Pending on the nodeSelector);
 *  - the registry's node mirror (DaemonSet runr-registry-mirror) is applied for every node once no
 *    node carries the label: projects then run on any node, and every node that pulls from the
 *    registry needs its containerd mirror configuration;
 *  - Knative's system deployments leave the nodeSelector once no node carries the label.
 * While some node still carries the label, the mirror and Knative stay on the dedicated workers.
 */
class DedicatedWorkerTolerationService
{
    /** Seconds an operator-managed resource (Prometheus, a CloudNativePG cluster) gets to pick up a patched spec */
    private const OPERATOR_SETTLE_SECONDS = 2;

    private const MIRROR = 'runr-registry-mirror';

    /**
     * Brings RunR's components to the cluster's dedicated workers as they are now: the registry
     * and Knative off a nodeSelector no node satisfies, the toleration where a component with a
     * node-bound volume needs it, Pending pods replaced.
     *
     * @param  iterable<KnativeRegion>  $regions  the regions on the cluster (which components RunR installed)
     * @param  list<array>  $nodes  the cluster's v1 Node items, labels and taints as they are now
     * @return list<string> what changed (dry run: would change); empty = nothing to do
     */
    public function reconcile(KubernetesClient $k8s, iterable $regions, array $nodes, bool $dryRun): array
    {
        $regions = is_array($regions) ? array_values($regions) : iterator_to_array($regions, false);
        $labeled = [];
        $dedicated = [];
        foreach ($nodes as $node) {
            $name = (string) ($node['metadata']['name'] ?? '');
            if (($node['metadata']['labels'][DedicatedWorkers::KEY] ?? null) === DedicatedWorkers::VALUE) {
                $labeled[] = $name;
            }
            if (DedicatedWorkers::isTainted($node)) {
                $dedicated[] = $name;
            }
        }

        $lines = array_merge(
            $this->registryPlacement($k8s, $regions, $nodes, $labeled, $dryRun),
            $this->knativePlacement($k8s, $regions, $labeled, $dryRun),
        );

        if ($dedicated !== []) {
            foreach ($this->workloads($k8s, $regions) as $workload) {
                $line = $this->reconcileWorkload($k8s, $workload, $dedicated, $dryRun);
                if ($line !== null) {
                    $lines[] = $line;
                }
            }
        }

        return $lines;
    }

    /**
     * The registry of each internal registry namespace: Zot off its nodeSelector where that holds
     * it nowhere (releaseZot()), the node mirror on every node once there are no dedicated workers.
     *
     * @param  list<KnativeRegion>  $regions
     * @param  list<array>  $nodes
     * @param  list<string>  $labeled  nodes labelled runr.dev/dedicated=projects
     * @return list<string>
     */
    private function registryPlacement(KubernetesClient $k8s, array $regions, array $nodes, array $labeled, bool $dryRun): array
    {
        $lines = [];
        $seen = [];
        foreach ($regions as $region) {
            $ns = $region->registry_namespace ?: ZotRegistryService::ZOT_DEFAULT_NS;
            if ($region->registry_mode !== 'internal' || isset($seen[$ns])) {
                continue;
            }
            $seen[$ns] = true;

            foreach (['StatefulSet', 'Deployment'] as $kind) {
                $zot = $k8s->getResourceStatus('apps/v1', $kind, ZotRegistryService::ZOT_RELEASE_NAME, $ns);
                if ($zot) {
                    $line = $this->releaseZot($k8s, $kind, $zot, $ns, $labeled, $dryRun);
                    if ($line !== null) {
                        $lines[] = $line;
                    }
                    break;
                }
            }

            $line = $this->mirrorOnEveryNode($k8s, $region, $ns, $nodes, $labeled, $dryRun);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Zot installed on the dedicated workers (nodeSelector runr.dev/dedicated=projects) gets the
     * placement of a system component — the nodeSelector dropped, the toleration kept, the other
     * nodes preferred — when it could not start again: no node carries the label (Zot on S3 or on a
     * volume alike), or its volume binds it to a node that lost the label (a StatefulSet's pod
     * there, or Pending on the nodeSelector — replaced, as the StatefulSet would not).
     *
     * @param  list<string>  $labeled
     */
    private function releaseZot(KubernetesClient $k8s, string $kind, array $zot, string $ns, array $labeled, bool $dryRun): ?string
    {
        $spec = $zot['spec']['template']['spec'] ?? [];
        if (($spec['nodeSelector'][DedicatedWorkers::KEY] ?? null) !== DedicatedWorkers::VALUE) {
            return null;
        }

        $reasons = $labeled === [] ? ['no node is a dedicated worker any more'] : [];
        $stuck = [];
        if ($kind === 'StatefulSet') {
            $pods = $k8s->listResources('v1', 'Pod', $ns, ['app.kubernetes.io/instance' => ZotRegistryService::ZOT_RELEASE_NAME])['items'] ?? [];
            foreach ($pods as $pod) {
                if (! empty($pod['metadata']['deletionTimestamp'])) {
                    continue;
                }
                $name = (string) ($pod['metadata']['name'] ?? '');
                $node = (string) ($pod['spec']['nodeName'] ?? '');
                if ($node !== '' && $labeled !== [] && ! in_array($node, $labeled, true)) {
                    $reasons[] = "its volume keeps pod {$name} on {$node}, no dedicated worker any more";
                } elseif ($node === '' && self::pendingOnNodeSelector($pod)) {
                    $stuck[] = $name;
                    $reasons[] = "pod {$name} waits Pending on the nodeSelector and ".($dryRun ? 'would be' : 'is').' replaced';
                }
            }
        }
        if ($reasons === []) {
            return null;
        }

        $ref = "{$kind} {$ns}/".ZotRegistryService::ZOT_RELEASE_NAME;
        $what = "{$ref} from the dedicated workers to any node (nodeSelector ".DedicatedWorkers::KEY.'='.DedicatedWorkers::VALUE
            .' dropped, the toleration of '.DedicatedWorkers::taint().' kept, the other nodes preferred): '.implode('; ', $reasons);
        if ($dryRun) {
            return "would move {$what}";
        }

        $placement = [
            'nodeSelector' => [DedicatedWorkers::KEY => null],
            'tolerations' => DedicatedWorkers::withToleration((array) ($spec['tolerations'] ?? [])),
        ];
        if (empty($spec['affinity']['nodeAffinity'])) {
            $placement['affinity'] = DedicatedWorkers::preferOtherNodes();
        }
        if (! $k8s->mergePatch('apps/v1', $kind, ZotRegistryService::ZOT_RELEASE_NAME, $ns, ['spec' => ['template' => ['spec' => $placement]]])) {
            throw new RuntimeException("merge patch of {$ref} was rejected");
        }
        if ($stuck !== []) {
            $this->waitObserved($k8s, ['kind' => $kind, 'name' => ZotRegistryService::ZOT_RELEASE_NAME, 'namespace' => $ns]);
            $this->deletePods($k8s, $ns, $stuck);
        }

        return "moved {$what}";
    }

    /**
     * The node mirror installed for the dedicated workers only (DaemonSet runr-registry-mirror with
     * their nodeSelector) is applied for every node once no node carries the label: projects then
     * run on any schedulable node, and a node pulls from the registry (an http address) only with
     * the containerd configuration the mirror writes. While dedicated workers exist it stays on
     * them — it follows their label by itself.
     *
     * @param  list<array>  $nodes
     * @param  list<string>  $labeled
     */
    private function mirrorOnEveryNode(KubernetesClient $k8s, KnativeRegion $region, string $ns, array $nodes, array $labeled, bool $dryRun): ?string
    {
        if ($labeled !== []) {
            return null;
        }
        $mirror = $k8s->getResourceStatus('apps/v1', 'DaemonSet', self::MIRROR, $ns);
        if (($mirror['spec']['template']['spec']['nodeSelector'][DedicatedWorkers::KEY] ?? null) !== DedicatedWorkers::VALUE) {
            return null;
        }

        $ref = "DaemonSet {$ns}/".self::MIRROR;
        if (! $region->registry_address) {
            return "skipped: {$ref} selects the dedicated workers, none is left, and region {$region->name} has no registry address to configure the nodes with";
        }
        $what = "{$ref} for every node: no node is a dedicated worker any more, projects run on any node and pull from the registry there"
            .' (a node whose containerd configuration it changes needs the restart its log names)';
        if ($dryRun) {
            return "would apply {$what}";
        }

        $flavor = ZotManifests::mirrorFlavorForNodes($nodes, false);
        $inPlace = count(app(KnativeNodeService::class)->schedulableCapacity($nodes)['nodes']) <= 1
            || app(RunrClusterIdentity::class)->isRunrCluster($region);
        $manifest = ZotManifests::mirrorDaemonSet((string) $region->registry_address, ZotRegistryService::ZOT_RELEASE_NAME, $ns, false, $flavor, $inPlace, $region->id);
        if (! $k8s->applyResource($manifest, $ns)) {
            throw new RuntimeException("{$ref} could not be applied");
        }
        // a DaemonSet created by another field manager keeps the fields the apply does not name
        if (! $k8s->mergePatch('apps/v1', 'DaemonSet', self::MIRROR, $ns, ['spec' => ['template' => ['spec' => ['nodeSelector' => [DedicatedWorkers::KEY => null]]]]])) {
            throw new RuntimeException("merge patch of {$ref} was rejected");
        }

        return "applied {$what}";
    }

    /**
     * Knative's system deployments that KnativeNodeService::applySystemTolerations() put on the
     * dedicated workers leave their nodeSelector once no node carries the label (their toleration
     * stays): otherwise their next pods would find no node.
     *
     * @param  list<KnativeRegion>  $regions
     * @param  list<string>  $labeled
     * @return list<string>
     */
    private function knativePlacement(KubernetesClient $k8s, array $regions, array $labeled, bool $dryRun): array
    {
        if ($labeled === []) {
            $namespaces = array_values(array_unique(array_map(fn (KnativeRegion $r) => $r->knative_serving_namespace ?: 'knative-serving', $regions)));
        } else {
            $namespaces = [];
        }

        $lines = [];
        foreach ($namespaces as $ns) {
            $released = [];
            foreach (KnativeNodeService::KNATIVE_SYSTEM_DEPLOYMENTS['knative-serving'] as $name) {
                $deployment = $k8s->getResourceStatus('apps/v1', 'Deployment', $name, $ns);
                if (($deployment['spec']['template']['spec']['nodeSelector'][DedicatedWorkers::KEY] ?? null) !== DedicatedWorkers::VALUE) {
                    continue;
                }
                if (! $dryRun && ! $k8s->mergePatch('apps/v1', 'Deployment', $name, $ns, ['spec' => ['template' => ['spec' => ['nodeSelector' => [DedicatedWorkers::KEY => null]]]]])) {
                    throw new RuntimeException("merge patch of Deployment {$ns}/{$name} was rejected");
                }
                $released[] = $name;
            }
            if ($released !== []) {
                $lines[] = ($dryRun ? 'would drop' : 'dropped').' the nodeSelector '.DedicatedWorkers::KEY.'='.DedicatedWorkers::VALUE
                    ." of Knative's ".implode(', ', $released)." in {$ns}: no node is a dedicated worker any more";
            }
        }

        return $lines;
    }

    /**
     * RunR's components with a node-bound volume on the cluster, as they are now: the object
     * holding the pod template (or the operator resource), where its pod tolerations are in it
     * (path) and the label selector of its pods (pods).
     *
     * @param  iterable<KnativeRegion>  $regions
     * @return list<array{apiVersion: string, kind: string, name: string, namespace: string, path: list<string>, pods: array<string, string>, object: array}>
     */
    public function workloads(KubernetesClient $k8s, iterable $regions): array
    {
        $regions = is_array($regions) ? $regions : iterator_to_array($regions, false);
        $podSpec = ['spec', 'template', 'spec', 'tolerations'];
        $found = [];

        $add = function (string $apiVersion, string $kind, string $name, string $namespace, array $pods, array $path, ?\Closure $ours = null) use ($k8s, &$found): void {
            $object = $k8s->getResourceStatus($apiVersion, $kind, $name, $namespace);
            if ($object && (! $ours || $ours($object))) {
                $found[] = compact('apiVersion', 'kind', 'name', 'namespace', 'path', 'pods', 'object');
            }
        };
        $managedByRunr = fn (array $object) => ($object['metadata']['labels']['app.kubernetes.io/managed-by'] ?? '') === 'runr';

        // The registry: Zot on a volume is a StatefulSet (the S3-backed Deployment has none)
        $registryNamespaces = [];
        foreach ($regions as $region) {
            if ($region->registry_mode === 'internal') {
                $registryNamespaces[$region->registry_namespace ?: ZotRegistryService::ZOT_DEFAULT_NS] = true;
            }
        }
        foreach (array_keys($registryNamespaces) as $ns) {
            $add('apps/v1', 'StatefulSet', ZotRegistryService::ZOT_RELEASE_NAME, (string) $ns, ['app.kubernetes.io/instance' => ZotRegistryService::ZOT_RELEASE_NAME], $podSpec);
        }

        // Prometheus (kube-prometheus-stack release "knative") and Loki, where RunR installed them
        if (collect($regions)->contains(fn (KnativeRegion $r) => $r->isPrometheusManaged())) {
            foreach ($k8s->listResources('monitoring.coreos.com/v1', 'Prometheus', 'observability', ['release' => 'knative'])['items'] ?? [] as $prometheus) {
                $name = (string) ($prometheus['metadata']['name'] ?? '');
                if ($name !== '') {
                    $found[] = [
                        'apiVersion' => 'monitoring.coreos.com/v1', 'kind' => 'Prometheus', 'name' => $name, 'namespace' => 'observability',
                        'path' => ['spec', 'tolerations'],
                        'pods' => ['app.kubernetes.io/name' => 'prometheus', 'app.kubernetes.io/instance' => $name],
                        'object' => $prometheus,
                    ];
                }
            }
        }
        if (collect($regions)->contains(fn (KnativeRegion $r) => $r->isLokiManaged())) {
            $add('apps/v1', 'StatefulSet', ManagedLoki::RELEASE, ManagedLoki::NAMESPACE, ['app' => 'loki', 'release' => ManagedLoki::RELEASE], $podSpec,
                fn (array $object) => ($object['metadata']['labels']['release'] ?? '') === ManagedLoki::RELEASE);
        }

        // RunR's Tempo: its pods carry app.kubernetes.io/part-of=runr (a reused Tempo is not RunR's)
        $add('apps/v1', 'StatefulSet', ManagedTracing::TEMPO_RELEASE, ManagedTracing::NAMESPACE,
            ['app.kubernetes.io/name' => ManagedTracing::TEMPO_CHART, 'app.kubernetes.io/instance' => ManagedTracing::TEMPO_RELEASE], $podSpec,
            fn (array $object) => ($object['spec']['template']['metadata']['labels']['app.kubernetes.io/part-of'] ?? '') === 'runr');

        // The data services of bindings
        $data = BindingsCluster::dataNamespace();
        $add('apps/v1', 'Deployment', BindingManifests::KV_NAME, $data, ['app.kubernetes.io/name' => BindingManifests::KV_NAME], $podSpec, $managedByRunr);
        $add('apps/v1', 'StatefulSet', BindingManifests::OBJECT_NAME, $data, ['app.kubernetes.io/name' => BindingManifests::OBJECT_NAME], $podSpec, $managedByRunr);
        foreach ($k8s->listResources(BindingManifests::CNPG_API, 'Cluster', $data, ['app.kubernetes.io/managed-by' => 'runr'])['items'] ?? [] as $cluster) {
            $name = (string) ($cluster['metadata']['name'] ?? '');
            if ($name !== '') {
                $found[] = [
                    'apiVersion' => BindingManifests::CNPG_API, 'kind' => 'Cluster', 'name' => $name, 'namespace' => $data,
                    'path' => ['spec', 'affinity', 'tolerations'],
                    'pods' => ['cnpg.io/cluster' => $name, 'cnpg.io/podRole' => 'instance'],
                    'object' => $cluster,
                ];
            }
        }

        return $found;
    }

    /**
     * A pod the scheduler cannot place because of the dedicated-worker taint: Pending, not
     * scheduled, the scheduler's message naming the taint (with the volume's node affinity it
     * reads "1 node(s) had untolerated taint {runr.dev/dedicated: projects}, 1 node(s) had
     * volume node affinity conflict").
     */
    public static function pendingOnTaint(array $pod): bool
    {
        if (($pod['status']['phase'] ?? '') !== 'Pending' || ! empty($pod['spec']['nodeName'])) {
            return false;
        }

        foreach ($pod['status']['conditions'] ?? [] as $condition) {
            if (($condition['type'] ?? '') === 'PodScheduled' && ($condition['status'] ?? '') === 'False') {
                return str_contains((string) ($condition['message'] ?? ''), DedicatedWorkers::KEY);
            }
        }

        return false;
    }

    /**
     * A pod the scheduler cannot place on any node its nodeSelector (or node affinity) allows:
     * Pending, not scheduled, "node(s) didn't match Pod's node affinity/selector".
     */
    public static function pendingOnNodeSelector(array $pod): bool
    {
        if (($pod['status']['phase'] ?? '') !== 'Pending' || ! empty($pod['spec']['nodeName'])) {
            return false;
        }

        foreach ($pod['status']['conditions'] ?? [] as $condition) {
            if (($condition['type'] ?? '') === 'PodScheduled' && ($condition['status'] ?? '') === 'False') {
                return preg_match("~didn't match (Pod's )?node (affinity/)?selector~", (string) ($condition['message'] ?? '')) === 1;
            }
        }

        return false;
    }

    /**
     * @param  array{apiVersion: string, kind: string, name: string, namespace: string, path: list<string>, pods: array<string, string>, object: array}  $workload
     * @param  list<string>  $dedicated
     */
    private function reconcileWorkload(KubernetesClient $k8s, array $workload, array $dedicated, bool $dryRun): ?string
    {
        $tolerations = (array) (data_get($workload['object'], $workload['path']) ?? []);
        $tolerated = DedicatedWorkers::tolerated($tolerations);

        $onWorkers = [];
        $stale = [];
        foreach ($k8s->listResources('v1', 'Pod', $workload['namespace'], $workload['pods'])['items'] ?? [] as $pod) {
            if (! empty($pod['metadata']['deletionTimestamp'])) {
                continue;
            }
            $name = (string) ($pod['metadata']['name'] ?? '');
            $node = (string) ($pod['spec']['nodeName'] ?? '');
            if ($node !== '' && in_array($node, $dedicated, true)) {
                $onWorkers[$name] = $node;
            } elseif (self::pendingOnTaint($pod) && ! DedicatedWorkers::tolerated((array) ($pod['spec']['tolerations'] ?? []))) {
                $stale[] = $name;
            }
        }

        $ref = "{$workload['kind']} {$workload['namespace']}/{$workload['name']}";
        $taint = DedicatedWorkers::taint();
        // a Deployment's controller replaces its pods itself on a new template
        $replaces = $workload['kind'] !== 'Deployment';

        if ($tolerated) {
            if ($stale === [] || ! $replaces) {
                return null;
            }
            if (! $dryRun) {
                $this->deletePods($k8s, $workload['namespace'], $stale);
            }

            return ($dryRun ? 'would replace' : 'replaced').' pod(s) '.implode(', ', $stale)." of {$ref}: Pending on {$taint}, created before {$workload['kind']} {$workload['name']} tolerated it";
        }

        if ($onWorkers === [] && $stale === []) {
            return null;
        }

        $reasons = [];
        foreach ($onWorkers as $pod => $node) {
            $reasons[] = "pod {$pod} runs on dedicated worker {$node} and ".($dryRun ? 'would restart' : 'restarts').' once, on that node';
        }
        foreach ($stale as $pod) {
            $reasons[] = "pod {$pod} waits Pending on the taint".($replaces ? ($dryRun ? ' and would be replaced' : ' and is replaced') : '');
        }

        $what = "the toleration of {$taint} to {$ref} (".implode('; ', $reasons).')';
        if ($dryRun) {
            return "would add {$what}";
        }

        $patch = array_reduce(array_reverse($workload['path']), fn ($value, $key) => [$key => $value], DedicatedWorkers::withToleration($tolerations));
        if (! $k8s->mergePatch($workload['apiVersion'], $workload['kind'], $workload['name'], $workload['namespace'], $patch)) {
            throw new RuntimeException("merge patch of {$ref} was rejected");
        }

        if ($stale !== [] && $replaces) {
            $this->waitObserved($k8s, $workload);
            $this->deletePods($k8s, $workload['namespace'], $stale);
        }

        return "added {$what}";
    }

    /**
     * Until the controller has the patched spec, so a deleted pod comes back from it: a
     * StatefulSet's observedGeneration (4.5 s at most), an operator moments.
     */
    private function waitObserved(KubernetesClient $k8s, array $workload): void
    {
        if ($workload['kind'] !== 'StatefulSet') {
            Sleep::for(self::OPERATOR_SETTLE_SECONDS)->seconds();

            return;
        }

        for ($i = 0; $i < 10; $i++) {
            if ($i > 0) {
                Sleep::for(500)->milliseconds();
            }
            $set = $k8s->getResourceStatus('apps/v1', 'StatefulSet', $workload['name'], $workload['namespace']);
            if (! $set || (int) ($set['status']['observedGeneration'] ?? 0) >= (int) ($set['metadata']['generation'] ?? 0)) {
                return;
            }
        }
    }

    /** @param  list<string>  $pods */
    private function deletePods(KubernetesClient $k8s, string $namespace, array $pods): void
    {
        foreach ($pods as $pod) {
            if (! $k8s->deleteResource('v1', 'Pod', $pod, $namespace)) {
                throw new RuntimeException("pod {$namespace}/{$pod} could not be deleted");
            }
        }
    }
}
