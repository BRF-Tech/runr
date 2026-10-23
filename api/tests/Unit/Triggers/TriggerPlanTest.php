<?php

namespace Tests\Unit\Triggers;

use App\Models\ProjectTrigger;
use App\Triggers\TriggerPlan;
use App\Triggers\TriggerTypes;
use App\Triggers\Types\InboxTriggerType;
use App\Triggers\Types\WebhookTrigger;
use Tests\TestCase;

/**
 * TriggerPlan: what a list of enabled triggers deploys and counts as (docs/triggers.md).
 */
class TriggerPlanTest extends TestCase
{
    private function plan(array ...$triggers): TriggerPlan
    {
        $rows = collect($triggers)->map(fn (array $t) => new ProjectTrigger(['name' => $t[0], 'type' => $t[0], 'config' => $t[1] ?? [], 'enabled' => true]));

        return new TriggerPlan($rows, app(TriggerTypes::class));
    }

    public function test_http_alone(): void
    {
        $plan = $this->plan(['http']);

        $this->assertFalse($plan->cronJob());
        $this->assertTrue($plan->public());
        $this->assertFalse($plan->gateway());
        $this->assertSame('http', $plan->runtimeTrigger());
        $this->assertSame('http', $plan->capacityTrigger());
        $this->assertSame('http', $plan->entryPoint());
    }

    public function test_a_schedule_alone_is_the_cronjob(): void
    {
        $plan = $this->plan(['schedule', ['cron' => '* * * * *']]);

        $this->assertTrue($plan->cronJob());
        $this->assertFalse($plan->gateway(), 'no gateway: the CronJob runs it');
        $this->assertSame('schedule', $plan->runtimeTrigger());
        $this->assertSame('schedule', $plan->capacityTrigger());
        $this->assertSame(['schedule'], $plan->runtimeTriggers());
    }

    public function test_a_schedule_next_to_http_runs_on_the_gateway(): void
    {
        $plan = $this->plan(['http'], ['schedule', ['cron' => '0 3 * * *']]);

        $this->assertFalse($plan->cronJob());
        $this->assertTrue($plan->public());
        $this->assertSame(['schedule'], $plan->gatewayTriggers()->pluck('type')->all());
        $this->assertSame('http', $plan->runtimeTrigger());
        $this->assertSame(['http'], $plan->runtimeTriggers());
        $this->assertSame('queue', $plan->capacityTrigger());
    }

    public function test_the_single_queue_keeps_the_legacy_form_until_it_is_customized(): void
    {
        $this->assertNotNull($this->plan(['queue', ['stream' => 'orders']])->legacyQueue());
        $this->assertNull($this->plan(['queue', ['stream' => 'orders', 'delivery' => ['concurrency' => 2]]])->legacyQueue());
        $this->assertNull($this->plan(['queue', ['stream' => 'orders', 'start_from' => 'latest']])->legacyQueue());
        $this->assertNull($this->plan(['queue', ['stream' => 'orders']], ['webhook', ['verify' => 'none']])->legacyQueue());
    }

    public function test_event_only_projects_are_cluster_local_queue_workloads(): void
    {
        $plan = $this->plan(['webhook', ['verify' => 'none']], ['topic', ['topic' => 'a']]);

        $this->assertFalse($plan->public());
        $this->assertTrue($plan->requiresRedis());
        $this->assertSame('queue', $plan->runtimeTrigger());
        $this->assertSame(['queue'], $plan->runtimeTriggers());
        $this->assertSame('queue', $plan->entryPoint());
    }

    public function test_no_enabled_trigger_is_an_unreachable_http_service(): void
    {
        $plan = $this->plan();

        $this->assertFalse($plan->cronJob());
        $this->assertFalse($plan->public());
        $this->assertFalse($plan->gateway());
        $this->assertSame('http', $plan->runtimeTrigger());
        $this->assertNull($plan->primary());
    }

    public function test_the_inbox_lease_covers_every_attempt_and_backoff(): void
    {
        $trigger = new ProjectTrigger(['type' => 'webhook', 'config' => ['delivery' => ['max_retries' => 3, 'retry_backoff_seconds' => 10, 'retry_backoff_max_seconds' => 15]]]);

        // 4 attempts × (30 + 5) s + backoff 10 + 15 + 15 + 60 s slack
        $this->assertSame(4 * 35 + 40 + 60, InboxTriggerType::leaseSeconds($trigger, 30));
        $this->assertSame(6 * 3600, InboxTriggerType::leaseSeconds(new ProjectTrigger(['type' => 'webhook', 'config' => ['delivery' => ['max_retries' => 20, 'retry_backoff_max_seconds' => 3600]]]), 900));
    }

    public function test_webhook_normalization_drops_what_the_preset_does_not_use(): void
    {
        $type = new WebhookTrigger;

        $this->assertSame(['verify' => 'github'], $type->normalize(['verify' => 'github', 'hmac' => ['header' => 'X']]));
        $this->assertSame(
            ['verify' => 'hmac', 'hmac' => ['header' => 'X-Signature', 'algorithm' => 'sha256', 'encoding' => 'hex', 'prefix' => ''], 'retention_days' => 3, 'delivery' => ['concurrency' => 2]],
            $type->normalize(['verify' => 'hmac', 'retention_days' => '3', 'delivery' => ['concurrency' => '2', 'bogus' => 1]]),
        );
    }
}
