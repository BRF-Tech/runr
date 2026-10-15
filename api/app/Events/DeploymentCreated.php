<?php

namespace App\Events;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\Version;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeploymentCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Deployment $deployment,
        public readonly Project $project,
        public readonly Version $version,
        public readonly ?string $initiatorId = null,
    ) {}
}
