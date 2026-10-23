<?php

namespace App\Http\Controllers\Api;

use App\Jobs\GlobalDns\SyncGlobalZoneJob;
use App\Models\KnativeRegion;
use App\Models\RegionGlobalDns;
use App\Services\Dns\DnsResolver;
use App\Services\GlobalDns\GlobalDnsServerService;
use App\Services\GlobalDns\GlobalDnsZone;
use App\Services\GlobalDns\GlobalZoneBuilder;
use App\Services\GlobalDns\GlobalZoneSync;
use App\Services\Runtimes\RuntimeRegistry;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Global DNS (docs/global-projects.md): the zone of the global projects and the PowerDNS servers
 * of the platform regions that answer it.
 *
 *   GET    /global-dns                              zone, delegation records, servers
 *   POST   /global-dns/sync                         sync the zone to every server now
 *   GET    /knative-regions/{knativeRegion}/global-dns  the region's server
 *   POST   /knative-regions/{knativeRegion}/global-dns  install it
 *   DELETE /knative-regions/{knativeRegion}/global-dns  remove it
 *   PUT    /knative-regions/{knativeRegion}/global-dns/geoip         its GeoIP source
 *   POST   /knative-regions/{knativeRegion}/global-dns/geoip/reload  read the source again
 *
 * The servers run in platform regions: an organization's request sees the zone only.
 */
class GlobalDnsController extends Controller
{
    /** The API document's fields of a region's server (serverObject()) */
    private const SERVER_FIELDS = 'region_id: string, region_name: string|null, status: "installing"|"ready"|"error"|"uninstalling"|"disabled", status_message: string|null, address: string|null, ns_name: string|null, reachable: bool|null, sync_status: "in_sync"|"drift"|"error"|null, sync_message: string|null, synced_at: string|null, tls_status: "none"|"pending"|"ready"|"error"|null, tls_message: string|null, tls_expires_at: string|null, geoip: array{source: "default"|"url"|"file"|"none", url: string|null, effective_url: string|null, status: "loaded"|"missing"|"off"|"unknown", message: string|null, checked_at: string|null}|null';

    /** The server with whether the region can run one and why not */
    private const ELIGIBLE_SERVER_TYPE = 'array{'.self::SERVER_FIELDS.', eligible: bool, reason: string|null}';

    public function __construct(
        private readonly GlobalDnsZone $zone,
        private readonly GlobalDnsServerService $servers,
    ) {}

    #[OpenApiResponse(200, 'The zone, its delegation and (platform administrators) the servers', type: 'array{zone: string|null, base_domain: string|null, tls: "acme"|"none", update_region_id: string|null, delegation: array{records: list<array{name: string, type: "NS"|"A"|"AAAA", value: string}>, status: "ok"|"partial"|"missing"|"unknown", message: string|null}, servers: list<array{'.self::SERVER_FIELDS.'}>, last_sync_at: string|null}')]
    public function index(): JsonResponse
    {
        $zone = $this->zone->name();
        $platform = RuntimeRegistry::currentTenantId() === null;
        $servers = $platform ? RegionGlobalDns::query()->orderBy('created_at')->get() : collect();
        $regions = KnativeRegion::query()->whereIn('id', $servers->pluck('knative_region_id')->all())->get()->keyBy('id');

        $records = [];
        $expected = [];
        foreach ($servers as $server) {
            $region = $regions->get($server->knative_region_id);
            if (! $region || ! $server->isReady() || $zone === null) {
                continue;
            }
            $ns = (string) $this->zone->nsName($region, $zone);
            $expected[] = $ns;
            $records[] = ['name' => $zone, 'type' => 'NS', 'value' => $ns];
            [$v4, $v6] = GlobalZoneBuilder::families($this->servers->addressesOf($server->address));
            foreach ($v4 as $ip) {
                $records[] = ['name' => $ns, 'type' => 'A', 'value' => $ip];
            }
            foreach ($v6 as $ip) {
                $records[] = ['name' => $ns, 'type' => 'AAAA', 'value' => $ip];
            }
        }

        $update = $platform ? $this->zone->updateServer($servers) : null;

        return response()->json([
            'zone' => $zone,
            'base_domain' => $this->zone->baseDomain(),
            'tls' => config('global_dns.tls.mode', 'acme') === 'acme' ? 'acme' : 'none',
            'update_region_id' => $update?->knative_region_id,
            'delegation' => array_merge(['records' => $records], $this->delegation($zone, $expected)),
            'servers' => $servers->map(fn (RegionGlobalDns $s) => $this->serverObject($regions->get($s->knative_region_id), $s, $zone))->values(),
            'last_sync_at' => $platform ? Cache::get(GlobalZoneSync::LAST_SYNC_KEY) : null,
        ]);
    }

