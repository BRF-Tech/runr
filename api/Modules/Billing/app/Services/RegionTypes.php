<?php

namespace Modules\Billing\Services;

use App\Services\Bindings\BindingTenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Models\FixedRequest;
use Modules\Billing\Models\RegionSetting;
use Modules\Billing\Support\BillingContext;
use Modules\Billing\Support\BillingPolicy;

/**
 * The billing type of a region (docs/billing.md#regions): payg or fixed, one per region
 * (BillingPolicy). Kept by the region's owner: a shared platform region carries the platform's
 * type into the tenant, a tenant's own region its own setting.
 */
class RegionTypes
{
    /** @var array<string, string> */
    private array $memo = [];

    public function __construct(private readonly Regions $regions) {}

    public function typeOf(?string $regionId): string
    {
        if ($regionId === null || $regionId === '') {
            return BillingPolicy::DEFAULT_TYPE;
        }

        $central = $this->regions->isShared($regionId);
        $key = ($central ? 'central' : BillingContext::key()).'|'.$regionId;

        if (! isset($this->memo[$key])) {
            try {
                $type = RegionSetting::on($central ? BillingContext::central() : null)->find($regionId)?->billing_type;
            } catch (QueryException) {
                $type = null;
            }
            $this->memo[$key] = in_array($type, BillingPolicy::TYPES, true) ? $type : BillingPolicy::DEFAULT_TYPE;
        }

        return $this->memo[$key];
    }

    public function isFixed(?string $regionId): bool
    {
        return $this->typeOf($regionId) === BillingPolicy::FIXED;
    }

    /**
     * Active allocations in the region: the organization's own, and on a platform region (platform
     * context) the central copies of every tenant's allocations there too.
     */
    public function activeAllocations(string $regionId): int
    {
        $query = FixedRequest::query()->active()->where('region_id', $regionId);
        if (BillingContext::inTenant()) {
            $query->whereNull('tenant_id');
        }

        return $query->count();
    }

    public function pendingRequests(string $regionId): int
    {
        return FixedRequest::query()->own()->where('status', FixedRequest::PENDING)->where('region_id', $regionId)->count();
    }

    /**
     * Change a region's type. Refused (BillingPolicy decision 1) while fixed allocations are active
     * there; switching to pay as you go cancels the region's pending requests — of a platform
     * region also those of every tenant.
     *
     * @throws RegionTypeLocked
     */
    public function set(string $regionId, string $type, ?string $actorId): RegionSetting
    {
        $current = $this->typeOf($regionId);
        if ($current !== $type && BillingPolicy::typeChangeBlockedByActiveAllocations() && ($active = $this->activeAllocations($regionId)) > 0) {
            throw new RegionTypeLocked($active);
        }

        $setting = RegionSetting::query()->updateOrCreate(['region_id' => $regionId], ['billing_type' => $type, 'changed_by' => $actorId]);

        if ($type === BillingPolicy::PAYG && $current !== $type) {
            $this->cancelPending($regionId);

            // A platform region shared with tenants: their pending requests there go too (each
            // tenant keeps its requests in its own schema; approving them is refused anyway)
            if (BillingContext::multiTenant() && ! BillingContext::inTenant() && $this->regions->isPlatformRegion($regionId)) {
                BindingTenancy::eachContext(function () use ($regionId) {
                    if (! BillingContext::inTenant()) {
                        return;
                    }
                    try {
                        $this->cancelPending($regionId);
                    } catch (QueryException $e) {
                        Log::warning('Billing: pending requests of a tenant not cancelled', ['tenant' => BillingContext::tenantKey(), 'region' => $regionId, 'error' => $e->getMessage()]);
                    }
                });
            }
        }

        $this->flush();

        return $setting;
    }

    /** The organization's pending requests in the region are cancelled (it is pay as you go now). */
    private function cancelPending(string $regionId): void
    {
        FixedRequest::query()->own()->where('region_id', $regionId)->where('status', FixedRequest::PENDING)
            ->update(['status' => FixedRequest::CANCELLED, 'decision_reason' => __('billing.region_now_payg'), 'decided_at' => now()]);
    }

    public function flush(): void
    {
        $this->memo = [];
    }
}
