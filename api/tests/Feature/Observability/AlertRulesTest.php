<?php

namespace Tests\Feature\Observability;

use App\Events\NotificationCreated;
use App\Jobs\DeliverAlertJob;
use App\Models\Notification;
use App\Models\Observability\AlertChannel;
use App\Models\Observability\AlertDelivery;
use App\Models\Observability\AlertEvent;
use App\Models\Observability\AlertRule;
use App\Models\Observability\ProjectObservabilitySetting;
use App\Services\Observability\Alerts\AlertEvaluator;
use App\Services\Observability\Alerts\AlertManager;
use App\Services\Observability\Alerts\SpanMetricsQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesObservabilityCluster;
use Tests\TestCase;

/**
 * Alert rules: fire / dedup / cooldown / resolve with one delivery per enabled channel, the
 * delivery job (retry with backoff, permanent failures, retry from the log), the metric rules
 * from span metrics (threshold, min_requests, resolve), the resource-threshold bridge, the
 * PromQL of each metric type, and the rules API (params per type, unknown channels).
 */
class AlertRulesTest extends TestCase
{
    use ActsWithRoles, FakesObservabilityCluster, RefreshDatabase;

    private const PROM = '/api/v1/namespaces/observability/services/http:prometheus-operated:9090/proxy/api/v1/query';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeObservabilityCluster();
        $this->dns->addressRecords['hooks.example.com'] = ['93.184.216.34'];
    }

    private function channel(string $name = 'ops', bool $enabled = true): AlertChannel
    {
        return AlertChannel::query()->create(['name' => $name, 'type' => 'webhook', 'config' => ['url' => 'https://hooks.example.com/'.$name], 'is_enabled' => $enabled]);
    }

    public function test_fire_dedup_cooldown_and_resolve(): void
    {
        Queue::fake();
        $on = $this->channel('on');
        $off = $this->channel('off', false);
        $rule = AlertRule::query()->create(['name' => 'errors', 'type' => 'error_rate', 'severity' => 'critical', 'cooldown_minutes' => 15, 'channel_ids' => [$on->id, $off->id]]);
        $alerts = app(AlertManager::class);

        $event = $alerts->fire($rule, 'p1', 'High error rate', '12 %', ['value' => '12 %'], 'p1');
        $this->assertSame(['firing', 'critical', $rule->id.':p1'], [$event->status, $event->severity, $event->fingerprint]);
        $this->assertSame('firing', $rule->fresh()->state);
        $this->assertSame(1, AlertDelivery::query()->count(), 'only enabled channels');
        Queue::assertPushed(DeliverAlertJob::class, 1);

        // still firing within the cooldown: deduplicated, no new delivery
        $this->assertNull($alerts->fire($rule, 'p1', 'High error rate', '14 %', [], 'p1'));
        $this->assertSame(1, AlertEvent::query()->count());
        $this->assertSame(1, AlertDelivery::query()->count());

        // after the cooldown: a reminder on the same event
        $this->travel(16)->minutes();
        $this->assertSame($event->id, $alerts->fire($rule, 'p1', 'High error rate', '15 %', [], 'p1')->id);
        $this->assertSame(2, AlertDelivery::query()->count());

        // another subject is another alert
        $alerts->fire($rule, 'p2', 'High error rate', '9 %', [], 'p2');
        $this->assertSame(2, AlertEvent::query()->where('status', 'firing')->count());

        $alerts->resolve($rule, 'p1', 'Back to 1 %');
        $this->assertSame('resolved', $event->fresh()->status);
        $this->assertSame('Back to 1 %', $event->fresh()->data['resolve_message']);
        $this->assertSame('firing', $rule->fresh()->state, 'p2 still fires');
        $this->assertSame(1, AlertDelivery::query()->where('kind', 'resolve')->count());

        $alerts->resolve($rule, 'p2');
        $this->assertSame('ok', $rule->fresh()->state);
        $this->assertNull($alerts->resolve($rule, 'p2'), 'nothing left to resolve');
    }

    public function test_delivery_retries_then_fails(): void
    {
        $channel = $this->channel();
        $event = AlertEvent::query()->create(['fingerprint' => 'x', 'type' => 'uptime_down', 'severity' => 'warning', 'status' => 'firing', 'title' => 'API is down', 'fired_at' => now()]);
        $delivery = AlertDelivery::query()->create(['alert_event_id' => $event->id, 'alert_channel_id' => $channel->id, 'kind' => 'alert']);
        $this->externalRoutes['https://hooks.example.com/'] = Http::response('upstream busy', 503);
        config(['observability.alerts.delivery_tries' => 2, 'observability.alerts.delivery_backoff' => [45]]);

        $job = (new DeliverAlertJob($delivery->id))->withFakeQueueInteractions();
        $job->handle(app(AlertManager::class));
        $job->assertReleased(45);
        $this->assertSame(['pending', 1, 503], [$delivery->fresh()->status, $delivery->fresh()->attempts, $delivery->fresh()->response_code]);
        $this->assertStringContainsString('upstream busy', $delivery->fresh()->last_error);

        // the last attempt fails for good (attempts() of the fake job is 1: tries 1 = last)
        config(['observability.alerts.delivery_tries' => 1]);
        $job = (new DeliverAlertJob($delivery->id))->withFakeQueueInteractions();
        $job->handle(app(AlertManager::class));
        $job->assertNotReleased();
        $this->assertSame('failed', $delivery->fresh()->status);

        // a 4xx is not retried
        $second = AlertDelivery::query()->create(['alert_event_id' => $event->id, 'alert_channel_id' => $channel->id, 'kind' => 'alert']);
        $this->externalRoutes['https://hooks.example.com/'] = Http::response('gone', 410);
        config(['observability.alerts.delivery_tries' => 5]);
        $job = (new DeliverAlertJob($second->id))->withFakeQueueInteractions();
        $job->handle(app(AlertManager::class));
        $job->assertNotReleased();
        $this->assertSame('failed', $second->fresh()->status);

        // retry from the delivery log
        Queue::fake();
        $owner = $this->userWithRole('owner');
        $this->signedIn($owner)->postJson("/api/observability/alert-deliveries/{$second->id}/retry")->assertStatus(202)->assertJsonPath('data.status', 'pending');
        Queue::assertPushed(DeliverAlertJob::class, fn ($j) => $j->deliveryId === $second->id);
        $this->signedIn($owner)->postJson("/api/observability/alert-deliveries/{$second->id}/retry")->assertStatus(409);

        $this->signedIn($owner)->getJson('/api/observability/alert-deliveries?status=failed')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.event.title', 'API is down');

        // a successful send
        $this->externalRoutes['https://hooks.example.com/'] = Http::response('ok', 200);
        $job = (new DeliverAlertJob($second->id))->withFakeQueueInteractions();
        $job->handle(app(AlertManager::class));
        $this->assertSame('sent', $second->fresh()->status);
        $this->assertNotNull($second->fresh()->sent_at);
    }

    public function test_metric_rules_fire_and_resolve_from_span_metrics(): void
    {
        Queue::fake();
        $region = $this->obsRegion();
        $this->tracingReady($region);
        $project = $this->obsProject($region);
        $untraced = $this->obsProject($region, 'quiet');
        ProjectObservabilitySetting::forProject($project)->fill(['tracing_enabled' => true])->save();
        $rule = AlertRule::query()->create([
            'name' => 'errors', 'type' => 'error_rate', 'channel_ids' => [$this->channel()->id],
            'params' => ['threshold' => 5, 'window_minutes' => 10, 'min_requests' => 20],
        ]);

        $values = ['value' => '12.5', 'volume' => '100'];
        $this->k8sRoutes['GET '.self::PROM] = function () use (&$values) {
            $q = (string) ($this->k8sCalls[array_key_last($this->k8sCalls)]['query']['query'] ?? '');
            $v = str_starts_with($q, 'sum(increase(') ? $values['volume'] : $values['value'];

            return [200, ['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => $v === null ? [] : [['metric' => [], 'value' => [1760000000, $v]]]]]];
        };

        $evaluator = app(AlertEvaluator::class);
        $this->assertSame(1, $evaluator->evaluateScope(), 'only traced projects');

        $event = AlertEvent::query()->sole();
        $this->assertSame([$project->id, 'firing'], [$event->project_id, $event->status]);
        $this->assertSame('12.5 %', $event->data['value']);
        $this->assertSame(12.5, $rule->fresh()->last_value);
        $queries = array_column(array_column($this->k8sCallsTo('GET', self::PROM), 'query'), 'query');
        $this->assertStringContainsString('service_name="'.$project->slug.'"', $queries[0]);
        $this->assertStringContainsString('[10m]', $queries[0]);
        $this->assertStringNotContainsString('service_name="'.$untraced->slug.'"', implode(' ', $queries));

        // not enough traffic: neither fires nor resolves
        $values = ['value' => '0.5', 'volume' => '3'];
        $evaluator->evaluateScope();
        $this->assertSame('firing', $event->fresh()->status);

        // back below the threshold with traffic: resolved
        $values = ['value' => '0.5', 'volume' => '300'];
        $evaluator->evaluateScope();
        $this->assertSame('resolved', $event->fresh()->status);
        $this->assertNotNull($rule->fresh()->last_evaluated_at);
    }

    public function test_promql_of_the_metric_types(): void
    {
        $error = SpanMetricsQuery::promql('error_rate', 'orders', 5);
        $this->assertSame(
            '100 * sum(rate(traces_span_metrics_calls_total{service_name="orders",span_kind="SPAN_KIND_SERVER",status_code="STATUS_CODE_ERROR"}[5m])) / sum(rate(traces_span_metrics_calls_total{service_name="orders",span_kind="SPAN_KIND_SERVER"}[5m]))',
            $error['value'],
        );
        $this->assertSame('sum(increase(traces_span_metrics_calls_total{service_name="orders",span_kind="SPAN_KIND_SERVER"}[5m]))', $error['volume']);
        $this->assertStringStartsWith('histogram_quantile(0.95, sum by (le) (rate(traces_span_metrics_duration_milliseconds_bucket{', SpanMetricsQuery::promql('p95_latency', 'orders', 5)['value']);
        $this->assertStringContainsString('runr_cold_start="true"', SpanMetricsQuery::promql('cold_start_rate', 'orders', 5)['value']);
        $this->assertStringContainsString('service_name="a\\"b"', SpanMetricsQuery::promql('error_rate', 'a"b', 5)['value']);
    }

    public function test_resource_threshold_notifications_reach_the_rules(): void
    {
        Queue::fake();
        $region = $this->obsRegion();
        $channel = $this->channel();
        $any = AlertRule::query()->create(['name' => 'any', 'type' => 'resource_threshold', 'channel_ids' => [$channel->id], 'params' => ['resource' => 'any']]);
        AlertRule::query()->create(['name' => 'mem critical', 'type' => 'resource_threshold', 'channel_ids' => [$channel->id], 'params' => ['resource' => 'memory', 'min_level' => 'critical']]);
        AlertRule::query()->create(['name' => 'other region', 'type' => 'resource_threshold', 'region_id' => '01JOTHERREGION000000000000', 'channel_ids' => [$channel->id]]);

        $notification = Notification::create([
            'type' => 'resource_threshold', 'level' => 'warning', 'title' => "{$region->name}: CPU 72%",
            'data' => ['region_id' => $region->id, 'region_name' => $region->name, 'resource_type' => 'cpu', 'percentage' => 72.0, 'threshold' => 60],
        ]);
        NotificationCreated::dispatch($notification);

        $event = AlertEvent::query()->sole();
        $this->assertSame($any->id, $event->alert_rule_id);
        $this->assertSame('72 %', $event->data['value']);
        $this->assertSame($any->id.':region:'.$region->id.':cpu', $event->fingerprint);
    }

    public function test_rules_api(): void
    {
        $owner = $this->userWithRole('owner');
        $channel = $this->channel();

        $this->signedIn($owner)->postJson('/api/observability/alert-rules', ['name' => 'p95', 'type' => 'p95_latency', 'params' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['params.threshold_ms']);
        $this->signedIn($owner)->postJson('/api/observability/alert-rules', ['name' => 'x', 'type' => 'uptime_down', 'channel_ids' => ['01JNOCHANNEL00000000000000']])
            ->assertUnprocessable()->assertJsonValidationErrors(['channel_ids']);

        $id = $this->signedIn($owner)->postJson('/api/observability/alert-rules', [
            'name' => 'p95', 'type' => 'p95_latency', 'severity' => 'critical', 'cooldown_minutes' => 30,
            'params' => ['threshold_ms' => 800, 'window_minutes' => 5, 'unknown' => 'dropped'], 'channel_ids' => [$channel->id, $channel->id],
        ])->assertCreated()
            ->assertJsonPath('data.params', ['threshold_ms' => 800, 'window_minutes' => 5])
            ->assertJsonPath('data.channel_ids', [$channel->id])
            ->assertJsonPath('data.state', 'ok')
            ->json('data.id');

        $this->signedIn($owner)->putJson("/api/observability/alert-rules/{$id}", ['is_enabled' => false])->assertOk()->assertJsonPath('data.is_enabled', false);
        $this->signedIn($owner)->putJson("/api/observability/alert-rules/{$id}", ['type' => 'error_rate'])->assertUnprocessable();

        $this->signedIn($owner)->getJson('/api/observability/alert-rules')->assertOk()->assertJsonCount(1, 'data');
        $this->signedIn($owner)->getJson('/api/observability/alert-events?status=firing')->assertOk();
        $this->signedIn($owner)->deleteJson("/api/observability/alert-rules/{$id}")->assertNoContent();
        $this->assertSame(0, AlertRule::query()->count());
    }
}
