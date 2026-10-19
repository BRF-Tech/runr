<?php

namespace App\Jobs\Concerns;

use App\Models\KnativeRegion;
use App\Services\Helpers\RunrClusterIdentity;
use App\Services\KubernetesClient;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileStep;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\RegionReconciler;

/**
 * A region setting changed that a region:reconcile step brings to the cluster: run that step now,
 * for the region and its sibling regions on the same cluster (in the same database context) —
 * a cluster-wide object (the region Traefik, the KnativeServing) follows all their settings.
 * Nothing to do for a region region:reconcile would skip (not active, Serving not ready): its
 * install, or the next reconcile, takes the setting.
 */
trait RunsClusterReconcileStep
{
    /**
     * @return list<string>|null the step's lines; null when the region is not reconcilable
     */
    protected function runStepForRegion(KnativeRegion $region, ReconcileStep $step, RunrClusterIdentity $identity): ?array
    {
        $region = $region->fresh();
        if (! $region || ! RegionReconciler::reconcilable($region->newQuery())->whereKey($region->getKey())->exists()) {
            return null;
        }

        $uid = $identity->regionClusterUid($region);
        $siblings = RegionReconciler::reconcilable($region->newQuery())
            ->whereKeyNot($region->getKey())
            ->get()
            ->filter(fn (KnativeRegion $other) => $uid !== null && $identity->regionClusterUid($other) === $uid);

        $targets = array_map(
            fn (KnativeRegion $r) => new ReconcileTarget($r),
            array_merge([$region], $siblings->values()->all()),
        );

        $cluster = new ClusterGroup('region:'.$region->id, $targets, new KubernetesClient($region->kubeconfig));

        return $step->run($cluster, false);
    }
}
