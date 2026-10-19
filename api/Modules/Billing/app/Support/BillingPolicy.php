<?php

namespace Modules\Billing\Support;

/**
 * The two billing rules the product owner left open, decided here — each in one place so it can
 * be changed without touching the rest of the module (docs/billing.md):
 *
 *  1. One billing type per region (payg or fixed), chosen when the region is opened. Changing it is
 *     refused while the region has active fixed allocations (RegionTypes::set()).
 *  2. A project in a fixed region is hard-limited to its approved resources: it cannot deploy
 *     without an approved allocation, and everything it can run there at its worst case — every
 *     pod up to max_scale (which it must have), its previews, its GPUs — must fit the allocation at
 *     every deploy, resume, scale and config change (Admission); a request or approval smaller
 *     than what runs is refused until the project scales down (FixedRequests). Usage above the
 *     allocation therefore cannot happen and is never billed as overage. A change of resources is
 *     a new request that needs approval again and is prorated by day from its approval
 *     (BillCalculator::fixedLines()).
 */
final class BillingPolicy
{
    /** Decision 1: a region's billing type cannot change while fixed allocations are active there. */
    public const TYPE_CHANGE_BLOCKED_BY_ACTIVE_ALLOCATIONS = true;

    /** Decision 2: fixed projects are hard-limited to their approved resources. */
    public const FIXED_HARD_LIMIT = true;

    public const PAYG = 'payg';

    public const FIXED = 'fixed';

    public const TYPES = [self::PAYG, self::FIXED];

    /** Billing type of a region that has no setting yet. */
    public const DEFAULT_TYPE = self::PAYG;

    /** Decision 1 as the services ask it. */
    public static function typeChangeBlockedByActiveAllocations(): bool
    {
        return self::TYPE_CHANGE_BLOCKED_BY_ACTIVE_ALLOCATIONS;
    }

    /** Decision 2 as the services ask it. */
    public static function fixedHardLimit(): bool
    {
        return self::FIXED_HARD_LIMIT;
    }
}
