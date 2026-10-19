<?php

namespace App\Jobs;

use App\Events\DeploymentCreated;
use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\Projects\LiveDeployments;
use App\Support\Projects\ProjectAdmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeployRegionDemoJob implements ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public int $tries = 1;

    private const MAX_ATTEMPTS = 60;

    public function __construct(
        public KnativeRegion $region,
        public array $projectIds,
        public int $attempt = 1,
        ?string $initiatorId = null,
    ) {
        $this->initiatorId = $initiatorId;
    }

    public function handle(): void
    {
        $projects = Project::whereIn('id', $this->projectIds)
            ->with('latestVersion')
            ->get();

        $allReady = true;
        $anyFailed = false;

        foreach ($projects as $project) {
            $version = $project->latestVersion;
            if (! $version) {
                // CaaS projects don't need build
                if ($project->service_type === 'caas') {
                    continue;
                }
                $allReady = false;

                continue;
            }

            if ($version->build_status === 'failed') {
                $anyFailed = true;
                break;
            }
            if ($version->build_status !== 'ready') {
                $allReady = false;
            }
        }

        if ($anyFailed) {
            Log::error('DeployRegionDemoJob: build failed', ['region' => $this->region->name]);
            $this->region->update(['region_demo_status' => 'error']);

            return;
        }

        if (! $allReady) {
            if ($this->attempt >= self::MAX_ATTEMPTS) {
                Log::error('DeployRegionDemoJob: timeout waiting for builds', [
                    'region' => $this->region->name,
                ]);
                $this->region->update(['region_demo_status' => 'error']);

                return;
            }

            self::dispatch($this->region, $this->projectIds, $this->attempt + 1, $this->initiatorId)
                ->delay(now()->addSeconds(5));

            return;
        }

        // All builds ready — deploy each project
        foreach ($projects as $project) {
            $version = $project->latestVersion;
            if (! $version) {
                continue;
            }

            // The deploy guards decide as for any deploy (e.g. a fixed-price region's allocation)
            if ($refusal = ProjectAdmission::refusal($project)) {
                Log::warning('DeployRegionDemoJob: demo project not deployed', ['project' => $project->slug, 'reason' => $refusal]);

                continue;
            }

            app(LiveDeployments::class)->supersede($project);

            $deployment = Deployment::create([
                'project_id' => $project->id,
                'version_id' => $version->id,
                'status' => 'pending',
                'deployed_by' => 'system',
            ]);

            DeploymentCreated::dispatch($deployment, $project, $version);
        }

        $this->region->update(['region_demo_status' => 'ready']);

        Log::info('DeployRegionDemoJob: all projects deployed', [
            'region' => $this->region->name,
            'count' => $projects->count(),
        ]);

        $this->broadcastSuccess('demo.deployed', [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
            'region_demo_status' => 'ready',
        ], "Demo deployed on {$this->region->name}");
    }

    public function failed(\Throwable $e): void
    {
        Log::error('DeployRegionDemoJob failed', [
            'region' => $this->region->name,
            'error' => $e->getMessage(),
        ]);
        $this->region->update(['region_demo_status' => 'error']);

        $this->broadcastFailure('demo.deploy_failed', [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
            'region_demo_status' => 'error',
        ], $e, "Demo deploy failed on {$this->region->name}");
    }
}
