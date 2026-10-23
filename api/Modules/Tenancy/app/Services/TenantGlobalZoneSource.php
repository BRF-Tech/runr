<?php

namespace Modules\Tenancy\Services;

use App\Services\GlobalDns\GlobalZoneProjects;
use Illuminate\Support\Facades\Log;
use Modules\Tenancy\Models\Tenant;
use Throwable;

/**
 * Adds every organization's global projects to the global zone (docs/global-projects.md): their
 * projects live in the organizations' schemas, the zone and its servers are the platform's. Each
 * schema is read in its tenant's context (GlobalZoneProjects::local()); the region ids of a
 * platform region's copy are the central ones, so the zone sync finds the regions centrally.
 */
class TenantGlobalZoneSource
{
    public static function register(): void
    {
        GlobalZoneProjects::addSource('tenancy', fn () => self::entries());
    }

    /**
     * @return list<array{slug: string, mode: string, regions: list<array{region_id: string, primary: bool, serving: bool}>}>
     */
    public static function entries(): array
    {
        $entries = [];

        foreach (Tenant::all() as $tenant) {
            /** @var Tenant $tenant */
            try {
                array_push($entries, ...$tenant->run(fn () => GlobalZoneProjects::local()));
            } catch (Throwable $e) {
                Log::warning('Global zone: tenant projects not read', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);
            }
        }

        return $entries;
    }
}
