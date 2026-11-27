<?php

namespace App\Services\RegionReconcile\Steps;

use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileStep;
use App\Services\Secrets\External\ExternalSecretsEgress;

/**
 * The egress policy of the External Secrets Operator controller (ExternalSecretsEgress): applied
 * where it is wanted — the multi-tenant edition, a platform region of the cluster with the
 * operator RunR installed, private targets not allowed — and missing or different (the API
 * server's address moved, the platform's private CIDRs changed, the cluster moved to Cilium);
 * removed where it is no longer wanted. Nothing restarts: the controller's next connection follows
 * the policy.
 */
final class ExternalSecretsEgressStep implements ReconcileStep
{
    public function name(): string
    {
        return 'external-secrets-egress';
    }

    public function run(ClusterGroup $cluster, bool $dryRun): array
    {
        $regions = array_map(fn ($target) => $target->region, $cluster->targets);

        return ExternalSecretsEgress::wanted($regions)
            ? ExternalSecretsEgress::ensure($cluster->k8s, $dryRun)
            : ExternalSecretsEgress::remove($cluster->k8s, $dryRun);
    }
}
