<?php

namespace App\Services\GlobalDns;

use App\Models\KnativeRegion;
use App\Models\RegionGlobalDns;
use App\Models\SecureSetting;
use App\Models\Setting;
use App\Services\Helpers\DomainName;
use App\Support\PlatformConnection;
use Illuminate\Support\Str;

/**
 * The global zone (docs/global-projects.md): its name, the names of its servers and the platform
 * secrets they share.
 *
 *  - zone          global_dns.zone, else <zone_label>.<base domain> — the platform's base domain,
 *                  also inside an organization's request (an organization domain never moves it)
 *  - ns name       ns-<region name>.<zone>: one per region server; the operator delegates the
 *                  zone to these names and creates their address (glue) records in the parent zone
 *  - update server the server cert-manager sends its DNS-01 updates (RFC 2136) to in every region:
 *                  the ready server of the oldest region. RunR copies the challenge records it
 *                  receives to the other servers, so every name server answers them — the ACME
 *                  validation and cert-manager's own check ask any of them.
 *  - TSIG secret   the key of those updates (SecureSetting global_dns.tsig_secret, created once)
 */
class GlobalDnsZone
{
    public const TSIG_SETTING = 'global_dns.tsig_secret';

    public const TSIG_ALGORITHM = 'hmac-sha256';

    /** The zone, lowercase without a trailing dot; null without a base domain. */
    public function name(): ?string
    {
        $zone = trim((string) config('global_dns.zone', ''));
        if ($zone === '') {
            $base = $this->baseDomain();
            if ($base === null) {
                return null;
            }
            $zone = trim((string) config('global_dns.zone_label', 'g'), '.').'.'.$base;
        }

        $zone = DomainName::normalize($zone);

        return DomainName::isValid($zone) ? $zone : null;
    }

    /** The platform's base domain (platform connection: never an organization's own setting). */
    public function baseDomain(): ?string
    {
        try {
            $value = Setting::on(PlatformConnection::name())->whereKey('base_domain')->value('value');
        } catch (\Throwable) {
            $value = null;
        }

        $value = DomainName::normalize((string) $value);

        return $value !== '' ? $value : null;
    }

    /** The zone name with the trailing dot of the PowerDNS API. */
    public function canonical(?string $zone = null): ?string
    {
        $zone ??= $this->name();

        return $zone !== null ? $zone.'.' : null;
    }

    /** The global host name of a project slug: <slug>.<zone>. */
    public function hostFor(string $slug): ?string
    {
        $zone = $this->name();

        return $zone !== null ? "{$slug}.{$zone}" : null;
    }

    /** The name-server name of a region's server: ns-<region name>.<zone>. */
    public function nsName(KnativeRegion $region, ?string $zone = null): ?string
    {
        $zone ??= $this->name();
        if ($zone === null) {
            return null;
        }

        return 'ns-'.Str::slug((string) $region->name).'.'.$zone;
    }

    /** The host every region's health route answers: runr-health.<zone>. */
    public function healthHost(?string $zone = null): ?string
    {
        $zone ??= $this->name();

        return $zone !== null ? config('global_dns.health.host_label', 'runr-health').'.'.$zone : null;
    }

    /** The shared TSIG secret (base64), created on first use. */
    public function tsigSecret(): string
    {
        $secret = SecureSetting::getValue(self::TSIG_SETTING);
        if ($secret === null) {
            $secret = base64_encode(random_bytes(32));
            SecureSetting::setValue(self::TSIG_SETTING, $secret);
        }

        return $secret;
    }

    /** TSIG key name of the DNS-01 updates. */
    public function tsigKeyName(): string
    {
        return (string) config('global_dns.tls.tsig_key_name', 'runr-acme');
    }

    /**
     * The server cert-manager updates: the ready server of the region created first (stable while
     * it stays ready; another one takes over when it is not).
     *
     * @param  iterable<RegionGlobalDns>|null  $servers  the servers to choose from (default: all ready ones)
     */
    public function updateServer(?iterable $servers = null): ?RegionGlobalDns
    {
        $servers ??= RegionGlobalDns::query()->where('status', RegionGlobalDns::STATUS_READY)->get();

        $ready = [];
        foreach ($servers as $server) {
            if ($server->isReady()) {
                $ready[] = $server;
            }
        }

        usort($ready, fn (RegionGlobalDns $a, RegionGlobalDns $b) => [$a->created_at?->getTimestamp() ?? 0, $a->knative_region_id]
            <=> [$b->created_at?->getTimestamp() ?? 0, $b->knative_region_id]);

        return $ready[0] ?? null;
    }

    /** Whether the zone has a name (global_dns.zone or a base domain). */
    public function configured(): bool
    {
        return $this->name() !== null;
    }
}
