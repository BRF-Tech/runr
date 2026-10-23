<?php

namespace App\Services\RegionReconcile\Steps;

use App\Services\Contracts\KnativeConstants;
use App\Services\Helpers\IngressTrust;
use App\Services\RegionNetworkPolicyService;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileStep;

/**
 * The region's NetworkPolicies (RegionNetworkPolicyManifests), which regions installed before them
 * lack: a project namespace's revision pods admit only the Serving namespace's Traefik,
 * activator and autoscaler, the activator only Traefik and the autoscaler. Before, any pod of the
 * cluster reached a revision's pods directly or through the activator, past the route and its
 * visibility.
 *
 * Only where the Serving namespace runs RunR's Traefik (the policies admit its pods), per project
 * namespace of the cluster's regions. Applied where missing or different; nothing restarts.
 */
final class NetworkPolicyStep implements ReconcileStep
{
    public function name(): string
    {
        return 'network-policy';
    }

    public function run(ClusterGroup $cluster, bool $dryRun): array
    {
        $lines = [];
        $runrTraefik = [];
        $seen = [];

        foreach ($cluster->targets as $target) {
            $region = $target->region;
            $servingNs = $region->knative_serving_namespace ?: 'knative-serving';
            $projectNs = $region->project_namespace ?: $region->name;

            if (! isset($runrTraefik[$servingNs])) {
                $deployment = $cluster->k8s->getResourceStatus('apps/v1', 'Deployment', KnativeConstants::TRAEFIK_RELEASE, $servingNs);
                $runrTraefik[$servingNs] = $deployment && IngressTrust::isRunrRelease($deployment, $servingNs);
            }
            if (! $runrTraefik[$servingNs] || isset($seen["{$projectNs}|{$servingNs}"])) {
                continue;
            }
            $seen["{$projectNs}|{$servingNs}"] = true;

            foreach (RegionNetworkPolicyService::ensure($cluster->k8s, $projectNs, $servingNs, $dryRun) as $line) {
                $lines[] = $line;
            }
        }

        return array_values(array_unique($lines));
    }
}
