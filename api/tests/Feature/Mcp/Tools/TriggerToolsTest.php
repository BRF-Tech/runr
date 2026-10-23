<?php

namespace Tests\Feature\Mcp\Tools;

use App\Jobs\DropCdcSlotsJob;
use App\Jobs\Triggers\NudgeEventGatewayJob;
use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Models\Topic;
use App\Models\TriggerEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTriggerFixtures;
use Tests\TestCase;

/**
 * triggers_list, trigger_add, trigger_update, trigger_remove, webhook_events_list,
 * webhook_event_replay, topics_list, topic_publish — the trigger routes (docs/triggers.md), with
 * the region's topic publisher faked and gateway nudges recorded instead of dispatched.
 */
class TriggerToolsTest extends TestCase
{
    use BuildsTriggerFixtures, McpToolFixtures, RefreshDatabase;

    private Project $project;

    /** @var list<array{path: string, headers: array, body: string}> requests to the cluster */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();
        Cache::flush();
        Bus::fake([NudgeEventGatewayJob::class]);

        Http::fake(function (Request $request) {
            $this->calls[] = [
                'path' => (string) parse_url($request->url(), PHP_URL_PATH),
                'headers' => $request->headers(),
                'body' => $request->body(),
            ];

            return str_contains($request->url(), '/proxy/v1/topics/')
                ? Http::response(['id' => 'evt-1', 'stream_id' => '1700000000000-0'], 202)
                : Http::response(['kind' => 'Status'], 200);
        });

