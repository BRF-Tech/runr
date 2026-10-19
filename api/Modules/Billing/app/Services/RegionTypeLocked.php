<?php

namespace Modules\Billing\Services;

/**
 * A region's billing type cannot change while fixed allocations are active there
 * (BillingPolicy::TYPE_CHANGE_BLOCKED_BY_ACTIVE_ALLOCATIONS).
 */
class RegionTypeLocked extends \RuntimeException
{
    public function __construct(public readonly int $activeAllocations)
    {
        parent::__construct(__('billing.region_type_locked', ['count' => $activeAllocations]));
    }
}
