<?php

namespace Modules\Tenancy\Models;

use App\Models\KnativeRegion;

class TenantAwareKnativeRegion extends KnativeRegion
{
    protected $table = 'knative_regions';

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->mergeFillable(['owner_tenant_id', 'is_synced']);
        $this->mergeCasts(['is_synced' => 'boolean']);
    }

    protected static function booted(): void
    {
        static::creating(fn (KnativeRegion $region) => self::markTenantOwned($region));
    }

    /**
     * A region created in a tenant's context is that tenant's own region (its cluster): not a
     * synced copy, owner_tenant_id = the tenant — the organization ceiling (namespace
     * ResourceQuota), platform runtime builds and the platform's quota checks leave it alone.
     * Synced copies of platform regions (is_synced) and central regions are not touched.
     * Registered for KnativeRegion too (TenancyServiceProvider): the region API creates
     * App\Models\KnativeRegion rows, whose events this model's own hook never sees.
     */
    public static function markTenantOwned(KnativeRegion $region): void
    {
        $attributes = $region->getAttributes();
        if (! empty($attributes['is_synced'])) {
            return;
        }

        // Tenant context only: the central schema has no is_synced column
        $tenant = tenancy()->initialized ? tenancy()->tenant : null;
        if (! $tenant) {
            return;
        }

        // Regions created through the normal flow are tenant-owned (not synced)
        $region->setAttribute('is_synced', false);
        if (empty($attributes['owner_tenant_id'])) {
            $region->setAttribute('owner_tenant_id', (string) $tenant->getTenantKey());
        }
    }

    public function ownerTenant()
    {
        return $this->belongsTo(Tenant::class, 'owner_tenant_id');
    }
}
