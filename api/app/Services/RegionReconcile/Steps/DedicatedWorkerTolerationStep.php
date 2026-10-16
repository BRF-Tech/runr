<?php

namespace App\Services\RegionReconcile\Steps;

use App\Services\DedicatedWorkerTolerationService;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileStep;
use App\Services\RegionReconcile\ReconcileTarget;
use RuntimeException;

/**
 * RunR's components and the cluster's dedicated workers as they are now
 * (DedicatedWorkerTolerationService):
 *
 *  - components with a node-bound volume (the registry, Prometheus, Loki, Tempo, the data
 *    services) installed by an earlier release lack the toleration of the dedicated-worker taint
 *    (runr.dev/dedicated=projects:NoSchedule). Where one of their pods runs on a dedicated worker,
 *    its next pod — the registry restart of registry-sync-config, the roll of image-registry, a Helm
 *    upgrade — could not start there again and would stay Pending; where one waits Pending on the
 *    taint already, the component is down. They get the toleration, their Pending pods are
 *    replaced; a pod running on a dedicated worker restarts once, on the same node. Components on
 *    the other nodes are left as they are: the worker selection adds the toleration when their
 *    node is selected;
 *  - a registry installed on the dedicated workers (nodeSelector runr.dev/dedicated=projects) runs
 *    on any node once no node carries the label, or once its volume binds it to a node that lost
 *    it; its node mirror then covers every node, and Knative's system deployments leave the
 *    nodeSelector too.
 *
 * It runs first: the steps after it restart the registry, Loki and Tempo.
 */
final class DedicatedWorkerTolerationStep implements ReconcileStep
{
    public function name(): string
    {
        return 'dedicated-worker-toleration';
    }

    public function run(ClusterGroup $cluster, bool $dryRun): array
    {
        $nodes = $cluster->k8s->listClusterResources('v1', 'Node');
        if ($nodes === null) {
            throw new RuntimeException('the nodes could not be listed');
        }

        return app(DedicatedWorkerTolerationService::class)->reconcile(
            $cluster->k8s,
            array_map(fn (ReconcileTarget $t) => $t->region, $cluster->targets),
            $nodes['items'] ?? [],
            $dryRun,
        );
    }
}
