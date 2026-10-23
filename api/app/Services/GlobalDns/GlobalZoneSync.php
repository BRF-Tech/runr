<?php

namespace App\Services\GlobalDns;

use App\Models\KnativeRegion;
use App\Models\RegionGlobalDns;
use App\Services\KubernetesClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the global zone to every ready server and keeps them identical (docs/global-projects.md,
 * "Zone sync"): SyncGlobalZoneJob runs it every minute and after every change of a global project
 * or a server.
 *
 * Per server: the zone is created when missing; the rrsets RunR owns are made equal to
 * GlobalZoneBuilder::build() — whatever else the zone has (a record added by hand) goes; the
 * DNS-01 challenge records are copied from the update server, which cert-manager in every region
 * writes them to; then the region's health route and wildcard certificate (GlobalRegionSetup).
 * A server whose zone differed from what the others needed changed is reported as drift (repaired).
 */
class GlobalZoneSync
{
    public const LAST_SYNC_KEY = 'global_dns.last_sync_at';

    public function __construct(
        private readonly GlobalDnsZone $zone,
        private readonly GlobalDnsServerService $servers,
        private readonly GlobalZoneProjects $projects,
        private readonly GlobalRegionSetup $setup,
    ) {}

    /**
     * @return list<string> what happened, one line per server
     */
    public function sync(): array
    {
        $zone = $this->zone->name();
        $servers = RegionGlobalDns::query()->where('status', RegionGlobalDns::STATUS_READY)->whereNotNull('address')->get()->all();
        if ($zone === null || $servers === []) {
            return [];
        }

        /** @var array<string, KnativeRegion> $regions */
        $regions = [];
        foreach (KnativeRegion::query()->get() as $region) {
            $regions[(string) $region->getKey()] = $region;
        }

        $serverList = [];
        foreach ($servers as $server) {
            $region = $regions[(string) $server->knative_region_id] ?? null;
            if ($region === null) {
                continue;
            }
            $serverList[] = [
                'ns' => (string) $this->zone->nsName($region, $zone),
                'addresses' => $this->servers->addressesOf($server->address),
            ];
        }

        $desired = GlobalZoneBuilder::build($zone, $serverList, $this->entries($regions), [
            'ttl' => (int) config('global_dns.ttl', 30),
            'health_host' => (string) $this->zone->healthHost($zone),
            'timeout' => (int) config('global_dns.health.timeout', 2),
            'minimum_failures' => (int) config('global_dns.health.minimum_failures', 2),
        ]);

        $canonical = (string) $this->zone->canonical($zone);
        $update = $this->zone->updateServer($servers);

        // the challenge records cert-manager wrote to the update server
        $challenges = null;
        $contexts = [];
        foreach ($servers as $server) {
            $region = $regions[(string) $server->knative_region_id] ?? null;
            if ($region === null || ! $region->kubeconfig) {
                continue;
            }
            try {
                $k8s = new KubernetesClient((string) $region->kubeconfig);
                $client = $this->servers->client($k8s, $server);
                $data = $client->zone($canonical);
                if ($data === null) {
                    $client->createZone($canonical, [GlobalZoneBuilder::absolute((string) $this->zone->nsName($region, $zone))]);
                    $data = $client->zone($canonical) ?? ['rrsets' => []];
                }
                $contexts[(string) $server->knative_region_id] = [$server, $region, $k8s, $client, (array) ($data['rrsets'] ?? [])];
                if ($update !== null && $server->is($update)) {
                    $challenges = GlobalZoneBuilder::challenges((array) ($data['rrsets'] ?? []));
                }
            } catch (Throwable $e) {
                $this->failed($server, $e);
            }
        }

        $changes = [];
        foreach ($contexts as $id => [$server, $region, $k8s, $client, $rrsets]) {
            $changes[$id] = GlobalZoneBuilder::diff($desired, $rrsets);
            if ($challenges !== null && ! ($update !== null && $server->is($update))) {
                array_push($changes[$id], ...GlobalZoneBuilder::challengeDiff($challenges, GlobalZoneBuilder::challenges($rrsets)));
            }
        }
        $fewest = $changes === [] ? 0 : min(array_map('count', $changes));

        $lines = [];
        foreach ($contexts as $id => [$server, $region, $k8s, $client, $rrsets]) {
            try {
                $client->patchRrsets($canonical, $changes[$id]);
                $count = count($changes[$id]);
                $drift = $count > $fewest;
                $server->update([
                    'sync_status' => $drift ? 'drift' : 'in_sync',
                    'sync_message' => $drift
                        ? __('api.global_dns.drift_repaired', ['count' => $count - $fewest])
                        : ($count > 0 ? __('api.global_dns.records_updated', ['count' => $count]) : null),
                    'synced_at' => now(),
                    'reachable' => $this->servers->answersZone((string) $server->address, $zone),
                ]);
                $lines[] = "{$region->name}: ".($count > 0 ? "{$count} rrset(s) changed".($drift ? ' (drift repaired)' : '') : 'in sync');

                $this->setup->sync($region, $server, $update, $k8s);
            } catch (Throwable $e) {
                $this->failed($server, $e);
                $lines[] = "{$region->name}: failed: {$e->getMessage()}";
            }
        }

        Cache::forever(self::LAST_SYNC_KEY, now()->toIso8601String());

        return $lines;
    }

    /**
     * The global projects with the addresses of their serving regions (regions without an external
     * address are left out: the zone cannot point at them).
     *
     * @param  array<string, KnativeRegion>  $regions
     * @return list<GlobalZoneEntry>
     */
    public function entries(array $regions): array
    {
        $entries = [];
        $addresses = [];

        foreach ($this->projects->all() as $project) {
            $list = [];
            foreach ($project['regions'] as $region) {
                if (! $region['serving']) {
                    continue;
                }
                $id = $region['region_id'];
                $addresses[$id] ??= $this->servers->addressesOf($regions[$id]->external_ip ?? null);
                if ($addresses[$id] === []) {
                    continue;
                }
                $list[] = ['region_id' => $id, 'primary' => $region['primary'], 'addresses' => $addresses[$id]];
            }
            if ($list !== []) {
                $entries[] = new GlobalZoneEntry($project['slug'], $project['mode'], $list);
            }
        }

        return $entries;
    }

    private function failed(RegionGlobalDns $server, Throwable $e): void
    {
        Log::warning('Global DNS: zone sync failed', ['region' => $server->knative_region_id, 'error' => $e->getMessage()]);
        $server->update(['sync_status' => 'error', 'sync_message' => mb_substr($e->getMessage(), 0, 2000)]);
    }
}
