<?php

namespace App\Events;

use App\Models\Project;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The owner of a project — the payer of its bills (docs/billing.md) — changed
 * (PUT /api/projects/{project}/owner). The Billing module turns it into the
 * project.owner_changed billing event.
 */
class ProjectOwnerChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Project $project,
        public readonly ?string $previousOwnerId,
        public readonly string $ownerId,
        public readonly ?string $actorId = null,
    ) {}
}
