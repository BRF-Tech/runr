<?php

namespace Modules\Tenancy\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Tenancy\Models\Tenant;

/**
 * The platform shared a region with a tenant that waits for the tenant's acceptance
 * (TenantRegionShares::requiresAcceptance()): the Billing module notifies the tenant's billing
 * administrators and records region_share.offered.
 */
class RegionShareOffered
{
    use Dispatchable;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $regionId,
        public readonly ?string $actorId = null,
    ) {}
}
