<?php

namespace App\Events;

use App\Models\Project;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A stopped project was resumed (ProjectStopService::resume); $deploymentId is the redeploy of the
 * version that ran when it was stopped, null when there was none. The Billing module records
 * project.resumed.
 */
class ProjectResumed
{
    use Dispatchable;

    public function __construct(
        public readonly Project $project,
        public readonly ?string $deploymentId = null,
        public readonly ?string $actorId = null,
    ) {}
}
