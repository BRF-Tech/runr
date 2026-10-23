<?php

namespace App\Services\GlobalDns;

use App\Jobs\GlobalDns\CheckGlobalDnsServerJob;
use App\Jobs\GlobalDns\RemoveGlobalDnsServerJob;
use App\Jobs\GlobalDns\SyncGlobalZoneJob;
use App\Jobs\RedeployRegionAgentJob;
use App\Models\KnativeRegion;
use App\Models\RegionGlobalDns;
use App\Services\Dns\DnsResolver;
use App\Services\GlobalProjects\Data\DataLinkManifests;
use App\Services\Helpers\RunrClusterIdentity;
use App\Services\KubernetesClient;
use App\Services\Runtimes\RuntimeRegistry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The PowerDNS server of a region (docs/global-projects.md, "Global DNS"): an optional region
 * feature the operator enables per platform region. RunR installs it (PowerDnsManifests), waits
 * for its pod and the LoadBalancer address, creates the zone and the TSIG key through the API,
 * checks that the address answers the zone on :53 from outside the cluster, and from then on keeps
 * it with the other servers (GlobalZoneSync, every minute) and with this release (region:reconcile,
 * GlobalDnsStep).
 *
 * A region whose load balancer gives the DNS Service no address — no public :53 — never becomes
 * ready and cannot take part in global projects. Regions on one cluster share one server.
 */
class GlobalDnsServerService
{
    /** Namespace of the region agent (its global-health Service lives next to it). */
    public const AGENT_NAMESPACE = 'observability';

    public function __construct(
        private readonly GlobalDnsZone $zone,
        private readonly RunrClusterIdentity $clusterIdentity,
    ) {}

    /** Why the region cannot run a server, or null when it can. */
    public function ineligibilityReason(KnativeRegion $region): ?string
    {
        if (! $this->zone->configured()) {
            return __('api.global_dns.no_zone');
        }
        if (RuntimeRegistry::currentTenantId() !== null || ($region->owner_tenant_id ?? null) !== null || (bool) ($region->is_synced ?? false)) {
            return __('api.global_dns.platform_regions_only');
        }
        if ($region->is_demo) {
            return __('api.global_dns.demo_region');
        }
        if (! $region->kubeconfig) {
            return __('api.project.region_no_kubeconfig');
        }
        if (! $region->has_serving) {
            return __('api.global_dns.no_serving');
        }

        return null;
    }

    /**
     * Install the server (or apply it again) and start watching it come up.
     */
    public function enable(KnativeRegion $region, ?string $userId = null): RegionGlobalDns
    {
        if ($reason = $this->ineligibilityReason($region)) {
            throw new RuntimeException($reason);
        }

        $server = RegionGlobalDns::forRegion($region) ?? new RegionGlobalDns(['knative_region_id' => $region->getKey()]);
        if (! $server->api_key) {
            // one key per cluster: a region on a cluster that already runs a server reuses its key
            $server->api_key = $this->sharedApiKey($region) ?? Str::random(40);
        }
        $server->fill([
            'status' => RegionGlobalDns::STATUS_INSTALLING,
            'status_message' => null,
            'created_by' => $server->created_by ?? $userId,
        ])->save();

        try {
            $this->apply($region, $server);
        } catch (Throwable $e) {
            $server->update(['status' => RegionGlobalDns::STATUS_ERROR, 'status_message' => mb_substr($e->getMessage(), 0, 2000)]);

            throw $e;
        }

        // the region agent answers the health checks of the regions' copies (its global-health
        // listener, Service and permission come with its deploy)
        RedeployRegionAgentJob::dispatch($region);
        CheckGlobalDnsServerJob::dispatch((string) $region->getKey())->delay(now()->addSeconds(5));

        return $server->refresh();
    }

