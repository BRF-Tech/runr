<?php

namespace App\Console\Commands;

use App\Services\Sandboxes\SandboxPoolService;
use App\Services\Sandboxes\SandboxReaper;
use App\Services\Sandboxes\SandboxUsageAggregator;
use Illuminate\Console\Command;

/**
 * The Sandbox API's scheduled work on demand (the scheduler runs each of them on its own):
 * reaper (TTL, idle, quota, stuck, lost / orphan pods, retention), warm pool refill, usage metering.
 */
class SandboxesMaintainCommand extends Command
{
    protected $signature = 'sandboxes:maintain
        {--reap : TTL / idle / quota expiry, stuck sandboxes, lost and orphan pods, retention}
        {--refill : refill the warm pools}
        {--meter : meter today\'s usage}';

    protected $description = 'Run the Sandbox API reaper, warm pool refill and usage metering now (all three without options)';

    public function handle(SandboxReaper $reaper, SandboxPoolService $pools, SandboxUsageAggregator $usage): int
    {
        $all = ! $this->option('reap') && ! $this->option('refill') && ! $this->option('meter');

        if ($all || $this->option('reap')) {
            foreach ($reaper->run() as $step => $count) {
                $this->line(sprintf('reaper %-8s %d', $step, $count));
            }
        }
        if ($all || $this->option('refill')) {
            $this->line('pools: '.$pools->refill().' warm sandbox(es) started');
        }
        if ($all || $this->option('meter')) {
            $this->line('usage: '.$usage->aggregateRecent().' record(s) written');
        }

        return self::SUCCESS;
    }
}
