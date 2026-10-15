<?php

namespace App\Workflows\Console;

use App\Workflows\Engine\Retention;
use App\Workflows\Support\WorkflowContexts;
use Illuminate\Console\Command;

class WorkflowsPruneCommand extends Command
{
    protected $signature = 'workflows:prune';

    protected $description = 'Delete finished workflow runs, events and usage rows past their retention';

    public function handle(): int
    {
        WorkflowContexts::each(function (string $context) {
            $this->line($context.': '.json_encode(app(Retention::class)->prune()));
        });

        return self::SUCCESS;
    }
}
