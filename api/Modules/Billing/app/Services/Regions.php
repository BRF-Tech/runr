<?php

namespace Modules\Billing\Services;

use App\Models\KnativeRegion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Modules\Billing\Support\BillingContext;

/**
 * Which regions an organization bills for and how (docs/billing.md#regions):
 *
 *  - install    every region
 *  - platform   the platform regions (no owner_tenant_id)
 *  - tenant     the regions in the tenant's schema: its own regions (is_synced false) and the
 *               platform regions shared with it and accepted (copies, is_synced true)
 */
class Regions
{
    /** @var array<string, array<string, KnativeRegion|null>> organization → id → region */
    private array $memo = [];

    /** @var array<string, array<string, KnativeRegion|null>> */
    private array $centralMemo = [];

    /** @return Collection<int, KnativeRegion> */
    public function forContext(): Collection
    {
        $query = KnativeRegion::query()->orderBy('name');

        if (BillingContext::kind() === BillingContext::PLATFORM && Schema::hasColumn('knative_regions', 'owner_tenant_id')) {
            $query->whereNull('owner_tenant_id');
        }

        return $query->get();
    }

    public function find(?string $regionId): ?KnativeRegion
    {
        if ($regionId === null || $regionId === '') {
            return null;
        }

        $org = BillingContext::key();
        if (! isset($this->memo[$org]) || ! array_key_exists($regionId, $this->memo[$org])) {
            $this->memo[$org][$regionId] = KnativeRegion::query()->find($regionId);
        }

        return $this->memo[$org][$regionId];
    }

    /** A region as the platform knows it (central schema). */
    public function findCentral(?string $regionId): ?KnativeRegion
    {
        if ($regionId === null || $regionId === '') {
            return null;
        }

        $connection = BillingContext::central() ?? '';
        if (! isset($this->centralMemo[$connection]) || ! array_key_exists($regionId, $this->centralMemo[$connection])) {
            $this->centralMemo[$connection][$regionId] = KnativeRegion::on(BillingContext::central())->find($regionId);
        }

        return $this->centralMemo[$connection][$regionId];
    }

    /** Is the region, in a tenant's context, a platform region shared with the tenant? */
    public function isShared(?string $regionId): bool
    {
        if (! BillingContext::inTenant()) {
            return false;
        }

        $region = $this->find($regionId);
        if ($region) {
            return (bool) $region->getAttribute('is_synced');
        }

        // Not (or no longer) in the tenant's schema: a platform region if the platform has it unowned
        return $this->isPlatformRegion($regionId);
    }

    /** Is the region a platform region (central, no owning tenant)? */
    public function isPlatformRegion(?string $regionId): bool
    {
        $region = $this->findCentral($regionId);
        if (! $region) {
            return false;
        }

        $owner = $region->getAttribute('owner_tenant_id');

        return $owner === null || $owner === '';
    }

    public function name(?string $regionId): ?string
    {
        if ($regionId === null) {
            return null;
        }

        return $this->find($regionId)->name ?? $this->findCentral($regionId)?->name;
    }

    /**
     * @param  iterable<string|null>  $regionIds
     * @return array<string, string>
     */
    public function names(iterable $regionIds): array
    {
        $names = [];
        foreach ($regionIds as $id) {
            if ($id !== null && $id !== '' && ! isset($names[$id])) {
                $names[$id] = $this->name($id) ?? $id;
            }
        }

        return $names;
    }

    /** Does the region meter GPUs (GPU support installed or detected)? */
    public function metersGpu(?string $regionId): bool
    {
        $region = $this->isShared($regionId) ? $this->findCentral($regionId) : ($this->find($regionId) ?? $this->findCentral($regionId));

        return $region !== null && ((string) ($region->getAttribute('gpu_mode') ?? 'none')) !== 'none';
    }

    public function flush(): void
    {
        $this->memo = [];
        $this->centralMemo = [];
    }
}
