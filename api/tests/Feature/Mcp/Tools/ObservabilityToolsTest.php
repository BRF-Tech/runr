<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\Observability\AlertEvent;
use App\Models\Observability\AlertRule;
use App\Models\Observability\ProjectObservabilitySetting;
use App\Models\Observability\ReplayCapture;
use App\Models\Observability\UptimeCheck;
use App\Models\Observability\UptimeIncident;
use App\Models\Project;
use App\Services\Observability\Replay\ReplayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesObservabilityCluster;
use Tests\TestCase;
use Tests\Unit\Observability\TraceNormalizerTest;

/**
 * observability_get / _update, traces_search, trace_get, replays_list, replay_get, replay_run,
 * uptime_checks_list, uptime_check_create / _update / _delete / _run / _results,
 * uptime_incidents_list, alert_rules_list, alert_events_list — the observability routes with the
 * faked region (Tempo and Loki through the Kubernetes API proxy, project endpoints as external
 * routes), as the observability feature tests do.
 */
class ObservabilityToolsTest extends TestCase
{
    use FakesObservabilityCluster, McpToolFixtures, RefreshDatabase;

    private const TEMPO = '/api/v1/namespaces/observability/services/http:tempo:3200/proxy/api/';

    private const LOKI = '/api/v1/namespaces/observability/services/http:loki-stack:3100/proxy/loki/api/v1/query_range';

    private const TRACE = '4bf92f3577b34da6a3ce929d0e0e4736';

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();
        $this->fakeObservabilityCluster();
        Queue::fake();
        config(['observability.replay.ingest_url' => 'https://console.runr.test']);

