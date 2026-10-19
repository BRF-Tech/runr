<?php

namespace Modules\Tenancy\Models;

use App\Models\KnativeRegion;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Tenancy\Support\TenantRegionShares;
use Stancl\Tenancy\Contracts\SyncMaster;
use Stancl\Tenancy\Database\Concerns\ResourceSyncing;
use Stancl\Tenancy\Events\SyncedResourceSaved;

class CentralSyncableRegion extends KnativeRegion implements SyncMaster
{
    use ResourceSyncing;

    protected $table = 'knative_regions';

    protected $connection = 'central';

    private const EXCLUDED_FROM_SYNC = [
        'kubeconfig',
    ];

    /**
     * Tenants the region is copied to — stancl's resource sync reads this relation: a share that
     * waits for the tenant's acceptance (TenantRegionShares::PENDING) gets no copy, so nobody in
     * the tenant can use the region before it is accepted.
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(
            Tenant::class,
            'tenant_regions',
            'knative_region_id',
            'tenant_id',
        )->withPivot('cpu_quota_m', 'memory_quota_mb', 'gpu_quota', 'status')
            ->wherePivot('status', TenantRegionShares::ACTIVE)
            ->withTimestamps();
    }

    public function getTenantModelName(): string
    {
        return TenantLocalRegion::class;
    }

    public function getGlobalIdentifierKeyName(): string
    {
        return 'id';
    }

    public function getGlobalIdentifierKey()
    {
        return $this->getKey();
    }

    public function getCentralModelName(): string
    {
        return static::class;
    }

    public function getSyncedAttributeNames(): array
    {
        return array_values(
            array_diff($this->getFillable(), self::EXCLUDED_FROM_SYNC)
        );
    }

    /**
     * Always fire as central context (null tenant) to ensure downward sync
     * to ALL attached tenants, regardless of current tenant context.
     */
    public function triggerSyncEvent(): void
    {
        event(new SyncedResourceSaved($this, null));
    }
}