        $this->project = $this->triggerProject($this->triggerRegion(), 'http', 'orders-fn');
    }

    private function storedEvent(ProjectTrigger $trigger, string $status, string $body, int $minutesAgo = 0): TriggerEvent
    {
        return TriggerEvent::query()->create([
            'project_id' => $this->project->id,
            'trigger_id' => $trigger->id,
            'kind' => TriggerEvent::KIND_WEBHOOK,
            'event_type' => 'dev.runr.webhook.received',
            'content_type' => 'application/json',
            'headers' => ['x-github-event' => 'push'],
            'body' => base64_encode($body),
            'body_size' => strlen($body),
            'status' => $status,
            'attempts' => $status === TriggerEvent::FAILED ? 6 : 1,
            'last_error' => $status === TriggerEvent::FAILED ? 'function answered 500' : null,
            'received_at' => now()->subMinutes($minutesAgo),
            'expires_at' => now()->addDays(7),
        ]);
    }

    public function test_triggers_are_listed_with_secret_names_only(): void
    {
        $this->addTrigger($this->project, 'webhook', ['verify' => 'github'], ['name' => 'github', 'secrets' => ['signing_secret' => 'gh-secret-value']]);

        $raw = $this->callTool('triggers_list', ['project' => 'orders-fn']);
        $result = $this->structured($raw);

        $this->assertSame(['http', 'github'], array_column($result['triggers'], 'name'));
        $this->assertTrue($result['triggers'][0]['primary']);
        $github = $result['triggers'][1];
        $this->assertSame('gateway', $github['runs_on']);
        $this->assertSame(['signing_secret'], $github['secrets']);
        $this->assertTrue($github['has_signing_secret']);
        $this->assertStringContainsString('/api/hooks/', $github['url']);
        $this->assertTrue($result['plan']['public']);
        $this->assertTrue($result['plan']['gateway']);
        $this->assertStringNotContainsString('gh-secret-value', json_encode($raw));
    }

    public function test_a_webhook_is_added_with_a_write_only_secret(): void
    {
        $raw = $this->callTool('trigger_add', [
            'project' => 'orders-fn', 'type' => 'webhook', 'name' => 'github',
            'config' => ['verify' => 'github', 'retention_days' => 3],
            'secrets' => ['signing_secret' => 'super-secret-value'],
        ]);
        $result = $this->structured($raw);

        $this->assertSame('github', $result['trigger']['name']);
        $this->assertSame(['signing_secret'], $result['trigger']['secrets']);
        $this->assertSame(3, $result['trigger']['retention_days']);
        $this->assertTrue($result['requires_deploy']);
        $this->assertStringContainsString('/api/hooks/', $result['trigger']['url']);
        $this->assertStringNotContainsString('super-secret-value', json_encode($raw));
        $this->assertSame('super-secret-value', ProjectTrigger::query()->where('name', 'github')->sole()->secret('signing_secret'));

        // The API's validation comes back as a tool error the model can act on
        $error = $this->mcpToolError($this->callTool('trigger_add', [
            'project' => 'orders-fn', 'type' => 'webhook', 'name' => 'slack', 'config' => ['verify' => 'slack'],
        ]));
        $this->assertArrayHasKey('secrets.signing_secret', $error['errors']);
        $this->assertSchemaRejects('trigger_add', ['project' => 'orders-fn', 'type' => 'carrier-pigeon'], '/type');
        $this->assertSchemaRejects('trigger_add', ['project' => 'orders-fn', 'type' => 'schedule', 'name' => 'Nightly Job'], '/name');
    }

    public function test_primary_makes_the_new_trigger_first(): void
    {
        $result = $this->structured($this->callTool('trigger_add', [
            'project' => 'orders-fn', 'type' => 'schedule', 'name' => 'nightly', 'config' => ['cron' => '0 3 * * *'], 'primary' => true,
        ]));

        $this->assertTrue($result['trigger']['primary']);
        $this->assertSame(['nightly', 'http'], $this->project->triggers()->pluck('name')->all());
        $this->assertSame('schedule', $this->project->fresh()->containerConfig->trigger_type);
    }

    public function test_update_merges_config_and_keeps_the_secret(): void
    {
        $this->addTrigger($this->project, 'webhook', [
            'verify' => 'hmac', 'hmac' => ['header' => 'X-Sig', 'algorithm' => 'sha1', 'encoding' => 'hex', 'prefix' => ''],
        ], ['name' => 'billing', 'secrets' => ['signing_secret' => 'billing-secret-1']]);

        $result = $this->structured($this->callTool('trigger_update', [
            'project' => 'orders-fn', 'trigger' => 'billing',
            'config' => ['hmac' => ['prefix' => 'sha1=', 'algorithm' => null], 'retention_days' => 14],
        ]));
        $this->assertSame(['config'], $result['changed']);

        $billing = ProjectTrigger::query()->where('name', 'billing')->sole();
        $this->assertSame('hmac', $billing->config['verify']);
        $this->assertSame('X-Sig', $billing->config['hmac']['header']);
        $this->assertSame('sha1=', $billing->config['hmac']['prefix']);
        $this->assertSame('sha256', $billing->config['hmac']['algorithm'], 'a removed key falls back to its default');
        $this->assertSame(14, $billing->config['retention_days']);
        $this->assertSame('billing-secret-1', $billing->secret('signing_secret'));

        $raw = $this->callTool('trigger_update', [
            'project' => 'orders-fn', 'trigger' => 'billing', 'name' => 'invoices', 'enabled' => false,
            'secrets' => ['signing_secret' => 'billing-secret-2'],
        ]);
        $result = $this->structured($raw);
        $this->assertEqualsCanonicalizing(['name', 'enabled', 'secrets'], $result['changed']);
        $this->assertStringNotContainsString('billing-secret-2', json_encode($raw));
        $invoices = ProjectTrigger::query()->where('name', 'invoices')->sole();
        $this->assertFalse($invoices->enabled);
        $this->assertSame('billing-secret-2', $invoices->secret('signing_secret'));

        $this->mcpToolError($this->callTool('trigger_update', ['project' => 'orders-fn', 'trigger' => 'invoices']));
        $this->mcpToolError($this->callTool('trigger_update', ['project' => 'orders-fn', 'trigger' => 'ghost', 'config' => ['verify' => 'none']]));
    }

    public function test_remove_needs_confirmation_and_keeps_the_last_trigger(): void
    {
        $this->assertSchemaRejects('trigger_remove', ['project' => 'orders-fn', 'trigger' => 'http'], '/confirm');
        $this->mcpToolError($this->callTool('trigger_remove', ['project' => 'orders-fn', 'trigger' => 'http', 'confirm' => true]));

        $nightly = $this->addTrigger($this->project, 'schedule', ['cron' => '0 3 * * *'], ['name' => 'nightly']);
        $result = $this->structured($this->callTool('trigger_remove', ['project' => 'orders-fn', 'trigger' => 'nightly', 'confirm' => true]));

        $this->assertSame('nightly', $result['removed']);
        $this->assertNull(ProjectTrigger::query()->find($nightly->id));
        $this->assertSame(['http'], $this->project->triggers()->pluck('name')->all());
        $this->assertArrayNotHasKey('cleanup', $result);
    }

    /** A removed cdc trigger's replication slot: the tool says RunR drops it (CdcSlotCleanup). */
    public function test_removing_a_cdc_trigger_says_which_slot_runr_drops(): void
    {
        Bus::fake([NudgeEventGatewayJob::class, DropCdcSlotsJob::class]);
        $this->addTrigger($this->project, 'cdc', ['publication' => 'orders'], ['name' => 'orders', 'secrets' => ['dsn' => 'postgres://u:p@db/shop']]);

        $raw = $this->callTool('trigger_remove', ['project' => 'orders-fn', 'trigger' => 'orders', 'confirm' => true]);
        $result = $this->structured($raw);

        $this->assertSame(['runr_orders_fn_orders'], $result['cleanup'][0]['slots']);
        $this->assertStringContainsString('runr_orders_fn_orders', json_encode($raw));
        Bus::assertDispatched(DropCdcSlotsJob::class);
    }

    public function test_webhook_events_are_listed_shown_and_replayed(): void
    {
        $github = $this->addTrigger($this->project, 'webhook', ['verify' => 'none'], ['name' => 'github']);
        $delivered = $this->storedEvent($github, TriggerEvent::DELIVERED, '{"a":1}', 5);
        $failed = $this->storedEvent($github, TriggerEvent::FAILED, '{"b":2}');

        $list = $this->structured($this->callTool('webhook_events_list', ['project' => 'orders-fn', 'trigger' => 'github']));
        $this->assertSame([$failed->id, $delivered->id], array_column($list['events'], 'id'));
        $this->assertSame(2, $list['total']);
        $this->assertSame('function answered 500', $list['events'][0]['last_error']);

        $onlyFailed = $this->structured($this->callTool('webhook_events_list', ['project' => 'orders-fn', 'trigger' => 'github', 'status' => 'failed']));
        $this->assertSame([$failed->id], array_column($onlyFailed['events'], 'id'));

        $one = $this->structured($this->callTool('webhook_events_list', ['project' => 'orders-fn', 'trigger' => 'github', 'event' => $failed->id]));
        $this->assertSame('{"b":2}', $one['event']['body_text']);
        $this->assertSame('push', $one['event']['headers']['x-github-event']);
        $this->assertArrayNotHasKey('body_base64', $one['event']);

        // Only webhook and platform_event triggers store events
        $this->mcpToolError($this->callTool('webhook_events_list', ['project' => 'orders-fn', 'trigger' => 'http']));

        $this->assertSchemaRejects('webhook_event_replay', ['project' => 'orders-fn', 'trigger' => 'github', 'event' => $failed->id], '/confirm');
        $replayed = $this->structured($this->callTool('webhook_event_replay', [
            'project' => 'orders-fn', 'trigger' => 'github', 'event' => $failed->id, 'confirm' => true,
        ]));
        $this->assertSame(1, $replayed['replayed']);
        $this->assertSame(TriggerEvent::PENDING, $failed->fresh()->status);
        $this->assertSame(1, $failed->fresh()->replays);

        $again = $this->storedEvent($github, TriggerEvent::FAILED, '{"c":3}');
        $many = $this->structured($this->callTool('webhook_event_replay', ['project' => 'orders-fn', 'trigger' => 'github', 'confirm' => true]));
        $this->assertSame(1, $many['replayed']);
        $this->assertSame('failed', $many['status']);
        $this->assertSame(TriggerEvent::PENDING, $again->fresh()->status);
        $this->assertSame(TriggerEvent::DELIVERED, $delivered->fresh()->status, 'only failed events by default');

        $all = $this->structured($this->callTool('webhook_event_replay', ['project' => 'orders-fn', 'trigger' => 'github', 'status' => 'all', 'confirm' => true]));
        $this->assertSame(1, $all['replayed'], 'pending events are not replayed again');
    }

    public function test_topics_are_listed_and_published(): void
    {
        Topic::query()->create(['name' => 'orders', 'knative_region_id' => $this->project->knative_region_id, 'created_by' => 7]);
        $this->addTrigger($this->project, 'topic', ['topic' => 'orders', 'start_from' => 'latest'], ['name' => 'orders-in']);

        $list = $this->structured($this->callTool('topics_list'));
        $this->assertSame(['orders'], array_column($list['topics'], 'name'));
        $this->assertSame(1, $list['topics'][0]['subscriptions']);
        $this->assertSame('7', $list['topics'][0]['created_by'], 'the creator\'s id as a string, as the API answers it');

        $one = $this->structured($this->callTool('topics_list', ['topic' => 'orders']));
        $this->assertSame($this->project->id, $one['topic']['subscriptions'][0]['project_id']);
        $this->assertSame('orders-in', $one['topic']['subscriptions'][0]['trigger']);

        $published = $this->structured($this->callTool('topic_publish', [
            'topic' => 'orders', 'data' => ['order' => 17], 'type' => 'shop.order.created', 'subject' => 'order/17',
        ]));
        $this->assertSame('1700000000000-0', $published['published']['stream_id']);

        $sent = collect($this->calls)->filter(fn ($c) => str_ends_with($c['path'], '/proxy/v1/topics/orders/events'))->values();
        $this->assertSame('{"order":17}', $sent[0]['body']);
        $this->assertSame('shop.order.created', $sent[0]['headers']['ce-type'][0]);
        $this->assertSame('order/17', $sent[0]['headers']['ce-subject'][0]);

        $this->structured($this->callTool('topic_publish', ['topic' => 'orders', 'data' => 'plain text', 'content_type' => 'text/plain']));
        $sent = collect($this->calls)->filter(fn ($c) => str_ends_with($c['path'], '/proxy/v1/topics/orders/events'))->values();
        $this->assertSame('plain text', $sent[1]['body'], 'a string with a non-JSON content type goes as is');

        $this->mcpToolError($this->callTool('topic_publish', ['topic' => 'ghost', 'data' => []]));
        $this->assertSchemaRejects('topic_publish', ['topic' => 'orders'], '/data');
    }

    public function test_trigger_tool_permissions(): void
    {
        $viewer = $this->userWithRole('viewer');

        $this->structured($this->callTool('triggers_list', ['project' => 'orders-fn'], $viewer));
        $this->structured($this->callTool('topics_list', [], $viewer));
        $this->assertToolHidden('trigger_add', ['*'], ['project' => 'orders-fn', 'type' => 'schedule'], $viewer);
        $this->assertToolHidden('trigger_remove', ['*'], ['project' => 'orders-fn', 'trigger' => 'http', 'confirm' => true], $viewer);
        $this->assertToolHidden('webhook_events_list', ['*'], ['project' => 'orders-fn', 'trigger' => 'http'], $viewer);
        $this->assertToolHidden('topic_publish', ['*'], ['topic' => 'orders', 'data' => []], $viewer);

        // A token scope narrows what the owner's connection may do
        $this->assertToolHidden('trigger_update', ['projects.view'], ['project' => 'orders-fn', 'trigger' => 'http', 'enabled' => false]);
        $this->assertToolHidden('webhook_event_replay', ['projects.view', 'projects.update'], ['project' => 'orders-fn', 'trigger' => 'http', 'confirm' => true]);
        $this->assertToolHidden('triggers_list', ['regions.view'], ['project' => 'orders-fn']);
    }
}
