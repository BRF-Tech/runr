<?php

namespace App\Services\Helpers;

use App\Models\KnativeRegion;
use App\Models\Setting;
use App\Services\Kubeconfigs\TenantKubeconfigs;
use App\Services\Observability\TenantScope;

/**
 * The domains a region's projects answer on.
 *
 * The function domain is the region's knative_domain (knative_settings), which the Serving
 * install sets to <region>.<base domain>. A region "follows" the platform base domain while its
 * function domain is <region>.<base domain> (or not set yet); one with a domain of its own never
 * changes with the base domain.
 *
 * While the base domain moves (DomainMigrateService, domain_migration_status dns_check), the
 * region keeps its function domain and also answers on <region>.<new base domain> — the
 * migration target — until DNS of the target answers the region's external address.
 */
final class RegionDomains
{
    /** knative_domain, else <region>.<base domain>; null without either. */
    public static function functionDomain(KnativeRegion $region): ?string
    {
        $domain = $region->effective_settings['knative_domain'] ?? null;
        if (! $domain) {
            $base = Setting::get('base_domain');
            $domain = $base ? self::forBase($region, $base) : null;
        }

        return $domain ? DomainName::normalize($domain) : null;
    }

    /** The function domain of the region under the base domain $base: <region>.<base>. */
    public static function forBase(KnativeRegion $region, string $base): string
    {
        return DomainName::normalize($region->name.'.'.$base);
    }

    /** The region's function domain is <region>.<$base> or not set: it moves with the base domain. */
    public static function followsBase(KnativeRegion $region, ?string $base): bool
    {
        $domain = $region->effective_settings['knative_domain'] ?? null;
        if (! $domain) {
            return true;
        }

        return $base !== null && $base !== '' && DomainName::normalize($domain) === self::forBase($region, $base);
    }

    /**
     * The domain a region moves to during a base-domain migration (<region>.<current base
     * domain>), or null when it is not moving.
     */
    public static function migrationTarget(KnativeRegion $region): ?string
    {
        if (($region->domain_migration_status ?? null) !== 'dns_check') {
            return null;
        }

        $base = Setting::get('base_domain');
        if (! $base) {
            return null;
        }

        $target = self::forBase($region, $base);

        return $target !== self::functionDomain($region) ? $target : null;
    }

    /**
     * Every domain the region answers on: the function domain and, while it moves, the target.
     *
     * @return list<string>
     */
    public static function served(KnativeRegion $region): array
    {
        return array_values(array_filter(array_unique([self::functionDomain($region), self::migrationTarget($region)])));
    }

    /**
     * $host is under the base domain of the organization the code runs for (an organization of
     * the multi-tenant edition with a domain of its own, TenantDomainService): the organization,
     * not the platform, decides what its names resolve to. False in central context and for the
     * platform's domain.
     */
    public static function organizationControlled(string $host): bool
    {
        if (TenantScope::current() === null) {
            return false;
        }

        $own = Setting::query()->whereKey('base_domain')->value('value');
        if (! is_string($own) || trim($own) === '') {
            return false;
        }
        $own = DomainName::normalize($own);
        $host = DomainName::normalize(rtrim(strtolower(trim($host, '[]')), '.'));

        return $host === $own || str_ends_with($host, '.'.$own);
    }

    /**
     * $host is a name on the function domain of one of the platform's regions (served(): a
     * function's host <name>.<function domain>): RunR's own names, whose DNS the platform runs —
     * not one under the organization's own base domain (organizationControlled()), nor on an
     * organization's own region, whose function domain the organization sets. Such a name may
     * answer the region's private ingress, as RunR's own names do for uptime checks, request
     * replay and the MCP gateway.
     */
    public static function platformFunctionHost(string $host): bool
    {
        $host = DomainName::normalize(rtrim(strtolower(trim($host, '[]')), '.'));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false || self::organizationControlled($host)) {
            return false;
        }

        foreach (KnativeRegion::query()->get() as $region) {
            if (TenantKubeconfigs::restricts($region)) {
                continue;
            }
            foreach (self::served($region) as $domain) {
                if (str_ends_with($host, '.'.$domain)) {
                    return true;
                }
            }
        }

        return false;
    }
}
