<?php

namespace Modules\Tenancy\Support;

use App\Services\CacheVersionService;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Tenancy\Events\RegionShareAccepted;
use Modules\Tenancy\Models\CentralSyncableRegion;
use Modules\Tenancy\Models\Tenant;

/**
 * Platform regions shared with a tenant (tenant_regions) and their acceptance (docs/tenancy.md):
 *
 *  - active   the region is copied into the tenant's schema; its users create projects there
 *  - pending  the platform shared it while acceptance is required — no copy in the tenant's
 *             schema, so nobody in the tenant can use it — until a tenant administrator accepts it
 *
 * Acceptance is required when a module asks for it: the Billing module does while billing is on —
 * the tenant sees the region's billing type and the platform prices and accepts with its markup
 * (docs/billing.md). Without it a shared region is active at once, as before, and offers still
 * pending become active (activatePending()).
 */
final class TenantRegionShares
{
    public const ACTIVE = 'active';

    public const PENDING = 'pending';

    private static ?Closure $acceptanceResolver = null;

    /** @param  (Closure(): bool)|null  $resolver  null: shares are active at once */
    public static function requireAcceptanceWhen(?Closure $resolver): void
    {
        self::$acceptanceResolver = $resolver;
    }

    public static function requiresAcceptance(): bool
    {
        return self::$acceptanceResolver !== null && (bool) (self::$acceptanceResolver)();
    }

    /** Status of a share, null when the region is not shared with the tenant. */
    public static function status(string $tenantId, string $regionId): ?string
    {
        $row = self::table()->where('tenant_id', $tenantId)->where('knative_region_id', $regionId)->first(['status']);

        return $row === null ? null : (string) ($row->status ?? self::ACTIVE);
    }

    /**
     * Every share of a tenant: rows with tenant_id, knative_region_id, status, offered_at,
     * accepted_at, accepted_by, cpu_quota_m, memory_quota_mb, gpu_quota.
     *
     * @return Collection<int, \stdClass>
     */
    public static function forTenant(string $tenantId): Collection
    {
        return self::table()
            ->where('tenant_id', $tenantId)
            ->orderBy('created_at')
            ->get(['tenant_id', 'knative_region_id', 'status', 'offered_at', 'accepted_at', 'accepted_by', 'cpu_quota_m', 'memory_quota_mb', 'gpu_quota']);
    }

    /**
     * A pending share becomes active: the region is copied into the tenant's schema (its users can
     * use it now). An active share stays as it is.
     */
    public static function accept(Tenant $tenant, string $regionId, ?string $userId): void
    {
        if (self::markAccepted($tenant, $regionId, $userId)) {
            self::accepted($tenant, $regionId, $userId);
        }
    }

    /**
     * The status change of accept() alone (pending → active), for a caller that writes more in the
     * same transaction and announces the acceptance after its commit (accepted()). False when the
     * share was not pending.
     */
    public static function markAccepted(Tenant $tenant, string $regionId, ?string $userId): bool
    {
        return self::table()
            ->where('tenant_id', (string) $tenant->id)
            ->where('knative_region_id', $regionId)
            ->where('status', self::PENDING)
            ->update(['status' => self::ACTIVE, 'accepted_at' => now(), 'accepted_by' => $userId, 'updated_at' => now()]) > 0;
    }

    /** After an acceptance was committed: the region is copied into the tenant, RegionShareAccepted. */
    public static function accepted(Tenant $tenant, string $regionId, ?string $userId): void
    {
        self::activated($regionId);

        event(new RegionShareAccepted($tenant, $regionId, $userId));
    }

    /**
     * Acceptance is only asked for while a module requires it: with billing off, offered shares
     * are active — they become active (copied into the tenants, usable at once) and stay active
     * when billing is switched on again, billed with the tenant's default markup
     * (docs/tenancy.md#shared-regions-with-billing). Scheduled every five minutes
     * (tenant-region-shares-activate). Returns the shares activated.
     */
    public static function activatePending(): int
    {
        if (self::requiresAcceptance()) {
            return 0;
        }

        $regions = self::table()->where('status', self::PENDING)->distinct()->pluck('knative_region_id')->map(fn ($id) => (string) $id)->all();
        if ($regions === []) {
            return 0;
        }

        $activated = self::table()->where('status', self::PENDING)->whereIn('knative_region_id', $regions)
            ->update(['status' => self::ACTIVE, 'accepted_at' => now(), 'updated_at' => now()]);
        foreach ($regions as $regionId) {
            self::activated($regionId);
        }

        return $activated;
    }

    /** Copy the region into the schemas of its active shares, settings caches refreshed. */
    public static function activated(string $regionId): void
    {
        /** @var CentralSyncableRegion|null $region */
        $region = CentralSyncableRegion::query()->find($regionId);
        $region?->triggerSyncEvent();
        app(CacheVersionService::class)->bump('settings');
    }

    private static function table(): Builder
    {
        return DB::connection('central')->table('tenant_regions');
    }
}
