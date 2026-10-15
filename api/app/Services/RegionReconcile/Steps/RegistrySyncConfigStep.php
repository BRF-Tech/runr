<?php

namespace App\Services\RegionReconcile\Steps;

use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileStep;
use App\Services\ZotRegistryService;

/**
 * Brings the sync upstreams of the internal registry (Zot pull-through cache) of existing regions
 * to this release's: ghcr.io on demand into {registry}/ghcr.io/ (Kaniko builds take their builder
 * and runtime base images from there instead of downloading them from ghcr.io on every build),
 * then Docker Hub as before. Only RunR's entries/keys of config.json change
 * (ZotManifests::reconcileSyncConfig()); Zot is restarted only when its config changed.
 *
 * Once per registry namespace of the cluster, for regions with a ready internal registry
 * (registry_mode internal); external registries are left alone.
 */
final class RegistrySyncConfigStep implements ReconcileStep
{
    public function name(): string
    {
        return 'registry-sync-config';
    }

    public function run(ClusterGroup $cluster, bool $dryRun): array
    {
        $lines = [];
        $seen = [];
        $service = app(ZotRegistryService::class);

        foreach ($cluster->targets as $target) {
            $region = $target->region;
            if ($region->registry_mode !== 'internal' || ! $region->has_registry) {
                continue;
            }

            $namespace = $region->registry_namespace ?: ZotRegistryService::ZOT_DEFAULT_NS;
            if (isset($seen[$namespace])) {
                continue;
            }
            $seen[$namespace] = true;

            $line = $service->reconcileSyncConfig($cluster->k8s, $namespace, $dryRun);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
