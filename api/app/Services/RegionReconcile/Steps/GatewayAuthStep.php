<?php

namespace App\Services\RegionReconcile\Steps;

use App\Models\KnativeRegion;
use App\Services\ApiProduct\GatewayAuthDeployer;
use App\Services\Manifests\GatewayAuthManifests;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileStep;
use App\Services\RegionReconcile\ReconcileTarget;

/**
 * The API product's gateway-auth service (docs/api-product.md): where a region runs it (a gateway
 * there has key or JWT routes), its objects are applied again when the release brings another
 * image (config api_product.gateway_auth_image) or another Deployment spec (its annotation
 * runr.sh/spec-revision: e.g. the metering proxy's port and upstream, and its Service) — the
 * Deployment rolls its pods once; their counters are in the region's Redis (without Redis, the
 * in-memory counters start over). A region without the service is left alone: the first key /
 * JWT route installs it. The gateways' routes follow in GatewayRoutesStep.
 */
final class GatewayAuthStep implements ReconcileStep
{
    public function name(): string
    {
        return 'gateway-auth';
    }

    public function run(ClusterGroup $cluster, bool $dryRun): array
    {
        $deployer = app(GatewayAuthDeployer::class);
        $lines = [];
        $seen = [];

        foreach ($cluster->targets as $target) {
            $region = $target->region;
            $namespace = $region->project_namespace ?? $region->name;
            if (isset($seen[$namespace])) {
                continue;
            }
            $seen[$namespace] = true;

            $deployment = $cluster->k8s->getResourceStatus('apps/v1', 'Deployment', GatewayAuthManifests::NAME, $namespace);
            if (! $deployment) {
                continue;
            }

            $current = (string) ($deployment['spec']['template']['spec']['containers'][0]['image'] ?? '');
            $wanted = $deployer->image($region);
            $revision = (string) ($deployment['metadata']['annotations'][GatewayAuthManifests::SPEC_ANNOTATION] ?? '');
            $wantedRevision = $this->wantedRevision($target, $deployer, $region, $namespace);
            if ($current === $wanted && ($wantedRevision === null || $revision === $wantedRevision)) {
                continue;
            }

            if (! $dryRun) {
                $target->run(fn () => $deployer->apply($cluster->k8s, $region, $namespace));
            }
            $lines[] = $current === $wanted
                ? "gateway-auth in {$namespace}: Deployment, Services and metering proxy of this release"
                : "gateway-auth in {$namespace}: {$current} → {$wanted}";
        }

        return $lines;
    }

    /** The spec revision of the Deployment RunR would apply (null: it cannot be built here). */
    private function wantedRevision(ReconcileTarget $target, GatewayAuthDeployer $deployer, KnativeRegion $region, string $namespace): ?string
    {
        try {
            $manifests = $target->run(fn () => $deployer->manifests($region, $namespace));
        } catch (\Throwable) {
            return null;
        }
        foreach ($manifests as $manifest) {
            if ($manifest['kind'] === 'Deployment') {
                return $manifest['metadata']['annotations'][GatewayAuthManifests::SPEC_ANNOTATION] ?? null;
            }
        }

        return null;
    }
}
