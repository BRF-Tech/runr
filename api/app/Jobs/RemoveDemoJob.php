<?php

namespace App\Jobs;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\KnativeRegion;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RemoveDemoJob implements ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(?string $initiatorId = null)
    {
        $this->initiatorId = $initiatorId;
    }

    public function handle(): void
    {
        $demoRegions = KnativeRegion::where('is_demo', true)->get();

        foreach ($demoRegions as $region) {
            foreach ($region->projects as $project) {
                $project->allDeployments()->delete();
                $project->versions()->delete();
                $project->xaasConfig()?->delete();
                $project->faasConfig()?->delete();
                $project->containerConfig()?->delete();
                $project->delete();
            }
            $region->delete();
        }

        Setting::set('demo_status', 'idle');

        $this->broadcastSuccess('demo.removed', ['demo_status' => 'idle'], 'Demo data removed');
    }

    public function failed(?\Throwable $e): void
    {
        Setting::set('demo_status', 'error');

        $this->broadcastFailure('demo.remove_failed', ['demo_status' => 'error'], $e, 'Demo data removal failed');
    }
}
