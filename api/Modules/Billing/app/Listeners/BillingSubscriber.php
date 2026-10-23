<?php

namespace Modules\Billing\Listeners;

use App\Events\ApiProduct\ApiQuotaExceeded;
use App\Events\ApiProduct\ApiUsageClosed;
use App\Events\ProjectOwnerChanged;
use App\Events\ProjectResumed;
use App\Events\ProjectStopped;
use App\Models\Gateway;
use App\Models\Project;
use App\Services\Projects\ProjectStopService;
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
 *  - API product usage (docs/api-product.md#usage-events) → api.usage.hourly, api.usage.monthly
 *    (once per gateway and period) and api.usage.quota_exceeded (once per consumer and day)
 */
class BillingSubscriber
{
    public function subscribe(Dispatcher $events): array
    {
        $map = [
            ProjectStopped::class => 'projectStopped',
            ProjectResumed::class => 'projectResumed',
            ProjectOwnerChanged::class => 'ownerChanged',
            ApiUsageClosed::class => 'apiUsageClosed',
            ApiQuotaExceeded::class => 'apiQuotaExceeded',
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
            // cost_limit: the project's own monthly cost limit stopped it (stopped_by system:cost_limit)
            'system' => ProjectStopService::systemOf($event->project),
        ]);
    }

    public function projectResumed(ProjectResumed $event): void
    {
        app(BillingEvents::class)->record(BillingEvents::PROJECT_RESUMED, [
            'project' => $this->project($event->project),
            'deployment_id' => $event->deploymentId,
            'resumed_by' => $event->actorId,
            // cost_limit: RunR resumed it by itself when a new month began (resumed_by system:cost_limit)
            'system' => str_starts_with((string) $event->actorId, ProjectStopService::SYSTEM_ACTOR)
                ? substr((string) $event->actorId, strlen(ProjectStopService::SYSTEM_ACTOR)) : null,
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

    public function apiUsageClosed(ApiUsageClosed $event): void
    {
        $monthly = $event->period === ApiUsageClosed::MONTH;
        $period = $monthly ? $event->from->format('Y-m') : $event->from->format('Y-m-d\TH');

        app(BillingEvents::class)->record($monthly ? BillingEvents::API_USAGE_MONTHLY : BillingEvents::API_USAGE_HOURLY, [
            'gateway' => $this->gateway($event->gateway),
            'period' => $period,
            'from' => $event->from->toIso8601ZuluString(),
            'to' => $event->to->toIso8601ZuluString(),
            'totals' => $event->totals,
            'consumers' => $event->consumers,
        ], 'api.usage.'.$event->period.':'.$event->gateway->id.':'.$period);
    }

    public function apiQuotaExceeded(ApiQuotaExceeded $event): void
    {
        $consumer = $event->consumer;
        $plan = $consumer->plan;

        app(BillingEvents::class)->record(BillingEvents::API_USAGE_QUOTA_EXCEEDED, [
            'gateway' => $this->gateway($event->gateway),
            'consumer' => ['id' => $consumer->id, 'name' => $consumer->name, 'external_id' => $consumer->external_id],
            'key_id' => $event->keyId,
            'route_id' => $event->routeId,
            'plan' => $plan ? ['id' => $plan->id, 'name' => $plan->name, 'quota_per_day' => $plan->quota_per_day, 'quota_per_month' => $plan->quota_per_month] : null,
            'hour' => $event->hour->toIso8601ZuluString(),
            'refused' => $event->refused,
        ], 'api.usage.quota_exceeded:'.$consumer->id.':'.$event->hour->format('Y-m-d'));
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

    /** @return array{id: string, subdomain: string, custom_domain: string|null, region_id: string} */
    private function gateway(Gateway $gateway): array
    {
        return [
            'id' => (string) $gateway->id,
            'subdomain' => $gateway->subdomain,
            'custom_domain' => $gateway->custom_domain,
            'region_id' => (string) $gateway->knative_region_id,
        ];
    }

    /** @return array{id: string, name: string} */
    private function project(Project $project): array
    {
        return ['id' => (string) $project->id, 'name' => $project->name];
    }
}
