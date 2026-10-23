<?php

namespace App\Workflows\Jobs;

use App\Models\Project;
use App\Workflows\Engine\WorkflowRegistry;
use App\Workflows\Support\WorkflowEnvironments;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Syncs the workflow definitions of a project's environment after its deployment became active
 * (WorkflowServiceProvider): production's ($environmentId null) or an environment's
 * (docs/workflows.md#environments). The result (ok / error) is stored on that environment's
 * workflow app.
 */
class SyncWorkflowsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> a freshly ready revision may still be warming up */
    public array $backoff = [10, 30, 60];

    public int $timeout = 120;

    public function __construct(public string $projectId, public ?string $environmentId = null)
    {
        $this->onQueue((string) config('workflows.queue', 'default'));
        if ($connection = config('workflows.connection')) {
            $this->onConnection($connection);
        }
    }

    public function handle(WorkflowRegistry $registry): void
    {
        $stored = Project::find($this->projectId);
        // The environment was deleted meanwhile: nothing to sync
        $project = $stored ? WorkflowEnvironments::view($stored, $this->environmentId) : null;
        if (! $project) {
            return;
        }

        try {
            $registry->sync($project);
        } catch (Throwable $e) {
            Log::info('Workflow sync after deploy failed', ['project' => $project->slug, 'environment' => $this->environmentId, 'error' => $e->getMessage()]);

            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 60);
            }
        }
    }
}