    /**
     * Apply the server's objects (install, or bring an existing one to this release's image and
     * configuration). Returns what was applied.
     *
     * @return list<string>
     */
    public function apply(KnativeRegion $region, RegionGlobalDns $server, ?KubernetesClient $k8s = null): array
    {
        $k8s ??= new KubernetesClient((string) $region->kubeconfig);
        $namespace = self::namespace();
        $applied = [];

        $options = self::options($server);
        foreach (PowerDnsManifests::all($namespace, (string) $server->api_key, $options) as $manifest) {
            $ok = $manifest['kind'] === 'Namespace'
                ? $k8s->applyClusterResource($manifest)
                : $k8s->applyResource($manifest, $namespace);
            if (! $ok) {
                throw new RuntimeException("{$manifest['kind']} {$manifest['metadata']['name']} could not be applied in {$namespace}");
            }
            $applied[] = "{$manifest['kind']}/{$manifest['metadata']['name']}";
        }

        // a GeoIP URL into the region's own object store: the DNS namespace may reach it
        $this->applyGeoipPolicy($k8s, $options);

        $server->update(['image' => (string) config('global_dns.image')]);

        return $applied;
    }

    /**
     * Where a region's server gets its GeoIP database (docs/global-projects.md, "GeoIP database"):
     * default — globalDns.geoipUrl; url — an operator-provided URL the cluster reaches (an internal
     * mirror, a file in the region's object store); file — the operator's own file on the server's
     * volume, nothing downloaded; none.
     *
     * @param  array{source: string, url?: ?string}  $data
     */
    public function setGeoip(KnativeRegion $region, RegionGlobalDns $server, array $data): RegionGlobalDns
    {
        $server->fill([
            'geoip_source' => $data['source'] === 'default' ? null : $data['source'],
            'geoip_url' => $data['source'] === 'url' ? trim((string) ($data['url'] ?? '')) : null,
            'geoip_status' => 'unknown',
            'geoip_message' => null,
        ])->save();

        $this->apply($region, $server);
        // its status once the restarted pod reported it
        if ($server->isReady()) {
            CheckGlobalDnsServerJob::dispatch((string) $region->getKey())->delay(now()->addSeconds(30));
        }

        return $server->refresh();
    }

    /** The server reads its GeoIP source again (its pod restarts: the operator copied a new file). */
    public function reloadGeoip(KnativeRegion $region, RegionGlobalDns $server): void
    {
        $k8s = new KubernetesClient((string) $region->kubeconfig);
        $k8s->strategicMergePatch('apps/v1', 'Deployment', PowerDnsManifests::DEPLOYMENT, self::namespace(), [
            'spec' => ['template' => ['metadata' => ['annotations' => ['runr.dev/geoip-reload' => now()->toIso8601String()]]]],
        ]);
        $server->update(['geoip_status' => 'unknown', 'geoip_message' => __('api.global_dns.geoip_reloading')]);
    }

    /**
     * The GeoIP state the server's init container reported (its last GEOIP_STATUS line): loaded
     * (source, size, date), missing (why) or off. Stored on the server row.
     */
    public function refreshGeoip(KnativeRegion $region, RegionGlobalDns $server, ?KubernetesClient $k8s = null): void
    {
        try {
            $k8s ??= new KubernetesClient((string) $region->kubeconfig);
            $pods = $k8s->listResources('v1', 'Pod', self::namespace(), ['app.kubernetes.io/name' => 'runr-pdns'])['items'] ?? [];
            usort($pods, fn (array $a, array $b) => strcmp((string) ($b['metadata']['creationTimestamp'] ?? ''), (string) ($a['metadata']['creationTimestamp'] ?? '')));
            $pod = $pods[0]['metadata']['name'] ?? null;
            $log = $pod ? (string) $k8s->getPodLogs((string) $pod, self::namespace(), 'prepare', 200) : '';
            $state = PowerDnsManifests::geoipState($log);
        } catch (Throwable $e) {
            // the cluster did not answer: the state stays what it was
            Log::info('Global DNS: GeoIP state not read', ['region' => $region->name, 'error' => $e->getMessage()]);

            return;
        }

        if ($state === null) {
            $server->update(['geoip_status' => 'unknown', 'geoip_checked_at' => now()]);

            return;
        }

        $message = match ($state['status']) {
            'loaded' => __('api.global_dns.geoip_loaded', ['source' => (string) ($state['source'] ?? ''), 'size' => round(((int) ($state['bytes'] ?? 0)) / 1048576, 1), 'date' => (string) ($state['updated'] ?? '')])
                .(! empty($state['refresh_error']) ? ' '.__('api.global_dns.geoip_refresh_failed', ['error' => (string) $state['refresh_error']]) : ''),
            'missing' => ($state['mode'] ?? '') === 'file'
                ? __('api.global_dns.geoip_file_missing')
                : __('api.global_dns.geoip_missing', ['error' => (string) ($state['error'] ?? '-')]),
            default => __('api.global_dns.geoip_off'),
        };
        $server->update(['geoip_status' => $state['status'], 'geoip_message' => mb_substr($message, 0, 2000), 'geoip_checked_at' => now()]);
    }