    public function sync(): JsonResponse
    {
        abort_unless(RuntimeRegistry::currentTenantId() === null, 403, __('api.global_dns.platform_regions_only'));
        SyncGlobalZoneJob::dispatch();

        return response()->json(['queued' => true], 202);
    }

    #[OpenApiResponse(200, 'The region\'s server (status disabled without one)', type: self::ELIGIBLE_SERVER_TYPE)]
    public function show(KnativeRegion $knativeRegion): JsonResponse
    {
        $reason = $this->servers->ineligibilityReason($knativeRegion);
        $server = RegionGlobalDns::forRegion($knativeRegion);
        // what the server's init container reported about its GeoIP database, read at most once a minute
        if ($server?->isReady() && RuntimeRegistry::currentTenantId() === null && Cache::add('global_dns_geoip:'.$knativeRegion->getKey(), true, 60)) {
            $this->servers->refreshGeoip($knativeRegion, $server);
        }

        return response()->json($this->serverObject($knativeRegion, RegionGlobalDns::forRegion($knativeRegion), $this->zone->name()) + [
            'eligible' => $reason === null,
            'reason' => $reason,
        ]);
    }

    #[OpenApiResponse(202, 'The server is being installed', type: self::ELIGIBLE_SERVER_TYPE)]
    public function enable(Request $request, KnativeRegion $knativeRegion): JsonResponse
    {
        if ($reason = $this->servers->ineligibilityReason($knativeRegion)) {
            return response()->json(['message' => $reason, 'reason' => $reason], 422);
        }

        try {
            $userId = $request->user()?->getKey();
            $server = $this->servers->enable($knativeRegion, $userId !== null ? (string) $userId : null);
        } catch (Throwable $e) {
            return response()->json(['message' => __('api.global_dns.install_failed', ['message' => $e->getMessage()])], 422);
        }

        return response()->json($this->serverObject($knativeRegion, $server, $this->zone->name()) + ['eligible' => true, 'reason' => null], 202);
    }

    /**
     * The region server's GeoIP source (docs/global-projects.md, "GeoIP database"): default (the
     * platform's globalDns.geoipUrl), url (a URL the cluster reaches — an internal mirror, a file in
     * the region's object store), file (the operator's own file on the server's volume, for a
     * cluster without internet access) or none. The server restarts with it.
     */
    #[OpenApiResponse(200, 'The server; it restarts with the new source', type: 'array{'.self::SERVER_FIELDS.'}')]
    public function geoip(Request $request, KnativeRegion $knativeRegion): JsonResponse
    {
        $server = RegionGlobalDns::forRegion($knativeRegion);
        abort_if($server === null, 404, __('api.global_dns.not_enabled'));
        $data = $request->validate([
            'source' => ['required', 'string', 'in:default,url,file,none'],
            'url' => ['required_if:source,url', 'nullable', 'string', 'max:2048', 'regex:#^https?://#i'],
        ]);

        try {
            $server = $this->servers->setGeoip($knativeRegion, $server, $data);
        } catch (Throwable $e) {
            return response()->json(['message' => __('api.global_dns.install_failed', ['message' => $e->getMessage()])], 422);
        }
        Cache::forget('global_dns_geoip:'.$knativeRegion->getKey());

        return response()->json($this->serverObject($knativeRegion, $server, $this->zone->name()));
    }

