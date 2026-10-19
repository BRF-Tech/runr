<?php

namespace Modules\Billing\Listeners;

use App\Events\ProjectOwnerChanged;
use App\Events\ProjectResumed;
use App\Events\ProjectStopped;
use App\Models\Project;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Services\BillingEvents;
use Modules\Billing\Services\RegionOffers;
use Modules\Tenancy\Events\RegionShareOffered;

/**
 * What the Billing module hears from the core and the Tenancy module (docs/billing.md):
 *
 *  - project stopped / resumed / owner changed → webhook events project.stopped, project.resumed,
 *    project.owner_changed in the project's organization
 *  - a platform region shared with a tenant → the tenant's billing managers are notified,
 *    region_share.offered in the platform's log
 */
class BillingSubscriber
{
    public function subscribe(Dispatcher $events): array
    {
        $map = [
            ProjectStopped::class => 'projectStopped',
            ProjectResumed::class => 'projectResumed',
            ProjectOwnerChanged::class => 'ownerChanged',
        ];
        if (class_exists(RegionShareOffered::class)) {
            $map[RegionShareOffered::class] = 'regionOffered';
        }

        return $map;
    }

    public function projectStopped(ProjectStopped $event): void
    {
        app(BillingEvents::class)->record(BillingEvents::PROJECT_STOPPED, [
            'project' => $this->project($event->project),
            'owner_id' => $event->project->payerId(),
            'reason' => $event->reason,
            'stopped_by' => $event->actorId,
            // platform | organization: who can resume it (docs/billing.md#project-stop-and-resume)
            'level' => $event->project->getAttribute('stop_level') === 'platform' ? 'platform' : 'organization',
        ]);
    }

    public function projectResumed(ProjectResumed $event): void
    {
        app(BillingEvents::class)->record(BillingEvents::PROJECT_RESUMED, [
            'project' => $this->project($event->project),
            'deployment_id' => $event->deploymentId,
            'resumed_by' => $event->actorId,
        ]);
    }

    public function ownerChanged(ProjectOwnerChanged $event): void
    {
        app(BillingEvents::class)->record(BillingEvents::PROJECT_OWNER_CHANGED, [
            'project' => $this->project($event->project),
            'previous_owner_id' => $event->previousOwnerId,
            'owner_id' => $event->ownerId,
            'changed_by' => $event->actorId,
        ]);
    }

    public function regionOffered(RegionShareOffered $event): void
    {
        try {
            app(RegionOffers::class)->offered($event->tenant, $event->regionId);
        } catch (\Throwable $e) {
            Log::warning('Billing: region offer not announced', ['tenant' => $event->tenant->getKey(), 'region' => $event->regionId, 'error' => $e->getMessage()]);
            report($e);
        }
    }

    /** @return array{id: string, name: string} */
    private function project(Project $project): array
    {
        return ['id' => (string) $project->id, 'name' => $project->name];
    }
}
