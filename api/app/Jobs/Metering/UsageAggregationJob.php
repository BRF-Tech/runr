<?php

namespace App\Jobs\Metering;

use App\Services\Bindings\BindingTenancy;
use App\Services\Metering\UsageAggregator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Metering (docs/monitoring.md): every five minutes, in the central context and in every tenant's,
 * the day's sessions and reservations into usage_records (UsageAggregator) — with or without
 * billing. During the first hour of a day the previous day is aggregated once more, so sessions
 * that ended after midnight and the last minutes of always-on reservations still count for it;
 * that day is calculated from what ran on it (the deployments active then, with the size they
 * were applied with), never from today's state. Then the tenant's usage in platform regions of the
 * running month (and of the previous month during the first two days of a month, before its bills
 * close) is copied into the central tables.
 */
class UsageAggregationJob implements ShouldQueue
{
    use Queueable;

    /**
     * Every run re-aggregates whole days: an immediate retry only repeats a failure, the next run
     * catches up.
     */
    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public ?string $at = null) {}

    public function handle(UsageAggregator $aggregator): void
    {
        $now = $this->at !== null ? Carbon::parse($this->at) : now();
        $dates = [$now->toDateString()];
        if ($now->hour === 0) {
            $dates[] = $now->copy()->subDay()->toDateString();
        }

        $mirrorFrom = ($now->day <= 2 ? $now->copy()->subMonthNoOverflow() : $now->copy())->startOfMonth()->toDateString();
        $today = $now->toDateString();

        // One organization's failure must not stop the metering of the others
        BindingTenancy::eachContext(function () use ($aggregator, $dates, $mirrorFrom, $today) {
            try {
                foreach ($dates as $date) {
                    $aggregator->aggregate($date);
                }
                $aggregator->mirror($mirrorFrom, $today);
            } catch (\Throwable $e) {
                Log::error('Usage aggregation failed', ['tenant' => UsageAggregator::tenantKey(), 'error' => $e->getMessage()]);
                report($e);
            }
        });
    }
}
