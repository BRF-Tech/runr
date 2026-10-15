<?php

namespace App\Events;

use App\Models\Deployment;
use App\Support\BroadcastChannelResolver;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeploymentStatusChanged implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Deployment $deployment,
        public readonly string $previousStatus,
        public readonly string $newStatus,
    ) {}

    public function broadcastOn(): array
    {
        return BroadcastChannelResolver::resolve('deployment.'.$this->deployment->project_id);
    }

    public function broadcastWith(): array
    {
        return [
            'deployment_id' => $this->deployment->id,
            'project_id' => $this->deployment->project_id,
            'previous_status' => $this->previousStatus,
            'new_status' => $this->newStatus,
        ];
    }
}