    /**
     * A NetworkPolicy of the data namespace that lets the DNS server download its GeoIP file from
     * the region's managed object store (url source pointing at it); none otherwise.
     *
     * @param  array<string, mixed>  $options
     */
    private function applyGeoipPolicy(KubernetesClient $k8s, array $options): void
    {
        $dataNs = (string) config('bindings.namespace', 'runr-data');
        $name = 'runr-global-dns-geoip';
        $url = (string) ($options['geoip_url'] ?? '');
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: 0);
        $service = DataLinkManifests::inClusterService($host);

        try {
            if (($options['geoip_mode'] ?? 'url') === 'url' && $service !== null && $service['namespace'] === $dataNs && $port > 0) {
                $k8s->applyResource([
                    'apiVersion' => 'networking.k8s.io/v1',
                    'kind' => 'NetworkPolicy',
                    'metadata' => ['name' => $name, 'namespace' => $dataNs, 'labels' => PowerDnsManifests::labels()],
                    'spec' => [
                        'podSelector' => ['matchLabels' => ['app.kubernetes.io/name' => $service['service']]],
                        'policyTypes' => ['Ingress'],
                        'ingress' => [[
                            'from' => [['namespaceSelector' => ['matchLabels' => ['kubernetes.io/metadata.name' => self::namespace()]]]],
                            'ports' => [['protocol' => 'TCP', 'port' => $port]],
                        ]],
                    ],
                ], $dataNs);
            } else {
                $k8s->deleteResource('networking.k8s.io/v1', 'NetworkPolicy', $name, $dataNs);
            }
        } catch (Throwable $e) {
            Log::info('Global DNS: GeoIP NetworkPolicy not applied', ['error' => $e->getMessage()]);
        }
    }

    /**
     * One check of an installing (or ready) server: pod ready, address, zone + TSIG key, public
     * reachability. Returns true when the server is ready (or failed for good), false to check again.
     */
    public function check(KnativeRegion $region, RegionGlobalDns $server, bool $timedOut = false): bool
    {
        try {
            $k8s = new KubernetesClient((string) $region->kubeconfig);
            $namespace = self::namespace();

            $deployment = $k8s->getResourceStatus('apps/v1', 'Deployment', PowerDnsManifests::DEPLOYMENT, $namespace);
            if ((int) ($deployment['status']['readyReplicas'] ?? 0) < 1) {
                return $this->notYet($server, __('api.global_dns.waiting_pod'), $timedOut);
            }

            $address = PowerDnsManifests::loadBalancerAddress($k8s->getResourceStatus('v1', 'Service', PowerDnsManifests::DNS_SERVICE, $namespace));
            if ($address === null) {
                return $this->notYet($server, __('api.global_dns.waiting_address'), $timedOut);
            }

            $zone = (string) $this->zone->name();
            $client = $this->client($k8s, $server);
            if ($client->zone($this->zone->canonical($zone)) === null) {
                $client->createZone($this->zone->canonical($zone), [GlobalZoneBuilder::absolute((string) $this->zone->nsName($region, $zone))]);
            }
            $client->ensureTsigKey($this->zone->tsigKeyName(), GlobalDnsZone::TSIG_ALGORITHM, $this->zone->tsigSecret());

            $reachable = $this->answersZone($address, $zone);
            $server->update([
                'status' => RegionGlobalDns::STATUS_READY,
                'status_message' => $reachable ? null : __('api.global_dns.unreachable', ['address' => $address]),
                'address' => $address,
                'reachable' => $reachable,
            ]);

            // what its init container reported about the GeoIP database (the console's status)
            $this->refreshGeoip($region, $server, $k8s);

            // the new server gets the zone, every region its certificate and routes
            SyncGlobalZoneJob::dispatch();

            return true;
        } catch (Throwable $e) {
            Log::warning('Global DNS: server check failed', ['region' => $region->id, 'error' => $e->getMessage()]);

            return $this->notYet($server, mb_substr($e->getMessage(), 0, 2000), $timedOut);
        }
    }

    /**
     * Start removing the server (RemoveGlobalDnsServerJob). Refused while global projects run in the
     * region: their copies there would lose their health checks and certificate.
     */
    public function disable(KnativeRegion $region): ?RegionGlobalDns
    {
        $server = RegionGlobalDns::forRegion($region);
        if (! $server) {
            return null;
        }

        $users = $this->projectsIn((string) $region->getKey());
        if ($users !== []) {
            throw new RuntimeException(__('api.global_dns.in_use', ['projects' => implode(', ', array_slice($users, 0, 5))]));
        }

        $server->update(['status' => RegionGlobalDns::STATUS_UNINSTALLING, 'status_message' => null]);
        RemoveGlobalDnsServerJob::dispatch((string) $region->getKey());

        return $server;
    }

    /**
     * Remove the server from the region (kept while another region of the same cluster still uses
     * it), then its row; the other servers drop its NS record with the next sync.
     */
    public function remove(KnativeRegion $region): void
    {
        $server = RegionGlobalDns::forRegion($region);
        if (! $server) {
            return;
        }

        try {
            $k8s = new KubernetesClient((string) $region->kubeconfig);
            app(GlobalRegionSetup::class)->remove($region, $k8s);

            if (! $this->sharedWithAnotherRegion($region)) {
                $namespace = self::namespace();
                foreach ([
                    ['v1', 'Service', PowerDnsManifests::DNS_SERVICE],
                    ['v1', 'Service', PowerDnsManifests::API_SERVICE],
                    ['apps/v1', 'Deployment', PowerDnsManifests::DEPLOYMENT],
                    ['v1', 'ConfigMap', PowerDnsManifests::CONFIG_MAP],
                    ['v1', 'Secret', PowerDnsManifests::API_SECRET],
                    ['v1', 'PersistentVolumeClaim', PowerDnsManifests::VOLUME],
                ] as [$apiVersion, $kind, $name]) {
                    $k8s->deleteResource($apiVersion, $kind, $name, $namespace);
                }
                $k8s->deleteClusterResource('v1', 'Namespace', $namespace);
            }
        } catch (Throwable $e) {
            $server->update(['status' => RegionGlobalDns::STATUS_ERROR, 'status_message' => mb_substr($e->getMessage(), 0, 2000)]);

            throw $e;
        }

        $server->delete();
        SyncGlobalZoneJob::dispatch();
    }

    /**
     * Slugs of the global projects (every organization's) that have a copy in the region.
     *
     * @return list<string>
     */
    public function projectsIn(string $regionId): array
    {
        $slugs = [];
        foreach (app(GlobalZoneProjects::class)->all() as $entry) {
            foreach ($entry['regions'] as $region) {
                if ($region['region_id'] === $regionId) {
                    $slugs[] = $entry['slug'];
                    break;
                }
            }
        }

        return $slugs;
    }

    /** Whether the server's address answers the zone's SOA authoritatively (from the control plane). */
    public function answersZone(string $address, string $zone): bool
    {
        $wire = app(DnsWire::class);
        foreach ($this->addressesOf($address) as $ip) {
            $answer = $wire->query($ip, $zone, 'SOA');
            if ($answer !== null && $answer['rcode'] === 0 && $answer['authoritative']) {
                return true;
            }
        }

        return false;
    }

    /**
     * IP addresses of a LoadBalancer address (an IP as it is, a host name resolved).
     *
     * @return list<string>
     */
    public function addressesOf(?string $address): array
    {
        $address = trim((string) $address);
        if ($address === '') {
            return [];
        }
        if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
            return [$address];
        }

        return array_values(array_unique(app(DnsResolver::class)->addresses($address)));
    }

    /** The region Traefik's first address (its external_ip, resolved when a host name), or null. */
    public static function firstAddress(?KnativeRegion $region): ?string
    {
        return $region ? (app(self::class)->addressesOf($region->external_ip)[0] ?? null) : null;
    }

    public function client(KubernetesClient $k8s, RegionGlobalDns $server): PowerDnsClient
    {
        return new PowerDnsClient($k8s, self::namespace(), (string) $server->api_key);
    }

    public static function namespace(): string
    {
        return (string) config('global_dns.namespace', 'runr-global-dns');
    }

    /**
     * The server's options; the GeoIP source is the region's ($server: default, url, file, none).
     *
     * @return array{image: string, ttl: int, health_interval: int, geoip_url: string, geoip_mode: string, tsig_key_name: string, storage_class: ?string, storage_size: string, service_annotations: array<string, string>}
     */
    public static function options(?RegionGlobalDns $server = null): array
    {
        $class = config('global_dns.storage_class');
        [$geoipMode, $geoipUrl] = self::geoipSource($server);

        return [
            'image' => (string) config('global_dns.image'),
            'ttl' => (int) config('global_dns.ttl', 30),
            'health_interval' => (int) config('global_dns.health.interval', 5),
            'geoip_url' => $geoipUrl,
            'geoip_mode' => $geoipMode,
            'tsig_key_name' => (string) config('global_dns.tls.tsig_key_name', 'runr-acme'),
            'storage_class' => is_string($class) && $class !== '' ? $class : null,
            'storage_size' => (string) config('global_dns.storage_size', '1Gi'),
            'service_annotations' => array_map('strval', (array) config('global_dns.service_annotations', [])),
        ];
    }

    /**
     * The GeoIP source of a region's server: [mode url|file|none, URL].
     *
     * @return array{0: string, 1: string}
     */
    public static function geoipSource(?RegionGlobalDns $server): array
    {
        $default = trim((string) config('global_dns.geoip.url', ''));

        return match ((string) $server?->getAttribute('geoip_source')) {
            'url' => ['url', trim((string) $server->getAttribute('geoip_url'))],
            'file' => ['file', ''],
            'none' => ['none', ''],
            default => $default === '' || $default === '-' ? ['none', ''] : ['url', $default],
        };
    }

    private function notYet(RegionGlobalDns $server, string $message, bool $timedOut): bool
    {
        if ($timedOut) {
            $server->update(['status' => RegionGlobalDns::STATUS_ERROR, 'status_message' => __('api.global_dns.timed_out', ['reason' => $message])]);

            return true;
        }

        if ($server->status !== RegionGlobalDns::STATUS_READY) {
            $server->update(['status_message' => $message]);
        }

        return false;
    }

    /** The API key of a server another region of the same cluster already runs. */
    private function sharedApiKey(KnativeRegion $region): ?string
    {
        $other = $this->sameClusterServer($region);

        return $other?->api_key;
    }

    private function sharedWithAnotherRegion(KnativeRegion $region): bool
    {
        return $this->sameClusterServer($region) !== null;
    }

    private function sameClusterServer(KnativeRegion $region): ?RegionGlobalDns
    {
        $uid = $this->clusterIdentity->regionClusterUid($region);
        if ($uid === null) {
            return null;
        }

        foreach (RegionGlobalDns::query()->whereKeyNot($region->getKey())->get() as $server) {
            $other = KnativeRegion::query()->find($server->knative_region_id);
            if ($other && $this->clusterIdentity->regionClusterUid($other) === $uid) {
                return $server;
            }
        }

        return null;
    }
}
