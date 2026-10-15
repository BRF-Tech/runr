<?php

namespace App\Console\Commands;

use App\Models\KnativeRegion;
use App\Services\Helpers\RunrClusterIdentity;
use App\Services\KnativeNodeService;
use App\Services\KubernetesClient;
use App\Services\Manifests\ZotManifests;
use Illuminate\Console\Command;

/**
 * Redeploy the registry mirror DaemonSet on existing regions.
 * Useful after updating the DaemonSet manifest or fixing containerd config issues.
 */
class RedeployRegistryMirrorCommand extends Command
{
    protected $signature = 'registry:redeploy-mirror {regionId?}';

    protected $description = 'Redeploy registry mirror DaemonSet on active regions with internal registry';

    private const ZOT_RELEASE_NAME = 'runr-zot';

    public function handle(): int
    {
        $regionId = $this->argument('regionId');

        $query = KnativeRegion::where('is_active', true)
            ->whereNotNull('kubeconfig')
            ->where('registry_mode', 'internal')
            ->whereNotNull('registry_address');

        if ($regionId) {
            $query->where('id', $regionId);
        }

        $regions = $query->get();

        if ($regions->isEmpty()) {
            $this->error('No regions found with internal registry');

            return 1;
        }

        foreach ($regions as $region) {
            $workersOnly = ! empty($region->selected_workers);

            $this->info("Region: {$region->name}");
            $this->info("  Registry: {$region->registry_address}");
            $this->info('  Target: '.($workersOnly ? 'selected workers only' : 'all nodes'));

            $ns = $region->registry_namespace ?? 'runr-registry';

            try {
                $k8s = new KubernetesClient($region->kubeconfig);
                $nodes = $k8s->listClusterResources('v1', 'Node')['items'] ?? [];
                $flavor = ZotManifests::mirrorFlavorForNodes($nodes, $workersOnly);
                // Restart hint in the mirror log: --in-place when no node can be drained
                $inPlace = count(app(KnativeNodeService::class)->schedulableCapacity($nodes)['nodes']) <= 1
                    || app(RunrClusterIdentity::class)->isRunrCluster($region);
                $manifest = ZotManifests::mirrorDaemonSet(
                    $region->registry_address,
                    self::ZOT_RELEASE_NAME,
                    $ns,
                    $workersOnly,
                    $flavor,
                    $inPlace,
                    $region->id,
                );
                $this->info("  Runtime: {$flavor}");

                // Delete old DaemonSet first to force pod recreation with new script
                try {
                    $k8s->deleteResource('apps/v1', 'DaemonSet', 'runr-registry-mirror', $ns);
                    $this->info('  Old DaemonSet deleted');
                    sleep(5);
                } catch (\Throwable) {
                    // Didn't exist, fine
                }

                $k8s->applyResource($manifest, $ns);
                $this->info('  DaemonSet applied');

                // Wait for rollout
                $ready = $this->waitForReady($k8s, $ns);
                $this->info($ready
                    ? '  DaemonSet ready — all target nodes configured'
                    : '  Warning: DaemonSet not fully ready within 120s'
                );
            } catch (\Throwable $e) {
                $this->error("  Failed: {$e->getMessage()}");
            }
        }

        return 0;
    }

    private function waitForReady(KubernetesClient $k8s, string $ns): bool
    {
        $start = time();
        while (time() - $start < 120) {
            $ds = $k8s->getResourceStatus('apps/v1', 'DaemonSet', 'runr-registry-mirror', $ns);
            if ($ds) {
                $desired = $ds['status']['desiredNumberScheduled'] ?? 0;
                $ready = $ds['status']['numberReady'] ?? 0;
                if ($desired > 0 && $ready >= $desired) {
                    return true;
                }
                $this->line("  Waiting... ({$ready}/{$desired} ready)");
            }
            sleep(10);
        }

        return false;
    }
}
