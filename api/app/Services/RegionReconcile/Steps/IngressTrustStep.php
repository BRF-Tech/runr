<?php

namespace App\Services\RegionReconcile\Steps;

use App\Services\Contracts\KnativeConstants;
use App\Services\Helpers\IngressTrust;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\HelmReleaseUpgrade;
use App\Services\RegionReconcile\ReconcileStep;
use App\Services\RegionReconcile\ReconcileTarget;

/**
 * Region Traefik (0.4.1): PROXY protocol and X-Forwarded-* are trusted only from
 * the regions' ingress_trusted_ips — regions installed before trusted both from 0.0.0.0/0, so any
 * client could choose the address Traefik records (a PROXY header or its own X-Forwarded-For).
 *
 * Only a Traefik RunR installed (Helm release "traefik" in the serving namespace), only when
 * its rendered trust args differ from the wanted ones. Updated the way RunR installs it: the
 * Helm release, upgraded with its own values reused and only the trust keys set, at the chart
 * version it runs (HelmReleaseUpgrade) — the Deployment rolls its Traefik pods once. Regions on
 * one cluster share the Traefik: it trusts the union of their settings.
 */
final class IngressTrustStep implements ReconcileStep
{
    public function name(): string
    {
        return 'ingress-trust';
    }

    public function run(ClusterGroup $cluster, bool $dryRun): array
    {
        $lines = [];

        foreach ($cluster->servingNamespaces() as $namespace) {
            $line = $this->reconcileNamespace($cluster, $namespace, $dryRun);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function reconcileNamespace(ClusterGroup $cluster, string $namespace, bool $dryRun): ?string
    {
        $deployment = $cluster->k8s->getResourceStatus('apps/v1', 'Deployment', KnativeConstants::TRAEFIK_RELEASE, $namespace);
        if (! $deployment) {
            return null;
        }

        if (! IngressTrust::isRunrRelease($deployment, $namespace)) {
            return "left alone: Traefik {$namespace}/".KnativeConstants::TRAEFIK_RELEASE." is not RunR's Helm release";
        }

        $members = array_filter(
            $cluster->targets,
            fn (ReconcileTarget $t) => ($t->region->knative_serving_namespace ?: 'knative-serving') === $namespace,
        );
        $ips = IngressTrust::union(array_map(fn (ReconcileTarget $t) => $t->region, $members));

        $containers = $deployment['spec']['template']['spec']['containers'] ?? [];
        $container = collect($containers)->firstWhere('name', 'traefik') ?? ($containers[0] ?? []);
        $current = IngressTrust::currentArgs($container['args'] ?? []);
        $wanted = IngressTrust::renderedArgs($ips);

        if ($current === $wanted) {
            return null;
        }

        $change = sprintf(
            'PROXY protocol / X-Forwarded-* trusted from %s → %s on web, websecure',
            IngressTrust::describe($current),
            $ips === [] ? 'nobody' : implode(', ', $ips),
        );

        return HelmReleaseUpgrade::apply(
            $cluster, $dryRun,
            KnativeConstants::TRAEFIK_RELEASE, $namespace, KnativeConstants::TRAEFIK_CHART,
            KnativeConstants::TRAEFIK_REPO_NAME, KnativeConstants::TRAEFIK_REPO_URL,
            IngressTrust::helmSetJson($ips),
            $change,
            'the Traefik pods roll once',
        );
    }
}
