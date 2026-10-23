<?php

namespace App\Listeners;

use App\Events\DeploymentCreated;
use App\Events\DeploymentStatusChanged;
use App\Services\KnativeDeployService;
use App\Services\Rollouts\RolloutService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class ProcessDeployment implements ShouldQueue
{
    /**
     * One try: every run applies the Knative Service again (a new revision). Errors inside the
     * deploy are recorded on the deployment by KnativeDeployService; anything that escapes
     * (worker killed, timeout, lost connection) ends in failed() — never a silent "pending".
     */
    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        private KnativeDeployService $deployer,
    ) {}

    public function handle(DeploymentCreated $event): void
    {
        $this->deployer->deploy(
            $event->project->load([
                'knativeRegion', 'xaasConfig', 'faasConfig', 'containerConfig',
            ]),
            $event->version,
            $event->deployment,
            $event->initiatorId,
        );
    }

    public function failed(DeploymentCreated $event, ?\Throwable $e): void
    {
        $deployment = $event->deployment->fresh();

        if (! $deployment || ! in_array($deployment->status, ['pending', 'running'], true)) {
            return;
        }

        $previous = $deployment->status;
        // Only while it is still pending / running: a stop in the meantime keeps its status
        if (! $deployment->transition(['pending', 'running'], [
            'status' => 'failed',
            'knative_ready' => false,
            'knative_message' => mb_substr('Deploy did not complete: '.($e?->getMessage() ?? 'unknown error'), 0, 255),
        ])) {
            return;
        }

        Log::error('Deploy job failed', [
            'deployment' => $deployment->id,
            'project' => $event->project->slug,
            'error' => $e?->getMessage(),
        ]);

        event(new DeploymentStatusChanged($deployment, $previous, 'failed'));

        // A rollout's new deployment: the rollout fails, the traffic stays where it was
        app(RolloutService::class)->candidateFailed($deployment, (string) $deployment->knative_message);
    }
}
