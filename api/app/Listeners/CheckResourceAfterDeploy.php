<?php

namespace App\Listeners;

use App\Events\DeploymentCreated;
use App\Services\ResourceThresholdService;
use Illuminate\Contracts\Queue\ShouldQueue;

class CheckResourceAfterDeploy implements ShouldQueue
{
    public function __construct(
        private ResourceThresholdService $thresholdService,
    ) {}

    public function handle(DeploymentCreated $event): void
    {
        $region = $event->project->knativeRegion;

        if ($region) {
            $this->thresholdService->checkRegion($region);
        }
    }
}