        $region = $this->obsRegion(['knative_settings' => ['knative_domain' => 'eu.runr.test']]);
        $this->tracingReady($region);
        $this->project = $this->obsProject($region);
    }

    private function capture(array $overrides = []): ReplayCapture
    {
        return app(ReplayService::class)->store($this->project, ProjectObservabilitySetting::forProject($this->project), array_replace([
            'captured_at' => now()->subMinute()->toIso8601String(),
            'trigger' => 'http',
            'request_id' => 'req-1',
            'trace_id' => self::TRACE,
            'revision' => 'orders-00003',
            'method' => 'POST',
            'path' => '/orders',
            'query' => 'page=2',
            'headers' => ['content-type' => 'application/json'],
            'redacted_headers' => [],
            'body' => '{"item":42}',
            'body_base64' => false,
            'body_size' => 11,
            'status' => 500,
            'timed_out' => false,
            'duration_ms' => 12.5,
            'response_headers' => ['content-type' => 'application/json'],
            'response_body' => '{"error":"database unreachable"}',
        ], $overrides));
    }

    private function check(array $attributes = []): UptimeCheck
    {
        return UptimeCheck::query()->create(array_merge([
            'project_id' => $this->project->id, 'name' => 'Health', 'path' => '/health',
            'interval_seconds' => 60, 'timeout_seconds' => 5, 'failure_threshold' => 3, 'recovery_threshold' => 2,
        ], $attributes));
    }

    // ── settings ───────────────────────────────────────────────────────────────

    public function test_observability_settings_are_read_and_switched(): void
    {
        $before = $this->structured($this->callTool('observability_get', ['project' => 'orders']));
        $this->assertFalse($before['tracing']['enabled']);
        $this->assertSame('managed', $before['tracing']['region']['mode']);
        $this->assertTrue($before['tracing']['region']['queryable']);
        $this->assertFalse($before['replay']['enabled']);

        $after = $this->structured($this->callTool('observability_update', [
            'project' => 'orders',
            'tracing' => ['enabled' => true, 'sample_ratio' => 0.5],
            'replay' => ['enabled' => true, 'retention_days' => 3, 'redact_headers' => ['X-Customer']],
        ]));
        $this->assertTrue($after['tracing']['enabled']);
        $this->assertSame(0.5, $after['tracing']['sample_ratio']);
        $this->assertTrue($after['replay']['enabled']);
        $this->assertSame(3, $after['replay']['retention_days']);
        $this->assertFalse($after['requires_redeploy']);

        $settings = ProjectObservabilitySetting::forProject($this->project)->fresh();
        $this->assertTrue((bool) $settings->tracing_enabled);
        $this->assertTrue((bool) $settings->replay_enabled);
    }

    public function test_tracing_needs_a_ready_backend(): void
    {
        $region = $this->obsRegion();
        $project = $this->obsProject($region, 'billing');

        $error = $this->mcpToolError($this->callTool('observability_update', ['project' => $project->id, 'tracing' => ['enabled' => true]]));
        $this->assertSame(409, $error['status']);
    }

    public function test_observability_update_follows_the_permission_of_each_switch(): void
    {
        $this->assertToolHidden('observability_update', ['projects.view'], ['project' => 'orders', 'tracing' => ['enabled' => true]]);

        $this->customRole('replayer', ['replay.manage']);
        $replayer = $this->userWithRole('replayer');
        $error = $this->mcpToolError($this->callTool('observability_update', ['project' => $this->project->id, 'tracing' => ['enabled' => true]], $replayer));
        $this->assertSame(403, $error['status']);
        $this->assertSame(['projects.update'], $error['permissions']);

        $this->assertSchemaRejects('observability_update', ['project' => 'orders', 'tracing' => ['enabled' => true, 'sample_ratio' => 2]], '/tracing/sample_ratio');
        $this->mcpToolError($this->callTool('observability_update', ['project' => 'orders']));
    }

    // ── traces ─────────────────────────────────────────────────────────────────

    public function test_traces_are_searched(): void
    {
        $this->k8sRoutes['GET '.self::TEMPO.'search'] = fn () => [200, ['traces' => [[
            'traceID' => self::TRACE, 'rootServiceName' => 'orders', 'rootTraceName' => 'GET',
            'startTimeUnixNano' => '1760000000000000000', 'durationMs' => 12,
            'spanSet' => ['matched' => 1, 'spans' => [['spanID' => 'bbbbbbbbbbbbbbbb', 'durationNanos' => '12000000', 'attributes' => [
                ['key' => 'http.response.status_code', 'value' => ['intValue' => '500']],
                ['key' => 'url.path', 'value' => ['stringValue' => '/orders']],
            ]]]],
        ]]]];

        $result = $this->structured($this->callTool('traces_search', ['project' => 'orders', 'status' => 'error', 'path' => '/orders', 'since' => '2h', 'limit' => 5]));

        $this->assertSame(1, $result['count']);
        $this->assertSame(self::TRACE, $result['traces'][0]['trace_id']);
        $this->assertTrue($result['traces'][0]['error']);
        $this->assertSame('/orders', $result['traces'][0]['path']);

        $call = $this->k8sCallsTo('GET', self::TEMPO.'search')[0];
        $this->assertStringContainsString('status = error', $call['query']['q']);
        $this->assertSame('5', $call['query']['limit']);
        $this->assertEqualsWithDelta(now()->timestamp - 7200, (int) $call['query']['start'], 5);
    }

    public function test_traces_search_needs_traces_view_and_valid_arguments(): void
    {
        $this->assertToolHidden('traces_search', ['projects.view'], ['project' => 'orders']);
        $this->assertSchemaRejects('traces_search', ['project' => 'orders', 'status' => 'broken'], '/status');

        $error = $this->mcpToolError($this->callTool('traces_search', ['project' => 'orders', 'since' => 'not a time at all']));
        $this->assertStringContainsString('since', $error['message']);
    }

    public function test_a_backend_that_cannot_be_searched_is_a_tool_error(): void
    {
        $region = $this->obsRegion();
        $this->tracingReady($region, ['mode' => 'external', 'export_endpoint' => 'https://otlp.example.com']);
        $project = $this->obsProject($region, 'billing');

        $error = $this->mcpToolError($this->callTool('traces_search', ['project' => $project->id]));
        $this->assertSame(409, $error['status']);
    }

    public function test_a_trace_is_read_as_a_span_tree_with_its_logs(): void
    {
        $this->k8sRoutes['GET '.self::TEMPO.'traces/'.self::TRACE] = fn () => [200, TraceNormalizerTest::otlpTrace($this->project->id)];
        $this->k8sRoutes['GET '.self::LOKI] = fn () => [200, ['data' => ['result' => [[
            'stream' => ['pod' => 'orders-00001-abc', 'container' => 'handler'],
            'values' => [['1760000000040000000', 'ERROR database unreachable trace_id='.self::TRACE]],
        ]]]]];

        $result = $this->structured($this->callTool('trace_get', ['project' => 'orders', 'trace_id' => strtoupper(self::TRACE), 'include_logs' => true]));

        $this->assertSame(3, $result['span_count']);
        $this->assertSame(1, $result['error_count']);
        $this->assertSame(0, $result['spans'][0]['depth']);
        $failed = collect($result['spans'])->firstWhere('status', 'error');
        $this->assertSame('/orders', $failed['attributes']['url.path']);
        $this->assertSame('database unreachable', $failed['events'][0]['message']);
        $this->assertStringContainsString('database unreachable', $result['logs'][0]['line']);
        $this->assertSame('orders-00001-abc', $result['logs'][0]['pod']);
    }

    public function test_trace_logs_need_logs_view_too(): void
    {
        $this->k8sRoutes['GET '.self::TEMPO.'traces/'.self::TRACE] = fn () => [200, TraceNormalizerTest::otlpTrace($this->project->id)];
        $this->customRole('tracer', ['traces.view']);

        $result = $this->structured($this->callTool('trace_get', ['project' => $this->project->id, 'trace_id' => self::TRACE, 'include_logs' => true], $this->userWithRole('tracer')));

        $this->assertSame(3, $result['span_count']);
        $this->assertArrayNotHasKey('logs', $result);
        $this->assertStringContainsString('logs.view', $result['logs_error']);
        $this->assertSchemaRejects('trace_get', ['project' => 'orders', 'trace_id' => 'not-a-trace'], '/trace_id');
    }

    // ── replay ─────────────────────────────────────────────────────────────────

    public function test_captures_are_listed_and_read(): void
    {
        $capture = $this->capture();

        $list = $this->structured($this->callTool('replays_list', ['project' => 'orders', 'status' => 500]));
        $this->assertSame(1, $list['total']);
        $this->assertSame($capture->id, $list['captures'][0]['id']);
        $this->assertArrayNotHasKey('body', $list['captures'][0]);

        $detail = $this->structured($this->callTool('replay_get', ['project' => 'orders', 'capture' => $capture->id]));
        $this->assertSame('{"error":"database unreachable"}', $detail['capture']['response_body']);
        $this->assertSame('POST', $detail['capture']['method']);
    }

    public function test_a_capture_is_replayed_after_confirmation(): void
    {
        $capture = $this->capture();
        $sent = null;
        $this->externalRoutes['https://orders.eu.runr.test/'] = function (Request $request) use (&$sent) {
            $sent = $request;

            return Http::response(['id' => 1, 'ok' => true], 201);
        };

        $this->assertSchemaRejects('replay_run', ['project' => 'orders', 'capture' => $capture->id], '/confirm');
        $this->assertNull($sent, 'nothing is sent without the confirmation');

        $result = $this->structured($this->callTool('replay_run', [
            'project' => 'orders', 'capture' => $capture->id, 'headers' => ['Authorization' => 'Bearer test-only'], 'confirm' => true,
        ]));

        $this->assertSame(500, $result['original']['status']);
        $this->assertSame(201, $result['replay']['status']);
        $this->assertTrue($result['diff']['changed']);
        $this->assertSame('Bearer test-only', $sent->header('Authorization')[0]);
        $this->assertSame(1, $capture->fresh()->replay_count);
    }

    public function test_replay_tools_need_replay_manage(): void
    {
        $capture = $this->capture();
        $viewer = $this->userWithRole('viewer');

        $this->assertToolHidden('replays_list', ['*'], ['project' => 'orders'], $viewer);
        $this->assertToolHidden('replay_run', ['*'], ['project' => 'orders', 'capture' => $capture->id, 'confirm' => true], $viewer);
        $this->assertSchemaRejects('replays_list', ['project' => 'orders', 'status' => 42], '/status');
    }

    // ── uptime ─────────────────────────────────────────────────────────────────

    public function test_an_uptime_check_is_created_changed_run_and_deleted(): void
    {
        $created = $this->structured($this->callTool('uptime_check_create', [
            'project' => 'orders', 'name' => 'API', 'path' => '/health', 'expected_status' => '200-299', 'headers' => ['X-Token' => 'secret-header'],
        ]));
        $this->assertSame('API', $created['check']['name']);
        $this->assertSame(['X-Token'], $created['check']['header_names']);
        $this->assertStringNotContainsString('secret-header', json_encode($created));

        $updated = $this->structured($this->callTool('uptime_check_update', ['project' => 'orders', 'check' => 'API', 'interval_seconds' => 120]));
        $this->assertSame(120, $updated['check']['interval_seconds']);
        $this->assertSame(['X-Token' => 'secret-header'], UptimeCheck::query()->sole()->headers, 'headers stay when not given');

        $this->externalRoutes['https://orders.eu.runr.test/health'] = Http::response('ok', 200);
        $run = $this->structured($this->callTool('uptime_check_run', ['project' => 'orders', 'check' => 'API']));
        $this->assertTrue($run['ok']);
        $this->assertSame(200, $run['status_code']);
        $this->assertSame('up', $run['check']['status']);

        $list = $this->structured($this->callTool('uptime_checks_list', ['project' => 'orders']));
        $this->assertSame(1, $list['count']);
        $this->assertSame(0, $list['down']);

        $results = $this->structured($this->callTool('uptime_check_results', ['project' => 'orders', 'check' => 'API', 'hours' => 1]));
        $this->assertTrue($results['results'][0]['ok']);

        $this->assertSchemaRejects('uptime_check_delete', ['project' => 'orders', 'check' => 'API'], '/confirm');
        $this->structured($this->callTool('uptime_check_delete', ['project' => 'orders', 'check' => 'API', 'confirm' => true]));
        $this->assertSame(0, UptimeCheck::query()->count());
    }

    public function test_uptime_checks_are_validated_by_the_api_and_the_schema(): void
    {
        $this->assertSchemaRejects('uptime_check_create', ['project' => 'orders', 'name' => 'x', 'interval_seconds' => 5], '/interval_seconds');
        $this->assertSchemaRejects('uptime_check_create', ['project' => 'orders'], '/name');

        $error = $this->mcpToolError($this->callTool('uptime_check_create', ['project' => 'orders', 'name' => 'x', 'expected_status' => 'whatever']));
        $this->assertSame(422, $error['status']);
        $this->assertArrayHasKey('expected_status', $error['errors']);

        $missing = $this->mcpToolError($this->callTool('uptime_check_run', ['project' => 'orders', 'check' => 'nope']));
        $this->assertStringContainsString('no uptime check', $missing['message']);
    }

    public function test_uptime_changes_need_uptime_manage(): void
    {
        $this->check();
        $viewer = $this->userWithRole('viewer');

        $this->assertToolHidden('uptime_check_create', ['*'], ['project' => 'orders', 'name' => 'x'], $viewer);
        $this->assertToolHidden('uptime_check_delete', ['*'], ['project' => 'orders', 'check' => 'Health', 'confirm' => true], $viewer);

        // reading is metrics.view: a viewer sees the checks and incidents
        $this->assertSame(1, $this->structured($this->callTool('uptime_checks_list', ['project' => $this->project->id], $viewer))['count']);
    }

    public function test_incidents_are_listed_and_filtered(): void
    {
        $check = $this->check();
        UptimeIncident::query()->create(['uptime_check_id' => $check->id, 'project_id' => $this->project->id, 'status' => 'resolved', 'title' => 'Health down', 'started_at' => now()->subDay(), 'resolved_at' => now()->subDay()->addMinutes(5)]);
        UptimeIncident::query()->create(['uptime_check_id' => $check->id, 'project_id' => $this->project->id, 'status' => 'open', 'title' => 'Health down', 'started_at' => now()->subMinutes(3)]);

        $all = $this->structured($this->callTool('uptime_incidents_list', ['project' => 'orders']));
        $this->assertSame(2, $all['count']);
        $this->assertSame(1, $all['open']);

        $open = $this->structured($this->callTool('uptime_incidents_list', ['project' => 'orders', 'status' => 'open']));
        $this->assertSame(1, $open['count']);

        $this->assertToolHidden('uptime_incidents_list', ['regions.view'], ['project' => 'orders']);
        $this->assertSchemaRejects('uptime_incidents_list', ['project' => 'orders', 'status' => 'closed'], '/status');
    }

    // ── alerts ─────────────────────────────────────────────────────────────────

    public function test_alert_rules_and_events_are_listed(): void
    {
        $rule = AlertRule::query()->create(['name' => 'Errors', 'type' => 'error_rate', 'project_id' => $this->project->id, 'params' => ['threshold' => 5], 'severity' => 'critical', 'cooldown_minutes' => 15, 'channel_ids' => [], 'is_enabled' => true, 'state' => 'firing']);
        AlertRule::query()->create(['name' => 'Capacity', 'type' => 'resource_threshold', 'params' => ['resource' => 'any'], 'severity' => 'warning', 'cooldown_minutes' => 15, 'channel_ids' => [], 'is_enabled' => true, 'state' => 'ok']);
        AlertEvent::query()->create(['alert_rule_id' => $rule->id, 'fingerprint' => 'f1', 'type' => 'error_rate', 'severity' => 'critical', 'status' => 'firing', 'title' => 'Error rate 12 %', 'project_id' => $this->project->id, 'fired_at' => now()]);

        $rules = $this->structured($this->callTool('alert_rules_list', []));
        $this->assertSame(2, $rules['count']);
        $this->assertSame(1, $rules['firing']);
        $this->assertSame(1, $this->structured($this->callTool('alert_rules_list', ['project' => 'orders']))['count']);

        $events = $this->structured($this->callTool('alert_events_list', ['status' => 'firing']));
        $this->assertSame(1, $events['count']);
        $this->assertSame('Error rate 12 %', $events['alerts'][0]['title']);
    }

    public function test_alert_tools_need_alerts_manage(): void
    {
        $developer = $this->userWithRole('developer');

        $this->assertToolHidden('alert_rules_list', ['*'], [], $developer);
        $this->assertToolHidden('alert_events_list', ['*'], [], $developer);
        $this->assertSchemaRejects('alert_events_list', ['status' => 'loud'], '/status');
    }
}
