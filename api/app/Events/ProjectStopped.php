<?php

namespace App\Events;

use App\Models\Project;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An administrator stopped a project (ProjectStopService::stop): its deployments are torn down and
 * it cannot be deployed until it is resumed. The Billing module records project.stopped.
 */
class ProjectStopped
{
    use Dispatchable;

    public function __construct(
        public readonly Project $project,
        public readonly string $reason,
        public readonly ?string $actorId = null,
    ) {}
}
