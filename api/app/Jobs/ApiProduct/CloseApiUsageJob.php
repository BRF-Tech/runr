<?php

namespace App\Jobs\ApiProduct;

use App\Events\ApiProduct\ApiUsageClosed;
use App\Models\ApiProduct\ApiUsageHour;
use App\Models\Gateway;
use App\Services\ApiProduct\ApiUsageReport;
use App\Services\Observability\TenantScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Closes a period of API usage (docs/api-product.md#usage-events), in the central context and in
 * every tenant's: for each gateway with usage in it, ApiUsageClosed with the per-consumer totals —
 * the Billing module turns it into api.usage.hourly / api.usage.monthly (deduplicated by gateway
 * and period, so a second run sends nothing twice).
 *
 *   hour   the previous hour, a few minutes after it ended (the regions report every 30 s)
 *   month  the previous calendar month, on the 1st
 *   prune  hourly rows older than api_product.usage_retention_days
 */
class CloseApiUsageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $kind = ApiUsageClosed::HOUR, public ?string $at = null) {}

    public function handle(ApiUsageReport $report): void
    {
        $now = ($this->at !== null ? Carbon::parse($this->at) : now())->utc();

        if ($this->kind === 'prune') {
            $before = $now->copy()->subDays(max(35, (int) config('api_product.usage_retention_days', 400)));
            TenantScope::each(fn () => ApiUsageHour::query()->where('hour', '<', $before)->delete());

            return;
        }

        [$from, $to] = $this->kind === ApiUsageClosed::MONTH
            ? [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->startOfMonth()]
            : [$now->copy()->subHour()->startOfHour(), $now->copy()->startOfHour()];

        TenantScope::each(function (?string $tenantId) use ($report, $from, $to) {
            $gatewayIds = ApiUsageHour::query()
                ->where('hour', '>=', $from)->where('hour', '<', $to)
                ->distinct()->pluck('gateway_id');

            foreach (Gateway::query()->whereIn('id', $gatewayIds)->get() as $gateway) {
                try {
                    $totals = $report->periodTotals($gateway, $from, $to);
                    ApiUsageClosed::dispatch($gateway, $this->kind, $from->copy(), $to->copy(), $totals['consumers'], $totals['totals']);
                } catch (\Throwable $e) {
                    Log::warning('API usage period not closed', ['tenant' => $tenantId, 'gateway' => $gateway->id, 'error' => $e->getMessage()]);
                }
            }
        });
    }
}
