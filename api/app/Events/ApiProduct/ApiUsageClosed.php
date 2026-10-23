<?php

namespace App\Events\ApiProduct;

use App\Models\Gateway;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * A finished period of a gateway's API usage (CloseApiUsageJob): an hour (period "hour", a few
 * minutes after it ended) or a calendar month (period "month", on the 1st). $consumers holds the
 * totals per consumer (ApiUsageReport::periodTotals). The Billing module records api.usage.hourly /
 * api.usage.monthly in the organization's event log and webhook — what an external invoicing
 * system bills from.
 */
class ApiUsageClosed
{
    use Dispatchable;

    public const HOUR = 'hour';

    public const MONTH = 'month';

    /**
     * @param  list<array<string, mixed>>  $consumers
     * @param  array<string, int>  $totals
     */
    public function __construct(
        public readonly Gateway $gateway,
        public readonly string $period,
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly array $consumers,
        public readonly array $totals,
    ) {}
}
