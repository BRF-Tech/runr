<?php

namespace App\Workflows\Jobs;

use App\Models\Project;
use App\Workflows\Engine\WorkflowRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Syncs a project's workflow definitions after its deployment became active
 * (WorkflowServiceProvider). The result (ok / error) is stored on the project's workflow app.
 */
class SyncWorkflowsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> a freshly ready revision may still be warming up */
    public array $backoff = [10, 30, 60];

    public int $timeout = 120;

    public function __construct(public string $projectId)
    {
        $this->onQueue((string) config('workflows.queue', 'default'));
        if ($connection = config('workflows.connection')) {
            $this->onConnection($connection);
        }
    }

    public function handle(WorkflowRegistry $registry): void
    {
        $project = Project::find($this->projectId);
        if (! $project) {
            return;
        }

        try {
            $registry->sync($project);
        } catch (Throwable $e) {
            Log::info('Workflow sync after deploy failed', ['project' => $project->slug, 'error' => $e->getMessage()]);

            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 60);
            }
        }
    }
}
