<?php

namespace App\Events\ApiProduct;

use App\Models\ApiProduct\ApiConsumer;
use App\Models\Gateway;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * The region's gateway-auth refused calls of a consumer because a daily or monthly quota of its
 * plan was used up (ApiUsageIngest). The Billing module records api.usage.quota_exceeded, once per
 * consumer and day — the invoicing system may sell a bigger plan.
 */
class ApiQuotaExceeded
{
    use Dispatchable;

    public function __construct(
        public readonly Gateway $gateway,
        public readonly ApiConsumer $consumer,
        public readonly ?string $keyId,
        public readonly ?string $routeId,
        public readonly Carbon $hour,
        public readonly int $refused,
    ) {}
}
