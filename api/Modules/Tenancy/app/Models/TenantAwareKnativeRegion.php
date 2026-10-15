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
        static::creating(function (self $region) {
            if (! $region->owner_tenant_id && request()->attributes->get('tenant')) {
                $region->owner_tenant_id = request()->attributes->get('tenant')->id;
            }

            // Regions created through normal flow are tenant-owned (not synced)
            if (! isset($region->attributes['is_synced'])) {
                $region->is_synced = false;
            }
        });
    }

    public function ownerTenant()
    {
        return $this->belongsTo(Tenant::class, 'owner_tenant_id');
    }
}
