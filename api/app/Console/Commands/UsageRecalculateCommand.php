<?php

namespace App\Console\Commands;

use App\Services\Bindings\BindingTenancy;
use App\Services\Metering\UsageAggregator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Calculates days of metered usage again (docs/monitoring.md#metering), in the central context and
 * in every tenant's, and copies a tenant's usage in platform regions centrally again — what the
 * usage-aggregation job does for today and yesterday, for the days you name. A recalculated day
 * replaces the rows of the projects' existing deployments; rows of deleted deployments stay. A
 * deployment applied before 0.5.8 keeps the scale mode and size of its row of the day (never today's
 * config, UsageAggregator::sizingOn). Bills that are closed are not changed; open bills read the new
 * rows.
 */
class UsageRecalculateCommand extends Command
{
    protected $signature = 'usage:recalculate {from : first day (Y-m-d)} {to? : last day (Y-m-d), default today}';

    protected $description = 'Calculate days of metered usage again (every organization)';

    public function handle(UsageAggregator $aggregator): int
    {
        try {
            $from = Carbon::parse((string) $this->argument('from'))->startOfDay();
            $to = Carbon::parse((string) ($this->argument('to') ?? now()->toDateString()))->startOfDay();
        } catch (\Throwable) {
            $this->error('Days are Y-m-d dates.');

            return self::INVALID;
        }

        $today = now()->startOfDay();
        if ($to->gt($today)) {
            $to = $today;
        }
        if ($from->gt($to) || $from->diffInDays($to) > 92) {
            $this->error('Give at most 93 days, the first one not after the last one (and not in the future).');

            return self::INVALID;
        }

        $written = 0;
        $contexts = 0;
        $failed = 0;
        // One organization's failure does not stop the others
        BindingTenancy::eachContext(function () use ($aggregator, $from, $to, &$written, &$contexts, &$failed) {
            $contexts++;
            try {
                for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                    $written += $aggregator->aggregate($day->toDateString());
                }
                $aggregator->mirror($from->toDateString(), $to->toDateString());
            } catch (\Throwable $e) {
                $failed++;
                $this->warn('Organization '.(UsageAggregator::tenantKey() ?? 'central').': '.$e->getMessage());
                report($e);
            }
        });

        $this->info("Recalculated {$from->toDateString()} … {$to->toDateString()} in {$contexts} organization context(s): {$written} record(s).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
