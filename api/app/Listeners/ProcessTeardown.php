<?php

namespace App\Listeners;

use App\Events\DeploymentStatusChanged;
use App\Services\KnativeDeployService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class ProcessTeardown implements ShouldQueue
{
    /**
     * Deletes are idempotent (404 = gone), so an incomplete teardown (API server briefly
     * unreachable) is retried with backoff; after the last try the deployment is marked failed.
     */
    public int $tries = 3;

    public array $backoff = [15, 60];

    public int $timeout = 120;

    public function __construct(
        private KnativeDeployService $deployer,
    ) {}

    public function handle(DeploymentStatusChanged $event): void
    {
        if ($event->newStatus !== 'shutting_down') {
            return;
        }

        $deployment = $event->deployment->fresh(['version', 'project.knativeRegion', 'project.xaasConfig', 'project.faasConfig', 'project.containerConfig']);
        if (! $deployment || $deployment->status !== 'shutting_down' || ! $deployment->project) {
            return;
        }

        $project = $deployment->project;

        // Replaced by a newer deploy of the same project: that deploy applies the same Knative
        // Service / CronJob / queue consumer by name — deleting them here removed the new
        // deployment's service (or raced with it). Only what the new deployment does not use goes.
        // A stopped project has no newer deployment that may serve: a deploy that raced the stop
        // does not keep the Knative Service alive (KnativeDeployService::teardownEnded)
        $complete = $this->deployer->teardownEnded($project, $deployment);

        if (! $complete) {
            throw new \RuntimeException("Teardown of deployment {$deployment->id} ({$project->slug}) incomplete — see the log");
        }

        $deployment->transition(['shutting_down'], ['status' => 'shut_down']);
    }

    public function failed(DeploymentStatusChanged $event, ?\Throwable $e): void
    {
        if ($event->newStatus !== 'shutting_down') {
            return;
        }

        $deployment = $event->deployment->fresh();
        if (! $deployment || $deployment->status !== 'shutting_down') {
            return;
        }

        if (! $deployment->transition(['shutting_down'], [
            'status' => 'failed',
            'knative_message' => mb_substr('Teardown did not complete, resources may remain: '.($e?->getMessage() ?? 'unknown error'), 0, 255),
        ])) {
            return;
        }

        Log::error('Teardown job failed', ['deployment' => $deployment->id, 'error' => $e?->getMessage()]);

        event(new DeploymentStatusChanged($deployment, 'shutting_down', 'failed'));
    }
}
