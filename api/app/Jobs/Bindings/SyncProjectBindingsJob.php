<?php

namespace App\Jobs\Bindings;

use App\Models\Project;
use App\Services\Bindings\BindingManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A project's bindings Secret + workload env (rolling restart) and the NetworkPolicies of the
 * given bindings (e.g. one just detached) and of every binding still attached.
 */
class SyncProjectBindingsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 120;

    public int $tries = 3;

    public array $backoff = [10, 30];

    /** @param list<string> $bindingIds */
    public function __construct(
        public string $projectId,
        public array $bindingIds = [],
    ) {}

    public function handle(BindingManager $manager): void
    {
        $project = Project::query()->with('knativeRegion')->find($this->projectId);
        if (! $project) {
            return;
        }

        $manager->syncProject($project, $this->bindingIds);
    }
}
