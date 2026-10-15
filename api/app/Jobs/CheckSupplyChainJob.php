<?php

namespace App\Jobs;

use App\Models\ImageSecurityReport;
use App\Services\SupplyChain\SupplyChainPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * Follows the supply-chain Job of a report until it finished (results recorded) or ran past
 * its deadline; one look every supply_chain.job.poll_interval_seconds, re-dispatched.
 */
class CheckSupplyChainJob implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 1;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public ImageSecurityReport $report, public int $attempt = 1) {}

    public static function start(ImageSecurityReport $report): PendingDispatch
    {
        return self::dispatch($report)->delay(now()->addSeconds(self::interval()));
    }

    public static function interval(): int
    {
        return max(1, (int) config('supply_chain.job.poll_interval_seconds', 5));
    }

    /** Checks that fit the Job deadline plus a minute for the log to be read. */
    public static function maxAttempts(): int
    {
        return (int) ceil(((int) config('supply_chain.job.deadline_seconds', 1800) + 60) / self::interval());
    }

    public function handle(SupplyChainPipeline $pipeline): void
    {
        $report = $this->report->fresh();
        if (! $report || $report->status !== 'running') {
            return;
        }

        try {
            if ($pipeline->collect($report)) {
                return;
            }
        } catch (\Throwable $e) {
            if ($this->attempt >= self::maxAttempts()) {
                $pipeline->fail($report, 'Supply-chain check failed: '.$e->getMessage());

                return;
            }
        }

        if ($this->attempt >= self::maxAttempts()) {
            $pipeline->fail($report, 'Supply-chain Job timed out');

            return;
        }

        self::dispatch($report, $this->attempt + 1)->delay(now()->addSeconds(self::interval()));
    }
}
