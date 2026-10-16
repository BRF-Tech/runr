<?php

namespace App\Services\RegionReconcile\Steps;

use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileStep;
use App\Services\ZotRegistryService;

/**
 * Has the internal registry (Zot pull-through cache) of existing regions fetch the base images of
 * this release's function builds (ZotRegistryService::prewarmBuildBaseImages()): Zot fetches an
 * image it does not hold before it answers a build, and the first build of a new base image could
 * run past its deadline waiting for that. Runs after registry-sync-config, which adds the ghcr.io
 * upstream. Starts a Job and does not wait for it.
 *
 * Once per registry namespace of the cluster, for regions with a ready internal registry.
 */
final class RegistryPrewarmStep implements ReconcileStep
{
    public function name(): string
    {
        return 'registry-prewarm';
    }

    public function run(ClusterGroup $cluster, bool $dryRun): array
    {
        $lines = [];
        $seen = [];
        $service = app(ZotRegistryService::class);

        foreach ($cluster->targets as $target) {
            $region = $target->region;
            if ($region->registry_mode !== 'internal' || ! $region->has_registry || ! $region->registry_address) {
                continue;
            }

            $namespace = $region->registry_namespace ?: ZotRegistryService::ZOT_DEFAULT_NS;
            if (isset($seen[$namespace])) {
                continue;
            }
            $seen[$namespace] = true;

            $line = $service->prewarmBuildBaseImages($cluster->k8s, $region, $dryRun);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
