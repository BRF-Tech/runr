<?php

namespace App\Jobs\Ai;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\AiModelService;
use App\Services\Ai\Models\ModelServiceDeployer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/** Deploy (or redeploy) a model service: ModelServiceDeployer::deploy(). */
class DeployModelServiceJob implements ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public string $serviceId, ?string $initiatorId = null)
    {
        $this->initiatorId = $initiatorId;
    }

    public function handle(ModelServiceDeployer $deployer): void
    {
        $service = AiModelService::with('model', 'region')->find($this->serviceId);
        if (! $service) {
            return;
        }

        $deployer->deploy($service);
    }

    public function failed(?\Throwable $e): void
    {
        $service = AiModelService::find($this->serviceId);
        $service?->update(['status' => AiModelService::STATUS_FAILED, 'status_message' => mb_substr($e?->getMessage() ?? 'unknown error', 0, 2000)]);

        if ($service) {
            $this->broadcastFailure('model_service.deploy_failed', ['model_service_id' => $service->id, 'name' => $service->name], $e ?? new \RuntimeException('unknown error'), "Model service {$service->name} failed to deploy");
        }
    }
}
