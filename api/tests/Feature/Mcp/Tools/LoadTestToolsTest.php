<?php

namespace Tests\Feature\Mcp\Tools;

use App\Jobs\LoadTests\StartLoadTestJob;
use App\Models\Observability\LoadTest;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesObservabilityCluster;
use Tests\TestCase;

/**
 * load_test_run and load_test_get (docs/load-test.md, docs/mcp.md): starting a test through the
 * load-test route and waiting for its results, the API's refusals as tool errors, reading the
 * latest run with the last five side by side and the limits, and who sees which tool.
 */
class LoadTestToolsTest extends TestCase
{
    use FakesObservabilityCluster, McpToolFixtures, RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();
        $this->fakeObservabilityCluster();
        Queue::fake();

        $region = $this->obsRegion(['knative_settings' => ['knative_domain' => 'eu.runr.test']]);
        $this->project = $this->obsProject($region);
        $this->deployment($this->project, $this->version($this->project));
    }

    private static function results(float $p95 = 42.5): array
    {
        return [
            'requests' => 6000, 'failed_requests' => 6, 'rps' => 199.8, 'error_rate' => 0.001,
            'latency_ms' => ['avg' => 20.1, 'min' => 2.0, 'p50' => 18.0, 'p95' => $p95, 'p99' => 88.0, 'max' => 301.0],
            'pods_peak' => 4, 'pods_source' => 'prometheus', 'cold_starts' => 2,
        ];
    }

    public function test_a_run_waits_for_its_results(): void
    {
        // the test ends between two polls
        $this->onPoll = function () {
            LoadTest::query()->where('status', LoadTest::PENDING)->update(['status' => LoadTest::COMPLETED, 'results' => json_encode(self::results()), 'finished_at' => now()]);
        };

        $out = $this->structured($this->callTool('load_test_run', ['project' => 'orders', 'path' => '/api', 'users' => 50, 'duration_seconds' => 30]));

        $test = $out['load_test'];
        $this->assertSame('completed', $test['status']);
        $this->assertSame('GET https://orders.eu.runr.test/api', $test['request']);
        // through JSON: a whole number of milliseconds reads back as an integer
        $this->assertEquals(['p50' => 18.0, 'p95' => 42.5, 'p99' => 88.0, 'max' => 301.0], $test['latency_ms']);
        $this->assertSame([199.8, 0.1, 4, 2], [$test['rps'], $test['error_rate_percent'], $test['pods_peak'], $test['cold_starts']]);
        $this->assertSame(50, LoadTest::query()->sole()->users);
        Queue::assertPushed(StartLoadTestJob::class);
    }

    public function test_a_run_without_waiting_answers_at_once(): void
    {
        $out = $this->structured($this->callTool('load_test_run', ['project' => 'orders', 'users' => 10, 'duration_seconds' => 300, 'wait' => false]));

        $this->assertSame('pending', $out['load_test']['status']);
        $this->assertSame(300, LoadTest::query()->sole()->duration_seconds);
    }

    public function test_refusals_of_the_api_are_tool_errors(): void
    {
        $foreign = $this->mcpToolError($this->callTool('load_test_run', ['project' => 'orders', 'host' => 'example.com', 'users' => 10, 'duration_seconds' => 30]));
        $this->assertSame(422, $foreign['status']);
        $this->assertArrayHasKey('host', $foreign['errors']);

        $this->structured($this->callTool('load_test_run', ['project' => 'orders', 'users' => 10, 'duration_seconds' => 30, 'wait' => false]));
        $busy = $this->mcpToolError($this->callTool('load_test_run', ['project' => 'orders', 'users' => 10, 'duration_seconds' => 30]));
        $this->assertSame(409, $busy['status']);

        $this->assertSchemaRejects('load_test_run', ['project' => 'orders', 'users' => 1000, 'duration_seconds' => 30], '/users');
        $this->assertSchemaRejects('load_test_run', ['project' => 'orders', 'users' => 10], '/duration_seconds');
    }

    public function test_get_reads_the_latest_run_with_the_last_five(): void
    {
        $empty = $this->structured($this->callTool('load_test_get', ['project' => 'orders']));
        $this->assertNull($empty['load_test']);
        $this->assertSame(20, $empty['limits']['daily_limit']);

        foreach ([30.0, 31.0, 32.0, 33.0, 34.0, 35.0] as $i => $p95) {
            (new LoadTest)->forceFill([
                'project_id' => $this->project->id, 'status' => LoadTest::COMPLETED, 'host' => 'orders.eu.runr.test', 'path' => '/',
                'users' => 10, 'duration_seconds' => 30, 'results' => self::results($p95), 'created_at' => now()->subMinutes(10 - $i),
            ])->save();
        }
        $failed = (new LoadTest)->forceFill([
            'project_id' => $this->project->id, 'status' => LoadTest::FAILED, 'host' => 'orders.eu.runr.test', 'path' => '/',
            'users' => 100, 'duration_seconds' => 60, 'error' => 'k6 ended without results', 'created_at' => now()->subHour(),
        ]);
        $failed->save();

        $out = $this->structured($this->callTool('load_test_get', ['project' => 'orders']));
        $this->assertEquals(35.0, $out['load_test']['latency_ms']['p95'], 'the latest run');
        $this->assertEquals([35.0, 34.0, 33.0, 32.0, 31.0], array_map(fn ($t) => $t['latency_ms']['p95'], $out['recent']));
        $this->assertSame([10, 50, 100], $out['limits']['users']);
        $this->assertSame(['orders.eu.runr.test'], $out['limits']['targets']);

        $one = $this->structured($this->callTool('load_test_get', ['project' => 'orders', 'load_test' => $failed->id]));
        $this->assertSame('failed', $one['load_test']['status']);
        $this->assertSame('k6 ended without results', $one['load_test']['error']);
    }

    public function test_running_needs_deploy_and_reading_needs_metrics(): void
    {
        $viewer = $this->userWithRole('viewer');

        $this->assertToolHidden('load_test_run', ['*'], ['project' => 'orders', 'users' => 10, 'duration_seconds' => 30], $viewer);
        $this->assertNull($this->structured($this->callTool('load_test_get', ['project' => $this->project->id], $viewer))['load_test']);
        // token scopes: running is projects.deploy, reading metrics.view
        $this->assertToolHidden('load_test_get', ['projects.deploy'], ['project' => 'orders']);
        $this->assertToolHidden('load_test_run', ['metrics.view'], ['project' => 'orders', 'users' => 10, 'duration_seconds' => 30]);
    }
}
