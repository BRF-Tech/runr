<?php

namespace App\Jobs;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\KnativeRegion;
use App\Services\RegionDemoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SeedRegionDemoJob implements ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(
        public KnativeRegion $region,
        public string $userId,
    ) {
        $this->initiatorId = $userId;
    }

    public function handle(RegionDemoService $service): void
    {
        Log::info('SeedRegionDemoJob: starting', ['region' => $this->region->name]);

        $service->validatePrerequisites($this->region);

        $projects = $service->createDemoProjects($this->region, $this->userId);

        $backendUrl = $service->resolveBackendUrl($projects['backend'], $this->region);
        [$redisHost, $redisPort] = $service->parseRedisAddress($this->region);

        $service->setEnvVars($projects, $backendUrl, $redisHost, $redisPort);
        $service->commitDemoCode($projects, $backendUrl, $this->userId);

        $projectIds = array_map(fn ($p) => $p->id, $projects);

        DeployRegionDemoJob::dispatch($this->region, $projectIds, 1, $this->initiatorId)
            ->delay(now()->addSeconds(10));

        Log::info('SeedRegionDemoJob: code committed, deploy job scheduled', [
            'region' => $this->region->name,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SeedRegionDemoJob failed', [
            'region' => $this->region->name,
            'error' => $e->getMessage(),
        ]);
        $this->region->update(['region_demo_status' => 'error']);

        $this->broadcastFailure('demo.seed_failed', [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
        ], $e, "Demo seed failed on {$this->region->name}");
    }
}
