<?php

namespace App\Workflows\Console;

use App\Workflows\Engine\Ticker;
use App\Workflows\Support\WorkflowContexts;
use Illuminate\Console\Command;

class WorkflowsTickCommand extends Command
{
    protected $signature = 'workflows:tick';

    protected $description = 'Wake due sleeps, time out waits and runs, recover expired leases and fire cron triggers of durable workflows';

    public function handle(): int
    {
        WorkflowContexts::each(function (string $context) {
            $stats = app(Ticker::class)->tick();

            if (array_sum($stats) > 0) {
                $this->line($context.': '.json_encode($stats));
            }
        });

        return self::SUCCESS;
    }
}
