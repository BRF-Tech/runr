<?php

namespace App\Workflows\Jobs;

use App\Workflows\Engine\WorkflowExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One execution step of a run (WorkflowExecutor::execute). Safe to deliver more than once: the
 * executor only invokes the function after taking the run's lease, so a duplicate job is a
 * no-op. A crashed job leaves the lease to expire; workflows:tick retries the run.
 */
class ExecuteWorkflowRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public string $runId)
    {
        $this->timeout = (int) config('workflows.request_timeout', 300) + 30;

        $this->onQueue((string) config('workflows.queue', 'default'));
        if ($connection = config('workflows.connection')) {
            $this->onConnection($connection);
        }
    }

    public function handle(WorkflowExecutor $executor): void
    {
        $executor->execute($this->runId);
    }
}
