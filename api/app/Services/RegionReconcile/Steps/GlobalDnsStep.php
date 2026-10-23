<?php

namespace App\Services\RegionReconcile\Steps;

use App\Jobs\GlobalDns\SyncGlobalZoneJob;
use App\Jobs\RedeployRegionAgentJob;
use App\Models\KnativeRegion;
use App\Models\RegionGlobalDns;
use App\Services\GlobalDns\GlobalDnsServerService;
use App\Services\GlobalDns\PowerDnsManifests;
use App\Services\GlobalProjects\GlobalRouteManifests;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileStep;
use App\Support\Images\ImageReference;

/**
 * Global DNS (docs/global-projects.md): the PowerDNS server of a region that runs one is brought to
 * this release — its image and its configuration (the config hash on the pod template: server
 * settings, the DNS UPDATE policy, the init script, the GeoIP source) — by applying its objects
 * again (GlobalDnsServerService::apply); the pod restarts once and keeps its volume. The region
 * agent gets its global-health Service and permission (an agent deployed before global projects
 * has neither) by being deployed again. Then the zone is synced.
 *
 * Only regions with a server (region_global_dns, platform regions): nothing is installed where the
 * operator did not enable it. Regions on one cluster share one server: the first of them decides.
 */
final class GlobalDnsStep implements ReconcileStep
{
    public function name(): string
    {
        return 'global-dns';
    }

    public function run(ClusterGroup $cluster, bool $dryRun): array
    {
        foreach ($cluster->targets as $target) {
            if ($target->scope !== 'central') {
                continue;
            }
            $server = RegionGlobalDns::forRegion($target->region);
            if (! $server || ! in_array($server->status, [RegionGlobalDns::STATUS_READY, RegionGlobalDns::STATUS_ERROR, RegionGlobalDns::STATUS_INSTALLING], true)) {
                continue;
            }

            return $this->reconcile($cluster, $target->region, $server, $dryRun);
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function reconcile(ClusterGroup $cluster, KnativeRegion $region, RegionGlobalDns $server, bool $dryRun): array
    {
        $lines = [];
        $namespace = GlobalDnsServerService::namespace();
        $options = GlobalDnsServerService::options($server);

        $live = $cluster->k8s->getResourceStatus('apps/v1', 'Deployment', PowerDnsManifests::DEPLOYMENT, $namespace);
        $containers = $live['spec']['template']['spec']['containers'] ?? [];
        $image = (string) (collect($containers)->firstWhere('name', 'pdns')['image'] ?? '');
        $hash = (string) ($live['spec']['template']['metadata']['annotations']['runr.dev/config-hash'] ?? '');

        $diff = [];
        if (! $live) {
            $diff[] = 'server missing';
        } else {
            if ($image === '' || ! ImageReference::same($image, $options['image'])) {
                $diff[] = 'image '.($image !== '' ? $image : '(unknown)')." → {$options['image']}";
            }
            if ($hash !== PowerDnsManifests::configHash($options)) {
                $diff[] = 'configuration';
            }
        }

        if ($diff !== []) {
            $what = "the PowerDNS server of region {$region->name} ({$namespace}/".PowerDnsManifests::DEPLOYMENT.'): '.implode(', ', $diff);
            if ($dryRun) {
                $lines[] = "would update {$what} — restart: the server pod restarts once";
            } else {
                app(GlobalDnsServerService::class)->apply($region, $server, $cluster->k8s);
                $lines[] = "updated {$what} — restart: the server pod restarts once, the zone is synced again";
            }
        }

        $healthService = $cluster->k8s->getResourceStatus('v1', 'Service', GlobalRouteManifests::AGENT_HEALTH_SERVICE, GlobalDnsServerService::AGENT_NAMESPACE);
        if (! $healthService) {
            if ($dryRun) {
                $lines[] = "would deploy the region agent of {$region->name} again (its global-health Service and permission)";
            } else {
                RedeployRegionAgentJob::dispatch($region);
                $lines[] = "queued: the region agent of {$region->name} deployed again (its global-health Service and permission)";
            }
        }

        if (! $dryRun && $lines !== []) {
            SyncGlobalZoneJob::dispatch();
        }

        return $lines;
    }
}
