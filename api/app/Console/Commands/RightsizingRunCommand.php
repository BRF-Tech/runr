<?php

namespace App\Console\Commands;

use App\Services\Bindings\BindingTenancy;
use App\Services\Metering\UsageAggregator;
use App\Services\Rightsizing\PeakCollector;
use App\Services\Rightsizing\RightsizingService;
use Illuminate\Console\Command;

/**
 * Runs the nightly rightsizing now (docs/monitoring.md#rightsizing), in the central context and in
 * every tenant's: the days of the window the projects have no peaks for are read from the regions'
 * Prometheus, then every running project's recommendation is calculated again. --no-collect only
 * calculates again from the peaks already read (e.g. after changing the margins).
 */
class RightsizingRunCommand extends Command
{
    protected $signature = 'rightsizing:run {--no-collect : calculate from the peaks already read, without asking Prometheus}';

    protected $description = 'Read the projects\' daily peaks and calculate the rightsizing recommendations (every organization)';

    public function handle(PeakCollector $collector, RightsizingService $rightsizing): int
    {
        if (! config('rightsizing.enabled', true)) {
            $this->warn('Rightsizing is off (RIGHTSIZING_ENABLED=false).');

            return self::SUCCESS;
        }

        $read = 0;
        $open = 0;
        $contexts = 0;
        $failed = 0;
        BindingTenancy::eachContext(function () use ($collector, $rightsizing, &$read, &$open, &$contexts, &$failed) {
            $contexts++;
            try {
                if (! $this->option('no-collect')) {
                    $read += $collector->collect();
                }
                $open += $rightsizing->refresh();
            } catch (\Throwable $e) {
                $failed++;
                $this->warn('Organization '.(UsageAggregator::tenantKey() ?? 'central').': '.$e->getMessage());
                report($e);
            }
        });

        $this->info("Rightsizing in {$contexts} organization context(s): {$read} day(s) read, {$open} open recommendation(s).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
