<?php

namespace Modules\Tenancy\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Tenancy\Models\Tenant;

/**
 * A tenant accepted a shared platform region (TenantRegionShares::accept): the region is copied
 * into the tenant's schema and its users can use it.
 */
class RegionShareAccepted
{
    use Dispatchable;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $regionId,
        public readonly ?string $userId = null,
    ) {}
}
