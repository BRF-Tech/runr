<?php

namespace Modules\Billing\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Billing\Models\RegionOverride;
use Modules\Billing\Models\RegionSetting;
use Modules\Billing\Models\TenantRegionOverride;
use Modules\Billing\Support\BillingContext;

/**
 * Whether queue consumers are charged (docs/billing.md#queue-consumers). A queue trigger runs a
 * consumer next to its function (QueueConsumerManifests: 50m CPU / 64 MiB, running as long as the
 * deployment). Metering records it always (usage_records source queue_consumer); billing charges
 * it — on pay as you go as a line of its own, in a fixed region as part of the worst case the
 * allocation must hold (Admission) — only when every level lets it:
 *
 *  1. the region charges queue consumers: billing_region_settings.charge_queue_consumers, kept by
 *     the region's owner (the platform for a platform region, shared ones included; a tenant for
 *     its own regions). Off unless set: an upgrade bills no consumer;
 *  2. the region's owner has not switched it off for the organization: on a platform region, the
 *     platform's choice per tenant (billing_tenant_region_overrides, central) — the platform's
 *     bill to that tenant and the tenant's own bills follow it;
 *  3. for an organization's own bills, it has not switched it off for itself in that region
 *     (billing_region_overrides, in its own database).
 *
 * A level can only switch charging off below the one above: nothing switches on what the region
 * leaves off. The platform's bill to a tenant follows levels 1 and 2 (chargedForTenant); a tenant's
 * choice (3) concerns the bills it makes for its own projects.
 */
class QueueConsumerCharging
{
    /** @var array<string, bool|null> */
    private array $memo = [];

    public function __construct(private readonly Regions $regions) {}

    /**
     * The current organization's projects in the region are charged for their queue consumers:
     * the region (1), in a tenant on a platform region the platform's choice for it (2), and the
     * organization's own choice (3).
     */
    public function charged(?string $regionId): bool
    {
        if (! $this->regionCharges($regionId) || $this->override($regionId) === false) {
            return false;
        }

        $tenant = BillingContext::tenantKey();

        return $tenant === null || ! $this->regions->isShared($regionId) || $this->ownerOverride($tenant, $regionId) !== false;
    }

    /** The platform's bill to a tenant (central): the region (1) and the platform's choice for the tenant (2). */
    public function chargedForTenant(string $tenantId, ?string $regionId): bool
    {
        return $this->regionCharges($regionId) && $this->ownerOverride($tenantId, $regionId) !== false;
    }

    /** The region's own setting, kept by its owner (a shared region: the platform's). */
    public function regionCharges(?string $regionId): bool
    {
        if ($regionId === null || $regionId === '') {
            return false;
        }

        $central = $this->regions->isShared($regionId);
        $key = 'region|'.($central ? 'central' : BillingContext::key()).'|'.$regionId;

        if (! array_key_exists($key, $this->memo)) {
            try {
                $this->memo[$key] = (bool) RegionSetting::on($central ? BillingContext::central() : null)->find($regionId)?->charge_queue_consumers;
            } catch (QueryException) {
                $this->memo[$key] = false; // the setting's migration has not run yet: off
            }
        }

        return (bool) $this->memo[$key];
    }

    /** The platform's choice for a tenant in a platform region: null as the region, false off. */
    public function ownerOverride(?string $tenantId, ?string $regionId): ?bool
    {
        if ($tenantId === null || $tenantId === '' || $regionId === null || $regionId === '') {
            return null;
        }

        $key = 'owner|'.$tenantId.'|'.$regionId;
        if (! array_key_exists($key, $this->memo)) {
            try {
                $this->memo[$key] = TenantRegionOverride::on(BillingContext::central())
                    ->where('tenant_id', $tenantId)->where('region_id', $regionId)->value('charge_queue_consumers');
                $this->memo[$key] = $this->memo[$key] === null ? null : (bool) $this->memo[$key];
            } catch (QueryException) {
                $this->memo[$key] = null;
            }
        }

        return $this->memo[$key];
    }

    /** The organization's own choice for the region: null as the region, false off, true as the region too. */
    public function override(?string $regionId): ?bool
    {
        if ($regionId === null || $regionId === '') {
            return null;
        }

        $key = 'override|'.BillingContext::key().'|'.$regionId;
        if (! array_key_exists($key, $this->memo)) {
            try {
                $this->memo[$key] = RegionOverride::query()->find($regionId)?->charge_queue_consumers;
            } catch (QueryException) {
                $this->memo[$key] = null;
            }
        }

        return $this->memo[$key];
    }

    /** Set the region's setting (the region's owner: RegionController refuses a shared region). */
    public function setRegion(string $regionId, bool $charge, ?string $actorId): void
    {
        RegionSetting::query()->updateOrCreate(['region_id' => $regionId], ['charge_queue_consumers' => $charge, 'changed_by' => $actorId]);
        $this->flush();
    }

    /** Set the organization's own choice for the region; null follows the region again. */
    public function setOverride(string $regionId, ?bool $charge, ?string $actorId): void
    {
        if ($charge === null) {
            RegionOverride::query()->whereKey($regionId)->delete();
        } else {
            RegionOverride::query()->updateOrCreate(['region_id' => $regionId], ['charge_queue_consumers' => $charge, 'changed_by' => $actorId]);
        }
        $this->flush();
    }

    /** Set the platform's choice for a tenant in a platform region (central); null follows the region again. */
    public function setOwnerOverride(string $tenantId, string $regionId, ?bool $charge, ?string $actorId): void
    {
        $query = TenantRegionOverride::on(BillingContext::central());
        if ($charge === null) {
            $query->where('tenant_id', $tenantId)->where('region_id', $regionId)->delete();
        } else {
            $query->updateOrCreate(['tenant_id' => $tenantId, 'region_id' => $regionId], ['charge_queue_consumers' => $charge, 'changed_by' => $actorId]);
        }
        $this->flush();
    }

    public function flush(): void
    {
        $this->memo = [];
    }

    /**
     * A region that is gone (deleted in the current database context): the choices made for it go
     * with it — the current organization's own (billing_region_overrides) and, centrally, the
     * platform's per tenant (billing_tenant_region_overrides). A tenant's copy of a deleted
     * platform region takes the tenant's own choice with it (TenantLocalRegion::removeCopy), a
     * deleted tenant its rows of the central table (TenantCentralTables). Whether billing is on or
     * not; tables that do not exist yet are skipped.
     */
    public static function forgetRegion(string $regionId): void
    {
        try {
            if (Schema::hasTable('billing_region_overrides')) {
                RegionOverride::query()->whereKey($regionId)->delete();
            }
            $central = BillingContext::central();
            if (Schema::connection($central)->hasTable('billing_tenant_region_overrides')) {
                TenantRegionOverride::on($central)->where('region_id', $regionId)->delete();
            }
        } catch (QueryException $e) {
            Log::warning('Queue consumer choices of a deleted region not removed', ['region' => $regionId, 'error' => $e->getMessage()]);
        }

        if (app()->resolved(self::class)) {
            app(self::class)->flush();
        }
    }
}
