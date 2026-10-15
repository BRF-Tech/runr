<?php

namespace App\Services\RegionReconcile\Steps;

use App\Services\Helpers\ManagedLoki;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\HelmReleaseUpgrade;
use App\Services\RegionReconcile\ReconcileStep;
use App\Services\RegionReconcile\ReconcileTarget;

/**
 * RunR's own Loki (0.4.1): loki-stack installs before 0.4.1 left Loki's delete API
 * off, so log retention's delete requests were refused. This step, only on a cluster where RunR
 * installed the Loki (a region with loki_managed) and only while the release's rendered config
 * lacks ManagedLoki::deletionConfig(), upgrades the Helm release loki-stack with its own values
 * reused and only those keys set, at the chart version it runs (HelmReleaseUpgrade) — the way
 * RunR installs it. The config change restarts the Loki pod once. A reused (operator /
 * platform) Loki is never touched.
 */
final class LokiDeletionStep implements ReconcileStep
{
    public function name(): string
    {
        return 'loki-deletion';
    }

    public function run(ClusterGroup $cluster, bool $dryRun): array
    {
        $managed = array_filter($cluster->targets, fn (ReconcileTarget $t) => $t->region->isLokiManaged());
        if (! $managed) {
            return [];
        }

        // The loki-stack chart renders the Loki config into Secret <release>/loki.yaml
        $secret = $cluster->k8s->getResourceStatus('v1', 'Secret', ManagedLoki::RELEASE, ManagedLoki::NAMESPACE);
        if (! $secret) {
            return [];
        }

        $release = ManagedLoki::NAMESPACE.'/'.ManagedLoki::RELEASE;
        if (($secret['metadata']['labels']['release'] ?? '') !== ManagedLoki::RELEASE) {
            return ["left alone: Secret {$release} does not belong to RunR's loki-stack release"];
        }

        $config = ManagedLoki::configFromSecret($secret);
        if ($config === null) {
            return ["skipped: Loki config in Secret {$release} could not be read"];
        }

        $missing = ManagedLoki::missing($config);
        if ($missing === []) {
            return [];
        }

        return [HelmReleaseUpgrade::apply(
            $cluster, $dryRun,
            ManagedLoki::RELEASE, ManagedLoki::NAMESPACE, ManagedLoki::CHART,
            ManagedLoki::REPO_NAME, ManagedLoki::REPO_URL,
            ManagedLoki::helmSetJson(),
            'enable the delete API for log retention — '.implode(', ', $missing)
                .' (Loki retention '.ManagedLoki::RETENTION_PERIOD.', i.e. none of its own)',
            'the Loki pod restarts once',
        )];
    }
}
