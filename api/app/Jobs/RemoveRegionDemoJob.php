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

class RemoveRegionDemoJob implements ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(
        public KnativeRegion $region,
        ?string $initiatorId = null,
    ) {
        $this->initiatorId = $initiatorId;
    }

    public function handle(RegionDemoService $service): void
    {
        Log::info('RemoveRegionDemoJob: starting', ['region' => $this->region->name]);

        $service->cleanupDemoProjects($this->region);

        $this->region->update(['region_demo_status' => null]);

        Log::info('RemoveRegionDemoJob: completed', ['region' => $this->region->name]);

        $this->broadcastSuccess('demo.removed', [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
            'region_demo_status' => null,
        ], "Demo removed from {$this->region->name}");
    }

    public function failed(\Throwable $e): void
    {
        Log::error('RemoveRegionDemoJob failed', [
            'region' => $this->region->name,
            'error' => $e->getMessage(),
        ]);
        $this->region->update(['region_demo_status' => 'error']);

        $this->broadcastFailure('demo.remove_failed', [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
            'region_demo_status' => 'error',
        ], $e, "Demo removal failed on {$this->region->name}");
    }
}
