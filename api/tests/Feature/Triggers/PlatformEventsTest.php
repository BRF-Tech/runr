<?php

namespace Tests\Feature\Triggers;

use App\Jobs\Triggers\NudgeEventGatewayJob;
use App\Models\Deployment;
use App\Models\Observability\AlertEvent;
use App\Models\Observability\AlertRule;
use App\Models\TriggerEvent;
use App\Services\Observability\Alerts\AlertManager;
use App\Triggers\PlatformEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\BuildsTriggerFixtures;
use Tests\TestCase;

/**
 * Platform events → functions (docs/triggers.md#platform-events): RunR stores one trigger event
 * per subscribed platform_event trigger; the gateway delivers it as dev.runr.<event>.
 */
class PlatformEventsTest extends TestCase
{
    use BuildsTriggerFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([NudgeEventGatewayJob::class]);
    }

    public function test_a_finished_and_a_failed_deployment_reach_the_subscribed_triggers(): void
    {
        $region = $this->triggerRegion(redis: false);
        $watched = $this->triggerProject($region, 'http', 'shop');
        $listener = $this->triggerProject($region, 'http', 'ops-bot');
        $trigger = $this->addTrigger($listener, 'platform_event', ['events' => ['deployment.finished', 'deployment.failed'], 'projects' => [$watched->id]]);
        $everything = $this->addTrigger($this->triggerProject($region, 'http', 'audit'), 'platform_event', ['events' => ['*']]);
        $otherEvent = $this->addTrigger($this->triggerProject($region, 'http', 'pager'), 'platform_event', ['events' => ['alert.fired']]);

        $version = $this->triggerVersion($watched);
        $deployment = Deployment::create(['project_id' => $watched->id, 'version_id' => $version->id, 'status' => 'pending']);
        $deployment->transition(['pending'], ['status' => 'active']);

        $event = TriggerEvent::query()->where('trigger_id', $trigger->id)->sole();
        $this->assertSame('dev.runr.deployment.finished', $event->event_type);
        $this->assertSame(TriggerEvent::KIND_PLATFORM, $event->kind);
        $this->assertSame($watched->id, $event->subject);
        $payload = json_decode($event->rawBody(), true);
        $this->assertSame('deployment.finished', $payload['event']);
        $this->assertSame($deployment->id, $payload['data']['deployment_id']);
        $this->assertSame(1, $payload['data']['version']);

        $this->assertSame(1, TriggerEvent::query()->where('trigger_id', $everything->id)->count());
        $this->assertSame(0, TriggerEvent::query()->where('trigger_id', $otherEvent->id)->count());
        Bus::assertDispatched(NudgeEventGatewayJob::class, fn ($job) => $job->projectId === $listener->id);

        // Events about other projects do not pass the projects filter
        $other = $this->triggerProject($region, 'http', 'blog');
        $otherDeployment = Deployment::create(['project_id' => $other->id, 'version_id' => $this->triggerVersion($other)->id, 'status' => 'pending']);
        $otherDeployment->transition(['pending'], ['status' => 'failed']);
        $this->assertSame(1, TriggerEvent::query()->where('trigger_id', $trigger->id)->count());
        $this->assertSame('dev.runr.deployment.failed', TriggerEvent::query()->where('trigger_id', $everything->id)->latest('received_at')->latest('id')->first()->event_type);
    }

    public function test_the_same_occurrence_is_stored_once(): void
    {
        $listener = $this->triggerProject($this->triggerRegion(redis: false));
        $this->addTrigger($listener, 'platform_event', ['events' => ['cost.limit_reached']]);
        $events = app(PlatformEvents::class);

        $this->assertSame(1, $events->dispatch(PlatformEvents::COST_LIMIT_REACHED, ['limit' => 100], $listener->id, 'month-2026-10'));
        $this->assertSame(0, $events->dispatch(PlatformEvents::COST_LIMIT_REACHED, ['limit' => 100], $listener->id, 'month-2026-10'));
        $this->assertSame(1, TriggerEvent::query()->count());
    }

    public function test_unknown_events_disabled_triggers_and_stopped_projects_get_nothing(): void
    {
        $listener = $this->triggerProject($this->triggerRegion(redis: false));
        $trigger = $this->addTrigger($listener, 'platform_event', ['events' => ['*']]);
        $events = app(PlatformEvents::class);

        $this->assertSame(0, $events->dispatch('deployment.exploded', []));

        $trigger->forceFill(['enabled' => false])->save();
        $this->assertSame(0, $events->dispatch(PlatformEvents::RUNTIME_PATCH_AVAILABLE, ['runtime' => 'python']));

        $trigger->forceFill(['enabled' => true])->save();
        $listener->forceFill(['stopped_at' => now()])->saveQuietly();
        $this->assertSame(0, $events->dispatch(PlatformEvents::RUNTIME_PATCH_AVAILABLE, ['runtime' => 'python']));
    }

    public function test_alerts_fire_and_resolve_platform_events(): void
    {
        $listener = $this->triggerProject($this->triggerRegion(redis: false));
        $this->addTrigger($listener, 'platform_event', ['events' => ['alert.fired', 'alert.resolved']]);

        $rule = AlertRule::query()->create([
            'name' => 'errors', 'type' => 'error_rate', 'severity' => 'critical', 'is_enabled' => true,
            'project_id' => $listener->id, 'channel_ids' => [], 'cooldown_minutes' => 0, 'params' => ['threshold' => 5], 'state' => 'ok',
        ]);
        $alerts = app(AlertManager::class);

        $fired = $alerts->fire($rule, $listener->id, 'Error rate high', 'Error rate 12%', ['value' => 12], $listener->id);
        $this->assertInstanceOf(AlertEvent::class, $fired);
        $alerts->resolve($rule, $listener->id, 'back to normal');

        $types = TriggerEvent::query()->orderBy('received_at')->orderBy('id')->pluck('event_type')->all();
        $this->assertSame(['dev.runr.alert.fired', 'dev.runr.alert.resolved'], $types);
    }
}
