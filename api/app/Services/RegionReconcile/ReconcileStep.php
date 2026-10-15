<?php

namespace App\Services\RegionReconcile;

/**
 * One idempotent upgrade-time change applied to every existing region cluster by
 * `php artisan region:reconcile` (RegionReconciler::steps()).
 *
 * Contract:
 * - idempotent: a second run on an already reconciled cluster changes nothing;
 * - $dryRun: read-only — report what would change, never write to the cluster or the database;
 * - never rebuild whole objects RunR shares with operators or with other releases' fields
 *   (no server-side apply of a rebuilt KnativeServing CR — merge-patch the keys it owns);
 * - throw on failure; RegionReconciler logs it and carries on with the next step/cluster.
 */
interface ReconcileStep
{
    /** Short name used in the command output and logs. */
    public function name(): string;

    /**
     * @return list<string> what was changed (or, in dry-run, would be); empty = already up to date
     */
    public function run(ClusterGroup $cluster, bool $dryRun): array;
}
