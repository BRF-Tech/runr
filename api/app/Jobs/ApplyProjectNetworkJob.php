<?php

namespace App\Jobs;

use App\Jobs\Concerns\RetriesInternalNetworkWrites;
use App\Models\Project;
use App\Services\InternalNetwork\InternalNetworkService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * A project's network onto its region again (InternalNetworkService::apply: its Knative Service's
 * allow list and access mode, its runr.internal and allowlist routes): a change of its access mode
 * or allow list — a caller added or removed, a caller renamed or deleted — that the region's API
 * server did not take. Until it is there a removed or deleted caller is still admitted (a later
 * project of its name too), an added one refused.
 */
class ApplyProjectNetworkJob implements ShouldQueue
{
    use Queueable, RetriesInternalNetworkWrites;

    public function __construct(public string $projectId) {}

    public function handle(InternalNetworkService $network): void
    {
        $project = Project::find($this->projectId);
        if (! $project) {
            return;
        }

        try {
            $network->apply($project);
        } catch (\Throwable $e) {
            Log::warning('Internal network not applied, trying again', ['project' => $project->slug, 'attempt' => $this->attempts(), 'error' => $e->getMessage()]);
            $this->tryAgain();
        }
    }

    public function failed(?\Throwable $e): void
    {
        Log::error('Internal network of a project not on its region; its allow list and access mode wait for php artisan region:reconcile or its next deploy', [
            'project' => $this->projectId,
            'error' => $e?->getMessage(),
        ]);
    }
}
