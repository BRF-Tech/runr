<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\AuditEvent;
use App\Models\Observability\AlertChannel;
use App\Models\Observability\AlertEvent;
use App\Models\Observability\AlertRule;
use App\Models\Observability\UptimeCheck;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesObservabilityCluster;
use Tests\TestCase;

/**
 * alert_channels_list, alert_channel_create / _update / _delete, alert_channel_test,
 * alert_rule_create / _update / _delete, status_page_get / _update — the observability routes of
 * alerting and the status page (alerts.manage, status_page.manage), secrets write-only.
 */
class AlertAndStatusPageToolsTest extends TestCase
{
    use FakesObservabilityCluster, McpToolFixtures, RefreshDatabase;

    private const SECRET = 'whsec_0123456789abcdef-do-not-show';

    private Project $project;

    /** @var list<Request> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();
        $this->fakeObservabilityCluster();
        $this->dns->addressRecords = ['hooks.example.com' => ['93.184.216.34'], 'intranet.example.com' => ['10.0.0.8']];
        $this->externalRoutes['https://hooks.example.com/'] = function (Request $request) {
            $this->sent[] = $request;

            return Http::response(['ok' => true], 200);
        };
        $this->project = $this->obsProject($this->obsRegion());
    }

    private function webhookChannel(string $name = 'ops'): array
    {
        return $this->structured($this->callTool('alert_channel_create', [
            'name' => $name, 'type' => 'webhook', 'config' => ['url' => 'https://hooks.example.com/alerts', 'secret' => self::SECRET],
        ]))['channel'];
    }

    // ── channels ──────────────────────────────────────────────────────────────

    public function test_channels_are_created_listed_tested_changed_and_deleted_without_showing_secrets(): void
    {
        $channel = $this->webhookChannel();
        $this->assertSame('webhook', $channel['type']);
        $this->assertTrue($channel['config']['secret_set']);

        $list = $this->structured($this->callTool('alert_channels_list'));
        $this->assertSame(['ops'], array_column($list['channels'], 'name'));

        $test = $this->structured($this->callTool('alert_channel_test', ['channel' => 'ops']));
        $this->assertTrue($test['ok']);
        $this->assertCount(1, $this->sent);

        $changed = $this->structured($this->callTool('alert_channel_update', ['channel' => 'ops', 'name' => 'oncall', 'is_enabled' => false]));
        $this->assertSame('oncall', $changed['channel']['name']);
        $this->assertFalse($changed['channel']['is_enabled']);
        $this->assertTrue($changed['channel']['config']['secret_set'], 'a secret left out is kept');

        $this->assertSchemaRejects('alert_channel_delete', ['channel' => 'oncall'], '/confirm');
        $this->assertTrue($this->structured($this->callTool('alert_channel_delete', ['channel' => 'oncall', 'confirm' => true]))['deleted']);
        $this->assertSame(0, AlertChannel::count());

        foreach (AuditEvent::all() as $event) {
            $this->assertStringNotContainsString(self::SECRET, json_encode($event->getAttributes()));
        }
        $this->assertStringNotContainsString(self::SECRET, json_encode([$channel, $list, $changed]));
    }

    public function test_channel_input_is_validated(): void
    {
        $this->assertSchemaRejects('alert_channel_create', ['name' => 'x', 'type' => 'sms', 'config' => []], '/type');
        $this->assertSchemaRejects('alert_channel_create', ['name' => 'x', 'type' => 'webhook', 'config' => ['token' => 'y']], '/config/token');

        // The API refuses private webhook targets (SSRF guard)
        $error = $this->mcpToolError($this->callTool('alert_channel_create', ['name' => 'x', 'type' => 'webhook', 'config' => ['url' => 'https://intranet.example.com/hook']]));
        $this->assertArrayHasKey('config.url', $error['errors']);

        $this->assertStringContainsString('Nothing to change', $this->mcpToolError($this->callTool('alert_channel_update', ['channel' => 'whatever']))['message']);
        $this->assertStringContainsString('alert_channels_list', $this->mcpToolError($this->callTool('alert_channel_update', ['channel' => 'nope', 'name' => 'x']))['message']);
    }

    // ── rules ─────────────────────────────────────────────────────────────────

    public function test_rules_are_created_changed_and_deleted(): void
    {
        $channel = $this->webhookChannel();

        $rule = $this->structured($this->callTool('alert_rule_create', [
            'name' => 'Errors', 'type' => 'error_rate', 'project' => 'orders',
            'params' => ['threshold' => 5, 'window_minutes' => 10], 'severity' => 'critical', 'channels' => ['ops'],
        ]))['rule'];
        $this->assertSame($this->project->id, $rule['project_id']);
        $this->assertSame([$channel['id']], $rule['channel_ids']);
        $this->assertSame('critical', $rule['severity']);

        $changed = $this->structured($this->callTool('alert_rule_update', ['rule' => 'Errors', 'params' => ['threshold' => 10], 'channels' => []]))['rule'];
        $this->assertSame([], $changed['channel_ids']);

        $fired = AlertEvent::query()->create(['alert_rule_id' => $rule['id'], 'fingerprint' => 'f1', 'type' => 'error_rate', 'severity' => 'critical', 'status' => 'firing', 'title' => 'Error rate 12 %', 'project_id' => $this->project->id, 'fired_at' => now()]);

        $this->assertSchemaRejects('alert_rule_delete', ['rule' => 'Errors'], '/confirm');
        $this->structured($this->callTool('alert_rule_delete', ['rule' => 'Errors', 'confirm' => true]));
        $this->assertSame(0, AlertRule::count());
        $this->assertSame('resolved', $fired->fresh()->status, 'its firing alerts are closed');
    }

    public function test_rule_input_is_validated(): void
    {
        $this->assertSchemaRejects('alert_rule_create', ['name' => 'x', 'type' => 'disk_full'], '/type');
        $this->assertSchemaRejects('alert_rule_create', ['name' => 'x', 'type' => 'error_rate', 'params' => ['threshold' => 500]], '/params/threshold');

        // error_rate needs its threshold: the API says so
        $error = $this->mcpToolError($this->callTool('alert_rule_create', ['name' => 'x', 'type' => 'error_rate']));
        $this->assertArrayHasKey('params.threshold', $error['errors']);

        $this->assertStringContainsString('alert_channels_list', $this->mcpToolError($this->callTool('alert_rule_create', ['name' => 'x', 'type' => 'uptime_down', 'channels' => ['nope']]))['message']);
    }

    public function test_alert_tools_need_alerts_manage(): void
    {
        $developer = $this->userWithRole('developer');

        foreach (['alert_channels_list' => [], 'alert_channel_create' => ['name' => 'x', 'type' => 'email', 'config' => ['emails' => ['a@example.com']]],
            'alert_rule_create' => ['name' => 'x', 'type' => 'uptime_down'], 'alert_rule_delete' => ['rule' => 'x', 'confirm' => true]] as $tool => $arguments) {
            $this->assertToolHidden($tool, ['*'], $arguments, $developer);
        }
        $this->assertToolHidden('alert_channel_create', ['projects.view'], ['name' => 'x', 'type' => 'email', 'config' => ['emails' => ['a@example.com']]]);
    }

    // ── status page ───────────────────────────────────────────────────────────

    public function test_the_status_page_is_read_created_and_changed(): void
    {
        $api = UptimeCheck::query()->create(['project_id' => $this->project->id, 'name' => 'API', 'status' => 'up']);

        $before = $this->structured($this->callTool('status_page_get'));
        $this->assertFalse($before['page']['exists']);
        $this->assertArrayNotHasKey('preview', $before);

        $saved = $this->structured($this->callTool('status_page_update', [
            'slug' => 'acme', 'title' => 'Acme status', 'is_public' => false,
            'components' => [['check' => $api->id, 'show' => true, 'name' => 'Public API', 'order' => 1]],
        ]));
        $this->assertSame('acme', $saved['page']['slug']);
        $this->assertFalse($saved['page']['is_public']);
        $this->assertSame('/status/acme', $saved['public_path']);
        $this->assertTrue($api->fresh()->show_on_status_page);
        $this->assertSame('Public API', $api->fresh()->status_page_name);

        // A partial change keeps the other settings
        $public = $this->structured($this->callTool('status_page_update', ['is_public' => true]));
        $this->assertTrue($public['page']['is_public']);
        $this->assertSame('Acme status', $public['page']['title']);
        $this->assertSame('acme', $public['page']['slug']);
    }

    public function test_status_page_input_and_permission(): void
    {
        $this->assertSchemaRejects('status_page_update', ['slug' => 'Bad Slug'], '/slug');
        $this->assertSchemaRejects('status_page_update', ['history_days' => 400], '/history_days');

        $developer = $this->userWithRole('developer');
        $this->assertToolHidden('status_page_get', ['*'], [], $developer);
        $this->assertToolHidden('status_page_update', ['*'], ['title' => 'x'], $developer);
    }
}
