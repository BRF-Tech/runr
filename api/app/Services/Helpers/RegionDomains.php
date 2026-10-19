<?php

namespace App\Services\Helpers;

use App\Models\KnativeRegion;
use App\Models\Setting;

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
}
