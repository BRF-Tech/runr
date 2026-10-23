<?php

namespace App\Services;

use App\Services\Manifests\RegionNetworkPolicyManifests as P;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Applies the region's NetworkPolicies (RegionNetworkPolicyManifests): at the Serving install and
 * through region:reconcile (NetworkPolicyStep) for regions installed before them.
 */
class RegionNetworkPolicyService
{
    /**
     * The revisions policy of a project namespace and the activator policy of its Serving
     * namespace, applied where missing or different. A namespace that does not exist is skipped.
     *
     * @return list<string> what was applied (or, in a dry run, would be)
     */
    public static function ensure(KubernetesClient $k8s, string $projectNamespace, string $servingNamespace, bool $dryRun = false): array
    {
        $lines = [];
        foreach ([P::activator($servingNamespace), P::revisions($projectNamespace, $servingNamespace)] as $policy) {
            $ns = $policy['metadata']['namespace'];
            $name = $policy['metadata']['name'];
            if (! $k8s->getClusterResource('v1', 'Namespace', $ns)) {
                continue;
            }
            if (P::upToDate($k8s->getResourceStatus(P::API, 'NetworkPolicy', $name, $ns), $policy)) {
                continue;
            }
            if (! $dryRun && ! $k8s->applyResource($policy, $ns)) {
                throw new RuntimeException("NetworkPolicy {$ns}/{$name} was rejected");
            }
            $lines[] = ($dryRun ? 'would apply' : 'applied')." NetworkPolicy {$ns}/{$name} (".self::describe($name).')';
        }

        return $lines;
    }

    /** Removes the revisions policy of a project namespace (Knative Serving uninstalled). */
    public static function remove(KubernetesClient $k8s, string $projectNamespace): void
    {
        try {
            $k8s->deleteResource(P::API, 'NetworkPolicy', P::REVISIONS, $projectNamespace);
        } catch (Throwable $e) {
            Log::debug('Cleanup: NetworkPolicy removal skipped', ['error' => $e->getMessage()]);
        }
    }

    private static function describe(string $name): string
    {
        return $name === P::ACTIVATOR
            ? 'the activator admits only Traefik and the autoscaler, its metrics port stays open'
            : 'revision pods admit only Traefik, the activator and the autoscaler';
    }
}