    /** The server reads its GeoIP source again (after the operator copied a new file). */
    #[OpenApiResponse(202, 'The server reads its source again', type: 'array{'.self::SERVER_FIELDS.'}')]
    public function reloadGeoip(KnativeRegion $knativeRegion): JsonResponse
    {
        $server = RegionGlobalDns::forRegion($knativeRegion);
        abort_if($server === null, 404, __('api.global_dns.not_enabled'));

        try {
            $this->servers->reloadGeoip($knativeRegion, $server);
        } catch (Throwable $e) {
            return response()->json(['message' => __('api.global_dns.install_failed', ['message' => $e->getMessage()])], 422);
        }
        Cache::forget('global_dns_geoip:'.$knativeRegion->getKey());

        return response()->json($this->serverObject($knativeRegion, $server->refresh(), $this->zone->name()), 202);
    }

    #[OpenApiResponse(202, 'The server is being removed', type: self::ELIGIBLE_SERVER_TYPE)]
    public function disable(KnativeRegion $knativeRegion): JsonResponse
    {
        try {
            $server = $this->servers->disable($knativeRegion);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $reason = $this->servers->ineligibilityReason($knativeRegion);

        return response()->json($this->serverObject($knativeRegion, $server, $this->zone->name()) + ['eligible' => $reason === null, 'reason' => $reason], 202);
    }

    /**
     * The server object of the API (docs/global-projects.md, "API").
     *
     * @return array<string, mixed>
     */
    private function serverObject(?KnativeRegion $region, ?RegionGlobalDns $server, ?string $zone): array
    {
        return [
            'region_id' => $region?->getKey() ?? $server?->knative_region_id,
            'region_name' => $region?->name,
            'status' => $server !== null ? $server->status : 'disabled',
            'status_message' => $server?->status_message,
            'address' => $server?->address,
            'ns_name' => $server && $region && $zone ? $this->zone->nsName($region, $zone) : null,
            'reachable' => $server?->reachable,
            'sync_status' => $server?->sync_status,
            'sync_message' => $server?->sync_message,
            'synced_at' => $server?->synced_at?->toIso8601String(),
            'tls_status' => $server?->tls_status,
            'tls_message' => $server?->tls_message,
            'tls_expires_at' => $server?->tls_expires_at?->toIso8601String(),
            // the GeoIP database of proximity mode: where it comes from and whether the server has it
            'geoip' => $server ? [
                'source' => (string) ($server->geoip_source ?? 'default'),
                'url' => $server->geoip_source === 'url' ? $server->geoip_url : null,
                'effective_url' => GlobalDnsServerService::geoipSource($server)[0] === 'url' ? GlobalDnsServerService::geoipSource($server)[1] : null,
                'status' => $server->geoip_status ?? 'unknown',
                'message' => $server->geoip_message,
                'checked_at' => $server->geoip_checked_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * Whether the parent zone delegates the zone to the servers: the NS records public DNS answers
     * (the control plane's resolver) against the servers' names.
     *
     * @param  list<string>  $expected
     * @return array{status: string, message: ?string}
     */
    private function delegation(?string $zone, array $expected): array
    {
        if ($zone === null) {
            return ['status' => 'unknown', 'message' => __('api.global_dns.no_zone')];
        }
        if ($expected === []) {
            return ['status' => 'unknown', 'message' => __('api.global_dns.no_servers')];
        }

        $found = app(DnsResolver::class)->ns($zone);
        $missing = array_values(array_diff($expected, $found));
        if ($found === [] || count($missing) === count($expected)) {
            return ['status' => 'missing', 'message' => __('api.global_dns.delegation_missing', ['zone' => $zone])];
        }
        if ($missing !== []) {
            return ['status' => 'partial', 'message' => __('api.global_dns.delegation_partial', ['names' => implode(', ', $missing)])];
        }

        return ['status' => 'ok', 'message' => null];
    }
}
